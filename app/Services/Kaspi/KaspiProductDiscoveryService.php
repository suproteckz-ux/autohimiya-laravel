<?php

namespace App\Services\Kaspi;

use App\Models\KaspiEnrichmentTask;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Throwable;

class KaspiProductDiscoveryService
{
    public function discover(Product $product, bool $dryRun = true): array
    {
        $started = microtime(true);
        $sku = $product->sku;
        $savedUrl = $product->kaspi_product_url ?: $product->kaspiEnrichmentTasks()->latest('updated_at')->value('kaspi_product_url');

        if (! $product->canShowKaspiCreditButton()) {
            return $this->result($product, $sku, null, 'skipped', 'Kaspi button is not rendered for this product.', $started);
        }

        if (filled($savedUrl)) {
            if (! $dryRun) {
                $this->upsertTask($product, $savedUrl, filled($product->kaspi_product_url) ? 'manual_url' : 'saved_task_url', 'pending', null);
            }

            return $this->result($product, $sku, $savedUrl, 'found_existing', null, $started, source: filled($product->kaspi_product_url) ? 'product.kaspi_product_url' : 'kaspi_enrichment_tasks.kaspi_product_url');
        }

        $message = 'Kaspi button is present, but backend cannot resolve the final Kaspi URL from the JS widget. Add or confirm the URL manually.';

        if (! $dryRun) {
            $this->upsertTask($product, null, 'kaspi_widget_needs_manual_url', 'needs_manual_url', $message);
        }

        return $this->result($product, $sku, null, 'needs_manual_url', $message, $started, source: 'kaspi_widget');
    }

    public function searchPublic(Product $product, bool $dryRun = true): array
    {
        $started = microtime(true);
        $sku = $product->sku;

        if (blank($sku)) {
            return $this->result($product, $sku, null, 'failed', 'Product SKU is empty.', $started);
        }

        $searchUrl = 'https://kaspi.kz/shop/search/?text='.rawurlencode($sku);

        if ($dryRun || ! config('services.kaspi.enrichment_enabled')) {
            return $this->result($product, $sku, $searchUrl, 'planned', 'Dry-run or enrichment disabled: public search request was not sent.', $started, source: 'public_search');
        }

        try {
            $response = Http::timeout(20)
                ->withHeaders(['User-Agent' => 'AutohimiyaKzBot/1.0 (+https://autohimiki.kz)'])
                ->get($searchUrl);

            $url = $this->extractFirstProductUrl($response->body(), $sku);
            $status = $url ? 'found' : 'not_found';
            $error = $url ? null : 'Public search did not return a product card. Add or confirm the URL manually.';

            if ($url) {
                $this->upsertTask($product, $url, 'discover_public_page', 'pending', null);
            }

            return $this->result($product, $sku, $url ?: $searchUrl, $status, $error, $started, $response->status(), strlen($response->body()), 'public_search');
        } catch (Throwable $exception) {
            return $this->result($product, $sku, $searchUrl, 'failed', $exception->getMessage(), $started, source: 'public_search');
        }
    }

    private function upsertTask(Product $product, ?string $url, string $source, string $status, ?string $error): KaspiEnrichmentTask
    {
        return KaspiEnrichmentTask::query()->updateOrCreate([
            'product_id' => $product->id,
            'kaspi_merchant_sku' => $product->sku,
        ], [
            'kaspi_product_url' => $url,
            'missing_photo' => ! $product->primary_image && ! $product->images()->exists(),
            'missing_description' => blank($product->description),
            'missing_attributes' => ! $product->attributes()->exists(),
            'status' => $status,
            'source' => $source,
            'error' => $error,
        ]);
    }

    private function extractFirstProductUrl(string $html, string $sku): ?string
    {
        if (! preg_match_all('/href=["\']([^"\']+\/shop\/p\/[^"\']+)["\']/i', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $url) {
            $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (! str_starts_with($url, 'http')) {
                $url = 'https://kaspi.kz'.$url;
            }

            if (str_contains(mb_strtolower($url), mb_strtolower($sku))) {
                return $url;
            }
        }

        $url = html_entity_decode($matches[1][0], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return str_starts_with($url, 'http') ? $url : 'https://kaspi.kz'.$url;
    }

    private function result(Product $product, ?string $sku, ?string $url, string $status, ?string $error, float $started, ?int $httpStatus = null, ?int $responseSize = null, ?string $source = null): array
    {
        return [
            'product_id' => $product->id,
            'sku' => $sku,
            'url' => $url,
            'status' => $status,
            'error' => $error,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'http_status' => $httpStatus,
            'response_size' => $responseSize,
            'source' => $source,
        ];
    }
}
