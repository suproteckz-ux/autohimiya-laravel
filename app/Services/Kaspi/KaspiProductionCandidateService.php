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
        $debug = filter_var($options['debug'] ?? false, FILTER_VALIDATE_BOOL);
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

        $result = [
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

        if ($debug) {
            $result['diagnostics'] = $this->diagnostics($skus, $includeProtected, $cursor);
        }

        return $result;
    }

    private function diagnostics(array $skus, bool $includeProtected, int $cursor): array
    {
        $total = Product::query()->count();
        $remaining = Product::query()
            ->withCount(['images', 'attributes'])
            ->whereNotNull('sku')
            ->where('sku', '<>', '');

        $rejected = [
            'manual_content_protected' => 0,
            'has_images' => 0,
            'has_description' => 0,
            'has_attributes' => 0,
            'missing_kaspi_url' => 0,
            'sku_filter' => 0,
            'other' => max(0, $total - (clone $remaining)->count()),
        ];

        if ($skus !== []) {
            $rejected['sku_filter'] = (clone $remaining)->whereNotIn('sku', $skus)->count();
            $remaining->whereIn('sku', $skus);
        }

        if ($cursor > 0) {
            $cursorRejected = (clone $remaining)->where('id', '<=', $cursor)->count();
            $rejected['other'] += $cursorRejected;
            $remaining->where('id', '>', $cursor);
        }

        if (! $includeProtected) {
            $rejected['manual_content_protected'] = (clone $remaining)
                ->where(fn (Builder $query) => $query
                    ->where('auto_content_locked', true)
                    ->orWhere('photos_are_manual', true)
                    ->orWhere('description_is_manual', true))
                ->count();

            $remaining
                ->where('auto_content_locked', false)
                ->where('photos_are_manual', false)
                ->where('description_is_manual', false);
        }

        $completeContent = (clone $remaining)
            ->whereHas('images')
            ->whereNotNull('description')
            ->where('description', '<>', '')
            ->count();

        $rejected['has_images'] = $completeContent;
        $rejected['has_description'] = $completeContent;

        return [
            'total_products' => $total,
            'returned_candidate_count' => (clone $remaining)
                ->where(fn (Builder $query) => $query
                    ->whereDoesntHave('images')
                    ->orWhere(fn (Builder $inner) => $inner->whereNull('description')->orWhere('description', '')))
                ->count(),
            'rejected' => $rejected,
            'notes' => [
                'has_images' => 'Products are excluded for complete content only when has_images and has_description are both true.',
                'has_attributes' => 'Not applied as a candidate filter.',
                'missing_kaspi_url' => 'Not applied as a candidate filter; local resolver may resolve a missing URL.',
            ],
            'requested_skus' => array_map(fn (string $sku): array => $this->explainSku($sku, $includeProtected, $cursor), $skus),
        ];
    }

    private function explainSku(string $sku, bool $includeProtected, int $cursor): array
    {
        $product = Product::query()
            ->withCount(['images', 'attributes'])
            ->where('sku', $sku)
            ->orderBy('id')
            ->first();

        if (! $product) {
            return [
                'sku' => $sku,
                'found' => false,
                'manual_content_protected' => null,
                'has_images' => null,
                'has_description' => null,
                'has_attributes' => null,
                'kaspi_url' => 'missing',
                'excluded_because' => 'sku_filter_no_exact_product_sku_match',
            ];
        }

        $manualProtected = (bool) ($product->auto_content_locked || $product->photos_are_manual || $product->description_is_manual);
        $hasImages = (int) $product->images_count > 0;
        $hasDescription = filled($product->description);
        $hasAttributes = (int) $product->attributes_count > 0;
        $reasons = [];

        if ($cursor > 0 && $product->id <= $cursor) {
            $reasons[] = 'cursor';
        }

        if (! $includeProtected && $manualProtected) {
            $reasons[] = 'manual_content_protected';
        }

        if ($hasImages && $hasDescription) {
            $reasons[] = 'has_images_and_has_description';
        }

        return [
            'sku' => $sku,
            'found' => true,
            'manual_content_protected' => $manualProtected,
            'has_images' => $hasImages,
            'has_description' => $hasDescription,
            'has_attributes' => $hasAttributes,
            'kaspi_url' => filled($product->kaspi_product_url) ? 'present' : 'missing',
            'excluded_because' => $reasons === [] ? 'not_excluded_by_candidate_filters' : implode(',', $reasons),
        ];
    }
}
