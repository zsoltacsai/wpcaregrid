<?php

namespace App\Console\Commands;

use App\Billing\InternalPlans;
use Illuminate\Console\Command;

/** Internal plans (PROBONO) start and end on their dates, whether or not anyone visits the site. */
class SyncInternalPlans extends Command
{
    protected $signature = 'internal-plans:sync';

    protected $description = 'Start internal plans whose start date has come and end those past their end date (run hourly)';

    public function handle(InternalPlans $plans): int
    {
        $result = $plans->sync();
        $this->components->info("Started {$result['started']}, ended {$result['ended']} internal plan(s).");

        return self::SUCCESS;
    }
}
