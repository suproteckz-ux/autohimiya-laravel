<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\CatalogEnrichmentTask;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CatalogBrandSuggestionsGenerateCommand extends Command
{
    protected $signature = 'catalog:brand-suggestions:generate';

    protected $description = 'Generate draft brand enrichment tasks from Laravel brand dictionary.';

    public function handle(): int
    {
        $brands = Brand::query()->orderByDesc('name')->get();
        $created = 0;
        $updated = 0;
        $ambiguous = 0;

        Product::query()
            ->whereNull('brand_id')
            ->orderBy('id')
            ->chunkById(100, function ($products) use ($brands, &$created, &$updated, &$ambiguous): void {
                foreach ($products as $product) {
                    $matches = $this->brandMatches($product->display_name, $brands);

                    if ($matches === []) {
                        continue;
                    }

                    if (count($matches) > 1) {
                        $ambiguous++;
                    }

                    $best = $matches[0];
                    $task = CatalogEnrichmentTask::query()->updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'task_type' => 'brand',
                            'source' => 'rule',
                            'status' => 'draft',
                        ],
                        [
                            'priority' => count($matches) > 1 ? 40 : 70,
                            'current_value' => null,
                            'suggested_value' => $best['brand']->display_name,
                            'confidence' => $best['confidence'],
                            'reason' => count($matches) > 1 ? 'Ambiguous brand name match in product title.' : 'Brand name found in product title.',
                            'payload_json' => [
                                'brand_id' => $best['brand']->id,
                                'brand_name' => $best['brand']->display_name,
                                'matches' => array_map(fn (array $match): array => [
                                    'brand_id' => $match['brand']->id,
                                    'brand_name' => $match['brand']->display_name,
                                    'confidence' => $match['confidence'],
                                ], $matches),
                            ],
                        ],
                    );

                    $task->wasRecentlyCreated ? $created++ : $updated++;
                }
            });

        $this->table(['Metric', 'Count'], [
            ['created', $created],
            ['updated', $updated],
            ['ambiguous', $ambiguous],
        ]);

        return self::SUCCESS;
    }

    private function brandMatches(string $productName, $brands): array
    {
        $normalizedProductName = Str::lower($productName);
        $matches = [];

        foreach ($brands as $brand) {
            $brandName = $brand->display_name;
            $normalizedBrand = Str::lower($brandName);

            if ($normalizedBrand === '' || mb_strlen($normalizedBrand) < 3) {
                continue;
            }

            if (str_contains($normalizedProductName, $normalizedBrand)) {
                $matches[] = ['brand' => $brand, 'confidence' => 90];
                continue;
            }

            similar_text($normalizedProductName, $normalizedBrand, $percent);

            if ($percent >= 55) {
                $matches[] = ['brand' => $brand, 'confidence' => 60];
            }
        }

        usort($matches, fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        return array_slice($matches, 0, 5);
    }
}
