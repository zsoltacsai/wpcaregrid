<?php

use App\Owner\SystemStatus;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// One scheduler process (cron: schedule:run every minute, docs/DEPLOYMENT.md). Every task
// is withoutOverlapping() and onOneServer() (cache lock), so a slow run or a second app
// server never starts the same task twice.

// Websites whose connector stopped reporting in.
Schedule::command('connector:update-presence')->everyTenMinutes()->withoutOverlapping()->onOneServer();

// Customer backups: queue due runs and time out stuck work. Platform database
// backups use their own persisted Owner schedule and never run retention deletes.
Schedule::command('backup:schedule')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('backup:monitor')->everyTenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('backup:retention')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
// Owner-managed cadence is evaluated from the database-backed settings on each
// invocation. A unique UTC slot row bounds catch-up and prevents duplicate dispatch.
Schedule::command('platform:database-backup-schedule')->everyTenMinutes()->timezone('UTC')->withoutOverlapping()->onOneServer();

// Admin access: unused tokens and ended sessions expire server-side.
Schedule::command('admin-access:expire')->everyMinute()->withoutOverlapping()->onOneServer();

// Security: time out stuck scans; scheduled scans (off unless SECURITY_SCHEDULE_ENABLED);
// vulnerability feed refresh (only when a source and feed URL are configured).
Schedule::command('security:monitor')->everyTenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('security:schedule')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('security:vulnerabilities:sync')->dailyAt('04:10')->withoutOverlapping()->onOneServer()
    ->when(fn () => config('security.vulnerabilities.source') === 'wordfence' && config('security.vulnerabilities.feed_url'));

// Monitoring is a single bounded dispatcher; probe jobs are site-unique and queued.
Schedule::command('monitoring:run')->everyFiveMinutes()->timezone('UTC')->withoutOverlapping()->onOneServer();
Schedule::command('monitoring:deliver-alerts')->everyMinute()->timezone('UTC')->withoutOverlapping()->onOneServer();

// Owner system page: proof that the scheduler runs (a timestamp, nothing else).
Schedule::call(fn () => Cache::forever(SystemStatus::SCHEDULER_HEARTBEAT, now()->toIso8601String()))
    ->everyMinute()->name('system:scheduler-heartbeat')->onOneServer();

// Internal plans (PROBONO) and recovery offers follow their dates.
Schedule::command('internal-plans:sync')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('offers:expire')->hourly()->withoutOverlapping()->onOneServer();
