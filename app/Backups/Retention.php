<?php

namespace App\Backups;

use App\Connector\JobStatus;
use App\Models\Backup;
use App\Models\BackupRestore;
use App\Models\Website;

/**
 * Reports legacy retention candidates for comparison only:
 *   - retention_count: keep the newest N verified backups;
 *   - retention_days: expire verified backups older than N days.
 * This service is intentionally non-destructive. Legacy environment values
 * must never authorize a customer or provider backup deletion.
 */
class Retention
{
    public function __construct(
        private readonly BackupPolicy $policy,
    ) {}

    /** @return list<string> UUIDs that the legacy count/day inputs would select. */
    public function preview(Website $website): array
    {
        $decision = $this->policy->for($website);

        if (! $decision->hasRetention()) {
            return [];
        }

        $verified = $website->backups()->usableRecoveryPoint()
            ->orderByDesc('verified_at')->orderByDesc('id')->get()->values();

        $protected = $this->protectedIds($website);
        $cutoff = $decision->retentionDays ? now()->subDays($decision->retentionDays) : null;
        $candidates = [];

        foreach ($verified as $position => $backup) {
            $overCount = $decision->retentionCount !== null && $position >= $decision->retentionCount;
            $tooOld = $cutoff !== null && $backup->verified_at->lt($cutoff);

            if ($position === 0 || in_array($backup->id, $protected, true) || (! $overCount && ! $tooOld)) {
                continue;
            }

            $candidates[] = $backup->uuid;
        }

        return $candidates;
    }

    /** @return list<int> */
    private function protectedIds(Website $website): array
    {
        $restores = BackupRestore::where('website_id', $website->id)
            ->whereIn('status', array_map(fn (RestoreStatus $status) => $status->value, array_filter(RestoreStatus::cases(), fn (RestoreStatus $status) => $status->needsRecoveryArtifacts())))
            ->get();
        $updates = $website->jobs()->whereNotNull('backup_id')->whereIn('status', [JobStatus::Waiting->value, JobStatus::Queued->value, JobStatus::Delivered->value])->pluck('backup_id');

        return $restores->pluck('backup_id')->merge($restores->pluck('safety_backup_id'))->merge($updates)->filter()->map(fn ($id) => (int) $id)->values()->all();
    }
}
