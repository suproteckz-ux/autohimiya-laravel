<?php

namespace App\Console\Commands;

use App\Support\CatalogUtf8Scanner;
use Illuminate\Console\Command;

class CatalogScanEncodingIssuesCommand extends Command
{
    protected $signature = 'catalog:scan-encoding-issues
        {--details : Show each affected field}
        {--limit=0 : Max issues to show}
        {--fix-drafts : Deprecated, kept for compatibility}
        {--dry-run : Deprecated, kept for compatibility}';

    protected $description = 'Scan storefront catalog text for UTF-8, mojibake, replacement characters, repeated question marks, and HTML entity artifacts.';

    public function handle(): int
    {
        $issues = CatalogUtf8Scanner::scan(max(0, (int) $this->option('limit')));

        $this->table(['Metric', 'Value'], [
            ['Issues found', count($issues)],
            ['Mode', $this->option('details') ? 'details' : 'summary'],
        ]);

        if ($issues === []) {
            $this->info('No encoding issues found in scanned storefront fields.');

            return self::SUCCESS;
        }

        $grouped = collect($issues)
            ->groupBy(fn (array $issue): string => $issue['table'].'.'.$issue['column'].' / '.$issue['issue'])
            ->map(fn ($items, string $key): array => [$key, $items->count()])
            ->values()
            ->all();

        $this->table(['Scope / issue', 'Count'], $grouped);

        if ($this->option('details')) {
            $this->table(
                ['model', 'id', 'field', 'sample', 'suspected_source'],
                collect($issues)->map(fn (array $issue): array => [
                    $this->modelName($issue['table']),
                    $issue['id'],
                    $issue['column'],
                    $issue['raw_preview'] ?? $issue['preview'],
                    $this->suspectedSource($issue['table'], $issue['column']),
                ])->all()
            );
        }

        return self::SUCCESS;
    }

    private function modelName(string $table): string
    {
        return match ($table) {
            'products' => 'Product',
            'categories' => 'Category',
            'brands' => 'Brand',
            'product_attributes' => 'ProductAttribute',
            'catalog_enrichment_tasks' => 'CatalogEnrichmentTask',
            'kaspi_enrichment_tasks' => 'KaspiEnrichmentTask',
            'sync_logs' => 'SyncLog',
            default => $table,
        };
    }

    private function suspectedSource(string $table, string $column): string
    {
        if (str_contains($table, 'kaspi') || str_starts_with($column, 'kaspi_')) {
            return 'kaspi_imported_text';
        }

        if (str_contains($table, 'enrichment') || str_contains($column, 'payload') || str_contains($column, 'draft')) {
            return 'content_draft';
        }

        if (str_starts_with($column, 'opencart_') || str_contains($column, 'opencart')) {
            return 'opencart_source';
        }

        if (str_starts_with($column, 'paloma_') || str_contains($column, 'paloma')) {
            return 'paloma_source';
        }

        return match ($table) {
            'categories' => 'category_dictionary',
            'brands' => 'brand_dictionary',
            'product_attributes' => 'product_attributes',
            'products' => 'product_field',
            default => 'database_text',
        };
    }
}
