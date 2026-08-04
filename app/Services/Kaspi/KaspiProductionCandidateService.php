<?php

namespace App\Services\Kaspi;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

class KaspiProductionCandidateService
{
    public function list(array $options = []): array
    {
        $limit = min(100, max(1, (int) ($options['limit'] ?? 25)));
        $cursor = max(0, (int) ($options['cursor'] ?? $options['page'] ?? 0));
        $includeProtected = filter_var($options['include_protected'] ?? false, FILTER_VALIDATE_BOOL);
        $skus = array_values(array_filter((array) ($options['sku'] ?? []), 'filled'));

        $query = Product::query()
            ->withCount(['images', 'attributes'])
            ->whereNotNull('sku')
            ->where('sku', '<>', '')
            ->where(function (Builder $query): void {
                $query
                    ->whereDoesntHave('images')
                    ->orWhere(fn (Builder $inner) => $inner->whereNull('description')->orWhere('description', ''));
            })
            ->orderBy('id');

        if ($skus !== []) {
            $query->whereIn('sku', $skus);
        }

        if (! $includeProtected) {
            $query->where('auto_content_locked', false)
                ->where('photos_are_manual', false)
                ->where('description_is_manual', false);
        }

        if ($cursor > 0) {
            $query->where('id', '>', $cursor);
        }

        $products = $query->limit($limit + 1)->get();
        $hasMore = $products->count() > $limit;
        $products = $products->take($limit)->values();
        $next = $hasMore && $products->isNotEmpty() ? $products->last()->id : null;

        return [
            'data' => $products->map(fn (Product $product): array => [
                'sku' => (string) $product->sku,
                'name' => (string) $product->display_name,
                'kaspi_product_url' => $product->kaspi_product_url,
                'has_images' => (int) $product->images_count > 0,
                'has_description' => filled($product->description),
                'has_attributes' => (int) $product->attributes_count > 0,
                'manual_content_protected' => (bool) ($product->auto_content_locked || $product->photos_are_manual || $product->description_is_manual),
            ])->all(),
            'next_cursor' => $next,
        ];
    }
}
