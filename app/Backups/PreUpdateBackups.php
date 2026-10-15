<?php

namespace App\Backups;

use App\Connector\JobQueue;
use App\Connector\JobStatus;
use App\Connector\JobType;
use App\Models\Backup;
use App\Models\Website;
use App\Models\WebsiteJob;
use App\Websites\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Updates protected by a backup:
 *
 *   update requested → policy requires a backup? → full backup (pre_update)
 *   → update job "waiting" → backup verified → update queued with backup_uuid
 *                          → backup failed   → update failed: BACKUP_REQUIRED_FAILED
 *
 * A waiting update is never delivered to the connector.
 */
class PreUpdateBackups
{
    public const BLOCKED = 'BACKUP_REQUIRED_FAILED';

    public function __construct(
        private readonly BackupPolicy $policy,
        private readonly JobQueue $jobs,
    ) {}

    /** @param  array<string, mixed>  $payload  Already validated. */
    public function queueUpdate(Website $website, JobType $type, array $payload, string $requestedBy): WebsiteJob
    {
        return DB::transaction(function () use ($website, $type, $payload, $requestedBy) {
            // Share the same website lock used by restore and backup deletion
            // so an update cannot appear after deletion has checked dependencies.
            $lockedWebsite = Website::query()->whereKey($website->id)->lockForUpdate()->firstOrFail();
            if (! $this->policy->for($lockedWebsite)->preUpdateRequired) {
                return $this->jobs->queue($lockedWebsite, $type, $payload, $requestedBy, ['status' => JobStatus::Queued]);
            }

            $job = $this->jobs->queue($lockedWebsite, $type, $payload, $requestedBy, ['status' => JobStatus::Waiting]);

            try {
                $backup = app(BackupService::class)->request($lockedWebsite, BackupReason::PreUpdate, $requestedBy, BackupType::Full, [
                    'update_job_id' => $job->id,
                    'update_target' => $this->target($website, $type, $payload),
                ]);
            } catch (BackupRefused $e) {
                $this->block($job, $e->errorCode);

                return $job->refresh();
            }

            $job->forceFill(['backup_id' => $backup->id])->save();

            return $job;
        });
    }

    /** Release or block the update waiting for this backup. */
    public function backupSettled(Backup $backup): void
    {
        if ($backup->reason !== BackupReason::PreUpdate || ! $backup->update_job_id) {
            return;
        }

        $job = WebsiteJob::whereKey($backup->update_job_id)->where('status', JobStatus::Waiting)->first();
        if (! $job) {
            return;
        }

        if ($backup->status === BackupStatus::Verified) {
            $job->forceFill(['status' => JobStatus::Queued, 'payload' => ['backup_uuid' => $backup->uuid] + ($job->payload ?? [])])->save();

            return;
        }

        $this->block($job, $backup->error_code ?? $backup->status->value);
    }

    private function block(WebsiteJob $job, string $reason): void
    {
        $job->forceFill([
            'status' => JobStatus::Failed,
            'result_code' => self::BLOCKED,
            'completed_at' => now(),
            'error' => ['code' => self::BLOCKED, 'message' => 'The update was not run: the required backup could not be verified.', 'details' => ['backup' => $reason]],
        ])->save();

        AuditLog::record($job->website, 'update.blocked', $job->backup, data: ['job' => $job->uuid, 'type' => $job->type->value, 'reason' => $reason]);
    }

    /** @return array{kind: string, identifier: string, previous_version: ?string} */
    private function target(Website $website, JobType $type, array $payload): array
    {
        $inventory = $website->inventory;

        return match ($type) {
            JobType::UpdatePlugin => ['kind' => 'plugin', 'identifier' => $payload['plugin'], 'previous_version' => collect($inventory?->plugins)->firstWhere('file', $payload['plugin'])['version'] ?? null],
            JobType::UpdateTheme => ['kind' => 'theme', 'identifier' => $payload['stylesheet'], 'previous_version' => collect($inventory?->themes)->firstWhere('stylesheet', $payload['stylesheet'])['version'] ?? null],
            default => ['kind' => 'core', 'identifier' => 'wordpress', 'previous_version' => $inventory?->core['version'] ?? $website->wordpress_version],
        };
    }
}
