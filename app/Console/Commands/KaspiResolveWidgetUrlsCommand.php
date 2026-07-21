<?php

namespace App\Console\Commands;

use App\Services\Automation\NullProgressReporter;
use App\Services\Kaspi\KaspiWidgetUrlBatchResolver;
use Illuminate\Console\Command;

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

    public function handle(KaspiWidgetUrlBatchResolver $service): int
    {
        $result = $service->run([
            'limit' => max(1, (int) $this->option('limit')),
            'dry_run' => (bool) $this->option('dry-run'),
            'headless' => (bool) $this->option('headless'),
            'delay_ms' => max(0, (int) $this->option('delay-ms')),
            'product_id' => $this->option('product-id'),
            'sku' => $this->option('sku'),
            'only_missing_url' => $this->boolOption('only-missing-url'),
            'fetch_content' => $this->boolOption('fetch-content'),
            'only_with_kaspi_button' => $this->boolOption('only-with-kaspi-button'),
            'include_without_kaspi_button' => (bool) $this->option('include-without-kaspi-button'),
            'retry_not_found' => (bool) $this->option('retry-not-found'),
        ], new NullProgressReporter());

        $this->table(['Metric', 'Count'], collect($result['metrics'] ?? [])->map(fn (int $count, string $metric): array => [$metric, $count])->values()->all());
        $this->info((string) ($result['message'] ?? 'Resolve complete.'));

        return self::SUCCESS;
    }

    private function boolOption(string $name): bool
    {
        return in_array(strtolower((string) $this->option($name)), ['1', 'true', 'yes', 'on'], true);
    }
}