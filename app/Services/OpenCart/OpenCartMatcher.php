<?php

namespace App\Services\OpenCart;

use App\Models\Product;
use Illuminate\Support\Collection;

class OpenCartMatcher
{
    /**
     * @param array<int, OpenCartProductData> $openCartProducts
     * @return array{results: array<int, array<string, mixed>>, stats: array<string, int>}
     */
    public function match(Collection $palomaProducts, array $openCartProducts): array
    {
        $skuIndex = $this->indexBy($openCartProducts, 'sku');
        $modelIndex = $this->indexBy($openCartProducts, 'model');
        $palomaSkuCounts = $palomaProducts
            ->pluck('paloma_sku')
            ->filter()
            ->map(fn (string $sku): string => $this->normalize($sku))
            ->countBy();
        $matchedOpenCartIds = [];
        $results = [];

        foreach ($palomaProducts as $product) {
            $result = $this->matchProduct($product, $openCartProducts, $skuIndex, $modelIndex, $palomaSkuCounts);
            $results[] = $result;

            if (($result['match_status'] ?? null) === 'matched' && ! empty($result['opencart_product_id'])) {
                $matchedOpenCartIds[] = (int) $result['opencart_product_id'];
            }
        }

        $duplicateSkuCount = $this->countBy($results, 'match_status', 'duplicate_sku');
        $duplicateModelCount = $this->countBy($results, 'match_status', 'duplicate_model');

        $stats = [
            'paloma_products_count' => $palomaProducts->count(),
            'opencart_products_count' => count($openCartProducts),
            'matched_by_model' => $this->countBy($results, 'match_method', 'matched_by_model'),
            'matched_by_sku' => $this->countBy($results, 'match_method', 'matched_by_sku_fallback'),
            'duplicate_sku' => $duplicateSkuCount,
            'duplicate_model' => $duplicateModelCount,
            'conflicts' => $duplicateSkuCount + $duplicateModelCount + $this->countBy($results, 'match_status', 'conflict'),
            'not_found' => $this->countBy($results, 'match_status', 'not_found'),
            'opencart_only_skipped' => count($openCartProducts) - count(array_unique($matchedOpenCartIds)),
        ];

        return [
            'results' => $results,
            'stats' => $stats,
        ];
    }

    /**
     * @param array<int, OpenCartProductData> $openCartProducts
     * @param array<string, array<int, OpenCartProductData>> $skuIndex
     * @param array<string, array<int, OpenCartProductData>> $modelIndex
     * @return array<string, mixed>
     */
    private function matchProduct(Product $product, array $openCartProducts, array $skuIndex, array $modelIndex, Collection $palomaSkuCounts): array
    {
        $palomaSku = $this->normalize($product->paloma_sku);

        if ($palomaSku && ($palomaSkuCounts[$palomaSku] ?? 0) > 1) {
            return $this->result($product, null, 'conflict', 'conflict', 0, 'manual_review');
        }

        $modelCandidates = $palomaSku ? ($modelIndex[$palomaSku] ?? []) : [];

        if (count($modelCandidates) === 1) {
            return $this->result($product, $modelCandidates[0], 'matched_by_model', 'matched', 100, 'auto_match');
        }

        if (count($modelCandidates) > 1) {
            return $this->result($product, $modelCandidates[0], 'duplicate_model', 'duplicate_model', 0, 'fix_model');
        }

        $skuCandidates = $palomaSku ? ($skuIndex[$palomaSku] ?? []) : [];

        if (count($skuCandidates) === 1) {
            return $this->result($product, $skuCandidates[0], 'matched_by_sku_fallback', 'matched', 95, 'auto_match');
        }

        if (count($skuCandidates) > 1) {
            return $this->result($product, $skuCandidates[0], 'duplicate_sku', 'duplicate_sku', 0, 'fix_sku');
        }

        $similar = $this->mostSimilarByName($product, $openCartProducts);

        return $this->result($product, $similar, 'not_found', 'not_found', $similar ? 50 : 0, $similar ? 'manual_review' : 'ignore');
    }

    private function result(
        Product $palomaProduct,
        ?OpenCartProductData $openCartProduct,
        string $method,
        string $status,
        int $confidence,
        string $recommendedAction,
    ): array {
        return [
            'product_id' => $palomaProduct->id,
            'paloma_sku' => $palomaProduct->paloma_sku,
            'paloma_name' => $palomaProduct->name,
            'opencart_product_id' => $status === 'matched' ? $openCartProduct?->product_id : null,
            'report_opencart_product_id' => $openCartProduct?->product_id,
            'opencart_sku' => $openCartProduct?->sku,
            'opencart_model' => $openCartProduct?->model,
            'match_method' => $method,
            'match_status' => $status,
            'confidence' => $confidence,
            'recommended_action' => $recommendedAction,
        ];
    }

    /**
     * @param array<int, OpenCartProductData> $products
     * @return array<string, array<int, OpenCartProductData>>
     */
    private function indexBy(array $products, string $field): array
    {
        $index = [];

        foreach ($products as $product) {
            $key = $this->normalize($product->{$field});

            if ($key) {
                $index[$key][] = $product;
            }
        }

        return $index;
    }

    /**
     * Name similarity is report-only and never creates an automatic match.
     *
     * @param array<int, OpenCartProductData> $openCartProducts
     */
    private function mostSimilarByName(Product $product, array $openCartProducts): ?OpenCartProductData
    {
        $name = mb_strtolower((string) $product->name);

        if ($name === '') {
            return null;
        }

        $best = null;
        $bestPercent = 0.0;

        foreach ($openCartProducts as $openCartProduct) {
            $candidate = mb_strtolower((string) $openCartProduct->name);

            if ($candidate === '') {
                continue;
            }

            similar_text($name, $candidate, $percent);

            if ($percent > $bestPercent) {
                $best = $openCartProduct;
                $bestPercent = $percent;
            }
        }

        return $bestPercent >= 85.0 ? $best : null;
    }

    private function countBy(array $results, string $field, string $value): int
    {
        return count(array_filter($results, fn (array $result): bool => ($result[$field] ?? null) === $value));
    }

    private function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_strtoupper($value);
    }
}
