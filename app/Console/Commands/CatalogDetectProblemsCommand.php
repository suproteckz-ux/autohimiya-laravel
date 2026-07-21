<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Catalog\ProductProblemDetector;
use Illuminate\Console\Command;

class CatalogDetectProblemsCommand extends Command
{
    protected $signature = 'catalog:detect-problems';

    protected $description = 'Show catalog content problem statistics.';

    public function handle(ProductProblemDetector $detector): int
    {
        $stats = [
            'total' => 0,
            ProductProblemDetector::MISSING_IMAGE => 0,
            ProductProblemDetector::MISSING_DESCRIPTION => 0,
            ProductProblemDetector::MISSING_SEO => 0,
            ProductProblemDetector::MISSING_BRAND => 0,
            ProductProblemDetector::MISSING_CATEGORY => 0,
            'score_sum' => 0,
        ];

        Product::query()
            ->with(['brand', 'category', 'primaryImage'])
            ->withCount('images')
            ->orderBy('id')
            ->chunkById(200, function ($products) use (&$stats, $detector): void {
                foreach ($products as $product) {
                    $stats['total']++;
                    $stats['score_sum'] += $detector->getScore($product);

                    foreach ($detector->detect($product) as $problem) {
                        $stats[$problem]++;
                    }
                }
            });

        $average = $stats['total'] > 0 ? (int) round($stats['score_sum'] / $stats['total']) : 0;

        $this->table(['Metric', 'Value'], [
            ['Total products', $stats['total']],
            ['Without photo', $stats[ProductProblemDetector::MISSING_IMAGE]],
            ['Without description', $stats[ProductProblemDetector::MISSING_DESCRIPTION]],
            ['Without SEO', $stats[ProductProblemDetector::MISSING_SEO]],
            ['Without brand', $stats[ProductProblemDetector::MISSING_BRAND]],
            ['Without category', $stats[ProductProblemDetector::MISSING_CATEGORY]],
            ['Average Content Score', $average.'%'],
        ]);

        return self::SUCCESS;
    }
}
