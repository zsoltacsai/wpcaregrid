<?php

namespace App\Privacy;

use App\Models\Customer;
use App\Models\PrivacyExportArtifact;
use App\Models\PrivacyRequest;
use App\Models\PrivacyTombstoneReplayEvent;
use App\Models\PrivacyTombstoneReplaySession;
use App\Owner\AdminAudit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Applies only explicit privacy suppression state from an independently verified journal. */
final class PrivacyTombstoneReplayApplier
{
    public const SNAPSHOT_LOCK = 'privacy-tombstone-journal-snapshot';

    public function __construct(private readonly PrivacyTombstoneJournal $journal) {}

    public function replay(string $recoveryId, ?string $snapshotWatermark, ?int $actorId = null, ?string $sourceBackupUuid = null): PrivacyTombstoneReplaySession
    {
        if (! Str::isUuid($recoveryId) || ($sourceBackupUuid !== null && ! Str::isUuid($sourceBackupUuid))) {
            throw new RuntimeException('Privacy replay recovery reference is invalid.');
        }
        if (! config('platform.recovery_mode')) {
            throw new RuntimeException('Privacy replay requires platform recovery quarantine to be active.');
        }

        $lock = Cache::lock(self::SNAPSHOT_LOCK, (int) config('platform_backup.timeout', 3600) + 300);
        if (! $lock->get()) {
            throw new RuntimeException('Privacy replay is blocked while a platform backup snapshot is active.');
        }

        try {
            return $this->replayLocked($recoveryId, $snapshotWatermark, $actorId, $sourceBackupUuid);
        } finally {
            $lock->release();
        }
    }

    private function replayLocked(string $recoveryId, ?string $snapshotWatermark, ?int $actorId, ?string $sourceBackupUuid): PrivacyTombstoneReplaySession
    {
        $session = PrivacyTombstoneReplaySession::query()->create([
            'recovery_id' => $recoveryId,
            'source_backup_uuid' => $sourceBackupUuid,
            'snapshot_watermark' => $snapshotWatermark,
            'ending_watermark' => $snapshotWatermark,
            'status' => 'running',
            'verification_status' => 'pending',
            'actor_user_id' => $actorId,
            'started_at' => now('UTC'),
        ]);
        AdminAudit::record(null, 'privacy.journal.replay_started', 'privacy_replay', $session->uuid, [
            'snapshot_watermark' => $snapshotWatermark,
            'source_backup_uuid' => $sourceBackupUuid,
        ]);

        $counts = ['discovered' => 0, 'applied' => 0, 'already' => 0, 'blocked' => 0];
        $discoveredIds = [];
        try {
            $readiness = $this->journal->readiness();
            if (($readiness['state'] ?? null) !== 'ready') {
                throw new RuntimeException('privacy_journal_unavailable');
            }
            $events = $this->journal->eventsAfter($snapshotWatermark);
            foreach ($events as $event) {
                $counts['discovered']++;
                $discoveredIds[] = $event['event_id'] ?? null;
                $result = $this->applyOne($session, $event);
                $counts[$result['counter']]++;
            }
            $endingWatermark = $this->journal->watermark();
            if (($this->journal->readiness()['state'] ?? null) !== 'ready') {
                throw new RuntimeException('privacy_journal_unavailable');
            }
            $verified = $this->journal->eventsAfter($snapshotWatermark);
            $verifiedIds = [];
            foreach ($verified as $event) {
                $verifiedIds[] = $event['event_id'] ?? null;
            }
            if ($verifiedIds !== $discoveredIds || in_array(null, $verifiedIds, true) || $this->journal->watermark() !== $endingWatermark) {
                throw new RuntimeException('privacy_replay_source_changed');
            }

            $session->forceFill([
                'ending_watermark' => $endingWatermark,
                'status' => $counts['blocked'] === 0 ? 'complete' : 'blocked',
                'verification_status' => $counts['blocked'] === 0 ? 'verified' : 'blocked',
                'events_discovered' => $counts['discovered'],
                'events_applied' => $counts['applied'],
                'events_already_applied' => $counts['already'],
                'events_blocked' => $counts['blocked'],
                'failure_code' => $counts['blocked'] === 0 ? null : 'replay_event_blocked',
                'completed_at' => now('UTC'),
            ])->save();
            AdminAudit::record(null, $counts['blocked'] === 0 ? 'privacy.journal.replay_completed' : 'privacy.journal.replay_blocked', 'privacy_replay', $session->uuid, [
                'events_discovered' => $counts['discovered'],
                'events_applied' => $counts['applied'],
                'events_already_applied' => $counts['already'],
                'events_blocked' => $counts['blocked'],
            ]);

            return $session->refresh();
        } catch (Throwable $exception) {
            $session->forceFill([
                'status' => 'failed',
                'verification_status' => 'failed',
                'events_discovered' => $counts['discovered'],
                'events_applied' => $counts['applied'],
                'events_already_applied' => $counts['already'],
                'events_blocked' => $counts['blocked'],
                'failure_code' => preg_match('/\Aprivacy_[a-z0-9_]+\z/', $exception->getMessage()) ? $exception->getMessage() : 'privacy_replay_failed',
                'completed_at' => now('UTC'),
            ])->save();
            AdminAudit::record(null, 'privacy.journal.replay_failed', 'privacy_replay', $session->uuid, [
                'failure_code' => $session->failure_code,
                'events_discovered' => $counts['discovered'],
            ]);

            throw $exception;
        }
    }

    private function applyOne(PrivacyTombstoneReplaySession $session, array $event): array
    {
        $eventId = (string) ($event['event_id'] ?? '');
        $sequence = (string) ($event['sequence'] ?? '');
        $action = (string) ($event['action'] ?? '');
        $subjectUuid = (string) ($event['subject_uuid'] ?? '');
        if (! preg_match('/\A[a-f0-9]{64}\z/', $eventId) || ! preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $sequence)) {
            throw new RuntimeException('privacy_journal_unsupported_event');
        }

        $existingOutcome = PrivacyTombstoneReplayEvent::query()->where('replay_session_id', $session->id)->where('event_id', $eventId)->value('outcome');
        if ($existingOutcome !== null) {
            return ['counter' => $existingOutcome === 'blocked' ? 'blocked' : 'already'];
        }

        $outcome = 'blocked';
        $blocker = null;
        if (($event['scope'] ?? null) !== 'customer_account' || ($event['tenant_uuid'] ?? null) !== $subjectUuid) {
            $blocker = 'tenant_scope_mismatch';
        } else {
            $customer = Customer::query()->where('uuid', $subjectUuid)->lockForUpdate()->first();
            $case = PrivacyRequest::query()->where('uuid', $event['request_uuid'] ?? '')
                ->whereHas('customer', fn ($query) => $query->where('uuid', $subjectUuid))->lockForUpdate()->first();
            if (! $customer || ! $case) {
                $blocker = 'subject_or_request_missing';
            } elseif ($action === 'account_access_restricted' && $case->request_type === PrivacyRequestType::AccountClosure) {
                if ($customer->activeSubscriptions()->exists()) {
                    $blocker = 'billing_reconciliation_required';
                } else {
                    $needsChange = $customer->active || $case->status !== PrivacyRequestStatus::AccessRestricted;
                    DB::transaction(function () use ($customer, $case): void {
                        if ($customer->active) {
                            $customer->forceFill(['active' => false])->save();
                        }
                        if ($case->status !== PrivacyRequestStatus::AccessRestricted) {
                            $case->forceFill([
                                'status' => PrivacyRequestStatus::AccessRestricted,
                                'lock_version' => $case->lock_version + 1,
                            ])->save();
                        }
                    }, attempts: 3);
                    $outcome = $needsChange ? 'applied' : 'already_applied';
                }
            } elseif ($action === 'privacy_export_revoked') {
                $artifact = PrivacyExportArtifact::query()->where('uuid', $event['artifact_uuid'] ?? '')
                    ->whereHas('privacyRequest', fn ($query) => $query->where('customer_id', $customer->id)->where('uuid', $case->uuid))
                    ->lockForUpdate()->first();
                if (! $artifact) {
                    $blocker = 'privacy_export_mapping_missing';
                } elseif ($artifact->status === PrivacyExportStatus::Revoked) {
                    $outcome = 'already_applied';
                } elseif (in_array($artifact->status, [PrivacyExportStatus::Available, PrivacyExportStatus::Failed, PrivacyExportStatus::CleanupFailed], true)) {
                    $artifact->forceFill(['status' => PrivacyExportStatus::Revoked, 'download_expires_at' => now('UTC'), 'failure_code' => null])->save();
                    $outcome = 'applied';
                } else {
                    $outcome = in_array($artifact->status, [PrivacyExportStatus::Expired, PrivacyExportStatus::Queued, PrivacyExportStatus::Generating], true) ? 'already_applied' : 'blocked';
                    $blocker = $outcome === 'blocked' ? 'privacy_export_state_unknown' : null;
                }
            } else {
                $blocker = 'unsupported_privacy_action';
            }
        }

        $counter = match ($outcome) {
            'applied' => 'applied',
            'already_applied' => 'already',
            default => 'blocked',
        };
        PrivacyTombstoneReplayEvent::query()->create([
            'replay_session_id' => $session->id,
            'event_id' => $eventId,
            'sequence' => $sequence,
            'action' => $action,
            'subject_uuid' => $subjectUuid,
            'outcome' => $outcome,
            'blocker_code' => $blocker,
            'processed_at' => now('UTC'),
        ]);
        if ($outcome === 'blocked') {
            AdminAudit::record(null, 'privacy.journal.replay_event_blocked', 'privacy_replay', $session->uuid, ['event_id' => $eventId, 'blocker_code' => $blocker]);
        } elseif ($outcome === 'applied') {
            AdminAudit::record(null, 'privacy.journal.replay_event_applied', 'privacy_replay', $session->uuid, ['event_id' => $eventId, 'action' => $action]);
        }

        return ['counter' => $counter];
    }
}
