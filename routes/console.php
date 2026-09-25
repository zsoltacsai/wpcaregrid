<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Websites whose connector stopped reporting in.
Schedule::command('connector:update-presence')->everyTenMinutes();

// Backups: queue due scheduled backups, time out stuck ones, apply retention.
Schedule::command('backup:schedule')->hourly()->withoutOverlapping();
Schedule::command('backup:monitor')->everyTenMinutes()->withoutOverlapping();
Schedule::command('backup:retention')->dailyAt('03:30')->withoutOverlapping();

// Admin access: unused tokens and ended sessions expire server-side.
Schedule::command('admin-access:expire')->everyMinute()->withoutOverlapping();
