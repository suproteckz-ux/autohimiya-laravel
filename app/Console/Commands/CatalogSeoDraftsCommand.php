<?php

namespace App\Console\Commands;

use App\Models\CatalogEnrichmentTask;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;

class CatalogSeoDraftsCommand extends Command
{
    protected $signature = 'catalog:seo-drafts';

    protected $description = 'Create draft SEO enrichment tasks without changing SEO fields.';

    public function handle(): int
    {
        $created = 0;
        $updated = 0;
        $productsChecked = 0;

        Product::query()
            ->with(['brand', 'category'])
            ->where(function (Builder $query): void {
                $query->whereNull('meta_title')->orWhere('meta_title', '')
                    ->orWhereNull('meta_description')->orWhere('meta_description', '')
                    ->orWhereNull('h1')->orWhere('h1', '')
                    ->orWhereNull('description')->orWhere('description', '');
            })
            ->orderBy('id')
            ->chunkById(100, function ($products) use (&$created, &$updated, &$productsChecked): void {
                foreach ($products as $product) {
                    $productsChecked++;
                    $context = [
                        'product_name' => $product->display_name,
                        'category' => $product->category?->display_name,
                        'brand' => $product->brand?->display_name,
                        'slug' => $product->slug,
                        'current_meta_title' => $product->meta_title,
                        'current_meta_description' => $product->meta_description,
                        'current_h1' => $product->h1,
                    ];

                    if (blank($product->meta_title) || blank($product->h1)) {
                        $task = CatalogEnrichmentTask::query()->updateOrCreate(
                            [
                                'product_id' => $product->id,
                                'task_type' => 'seo_title',
                                'source' => 'manual',
                                'status' => 'draft',
                            ],
                            [
                                'priority' => 70,
                                'current_value' => trim(($product->meta_title ?: '').' | '.($product->h1 ?: ''), ' |'),
                                'suggested_value' => null,
                                'confidence' => 0,
                                'reason' => 'Meta title or H1 is missing. Prepare manual SEO draft.',
                                'payload_json' => $context,
                            ],
                        );
                        $task->wasRecentlyCreated ? $created++ : $updated++;
                    }

                    if (blank($product->meta_description) || blank($product->description)) {
                        $task = CatalogEnrichmentTask::query()->updateOrCreate(
                            [
                                'product_id' => $product->id,
                                'task_type' => 'seo_description',
                                'source' => 'manual',
                                'status' => 'draft',
                            ],
                            [
                                'priority' => 70,
                                'current_value' => $product->meta_description,
                                'suggested_value' => null,
                                'confidence' => 0,
                                'reason' => 'Meta description or SEO/product description is missing. Prepare manual SEO draft.',
                                'payload_json' => $context,
                            ],
                        );
                        $task->wasRecentlyCreated ? $created++ : $updated++;
                    }
                }
            });

        $this->writeReport($productsChecked, $created, $updated);
        $this->table(['Metric', 'Count'], [['products checked', $productsChecked], ['created', $created], ['updated', $updated]]);

        return self::SUCCESS;
    }

    private function writeReport(int $productsChecked, int $created, int $updated): void
    {
        $lines = [
            '# SEO_DRAFT_QUEUE_REPORT',
            '',
            'Дата проверки: '.now()->toDateString(),
            '',
            '| Метрика | Количество |',
            '| --- | ---: |',
            '| Products checked | '.$productsChecked.' |',
            '| Draft tasks created | '.$created.' |',
            '| Draft tasks updated | '.$updated.' |',
            '',
            'SEO-поля товаров не менялись. Все задачи созданы в статусе draft.',
        ];

        File::put(dirname(base_path()).DIRECTORY_SEPARATOR.'SEO_DRAFT_QUEUE_REPORT.md', implode(PHP_EOL, $lines).PHP_EOL);
    }
}
