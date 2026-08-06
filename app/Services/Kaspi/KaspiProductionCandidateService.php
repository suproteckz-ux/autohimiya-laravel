<?php

namespace App\Services\Kaspi;

use App\Models\Product;
use App\Support\MeaningfulContent;
use App\Support\StorefrontCanonicalUrl;
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
            ->where('auto_content_locked', false)
            ->orderBy('id');

        if ($skus !== []) {
            $query->whereIn('sku', $skus);
        }

        if ($cursor > 0) {
            $query->where('id', '>', $cursor);
        }

        $eligible = $query->get()
            ->filter(fn (Product $product): bool => $this->isCandidate($product))
            ->values();
        $hasMore = $eligible->count() > $limit;
        $products = $eligible->take($limit)->values();
        $next = $hasMore && $products->isNotEmpty() ? $products->last()->id : null;

        $result = [
            'data' => $products->map(fn (Product $product): array => [
                'sku' => (string) $product->sku,
                'slug' => (string) $product->slug,
                'name' => (string) $product->display_name,
                'storefront_url' => $this->storefrontUrl($product),
                'kaspi_product_url' => $product->kaspi_product_url,
                'has_images' => $this->hasImages($product),
                'has_description' => $this->hasDescription($product),
                'has_attributes' => (int) $product->attributes_count > 0,
                'manual_content_protected' => $this->manualContentProtected($product),
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

        $products = $remaining->get();
        $autoLocked = $products->filter(fn (Product $product): bool => (bool) $product->auto_content_locked)->count();
        $remainingProducts = $products->reject(fn (Product $product): bool => (bool) $product->auto_content_locked)->values();
        $completeContent = $remainingProducts->filter(fn (Product $product): bool => $this->hasImages($product) && $this->hasDescription($product))->count();

        $rejected['manual_content_protected'] = $autoLocked;
        $rejected['has_images'] = $completeContent;
        $rejected['has_description'] = $completeContent;

        return [
            'total_products' => $total,
            'returned_candidate_count' => $remainingProducts->filter(fn (Product $product): bool => $this->isCandidate($product))->count(),
            'rejected' => $rejected,
            'notes' => [
                'has_images' => 'Products are excluded for complete content only when has_images and has_description are both true.',
                'has_attributes' => 'Not applied as a candidate filter.',
                'missing_kaspi_url' => 'Not applied as a candidate filter; local resolver may resolve a missing URL.',
                'manual_content_protected' => 'Empty manual photo/description fields do not block candidacy; auto_content_locked still excludes the product.',
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

        $manualProtected = $this->manualContentProtected($product);
        $hasImages = $this->hasImages($product);
        $hasDescription = $this->hasDescription($product);
        $hasAttributes = (int) $product->attributes_count > 0;
        $reasons = [];

        if ($cursor > 0 && $product->id <= $cursor) {
            $reasons[] = 'cursor';
        }

        if ((bool) $product->auto_content_locked) {
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

    private function isCandidate(Product $product): bool
    {
        return ! (bool) $product->auto_content_locked
            && (! $this->hasImages($product) || ! $this->hasDescription($product));
    }

    private function hasImages(Product $product): bool
    {
        return (int) ($product->images_count ?? 0) > 0;
    }

    private function hasDescription(Product $product): bool
    {
        return MeaningfulContent::hasDescription($product->description);
    }

    private function manualContentProtected(Product $product): bool
    {
        return (bool) $product->auto_content_locked
            || ($this->hasImages($product) && (bool) $product->photos_are_manual)
            || ($this->hasDescription($product) && (bool) $product->description_is_manual);
    }

    private function storefrontUrl(Product $product): ?string
    {
        $slug = trim((string) $product->slug);
        if ($slug === '') {
            return null;
        }

        return StorefrontCanonicalUrl::path('/product/'.$slug);
    }
}
