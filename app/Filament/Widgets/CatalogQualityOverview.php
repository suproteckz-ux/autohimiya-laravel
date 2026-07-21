<?php

namespace App\Filament\Widgets;

use App\Models\CatalogEnrichmentTask;
use App\Models\Product;
use App\Models\SyncLog;
use App\Filament\Resources\CatalogEnrichmentTaskResource;
use App\Filament\Resources\ProductResource;
use App\Support\ProductStatus;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class CatalogQualityOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Панель качества каталога';

    protected function getStats(): array
    {
        $lastPalomaSync = SyncLog::query()
            ->where('source', 'paloma')
            ->latest('started_at')
            ->value('started_at');

        $lastQaRun = file_exists(dirname(base_path()).DIRECTORY_SEPARATOR.'CATALOG_QUALITY_REPORT.md')
            ? date('Y-m-d H:i', filemtime(dirname(base_path()).DIRECTORY_SEPARATOR.'CATALOG_QUALITY_REPORT.md'))
            : 'none';

        return [
            Stat::make('Всего товаров', Product::query()->count())->color('primary')->url(ProductResource::getUrl('index')),
            Stat::make('В наличии', Product::query()->where('availability', true)->where('quantity', '>', 0)->count())->color('success')->url(ProductResource::getUrl('index', ['activeTab' => 'in_stock'])),
            Stat::make('Без фото', Product::query()->whereNull('primary_image')->whereDoesntHave('images')->count())->color('warning')->url(ProductResource::getUrl('index', ['activeTab' => 'needs_image'])),
            Stat::make('Без описания', Product::query()->where(fn (Builder $query) => $query->whereNull('description')->orWhere('description', ''))->count())->color('warning')->url(ProductResource::getUrl('index', ['activeTab' => 'needs_description'])),
            Stat::make('Без SEO', Product::query()->where(fn (Builder $query) => $query->whereNull('meta_title')->orWhere('meta_title', '')->orWhereNull('meta_description')->orWhere('meta_description', ''))->count())->color('warning')->url(ProductResource::getUrl('index', ['activeTab' => 'needs_seo'])),
            Stat::make('Без бренда', Product::query()->whereNull('brand_id')->count())->color('warning')->url(ProductResource::getUrl('index', ['activeTab' => 'needs_brand'])),
            Stat::make('Needs review', Product::query()->where('product_status', ProductStatus::NEEDS_REVIEW)->count())->color('danger')->url(ProductResource::getUrl('index', ['activeTab' => 'needs_review'])),
            Stat::make('Draft enrichment tasks', CatalogEnrichmentTask::query()->where('status', 'draft')->count())->color('info')->url(CatalogEnrichmentTaskResource::getUrl('index')),
            Stat::make('Last Paloma sync', $lastPalomaSync ? $lastPalomaSync->format('Y-m-d H:i') : 'none')->color('gray'),
            Stat::make('Last QA run', $lastQaRun)->color('gray'),
        ];
    }
}
