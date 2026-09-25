<?php

namespace App\Console\Commands\Backups;

use App\Backups\Retention;
use App\Models\Website;
use Illuminate\Console\Command;

class ApplyRetention extends Command
{
    protected $signature = 'backup:retention';

    protected $description = 'Expire backups beyond each plan\'s retention (run daily)';

    public function handle(Retention $retention): int
    {
        $count = 0;
        Website::whereHas('backups')->chunkById(100, function ($websites) use ($retention, &$count) {
            foreach ($websites as $website) {
                $count += count($retention->apply($website));
            }
        });

        $this->components->info("Expired {$count} backup(s).");

        return self::SUCCESS;
    }
}
