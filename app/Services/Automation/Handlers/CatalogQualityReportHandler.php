<?php

namespace App\Services\Automation\Handlers;

use App\Models\AutomationRun;
use App\Models\Product;
use App\Services\Automation\AutomationHandlerInterface;
use App\Services\Automation\AutomationProgressReporterInterface;

class CatalogQualityReportHandler implements AutomationHandlerInterface
{
    public function handle(AutomationRun $run, AutomationProgressReporterInterface $progress, bool $dryRun = false): array
    {
        $metrics = ['products_without_category' => Product::query()->whereNull('category_id')->count(), 'products_without_photo' => Product::query()->whereNull('primary_image')->whereDoesntHave('images')->count(), 'products_without_description' => Product::query()->where(fn ($query) => $query->whereNull('description')->orWhere('description', ''))->count(), 'products_without_specifications' => Product::query()->whereDoesntHave('attributes')->count()];
        $progress->start(count($metrics), 'Отчет качества каталога.');
        $progress->setProgress(count($metrics), count($metrics), 'Отчет качества каталога готов.');
        return ['successful' => true, 'message' => 'Catalog quality report complete.', 'total_items' => count($metrics), 'processed_items' => count($metrics), 'skipped_count' => array_sum($metrics), 'metrics' => $metrics];
    }
}