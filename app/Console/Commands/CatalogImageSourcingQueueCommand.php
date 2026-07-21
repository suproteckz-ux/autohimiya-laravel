<?php

namespace App\Console\Commands;

use App\Models\CatalogEnrichmentTask;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CatalogImageSourcingQueueCommand extends Command
{
    protected $signature = 'catalog:image-sourcing-queue';

    protected $description = 'Create draft image sourcing tasks without downloading images.';

    public function handle(): int
    {
        $created = 0;
        $updated = 0;

        Product::query()
            ->with(['brand', 'category'])
            ->whereNull('primary_image')
            ->whereDoesntHave('images')
            ->orderBy('id')
            ->chunkById(100, function ($products) use (&$created, &$updated): void {
                foreach ($products as $product) {
                    $brand = $product->brand?->display_name;
                    $category = $product->category?->display_name;
                    $query = trim(implode(' ', array_filter([$brand, $product->display_name, $category])));
                    $priority = $product->isAvailableForStorefront() ? 90 : 55;

                    $task = CatalogEnrichmentTask::query()->updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'task_type' => 'image',
                            'source' => 'manual',
                            'status' => 'draft',
                        ],
                        [
                            'priority' => $priority,
                            'current_value' => $product->primary_image,
                            'suggested_value' => null,
                            'confidence' => 0,
                            'reason' => 'Product has no image. Source manually; no scraping or automatic download performed.',
                            'payload_json' => [
                                'product_name' => $product->display_name,
                                'sku' => $product->paloma_sku ?: $product->sku,
                                'brand' => $brand,
                                'category' => $category,
                                'possible_search_query' => $query,
                                'current_placeholder_path' => null,
                                'priority' => $priority,
                            ],
                        ],
                    );

                    $task->wasRecentlyCreated ? $created++ : $updated++;
                }
            });

        $total = Product::query()->whereNull('primary_image')->whereDoesntHave('images')->count();
        $this->writeReport($total, $created, $updated);
        $this->table(['Metric', 'Count'], [['products without image', $total], ['created', $created], ['updated', $updated]]);

        return self::SUCCESS;
    }

    private function writeReport(int $total, int $created, int $updated): void
    {
        $lines = [
            '# IMAGE_SOURCING_QUEUE_REPORT',
            '',
            'Дата проверки: '.now()->toDateString(),
            '',
            '| Метрика | Количество |',
            '| --- | ---: |',
            '| Products without image | '.$total.' |',
            '| Draft tasks created | '.$created.' |',
            '| Draft tasks updated | '.$updated.' |',
            '',
            'Изображения не скачивались. Внешние сайты не использовались.',
        ];

        File::put(dirname(base_path()).DIRECTORY_SEPARATOR.'IMAGE_SOURCING_QUEUE_REPORT.md', implode(PHP_EOL, $lines).PHP_EOL);
    }
}
