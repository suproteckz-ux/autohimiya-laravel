<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Kaspi\KaspiWidgetBrowserResolver;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class KaspiResolveWidgetUrlsCommand extends Command
{
    protected $signature = 'kaspi:resolve-widget-urls
        {--limit=10}
        {--dry-run}
        {--headless}
        {--delay-ms=5000}
        {--product-id=}
        {--sku=}
        {--only-missing-url=true}
        {--fetch-content=false}
        {--only-with-kaspi-button=true}
        {--include-without-kaspi-button}
        {--retry-not-found : Include products previously marked widget_not_found (skipped by default)}';

    protected $description = 'Resolve public Kaspi product URLs by clicking the storefront Kaspi widget with Playwright.';

    public function handle(KaspiWidgetBrowserResolver $resolver): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $headless = (bool) $this->option('headless');
        $delayMs = max(0, (int) $this->option('delay-ms'));
        $fetchContent = $this->boolOption('fetch-content');
        $onlyMissingUrl = $this->boolOption('only-missing-url');
        $onlyWithKaspiButton = ! $this->option('include-without-kaspi-button')
            && $this->boolOption('only-with-kaspi-button');
        // Targeted single-product runs always retry regardless of past widget_not_found.
        $retryNotFound = (bool) $this->option('retry-not-found')
            || filled($this->option('product-id'))
            || filled($this->option('sku'));

        if ($onlyWithKaspiButton
            && (! filled(config('services.kaspi.merchant_code')) || ! filled(config('services.kaspi.city_code')))) {
            $this->warn('Kaspi merchant_code or city_code is not configured. No products will be processed.');
            $this->warn('Pass --include-without-kaspi-button to override (diagnostic mode only).');

            return self::SUCCESS;
        }

        $rows = [];
        $consecutiveErrors = 0;
        $failedDetails = [];
        $metrics = [
            'processed' => 0,
            'resolved' => 0,
            'skipped' => 0,
            'failed' => 0,
            'timeouts' => 0,
            'captcha' => 0,
            'not_found' => 0,
        ];

        $products = $this->queryProducts($limit, $onlyMissingUrl, $onlyWithKaspiButton, $retryNotFound)->get()->values();

        foreach ($products as $product) {
            $result = $resolver->resolve($product, [
                'dry_run' => $dryRun,
                'headless' => $headless,
                'delay_ms' => $delayMs,
                'fetch_content' => $fetchContent,
            ]);

            $rows[] = [
                $result['product_id'],
                $result['sku'],
                $result['product_url'],
                $result['kaspi_search_url'] ?? null,
                $result['widget_found'],
                $result['button_found'],
                $result['resolved_kaspi_url'],
                $result['status'],
                $result['error'],
                $result['current_step'] ?? null,
                $result['duration_ms'],
            ];
            $this->updateMetrics($metrics, $result);

            if (in_array($result['status'], ['resolved_from_widget', 'found_existing'], true)) {
                $consecutiveErrors = 0;
            } elseif ($result['status'] !== 'widget_not_found') {
                // widget_not_found is expected for products not in the Kaspi catalogue —
                // it is not a resolver failure and must not abort a mass run.
                $consecutiveErrors++;
                $failedDetails[] = $this->failureDetail($result);
            }

            if ($consecutiveErrors >= 5) {
                $this->warn('Last 5 resolver errors:');
                foreach (array_slice($failedDetails, -5) as $detail) {
                    $this->line('');
                    $this->line('Product '.$detail['product_id'].' / '.$detail['sku']);
                    $this->line('Kaspi search URL: '.$detail['kaspi_search_url']);
                    $this->line('Step: '.$detail['current_step']);
                    $this->line('Status: '.$detail['status']);
                    $this->line('Exception: '.($detail['exception_class'] ?: 'n/a'));
                    $this->line('Message: '.($detail['exception_message'] ?: $detail['error'] ?: 'n/a'));
                    $this->line('Playwright URL: '.($detail['playwright_page_url'] ?: 'n/a'));
                    $this->line('HTTP status: '.($detail['http_status'] ?: 'n/a'));
                    $this->line('Timeout: '.($detail['timeout'] ? 'yes' : 'no'));
                    $this->line('Captcha: '.($detail['captcha'] ? 'yes' : 'no'));
                }
                $this->line('');
                $this->warn('Stopped after 5 consecutive resolver errors.');
                break;
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $this->table([
            'product_id',
            'sku',
            'product_url',
            'kaspi_search_url',
            'widget_found',
            'button_found',
            'resolved_kaspi_url',
            'status',
            'error',
            'step',
            'duration_ms',
        ], $rows);

        $this->info(($dryRun ? 'Dry-run complete. ' : 'Resolve complete. ').'Products checked: '.count($rows));
        $this->table(['Metric', 'Count'], collect($metrics)->map(fn (int $count, string $metric): array => [$metric, $count])->values()->all());

        return self::SUCCESS;
    }

    private function queryProducts(int $limit, bool $onlyMissingUrl, bool $onlyWithKaspiButton, bool $retryNotFound = false): Builder
    {
        $query = Product::query()
            ->eligibleForKaspiEnrichment()
            ->orderBy('id')
            ->limit($limit);

        if ($onlyWithKaspiButton) {
            $query->withKaspiButton();
            // Exclude permanently-failed products only for mass runs; targeted runs always retry.
            if (! $retryNotFound) {
                $query->whereDoesntHave('kaspiEnrichmentTasks', fn (Builder $q) => $q->where('status', 'widget_not_found'));
            }
        } else {
            $query->whereNotNull('sku')->where('sku', '<>', '');
        }

        if (filled($this->option('product-id'))) {
            $query->whereKey((int) $this->option('product-id'));
        }

        if (filled($this->option('sku'))) {
            $query->where('sku', (string) $this->option('sku'));
        }

        if ($onlyMissingUrl) {
            $query->where(fn (Builder $inner) => $inner->whereNull('kaspi_product_url')->orWhere('kaspi_product_url', ''));
        }

        return $query;
    }

    private function boolOption(string $name): bool
    {
        $value = $this->option($name);

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, int> $metrics
     * @param array<string, mixed> $result
     */
    private function updateMetrics(array &$metrics, array $result): void
    {
        $metrics['processed']++;
        $status = (string) ($result['status'] ?? '');

        if (in_array($status, ['resolved_from_widget', 'found_existing'], true)) {
            $metrics['resolved']++;

            return;
        }

        if ($status === 'widget_not_found') {
            $metrics['not_found']++;
            $metrics['skipped']++;

            return;
        }

        $metrics['failed']++;

        if ((bool) ($result['timeout'] ?? false) || str_contains($status, 'timeout')) {
            $metrics['timeouts']++;
        }

        if ((bool) ($result['captcha'] ?? false) || str_contains(strtolower((string) ($result['error'] ?? '')), 'captcha')) {
            $metrics['captcha']++;
        }
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function failureDetail(array $result): array
    {
        return [
            'product_id' => $result['product_id'] ?? null,
            'sku' => $result['sku'] ?? null,
            'kaspi_search_url' => $result['kaspi_search_url'] ?? null,
            'current_step' => $result['current_step'] ?? null,
            'status' => $result['status'] ?? null,
            'error' => $result['error'] ?? null,
            'exception_class' => $result['exception_class'] ?? null,
            'exception_message' => $result['exception_message'] ?? null,
            'playwright_page_url' => $result['playwright_page_url'] ?? null,
            'http_status' => $result['http_status'] ?? null,
            'timeout' => (bool) ($result['timeout'] ?? false),
            'captcha' => (bool) ($result['captcha'] ?? false),
        ];
    }
}
