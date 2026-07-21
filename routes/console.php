<?php

use App\Enums\AutomationType;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('automation:scheduler-heartbeat')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('automation:run-pending --limit=1')
    ->everyMinute()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/automation-run-pending.log'));

Schedule::command('automation:scheduler-heartbeat --queue')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('queue:work --stop-when-empty --tries=3 --timeout=120')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/automation-queue-work.log'));

Schedule::command('automation:queue --type='.AutomationType::PalomaSyncRemains->value.' --source=scheduler')
    ->everyFifteenMinutes()
    ->between('09:00', '20:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/automation-paloma-sync.log'));

Schedule::command('automation:queue --type='.AutomationType::KaspiResolveWidgetUrls->value.' --source=scheduler')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/automation-kaspi-resolve.log'));

Schedule::command('automation:queue --type='.AutomationType::KaspiImportContent->value.' --source=scheduler')
    ->dailyAt('01:40')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/automation-kaspi-import.log'));

Schedule::command('automation:queue --type='.AutomationType::CatalogQualityReport->value.' --source=scheduler')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/automation-catalog-quality.log'));

Schedule::command('automation:queue --type='.AutomationType::AutomationHealth->value.' --source=scheduler')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/automation-health.log'));