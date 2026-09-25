<?php

namespace App\Backups;

use App\Connector\JobStatus;
use App\Models\Backup;
use App\Models\BackupRestore;
use App\Models\Website;
use Illuminate\Support\Facades\Log;

/**
 * Applies the plan's retention to a website's verified backups:
 *   - retention_count: keep the newest N verified backups;
 *   - retention_days: expire verified backups older than N days.
 * Without both values nothing is deleted. The newest verified backup is
 * never expired, nor a backup a restore or a waiting update depends on.
 */
class Retention
{
    public function __construct(
        private readonly BackupPolicy $policy,
        private readonly BackupDeletion $deletion,
    ) {}

    /** @return list<string> UUIDs of the backups expired. */
    public function apply(Website $website): array
    {
        $decision = $this->policy->for($website);

        if (! $decision->hasRetention()) {
            return [];
        }

        $verified = $website->backups()->verified()->whereNull('objects_deleted_at')
            ->orderByDesc('verified_at')->orderByDesc('id')->get()->values();

        $protected = $this->protectedIds($website);
        $cutoff = $decision->retentionDays ? now()->subDays($decision->retentionDays) : null;
        $expired = [];

        foreach ($verified as $position => $backup) {
            $overCount = $decision->retentionCount !== null && $position >= $decision->retentionCount;
            $tooOld = $cutoff !== null && $backup->verified_at->lt($cutoff);

            if ($position === 0 || in_array($backup->id, $protected, true) || (! $overCount && ! $tooOld)) {
                continue;
            }

            try {
                $this->deletion->remove($backup, BackupStatus::Expired, 'retention');
                $expired[] = $backup->uuid;
            } catch (\Throwable $e) {
                Log::warning('Backup retention could not expire a backup.', ['backup' => $backup->uuid, 'error' => $e->getMessage()]);
            }
        }

        return $expired;
    }

    /** @return list<int> */
    private function protectedIds(Website $website): array
    {
        $restores = BackupRestore::where('website_id', $website->id)->whereIn('status', ['awaiting_safety_backup', 'queued', 'restoring'])->get();
        $updates = $website->jobs()->whereNotNull('backup_id')->whereIn('status', [JobStatus::Waiting->value, JobStatus::Queued->value, JobStatus::Delivered->value])->pluck('backup_id');

        return $restores->pluck('backup_id')->merge($restores->pluck('safety_backup_id'))->merge($updates)->filter()->map(fn ($id) => (int) $id)->values()->all();
    }
}
