<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('paloma:sync-remains --timeout=60')
    ->everyFifteenMinutes()
    ->between('09:00', '20:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/automation-paloma-sync.log'));

Schedule::command('kaspi:resolve-widget-urls --limit=500 --headless --delay-ms=3000 --only-missing-url=true')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/automation-kaspi-resolve.log'));

Schedule::command('kaspi:discover-products --limit=200')
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/automation-kaspi-discover.log'));

Schedule::command('kaspi:import-content --limit=0 --only-missing=true --force=false --delay-ms=3000')
    ->dailyAt('01:40')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/automation-kaspi-import.log'));

Schedule::command('catalog:quality-report')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/automation-catalog-quality.log'));
