<?php

namespace App\Services\Kaspi;

use App\Models\Product;
use App\Services\Automation\AutomationProgressReporterInterface;
use App\Services\Automation\NullProgressReporter;
use Illuminate\Database\Eloquent\Builder;

class KaspiWidgetUrlBatchResolver
{
    public function __construct(private readonly KaspiWidgetBrowserResolver $resolver) {}

    public function run(array $options = [], ?AutomationProgressReporterInterface $progress = null): array
    {
        $progress ??= new NullProgressReporter();
        $limit = max(1, (int) ($options['limit'] ?? 10));
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $headless = (bool) ($options['headless'] ?? false);
        $delayMs = max(0, (int) ($options['delay_ms'] ?? 5000));
        $fetchContent = (bool) ($options['fetch_content'] ?? false);
        $onlyMissingUrl = filter_var($options['only_missing_url'] ?? true, FILTER_VALIDATE_BOOL);
        $onlyWithKaspiButton = ! (bool) ($options['include_without_kaspi_button'] ?? false) && filter_var($options['only_with_kaspi_button'] ?? true, FILTER_VALIDATE_BOOL);
        $retryNotFound = (bool) ($options['retry_not_found'] ?? false) || filled($options['product_id'] ?? null) || filled($options['sku'] ?? null);

        if ($onlyWithKaspiButton && (! filled(config('services.kaspi.merchant_code')) || ! filled(config('services.kaspi.city_code')))) {
            return ['successful' => true, 'warnings' => true, 'message' => 'Kaspi merchant_code or city_code is not configured. No products were processed.', 'total_items' => 0, 'processed_items' => 0, 'skipped_count' => 0, 'failed_count' => 0];
        }

        $metrics = ['processed' => 0, 'resolved' => 0, 'skipped' => 0, 'failed' => 0, 'timeouts' => 0, 'captcha' => 0, 'not_found' => 0];
        $products = $this->queryProducts($limit, $onlyMissingUrl, $onlyWithKaspiButton, $retryNotFound, $options)->get()->values();
        $progress->start($products->count(), 'Kaspi: поиск URL.');
        $consecutiveErrors = 0;

        foreach ($products as $product) {
            $result = $this->resolver->resolve($product, ['dry_run' => $dryRun, 'headless' => $headless, 'delay_ms' => $delayMs, 'fetch_content' => $fetchContent]);
            $this->updateMetrics($metrics, $result);

            if (in_array($result['status'], ['resolved_from_widget', 'found_existing'], true)) { $consecutiveErrors = 0; $progress->incrementUpdated(); }
            elseif ($result['status'] === 'widget_not_found') { $progress->incrementSkipped(); }
            else { $consecutiveErrors++; $progress->incrementFailed(); }

            $progress->advance(1, 'Kaspi: обработан товар '.$product->id);
            if ($consecutiveErrors >= 5) { break; }
            if ($delayMs > 0) { usleep($delayMs * 1000); }
        }

        return ['successful' => true, 'warnings' => $metrics['failed'] > 0, 'message' => 'Kaspi URL resolve complete. Products checked: '.$metrics['processed'], 'total_items' => $products->count(), 'processed_items' => $metrics['processed'], 'updated_count' => $metrics['resolved'], 'skipped_count' => $metrics['skipped'], 'failed_count' => $metrics['failed'], 'metrics' => $metrics];
    }

    private function queryProducts(int $limit, bool $onlyMissingUrl, bool $onlyWithKaspiButton, bool $retryNotFound, array $options): Builder
    {
        $query = Product::query()->eligibleForKaspiEnrichment()->orderBy('id')->limit($limit);
        if ($onlyWithKaspiButton) { $query->withKaspiButton(); if (! $retryNotFound) { $query->whereDoesntHave('kaspiEnrichmentTasks', fn (Builder $q) => $q->where('status', 'widget_not_found')); } }
        else { $query->whereNotNull('sku')->where('sku', '<>', ''); }
        if (filled($options['product_id'] ?? null)) { $query->whereKey((int) $options['product_id']); }
        if (filled($options['ids'] ?? null)) { $ids = array_filter(array_map('intval', explode(',', (string) $options['ids']))); if ($ids !== []) { $query->whereIn('id', $ids); } }
        if (filled($options['sku'] ?? null)) { $query->where('sku', (string) $options['sku']); }
        if ($onlyMissingUrl) { $query->where(fn (Builder $inner) => $inner->whereNull('kaspi_product_url')->orWhere('kaspi_product_url', '')); }
        return $query;
    }

    private function updateMetrics(array &$metrics, array $result): void
    {
        $metrics['processed']++;
        $status = (string) ($result['status'] ?? '');
        if (in_array($status, ['resolved_from_widget', 'found_existing'], true)) { $metrics['resolved']++; return; }
        if ($status === 'widget_not_found') { $metrics['not_found']++; $metrics['skipped']++; return; }
        $metrics['failed']++;
        if ((bool) ($result['timeout'] ?? false) || str_contains($status, 'timeout')) { $metrics['timeouts']++; }
        if ((bool) ($result['captcha'] ?? false) || str_contains(strtolower((string) ($result['error'] ?? '')), 'captcha')) { $metrics['captcha']++; }
    }
}