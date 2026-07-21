<?php

namespace App\Filament\Widgets;

use App\Models\KaspiEnrichmentTask;
use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class KaspiContentStatsOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Kaspi Content Center';

    protected function getStats(): array
    {
        $urlQuery = fn (Builder $query) => $query
            ->where(fn (Builder $inner) => $inner->whereNotNull('kaspi_product_url')->where('kaspi_product_url', '<>', ''))
            ->orWhereHas('kaspiEnrichmentTasks', fn (Builder $task) => $task->whereNotNull('kaspi_product_url')->where('kaspi_product_url', '<>', ''));

        // Latest import status per product (kaspi_imported or kaspi_partial)
        $importedCount = Product::query()
            ->whereHas('kaspiEnrichmentTasks', fn (Builder $q) => $q->where('status', 'kaspi_imported'))
            ->count();

        $partialCount = Product::query()
            ->whereHas('kaspiEnrichmentTasks', fn (Builder $q) => $q->where('status', 'kaspi_partial'))
            ->whereDoesntHave('kaspiEnrichmentTasks', fn (Builder $q) => $q->where('status', 'kaspi_imported'))
            ->count();

        // Products with Kaspi-sourced photos on site
        $kaspiPhotosOnSite = Product::query()
            ->whereHas('images', fn (Builder $q) => $q->where('source', 'kaspi'))
            ->count();

        return [
            Stat::make('Всего товаров', Product::query()->count())->color('primary'),
            Stat::make('Kaspi URL', Product::query()->where($urlQuery)->count())->color('success'),
            Stat::make('Фото Kaspi на сайте', $kaspiPhotosOnSite)->color('success'),
            Stat::make('Импортировано', $importedCount)->color('success'),
            Stat::make('Частично', $partialCount)->color('warning'),
            Stat::make('URL нужен', KaspiEnrichmentTask::query()->where('status', 'needs_manual_url')->count())->color('warning'),
            Stat::make('Ошибки', KaspiEnrichmentTask::query()->whereIn('status', ['failed', 'error', 'kaspi_blocked', 'widget_not_found', 'widget_timeout', 'kaspi_js_not_loaded', 'kaspi_button_not_found', 'kaspi_url_not_opened', 'invalid_kaspi_url'])->count())->color('danger'),
        ];
    }
}
