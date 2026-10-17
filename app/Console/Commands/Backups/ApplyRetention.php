<?php

namespace App\Console\Commands\Backups;

use App\Backups\BackupGfsShadowStore;
use App\Backups\Retention;
use App\Models\Website;
use Illuminate\Console\Command;

class ApplyRetention extends Command
{
    protected $signature = 'backup:retention';

    protected $description = 'Report legacy backup retention candidates without deleting backups (run daily)';

    public function handle(Retention $retention, BackupGfsShadowStore $gfs): int
    {
        $legacyCandidates = 0;
        $recoveryGaps = 0;
        $blockedCandidates = 0;
        Website::whereHas('backups')->chunkById(100, function ($websites) use ($retention, $gfs, &$legacyCandidates, &$recoveryGaps, &$blockedCandidates) {
            foreach ($websites as $website) {
                $legacyCandidates += count($retention->preview($website));
                $evaluation = $gfs->evaluate($website);
                $recoveryGaps += $evaluation->missing_slot_count;
                $blockedCandidates += count($evaluation->result['blocked_candidates'] ?? []);
            }
        });

        $this->components->info("Shadow report: {$legacyCandidates} legacy comparison candidate(s), {$recoveryGaps} GFS recovery gap(s), {$blockedCandidates} blocked GFS candidate(s); no backups were deleted.");

        return self::SUCCESS;
    }
}
