<?php

namespace App\Console\Commands\Backups;

use App\Backups\Retention;
use App\Models\Website;
use Illuminate\Console\Command;

class ApplyRetention extends Command
{
    protected $signature = 'backup:retention';

    protected $description = 'Report legacy backup retention candidates without deleting backups (run daily)';

    public function handle(Retention $retention): int
    {
        $count = 0;
        Website::whereHas('backups')->chunkById(100, function ($websites) use ($retention, &$count) {
            foreach ($websites as $website) {
                $count += count($retention->preview($website));
            }
        });

        $this->components->info("Reported {$count} legacy retention candidate(s); no backups were deleted.");

        return self::SUCCESS;
    }
}
