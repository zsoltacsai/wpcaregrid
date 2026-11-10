<?php

namespace App\Console\Commands\Platform;

use App\Platform\Updates\PlatformUpdateNotifier;
use App\Platform\Updates\PlatformUpdateScanner;
use Illuminate\Console\Command;

/** Runs one read-only platform update check. It can never update or install anything. */
class CheckPlatformUpdates extends Command
{
    protected $signature = 'platform:updates:check {--manual : Record the check as started by a person}';

    protected $description = 'Check installed platform components against published advisories (report only)';

    public function handle(PlatformUpdateScanner $scanner, PlatformUpdateNotifier $notifier): int
    {
        if (! config('platform_updates.enabled')) {
            $this->line('PLATFORM_UPDATE_CHECK=REFUSED;CODE=disabled');

            return self::FAILURE;
        }
        $check = $scanner->run($this->option('manual') ? 'manual' : 'scheduled');
        $notifier->afterCheck($check);
        $this->line(sprintf(
            'PLATFORM_UPDATE_CHECK=%s;CODE=%s;COMPONENTS=%d;UNKNOWN=%d;OPEN_ADVISORIES=%d;CRITICAL=%d',
            strtoupper($check->status), $check->reason_code ?? 'none', $check->components_total ?? 0,
            $check->components_unknown ?? 0, $check->advisories_open ?? 0, $check->advisories_critical ?? 0,
        ));

        return in_array($check->status, ['succeeded', 'partial'], true) ? self::SUCCESS : self::FAILURE;
    }
}
