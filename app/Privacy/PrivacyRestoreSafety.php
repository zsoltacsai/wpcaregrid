<?php

namespace App\Privacy;

use App\Models\PrivacyTombstoneReplaySession;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Recovery must stay quarantined until an independent privacy journal can be read and replayed. */
final class PrivacyRestoreSafety
{
    public function __construct(private readonly PrivacyTombstoneJournal $journal) {}

    /** @return array{state:string, reason:string, recovery_mode:bool, replay_required:bool, replay_verified:bool, destructive_retention_enabled:false, journal_state:string, journal_watermark:?string, verified_events:int} */
    public function status(): array
    {
        $journal = $this->journal->readiness();
        $recoveryMode = (bool) config('platform.recovery_mode');
        $recoveryId = config('platform.recovery_id');
        $sourceBackupUuid = config('platform.recovery_source_backup_uuid');
        $snapshotWatermark = config('platform.recovery_snapshot_watermark');
        $journalWatermark = $journal['watermark'] ?? null;
        $hasRecoveryContext = is_string($recoveryId) && Str::isUuid($recoveryId)
            && is_string($sourceBackupUuid) && Str::isUuid($sourceBackupUuid)
            && ($snapshotWatermark === null || (is_string($snapshotWatermark) && preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $snapshotWatermark)));
        $latestReplay = $hasRecoveryContext && Schema::hasTable('privacy_tombstone_replay_sessions')
            ? PrivacyTombstoneReplaySession::query()->where('recovery_id', $recoveryId)
                ->where('source_backup_uuid', $sourceBackupUuid)
                ->where('snapshot_watermark', $snapshotWatermark)->where('status', 'complete')->where('verification_status', 'verified')
                ->orderByDesc('id')->first()
            : null;
        $replayVerified = $latestReplay !== null && $latestReplay->ending_watermark === $journalWatermark;
        $journalReady = ($journal['state'] ?? 'unknown') === 'ready';
        $ready = $journalReady && (! $recoveryMode || $replayVerified);
        $reason = ! $journalReady
            ? (string) ($journal['reason'] ?? 'journal_unavailable')
            : ($recoveryMode && ! $hasRecoveryContext ? 'recovery_context_missing'
                : ($recoveryMode && ! $replayVerified ? 'privacy_replay_required' : 'journal_integrity_verified'));

        return [
            'state' => $ready ? 'ready' : 'blocked',
            'reason' => $reason,
            'recovery_mode' => $recoveryMode,
            'replay_required' => $recoveryMode,
            'replay_verified' => $replayVerified,
            'destructive_retention_enabled' => false,
            'journal_state' => (string) ($journal['state'] ?? 'unknown'),
            'journal_watermark' => $journalWatermark,
            'verified_events' => (int) ($journal['verified_events'] ?? 0),
        ];
    }

    /** @return iterable<array{event_id:string, subject_uuid:string, scope:string, action:string, sequence:string}> */
    public function eventsAfter(?string $snapshotWatermark): iterable
    {
        if (($this->journal->readiness()['state'] ?? 'blocked') !== 'ready') {
            throw new \RuntimeException('Recovery quarantine cannot exit while privacy tombstone journal readiness is blocked.');
        }

        return $this->journal->eventsAfter($snapshotWatermark);
    }
}
