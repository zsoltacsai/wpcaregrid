<?php

namespace App\Backups;

use App\Compatibility\CapabilitySnapshotStore;
use App\Connector\ConnectorError;
use App\Connector\JobQueue;
use App\Connector\JobStatus;
use App\Models\Backup;
use App\Models\Connector;
use App\Models\Website;
use App\Models\WebsiteJob;
use App\Security\RemediationService;
use App\Websites\AuditLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Starts backups and moves them through their lifecycle. The connector job
 * (BACKUP_FILES / _DATABASE / _FULL) makes and uploads the data; the
 * backup is only "verified" once TransferBackup has stored and checked it.
 */
class BackupService
{
    public function __construct(
        private readonly JobQueue $jobs,
        private readonly BackupPolicy $policy,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  e.g. schedule_window, update_target, retry_of_id
     *
     * @throws BackupRefused
     */
    public function request(Website $website, BackupReason $reason, string $actor, ?BackupType $type = null, array $attributes = []): Backup
    {
        $decision = $this->policy->for($website, $reason);
        $type ??= $decision->type;

        if ($refusal = $this->refusal($website, $decision, $type, $reason)) {
            throw new BackupRefused($refusal);
        }

        try {
            return DB::transaction(function () use ($website, $reason, $actor, $type, $attributes, $decision) {
                $entitlementSnapshot = $website->subscription?->backupEntitlements()
                    ->where('effective_at', '<=', now())
                    ->orderByDesc('effective_at')
                    ->orderByDesc('id')
                    ->first();
                $backup = $website->backups()->create([
                    'type' => $type,
                    'reason' => $reason,
                    'status' => BackupStatus::Queued,
                    'storage_provider' => $decision->storage,
                    'requested_by' => $actor,
                    'backup_entitlement_snapshot_id' => $entitlementSnapshot?->id,
                ] + $attributes);
                $backup->forceFill(['storage_path' => $backup->storagePrefix()])->save();

                $this->jobs->queue($website, $type->jobType(), [
                    'backup_uuid' => $backup->uuid,
                    'reason' => $reason->value,
                    'chunk_size' => (int) config('backup.upload.chunk_size'),
                ], $actor, ['backup_id' => $backup->id]);

                AuditLog::record($website, 'backup.requested', $backup, actor: $actor, data: ['type' => $type->value, 'reason' => $reason->value]);

                return $backup;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BackupRefused('ALREADY_SCHEDULED');
        } catch (ConnectorError|ValidationException $e) {
            throw new BackupRefused('JOB_NOT_ALLOWED', $e->getMessage());
        }
    }

    /** Why a backup cannot start now, or null. */
    public function refusal(Website $website, PolicyDecision $decision, BackupType $type, BackupReason $reason): ?string
    {
        $connector = $website->activeConnector;

        return match (true) {
            ! $decision->eligible => $decision->refusal,
            $connector === null => 'NOT_CONNECTED',
            ! $connector->can($type->backupCapability()) => 'CAPABILITY_MISSING',
            $reason === BackupReason::Manual && ! $decision->manualAllowed => 'MANUAL_NOT_ALLOWED',
            $reason === BackupReason::Manual && $this->inProgress($website) => 'BACKUP_IN_PROGRESS',
            $reason === BackupReason::Manual && $this->withinCooldown($website) => 'TOO_SOON',
            default => null,
        };
    }

    public function inProgress(Website $website): bool
    {
        return $website->backups()->whereIn('status', array_map(fn ($s) => $s->value, array_filter(BackupStatus::cases(), fn ($s) => $s->inProgress())))->exists();
    }

    private function withinCooldown(Website $website): bool
    {
        return $website->backups()
            ->where('reason', BackupReason::Manual)
            ->where('created_at', '>', now()->subMinutes((int) config('backup.manual_cooldown_minutes')))
            ->whereNotIn('status', [BackupStatus::Failed->value])
            ->exists();
    }

    /** The job reached the connector. */
    public function started(Backup $backup, ?Connector $connector): void
    {
        if ($backup->status !== BackupStatus::Queued) {
            return;
        }

        $backup->transitionTo(BackupStatus::Running, ['connector_id' => $connector?->id, 'started_at' => now()]);
        AuditLog::record($backup->website, 'backup.started', $backup);
    }

    /** The connector job ended. Anything but a finalized upload means the backup failed. */
    public function jobFinished(Backup $backup, WebsiteJob $job): void
    {
        $backup->refresh();

        if (! in_array($backup->status, [BackupStatus::Queued, BackupStatus::Running, BackupStatus::Uploading], true)) {
            return; // Already uploaded (or settled): the job result changes nothing.
        }

        [$code, $message] = match (true) {
            $job->status === JobStatus::Success => ['UPLOAD_INCOMPLETE', 'The connector finished without completing the upload.'],
            is_array($job->error) && isset($job->error['code']) => [(string) $job->error['code'], (string) ($job->error['message'] ?? '')],
            default => [$job->result_code ?: 'BACKUP_FAILED', 'The backup did not complete.'],
        };

        $this->fail($backup, $code, $message);
    }

    /** Fail an unfinished backup, and settle whatever waited for it. */
    public function fail(Backup $backup, string $code, string $message = '', string $actor = 'system'): void
    {
        $backup->transitionTo(BackupStatus::Failed, [
            'error_code' => substr($code, 0, 64),
            'error_message' => substr($message, 0, 500),
            'failed_at' => now(),
            'completed_at' => now(),
        ]);

        $backup->job()->whereIn('status', [JobStatus::Queued->value, JobStatus::Delivered->value])
            ->update(['status' => JobStatus::Cancelled->value, 'error' => json_encode(['code' => 'BACKUP_FAILED', 'message' => 'The backup failed.'])]);

        AuditLog::record($backup->website, 'backup.failed', $backup, actor: $actor, data: ['code' => $code]);

        $this->settled($backup);
    }

    /** A backup reached verified, failed or corrupt: release or block what depends on it. */
    public function settled(Backup $backup): void
    {
        $snapshot = $backup->website->capabilitySnapshot;
        if ($snapshot) {
            app(CapabilitySnapshotStore::class)->refresh(
                $backup->website,
                $backup->website->activeConnector,
                $snapshot->facts,
                $snapshot->checked_at,
            );
        }

        app(PreUpdateBackups::class)->backupSettled($backup);
        app(RestoreService::class)->safetyBackupSettled($backup);
        app(RemediationService::class)->backupSettled($backup);
    }
}
