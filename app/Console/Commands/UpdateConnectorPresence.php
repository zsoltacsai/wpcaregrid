<?php

namespace App\Console\Commands;

use App\Connector\Presence;
use App\Models\Website;
use App\Websites\WebsiteStatus;
use Illuminate\Console\Command;

/** Marks websites whose connector has been silent too long as disconnected. */
class UpdateConnectorPresence extends Command
{
    protected $signature = 'connector:update-presence';

    protected $description = 'Mark websites with silent connectors as disconnected';

    public function handle(): int
    {
        $count = 0;

        Website::whereHas('activeConnector')->with('activeConnector')->where('status', '!=', WebsiteStatus::Disconnected->value)->each(function (Website $website) use (&$count) {
            if ($website->presence() === Presence::Disconnected) {
                $website->update(['status' => WebsiteStatus::Disconnected]);
                $count++;
            }
        });

        $this->info("{$count} website(s) marked disconnected.");

        return self::SUCCESS;
    }
}
