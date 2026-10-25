<?php

namespace App\Backups;

use App\Compatibility\CapabilityReadiness;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Restores are protected by a verified full pre-restore backup. When the
 * connector confirms that destructive work started and then reports failure,
 * the same restore is kept active while one compensating restore of that
 * linked safety backup is queued.
 */
class RestoreService
{
    private const MAX_AUTOMATIC_RECOVERIES = 1;

    public function __construct(
        private readonly BackupPolicy $policy,
        private readonly JobQueue $jobs,
    ) {}

    /** @throws BackupRefused */
    public function request(Website $website, Backup $backup, BackupType $scope, string $actor, string $confirmation): BackupRestore
    {
        if (strcasecmp(trim($confirmation), $website->host()) !== 0) {
            throw new BackupRefused('CONFIRMATION_MISMATCH');
        }

        return DB::transaction(function () use ($website, $backup, $scope, $actor) {
            // Serializes requests for a website on MySQL. The in-progress check
            // and row creation happen while this lock is held.
            $lockedWebsite = Website::query()->whereKey($website->id)->lockForUpdate()->firstOrFail();
            $source = Backup::query()->whereKey($backup->id)->where('website_id', $lockedWebsite->id)->lockForUpdate()->first();

            if (! $source || ($refusal = $this->refusal($lockedWebsite, $source, $scope))) {
                throw new BackupRefused($source ? $refusal : 'BACKUP_NOT_FOUND');
            }

            $restore = $lockedWebsite->restores()->create([
                'backup_id' => $source->id,
                'scope' => $scope,
                'status' => RestoreStatus::AwaitingSafetyBackup,
                'requested_by' => $actor,
                'confirmed_at' => now(),
            ]);

            AuditLog::record($lockedWebsite, 'restore.requested', $source, $restore, $actor, ['scope' => $scope->value]);

            try {
                $safety = app(BackupService::class)->request($lockedWebsite, BackupReason::PreRestore, $actor, BackupType::Full);
            } catch (BackupRefused $e) {
                $this->finish($restore, RestoreStatus::Failed, 'SAFETY_BACKUP_FAILED', 'The safety backup could not be started ('.$e->errorCode.').');

                return $restore;
            }

            $restore->forceFill(['safety_backup_id' => $safety->id])->save();

            return $restore;
        });
    }

    /** Why this restore may not be requested, or null. */
    public function refusal(Website $website, Backup $backup, BackupType $scope, ?int $ignoreRestoreId = null): ?string
    {
        if ($backup->website_id !== $website->id) {
            return 'BACKUP_NOT_FOUND';
        }

        // Keep one authoritative host-readiness decision visible to both UI
        // and request handling, even when another restore policy is disabled.
        // The Connector repeats isolation acquisition before destructive work.
        if (! app(CapabilityReadiness::class)->restoreIsolationSupported($website)) {
            return 'RESTORE_ISOLATION_UNAVAILABLE';
        }

        $decision = $this->policy->for($website, BackupReason::PreRestore);
        $connector = $website->activeConnector;

        $refusal = match (true) {
            ! $decision->restoreAllowed => 'RESTORE_NOT_AVAILABLE',
            ! $decision->eligible => $decision->refusal,
            ! $backup->isRestorable() => 'BACKUP_NOT_RESTORABLE',
            ! $backup->type->canRestore($scope) => 'RESTORE_TYPE_MISMATCH',
            $connector === null || $connector->presence() !== Presence::Connected => 'NOT_CONNECTED',
            ! $connector->can($scope->restoreCapability()) => 'CAPABILITY_MISSING',
            ! $connector->can(BackupType::Full->backupCapability()) => 'SAFETY_BACKUP_UNAVAILABLE',
            $website->restores()->whereIn('status', $this->activeStatusValues())->when($ignoreRestoreId, fn ($query) => $query->whereKeyNot($ignoreRestoreId))->exists() => 'RESTORE_IN_PROGRESS',
            default => null,
        };

        if ($refusal !== null) {
            return $refusal;
        }

        return app(CapabilityReadiness::class)->restoreRefusal($website, $backup, $scope->value);
    }

    /** The safety backup settled: queue the original restore, or stop. */
    public function safetyBackupSettled(Backup $safety): void
    {
        if ($safety->reason !== BackupReason::PreRestore) {
            return;
        }

        DB::transaction(function () use ($safety) {
            $candidate = BackupRestore::query()
                ->where('safety_backup_id', $safety->id)
                ->where('status', RestoreStatus::AwaitingSafetyBackup)
                ->first();

            if (! $candidate) {
                return;
            }

            // Keep the website → restore lock order consistent with request()
            // and jobFinished(), avoiding a lock inversion at settlement.
            $website = Website::query()->whereKey($candidate->website_id)->lockForUpdate()->first();
            if (! $website) {
                return;
            }

            $restore = BackupRestore::query()
                ->whereKey($candidate->id)
                ->where('status', RestoreStatus::AwaitingSafetyBackup)
                ->lockForUpdate()
                ->first();

            if (! $restore) {
                return;
            }

            $restore->loadMissing(['website', 'backup']);
            if (! $this->validSafetyBackup($restore, $safety)) {
                $this->finish($restore, RestoreStatus::Failed, 'SAFETY_BACKUP_FAILED', 'The safety backup of the current site was not verified, so nothing was restored.');

                return;
            }

            if ($refusal = $this->refusal($website, $restore->backup, $restore->scope, $restore->id)) {
                $this->finish($restore, RestoreStatus::Failed, $refusal, 'The restore is no longer allowed.');

                return;
            }

            try {
                $job = $this->queueRestore($restore, $restore->backup, $restore->scope, $restore->requested_by);
            } catch (BackupRefused|ConnectorError|ValidationException $e) {
                $this->finish($restore, RestoreStatus::Failed, 'RESTORE_NOT_ALLOWED', $e->getMessage());

                return;
            }

            $restore->transitionTo(RestoreStatus::Queued);
            AuditLog::record($website, 'restore.queued', $restore->backup, $restore, data: ['job' => $job->uuid]);
        });
    }

    /** A connector job reached the site. Re-delivery leaves the same phase intact. */
    public function started(BackupRestore $restore, WebsiteJob $job): void
    {
        $restore->refresh();

        if ((int) $restore->recovery_job_id === (int) $job->id) {
            if ($restore->status === RestoreStatus::RecoveryQueued) {
                $restore->transitionTo(RestoreStatus::Recovering, ['recovery_started_at' => now()]);
                AuditLog::record($restore->website, 'restore.recovery_started', $restore->safetyBackup, $restore, data: ['job' => $job->uuid]);
            }

            return;
        }

        if ($restore->status === RestoreStatus::Queued) {
            $restore->transitionTo(RestoreStatus::Restoring, ['started_at' => now()]);
            AuditLog::record($restore->website, 'restore.started', $restore->backup, $restore);
        }
    }

    /** Process exactly one final result for either the requested restore or its recovery job. */
    public function jobFinished(BackupRestore $restore, WebsiteJob $job): void
    {
        DB::transaction(function () use ($restore, $job) {
            $lockedWebsite = Website::query()->whereKey($restore->website_id)->lockForUpdate()->firstOrFail();
            $restore = BackupRestore::query()->whereKey($restore->id)->lockForUpdate()->firstOrFail();
            $isRecovery = (int) $restore->recovery_job_id === (int) $job->id;

            if ($isRecovery) {
                if (! in_array($restore->status, [RestoreStatus::RecoveryQueued, RestoreStatus::Recovering], true)) {
                    return;
                }
                if ($restore->status === RestoreStatus::RecoveryQueued) {
                    $restore->transitionTo(RestoreStatus::Recovering, ['recovery_started_at' => $restore->recovery_started_at ?? now()]);
                }
                $this->finishRecovery($restore, $job);

                return;
            }

            if ($restore->status !== RestoreStatus::Restoring) {
                return;
            }

            $checksPassed = $this->checksPassed($job);
            $destructive = $this->destructiveBoundary($job);

            if ($job->status === JobStatus::Success && $checksPassed) {
                $this->finish($restore, RestoreStatus::Restored, null, null, $job->result);

                return;
            }

            $code = $job->error['code'] ?? $job->result_code ?? 'RESTORE_FAILED';
            $message = (string) ($job->error['message'] ?? 'The restore failed.');
            $stage = $this->failureStage($job);

            if ($destructive === true) {
                $this->beginCompensatingRecovery($lockedWebsite, $restore, $job, (string) $code, $message, $stage);

                return;
            }

            if ($destructive === null) {
                // Older or malformed connector reports cannot prove whether the
                // live DB/files changed. Never guess and never auto-run a restore.
                $this->requireManualRecovery($restore, (string) $code, $message, 'DESTRUCTIVE_STATE_UNKNOWN', $stage, null, $job->result);

                return;
            }

            if ($job->status === JobStatus::Success || $job->status === JobStatus::Partial) {
                $this->finish($restore, RestoreStatus::NeedsAttention, (string) $code, 'The restore ran, but the checks afterwards found problems.', $job->result);

                return;
            }

            $this->finish($restore, RestoreStatus::Failed, (string) $code, $message, $job->result);
        });
    }

    private function beginCompensatingRecovery(Website $website, BackupRestore $restore, WebsiteJob $failedJob, string $code, string $message, string $stage): void
    {
        $restore->forceFill([
            'error_code' => substr($code, 0, 64),
            'error_message' => substr($message, 0, 500),
            'failure_stage' => $stage,
            'destructive_started_at' => now(),
            'result' => $this->safeResult($failedJob->result),
        ])->save();

        AuditLog::record($website, 'restore.failed', $restore->backup, $restore, data: ['status' => RestoreStatus::Restoring->value, 'code' => $code, 'stage' => $stage, 'destructive' => true]);

        $safety = $restore->safetyBackup;
        $reason = match (true) {
            $restore->recovery_attempts >= self::MAX_AUTOMATIC_RECOVERIES || $restore->recovery_job_id !== null => 'RECOVERY_ALREADY_ATTEMPTED',
            ! $safety || ! $this->validSafetyBackup($restore, $safety) => 'SAFETY_BACKUP_NOT_ELIGIBLE',
            $restore->backup_id === $safety->id || $failedJob->backup_id === $safety->id => 'SAFETY_BACKUP_IS_FAILED_SOURCE',
            $website->restores()->whereIn('status', $this->activeStatusValues())->whereKeyNot($restore->id)->exists() => 'CONCURRENT_RESTORE_ACTIVE',
            default => null,
        };

        if ($reason !== null) {
            $this->requireManualRecovery($restore, $code, $message, $reason, $stage, now(), $failedJob->result);

            return;
        }

        try {
            $job = $this->queueRestore($restore, $safety, BackupType::Full, 'system:restore-recovery');
            $restore->transitionTo(RestoreStatus::RecoveryQueued, [
                'recovery_job_id' => $job->id,
                'recovery_attempts' => 1,
                'recovery_attempted_at' => now(),
                'recovery_started_at' => null,
                'recovery_error_code' => null,
                'recovery_error_message' => null,
                'manual_recovery_required' => false,
            ]);
            AuditLog::record($website, 'restore.recovery_requested', $safety, $restore, data: ['job' => $job->uuid, 'source_failure' => $code, 'stage' => $stage]);
        } catch (BackupRefused|ConnectorError|ValidationException $e) {
            // Do not expose arbitrary connector/DB exception text in the audit
            // record. The original restore failure remains in error_code/message.
            $this->requireManualRecovery($restore, $code, $message, 'RECOVERY_QUEUE_FAILED', $stage, now(), $failedJob->result);
        }
    }

    private function finishRecovery(BackupRestore $restore, WebsiteJob $job): void
    {
        $connectorCode = (string) ($job->error['code'] ?? $job->result_code ?? 'RESTORE_FAILED');
        $code = $this->recoveryFailureCode($job);
        $recoveryResult = $this->safeResult($job->result) ?? [];
        $recoveryResult['failure_stage'] = $this->failureStage($job);
        $recoveryResult['connector_error_code'] = substr($connectorCode, 0, 64);

        if ($job->status === JobStatus::Success && $this->checksPassed($job)) {
            $restore->transitionTo(RestoreStatus::Recovered, [
                'recovery_completed_at' => now(),
                'recovery_result' => $recoveryResult,
                'recovery_error_code' => null,
                'recovery_error_message' => null,
                'manual_recovery_required' => false,
                'completed_at' => now(),
                // Keep the original failure code/message for operator history.
            ]);
            AuditLog::record($restore->website, 'restore.recovery_completed', $restore->safetyBackup, $restore, data: ['job' => $job->uuid, 'source_failure' => $restore->error_code]);

            return;
        }

        $restore->transitionTo(RestoreStatus::RecoveryFailed, [
            'recovery_completed_at' => now(),
            'recovery_error_code' => substr($code, 0, 64),
            'recovery_error_message' => 'The compensating restore did not complete ('.$code.').',
            'recovery_result' => $recoveryResult,
            'manual_recovery_required' => true,
            'completed_at' => now(),
        ]);
        AuditLog::record($restore->website, 'restore.recovery_failed', $restore->safetyBackup, $restore, data: ['job' => $job->uuid, 'code' => $code, 'manual_recovery_required' => true]);
    }

    private function requireManualRecovery(BackupRestore $restore, string $originalCode, string $originalMessage, string $recoveryCode, string $stage, ?Carbon $destructiveAt, ?array $result): void
    {
        $restore->refresh();
        $status = $restore->status;
        if (! in_array($status, [RestoreStatus::Restoring, RestoreStatus::RecoveryQueued], true)) {
            return;
        }

        $restore->transitionTo(RestoreStatus::ManualRecoveryRequired, [
            'error_code' => substr($originalCode, 0, 64),
            'error_message' => substr($originalMessage, 0, 500),
            'failure_stage' => $stage,
            'destructive_started_at' => $restore->destructive_started_at ?? $destructiveAt,
            'recovery_error_code' => substr($recoveryCode, 0, 64),
            'recovery_error_message' => 'Automatic recovery was not safe to start; operator intervention is required.',
            'recovery_result' => $this->safeResult($result),
            'manual_recovery_required' => true,
            'completed_at' => now(),
        ]);
        AuditLog::record($restore->website, 'restore.manual_recovery_required', $restore->safetyBackup, $restore, data: ['code' => $recoveryCode, 'stage' => $stage]);
    }

    private function queueRestore(BackupRestore $restore, Backup $backup, BackupType $scope, string $actor): WebsiteJob
    {
        $objects = $backup->objects()->whereIn('name', $scope->objects())->get()
            ->map(fn ($object) => ['name' => $object->name, 'size' => $object->size, 'sha256' => $object->sha256])->values()->all();

        if (count($objects) !== count($scope->objects()) || collect($objects)->contains(fn ($object) => ! $object['size'] || ! $object['sha256'])) {
            throw new BackupRefused('BACKUP_NOT_RESTORABLE');
        }

        return $this->jobs->queue($restore->website, JobType::Restore, [
            'restore_uuid' => $restore->uuid,
            'backup_uuid' => $backup->uuid,
            'scope' => $scope->value,
            'objects' => $objects,
        ], $actor, ['backup_id' => $backup->id, 'restore_id' => $restore->id]);
    }

    private function validSafetyBackup(BackupRestore $restore, Backup $safety): bool
    {
        return (int) $safety->id === (int) $restore->safety_backup_id
            && (int) $safety->website_id === (int) $restore->website_id
            && (int) $safety->id !== (int) $restore->backup_id
            && $safety->reason === BackupReason::PreRestore
            && $safety->type === BackupType::Full
            && $safety->isRestorable();
    }

    private function checksPassed(WebsiteJob $job): bool
    {
        $checks = $job->result['post_checks'] ?? null;
        $checksPassed = is_array($checks) && $checks !== [] && ! in_array(false, array_map(fn ($check) => ($check['passed'] ?? false) === true, $checks), true);
        $healthStatus = $job->result['health']['status'] ?? null;

        return $checksPassed && $healthStatus !== null && $healthStatus !== 'critical';
    }

    /** true = explicitly destructive, false = explicitly pre-destructive, null = unknown/legacy report. */
    private function destructiveBoundary(WebsiteJob $job): ?bool
    {
        $details = $job->error['details'] ?? [];
        $value = is_array($details) ? ($details['destructive_mutation_started'] ?? null) : null;
        $value ??= $job->result['destructive_mutation_started'] ?? null;

        return is_bool($value) ? $value : null;
    }

    private function failureStage(WebsiteJob $job): string
    {
        $details = $job->error['details'] ?? [];
        $stage = is_array($details) ? ($details['failure_stage'] ?? null) : null;
        $stage ??= $job->result['failure_stage'] ?? null;
        $allowed = ['source_validation', 'backup_download', 'file_staging', 'database_staging', 'database_swap', 'file_apply', 'post_restore_health'];

        return is_string($stage) && in_array($stage, $allowed, true) ? $stage : 'unknown';
    }

    private function recoveryFailureCode(WebsiteJob $job): string
    {
        return match ($this->failureStage($job)) {
            'database_staging', 'database_swap' => 'RECOVERY_DATABASE_FAILED',
            'file_staging', 'file_apply' => 'RECOVERY_FILES_FAILED',
            'post_restore_health' => 'RECOVERY_HEALTH_FAILED',
            default => 'RECOVERY_FAILED',
        };
    }

    /** @return array<string, mixed>|null */
    private function safeResult(?array $result): ?array
    {
        if (! is_array($result)) {
            return null;
        }

        return array_intersect_key($result, array_flip(['post_checks', 'health', 'restored', 'wordpress_version', 'destructive_mutation_started']));
    }

    /** Finalize a restore which does not require compensating recovery. */
    private function finish(BackupRestore $restore, RestoreStatus $status, ?string $code, ?string $message, ?array $result = null): void
    {
        $restore->transitionTo($status, [
            'error_code' => $code ? substr($code, 0, 64) : null,
            'error_message' => $message ? substr($message, 0, 500) : null,
            'result' => $this->safeResult($result),
            'completed_at' => now(),
        ]);

        AuditLog::record($restore->website, $status === RestoreStatus::Failed ? 'restore.failed' : 'restore.completed', $restore->backup, $restore, data: ['status' => $status->value, 'code' => $code]);
    }

    /** @return list<string> */
    private function activeStatusValues(): array
    {
        return array_map(fn (RestoreStatus $status) => $status->value, array_filter(RestoreStatus::cases(), fn (RestoreStatus $status) => $status->isActive()));
    }
}
