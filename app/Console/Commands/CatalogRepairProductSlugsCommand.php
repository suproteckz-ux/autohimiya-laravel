<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\ProductSlugger;
use Illuminate\Console\Command;

class CatalogRepairProductSlugsCommand extends Command
{
    protected $signature = 'catalog:repair-product-slugs
        {--dry-run : Show changes without writing}
        {--limit= : Limit scanned products}
        {--product-id= : Repair only one product}
        {--force : Apply slug repairs}';

    protected $description = 'Repair missing or SKU-style product slugs using product names.';

    public function handle(): int
    {
        $apply = (bool) $this->option('force');
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $productId = $this->option('product-id') !== null ? (int) $this->option('product-id') : null;

        if (! $apply) {
            $this->warn('Dry-run mode. Pass --force to update product slugs.');
        }

        $query = Product::query()
            ->orderBy('id');

        if ($productId) {
            $query->whereKey($productId);
        }

        if ($limit) {
            $query->limit($limit);
        }

        $rows = [];
        $checked = 0;
        $changed = 0;

        foreach ($query->get() as $product) {
            $checked++;

            if (! ProductSlugger::isBad($product->slug, $product)) {
                continue;
            }

            $oldSlug = (string) $product->slug;
            $newSlug = ProductSlugger::uniqueFromName((string) $product->name, (string) ($product->model ?: $product->sku), $product->id);

            if ($newSlug === $oldSlug) {
                continue;
            }

            $rows[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => mb_substr($product->display_name, 0, 70),
                'old_slug' => $oldSlug ?: '(empty)',
                'new_slug' => $newSlug,
            ];

            if ($apply) {
                $product->update(['slug' => $newSlug]);
                $changed++;
            }
        }

        $this->table(['product_id', 'sku', 'name', 'old_slug', 'new_slug'], array_map(fn (array $row): array => array_values($row), $rows));
        $this->table(['Metric', 'Value'], [
            ['checked', $checked],
            ['candidates', count($rows)],
            [$apply ? 'updated' : 'would_update', $apply ? $changed : count($rows)],
        ]);

        return self::SUCCESS;
    }
}
