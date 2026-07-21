<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\Utf8Sanitizer;
use Illuminate\Console\Command;

class CatalogCleanBrokenTextCommand extends Command
{
    protected $signature = 'catalog:clean-broken-text
        {--force : Apply cleaned values}
        {--limit=50 : Max products to scan}
        {--product-id= : Scan one product}';

    protected $description = 'Detect and clean broken UTF-8 text in product content and attributes.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $limit = max(1, (int) $this->option('limit'));
        $rows = [];
        $updated = 0;

        $query = Product::query()
            ->with(['attributes'])
            ->orderBy('id')
            ->limit($limit);

        if (filled($this->option('product-id'))) {
            $query->whereKey((int) $this->option('product-id'));
        }

        foreach ($query->get() as $product) {
            foreach (['name', 'short_description', 'description', 'h1', 'meta_title', 'meta_description'] as $field) {
                $old = $product->{$field};
                $new = is_string($old) ? Utf8Sanitizer::forDb($old, $field === 'description' ? 65000 : 255) : $old;

                if (is_string($old) && $new !== $old && (Utf8Sanitizer::hasBrokenText($old) || trim($old) !== trim($new))) {
                    $rows[] = $this->row($product, $field, $old, $new);
                    if ($force) {
                        $product->forceFill([$field => $new])->save();
                        $updated++;
                    }
                }
            }

            foreach ($product->attributes as $attribute) {
                foreach (['name', 'value'] as $field) {
                    $old = $attribute->{$field};
                    $new = is_string($old) ? Utf8Sanitizer::forDb($old, $field === 'value' ? 600 : 191) : $old;

                    if (is_string($old) && $new !== $old && (Utf8Sanitizer::hasBrokenText($old) || trim($old) !== trim($new))) {
                        $rows[] = $this->row($product, 'attribute_'.$field.'#'.$attribute->id, $old, $new);
                        if ($force) {
                            $attribute->forceFill([$field => $new])->save();
                            $updated++;
                        }
                    }
                }
            }
        }

        $this->table(['product_id', 'sku', 'field', 'old_value', 'new_value'], $rows);
        $this->info(($force ? 'Updated fields: ' : 'Dry-run changes: ').($force ? $updated : count($rows)));

        return self::SUCCESS;
    }

    private function row(Product $product, string $field, string $old, string $new): array
    {
        return [
            $product->id,
            $product->sku ?: $product->paloma_sku ?: $product->model,
            $field,
            mb_substr($old, 0, 120),
            mb_substr($new, 0, 120),
        ];
    }
}
