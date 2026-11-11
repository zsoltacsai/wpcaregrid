<?php

namespace App\Console\Commands\Platform;

use App\Platform\Updates\PlatformUpdateNotifier;
use Illuminate\Console\Command;

/** The daily platform update summary for the Owner (only when there is something to report). */
class SendPlatformUpdateDigest extends Command
{
    protected $signature = 'platform:updates:digest';

    protected $description = 'Send the daily platform update summary to the Owner';

    public function handle(PlatformUpdateNotifier $notifier): int
    {
        $this->line('PLATFORM_UPDATE_DIGEST='.($notifier->digest() ? 'SENT' : 'SKIPPED'));

        return self::SUCCESS;
    }
}
