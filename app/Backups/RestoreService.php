<?php

namespace App\Backups;

use App\Connector\ConnectorError;
use App\Connector\JobQueue;
use App\Connector\JobStatus;
use App\Connector\JobType;
use App\Connector\Presence;
use App\Models\Backup;
use App\Models\BackupRestore;
use App\Models\Website;
use App\Models\WebsiteJob;
use App\Websites\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Restores are never started directly:
 *
 *   request (confirmed, validated) → safety backup of the current site
 *   → safety backup verified → RESTORE job → connector restores
 *   (maintenance mode, then post-restore checks) → restored | needs_attention | failed
 *
 * The source is always one specific verified backup of the same website.
 */
class RestoreService
{
    public function __construct(
        private readonly BackupPolicy $policy,
        private readonly JobQueue $jobs,
    ) {}

    /** @throws BackupRefused */
    public function request(Website $website, Backup $backup, BackupType $scope, string $actor, string $confirmation): BackupRestore
    {
        if ($refusal = $this->refusal($website, $backup, $scope)) {
            throw new BackupRefused($refusal);
        }

        if (strcasecmp(trim($confirmation), $website->host()) !== 0) {
            throw new BackupRefused('CONFIRMATION_MISMATCH');
        }

        return DB::transaction(function () use ($website, $backup, $scope, $actor) {
            $restore = $website->restores()->create([
                'backup_id' => $backup->id,
                'scope' => $scope,
                'status' => RestoreStatus::AwaitingSafetyBackup,
                'requested_by' => $actor,
                'confirmed_at' => now(),
            ]);

            AuditLog::record($website, 'restore.requested', $backup, $restore, $actor, ['scope' => $scope->value]);

            try {
                $safety = app(BackupService::class)->request($website, BackupReason::PreRestore, $actor, BackupType::Full);
            } catch (BackupRefused $e) {
                $this->finish($restore, RestoreStatus::Failed, 'SAFETY_BACKUP_FAILED', 'The safety backup could not be started ('.$e->errorCode.').');

                return $restore;
            }

            $restore->forceFill(['safety_backup_id' => $safety->id])->save();

            return $restore;
        });
    }

    /** Why this restore may not be requested, or null. */
    public function refusal(Website $website, Backup $backup, BackupType $scope): ?string
    {
        $decision = $this->policy->for($website);
        $connector = $website->activeConnector;

        return match (true) {
            $backup->website_id !== $website->id => 'BACKUP_NOT_FOUND',
            ! $decision->restoreAllowed => 'RESTORE_NOT_AVAILABLE',
            ! $decision->eligible => $decision->refusal,
            ! $backup->isRestorable() => 'BACKUP_NOT_RESTORABLE',
            ! $backup->type->canRestore($scope) => 'RESTORE_TYPE_MISMATCH',
            $connector === null || $connector->presence() !== Presence::Connected => 'NOT_CONNECTED',
            ! $connector->can($scope->restoreCapability()) => 'CAPABILITY_MISSING',
            ! $connector->can(BackupType::Full->backupCapability()) => 'SAFETY_BACKUP_UNAVAILABLE',
            $website->restores()->whereIn('status', ['awaiting_safety_backup', 'queued', 'restoring'])->exists() => 'RESTORE_IN_PROGRESS',
            default => null,
        };
    }

    /** The safety backup settled: queue the restore, or stop. Nothing is overwritten without it. */
    public function safetyBackupSettled(Backup $safety): void
    {
        if ($safety->reason !== BackupReason::PreRestore) {
            return;
        }

        $restore = BackupRestore::where('safety_backup_id', $safety->id)->where('status', RestoreStatus::AwaitingSafetyBackup)->first();
        if (! $restore) {
            return;
        }

        if ($safety->status !== BackupStatus::Verified) {
            $this->finish($restore, RestoreStatus::Failed, 'SAFETY_BACKUP_FAILED', 'The safety backup of the current site was not verified, so nothing was restored.');

            return;
        }

        $source = $restore->backup;
        // Everything is checked again: time has passed since the request.
        if ($refusal = $this->refusal($restore->website, $source, $restore->scope)) {
            if ($refusal !== 'RESTORE_IN_PROGRESS') {
                $this->finish($restore, RestoreStatus::Failed, $refusal, 'The restore is no longer allowed.');

                return;
            }
        }

        $objects = $source->objects()->whereIn('name', $restore->scope->objects())->get()
            ->map(fn ($object) => ['name' => $object->name, 'size' => $object->size, 'sha256' => $object->sha256])->values()->all();

        try {
            $this->jobs->queue($restore->website, JobType::Restore, [
                'restore_uuid' => $restore->uuid,
                'backup_uuid' => $source->uuid,
                'scope' => $restore->scope->value,
                'objects' => $objects,
            ], $restore->requested_by, ['backup_id' => $source->id, 'restore_id' => $restore->id]);
        } catch (ConnectorError|ValidationException $e) {
            $this->finish($restore, RestoreStatus::Failed, 'RESTORE_NOT_ALLOWED', $e->getMessage());

            return;
        }

        $restore->transitionTo(RestoreStatus::Queued);
    }

    public function started(BackupRestore $restore): void
    {
        if ($restore->status === RestoreStatus::Queued) {
            $restore->transitionTo(RestoreStatus::Restoring, ['started_at' => now()]);
            AuditLog::record($restore->website, 'restore.started', $restore->backup, $restore);
        }
    }

    /**
     * The connector reported. Success needs the connector's post-restore
     * checks to have passed; a restore that only unpacked is not "restored".
     */
    public function jobFinished(BackupRestore $restore, WebsiteJob $job): void
    {
        $restore->refresh();
        if (! $restore->status->isActive() || $restore->status === RestoreStatus::AwaitingSafetyBackup) {
            return;
        }

        if ($restore->status === RestoreStatus::Queued) {
            $restore->transitionTo(RestoreStatus::Restoring, ['started_at' => now()]);
        }

        $checks = $job->result['post_checks'] ?? null;
        $checksPassed = is_array($checks) && $checks !== [] && ! in_array(false, array_map(fn ($check) => ($check['passed'] ?? false) === true, $checks), true);
        $healthStatus = $job->result['health']['status'] ?? null;

        match (true) {
            $job->status === JobStatus::Success && $checksPassed && $healthStatus !== 'critical' => $this->finish($restore, RestoreStatus::Restored, null, null, $job->result),
            in_array($job->status, [JobStatus::Success, JobStatus::Partial], true) => $this->finish($restore, RestoreStatus::NeedsAttention, $job->result_code ?? 'POST_CHECKS_FAILED', 'The restore ran, but the checks afterwards found problems.', $job->result),
            default => $this->finish($restore, RestoreStatus::Failed, $job->error['code'] ?? $job->result_code ?? 'RESTORE_FAILED', (string) ($job->error['message'] ?? 'The restore failed.'), $job->result),
        };
    }

    private function finish(BackupRestore $restore, RestoreStatus $status, ?string $code, ?string $message, ?array $result = null): void
    {
        $restore->transitionTo($status, [
            'error_code' => $code ? substr($code, 0, 64) : null,
            'error_message' => $message ? substr($message, 0, 500) : null,
            'result' => $result ? array_intersect_key($result, array_flip(['post_checks', 'health', 'restored', 'wordpress_version'])) : null,
            'completed_at' => now(),
        ]);

        AuditLog::record($restore->website, $status === RestoreStatus::Failed ? 'restore.failed' : 'restore.completed', $restore->backup, $restore, data: ['status' => $status->value, 'code' => $code]);
    }
}
