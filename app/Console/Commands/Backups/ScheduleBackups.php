<?php

namespace App\Console\Commands\Backups;

use App\Backups\BackupPolicy;
use App\Backups\BackupReason;
use App\Backups\BackupRefused;
use App\Backups\BackupService;
use App\Connector\Presence;
use App\Models\Website;
use Illuminate\Console\Command;

/**
 * Queues the scheduled backups that are due. One scheduled backup per
 * website and window (day, ISO week or month, UTC): the unique
 * (website, schedule_window) index makes a second one impossible.
 */
class ScheduleBackups extends Command
{
    protected $signature = 'backup:schedule';

    protected $description = 'Queue scheduled backups that are due (run hourly)';

    public function handle(BackupPolicy $policy, BackupService $backups): int
    {
        $queued = 0;

        Website::query()->whereHas('activeConnector')->with(['subscription', 'customer', 'activeConnector'])->chunkById(100, function ($websites) use ($policy, $backups, &$queued) {
            foreach ($websites as $website) {
                $decision = $policy->for($website);
                $window = $decision->frequency ? BackupPolicy::window($decision->frequency, now()) : null;

                if (! $decision->eligible || ! $window || $website->presence() !== Presence::Connected
                    || $website->backups()->where('schedule_window', $window)->exists()) {
                    continue;
                }

                try {
                    $backups->request($website, BackupReason::Scheduled, 'scheduler', null, ['schedule_window' => $window]);
                    $queued++;
                } catch (BackupRefused $e) {
                    $this->components->warn("{$website->uuid}: {$e->errorCode}");
                }
            }
        });

        $this->components->info("Queued {$queued} scheduled backup(s).");

        return self::SUCCESS;
    }
}
