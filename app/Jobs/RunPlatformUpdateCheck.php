<?php

namespace App\Jobs;

use App\Platform\Updates\PlatformUpdateScanner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A manual "Check for updates" request: one read-only check, never more than one queued at a time. */
class RunPlatformUpdateCheck implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function handle(PlatformUpdateScanner $scanner): void
    {
        if (config('platform_updates.enabled') === true) {
            $scanner->run('manual');
        }
    }
}
