<?php

namespace App\Console\Commands;

use App\Models\CatalogEnrichmentTask;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;

class CatalogDescriptionDraftsCommand extends Command
{
    protected $signature = 'catalog:description-drafts';

    protected $description = 'Create draft description enrichment tasks without generating or publishing content.';

    public function handle(): int
    {
        $created = 0;
        $updated = 0;

        Product::query()
            ->with(['brand', 'category'])
            ->where(fn (Builder $query) => $query->whereNull('description')->orWhere('description', ''))
            ->orderBy('id')
            ->chunkById(100, function ($products) use (&$created, &$updated): void {
                foreach ($products as $product) {
                    $context = [
                        'product_name' => $product->display_name,
                        'category' => $product->category?->display_name,
                        'brand' => $product->brand?->display_name,
                        'price' => $product->price,
                        'sku' => $product->paloma_sku ?: $product->sku,
                        'availability' => $product->availability,
                        'quantity' => $product->quantity,
                        'technical_hints' => $this->technicalHints($product->display_name),
                    ];

                    $task = CatalogEnrichmentTask::query()->updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'task_type' => 'description',
                            'source' => 'manual',
                            'status' => 'draft',
                        ],
                        [
                            'priority' => $product->isAvailableForStorefront() ? 80 : 50,
                            'current_value' => null,
                            'suggested_value' => null,
                            'confidence' => 0,
                            'reason' => 'Product description is missing. Prepare manual or future AI draft from payload context.',
                            'payload_json' => $context,
                        ],
                    );

                    $task->wasRecentlyCreated ? $created++ : $updated++;
                }
            });

        $total = Product::query()->where(fn (Builder $query) => $query->whereNull('description')->orWhere('description', ''))->count();
        $this->writeReport($total, $created, $updated);
        $this->table(['Metric', 'Count'], [['products without description', $total], ['created', $created], ['updated', $updated]]);

        return self::SUCCESS;
    }

    private function technicalHints(string $name): array
    {
        return array_values(array_filter(preg_split('/[\s,.;()\/-]+/u', $name) ?: [], fn (string $word): bool => mb_strlen($word) > 3));
    }

    private function writeReport(int $total, int $created, int $updated): void
    {
        $lines = [
            '# DESCRIPTION_DRAFT_QUEUE_REPORT',
            '',
            'Дата проверки: '.now()->toDateString(),
            '',
            '| Метрика | Количество |',
            '| --- | ---: |',
            '| Products without description | '.$total.' |',
            '| Draft tasks created | '.$created.' |',
            '| Draft tasks updated | '.$updated.' |',
            '',
            'AI API не вызывался. Описания товаров не менялись.',
        ];

        File::put(dirname(base_path()).DIRECTORY_SEPARATOR.'DESCRIPTION_DRAFT_QUEUE_REPORT.md', implode(PHP_EOL, $lines).PHP_EOL);
    }
}
