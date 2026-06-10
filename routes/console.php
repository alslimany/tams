<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('flight:cache-schedules --days=30')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();

// Re-sync 3T hotel reference data (cities, hotels, board types) every Sunday at 03:00.
// Requires a tenant with an active 3T provider; edit --tenant= to match your setup.
// Run manually first: php artisan providers:sync-locations --tenant=<your-tenant-id>
Schedule::command('providers:sync-locations 3t')
    ->weekly()
    ->sundays()
    ->at('03:00')
    ->withoutOverlapping()
    ->onOneServer();
