<?php

namespace App\Console\Commands;

use App\Models\KaspiEnrichmentTask;
use App\Models\Product;
use App\Models\SyncLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class AutomationHealthCommand extends Command
{
    protected $signature = 'automation:health';

    protected $description = 'Show automation scheduler and catalog health status.';

    public function handle(): int
    {
        $scheduleOutput = Artisan::call('schedule:list') === 0 ? Artisan::output() : '';
        $schedulerRegistered = str_contains($scheduleOutput, 'paloma:sync-remains')
            && str_contains($scheduleOutput, 'kaspi:resolve-widget-urls')
            && str_contains($scheduleOutput, 'kaspi:import-content')
            && str_contains($scheduleOutput, 'catalog:quality-report');

        $lastPaloma = SyncLog::query()->where('source', 'paloma')->latest('started_at')->first();
        $lastKaspiResolve = KaspiEnrichmentTask::query()->where('source', 'widget_browser')->latest('updated_at')->first();
        $lastKaspiImport = KaspiEnrichmentTask::query()->whereIn('status', ['kaspi_imported', 'kaspi_partial', 'published'])->latest('updated_at')->first();
        $recentErrors = SyncLog::query()->where('started_at', '>=', now()->subDay())->sum('error_count')
            + KaspiEnrichmentTask::query()->where('updated_at', '>=', now()->subDay())->whereIn('status', ['failed', 'error'])->count();

        $rows = [
            ['Scheduler registered', $schedulerRegistered ? 'yes' : 'no'],
            ['Last Paloma sync', $lastPaloma?->started_at?->format('Y-m-d H:i:s') ?: 'never'],
            ['Last Kaspi URL resolve', $lastKaspiResolve?->updated_at?->format('Y-m-d H:i:s') ?: 'never'],
            ['Last Kaspi import', $lastKaspiImport?->updated_at?->format('Y-m-d H:i:s') ?: 'never'],
            ['Errors during last 24 hours', $recentErrors],
            ['Products without category', Product::query()->whereNull('category_id')->count()],
            ['Products without photos', Product::query()->whereNull('primary_image')->whereDoesntHave('images')->count()],
            ['Products without description', Product::query()->where(fn ($query) => $query->whereNull('description')->orWhere('description', ''))->count()],
            ['Products without specifications', Product::query()->whereDoesntHave('attributes')->count()],
            ['Products without price', Product::query()->where(fn ($query) => $query->whereNull('price')->orWhere('price', '<=', 0))->count()],
            ['Products without SKU', Product::query()->where(fn ($query) => $query->whereNull('sku')->orWhere('sku', ''))->count()],
        ];

        $this->table(['Metric', 'Value'], $rows);

        return $schedulerRegistered ? self::SUCCESS : self::FAILURE;
    }
}
