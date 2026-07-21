<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\CatalogRecovery\OpenCartCatalogData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SeoRecoveryCommand extends Command
{
    protected $signature = 'catalog:seo-recovery {--dry-run} {--apply}';

    protected $description = 'Recover missing product SEO fields from OpenCart without overwriting existing values.';

    public function handle(OpenCartCatalogData $openCart): int
    {
        if ($this->option('dry-run') === $this->option('apply')) {
            $this->error('Run exactly one mode: --dry-run or --apply.');

            return self::FAILURE;
        }

        $data = $openCart->all();
        $products = Product::query()->whereNotNull('opencart_product_id')->with(['brand', 'category'])->get();
        $updatesCount = 0;
        $missing = [];
        $draftQueue = [];

        foreach ($products as $product) {
            $description = $data['descriptions'][(int) $product->opencart_product_id] ?? [];
            $updates = [];

            foreach (['meta_title', 'meta_description'] as $field) {
                if (blank($product->{$field}) && filled($description[$field] ?? null)) {
                    $updates[$field] = $description[$field];
                }
            }

            if (blank($product->h1) && filled($description['meta_h1'] ?? null)) {
                $updates['h1'] = $description['meta_h1'];
            }

            if ($updates !== []) {
                $updatesCount++;

                if ($this->option('apply')) {
                    $product->update($updates);
                }
            }

            if (blank($product->slug) || blank($product->meta_title) || blank($product->meta_description)) {
                $missing[] = [$product->id, $product->paloma_sku, $product->display_name, blank($product->slug) ? 1 : 0, blank($product->meta_title) ? 1 : 0, blank($product->meta_description) ? 1 : 0];
                $draftQueue[] = [$product->id, $product->display_name, $product->brand?->display_name, $product->category?->display_name, 'seo_draft_needed'];
            }
        }

        $stats = [
            'Products checked' => $products->count(),
            'Products with recoverable SEO fields' => $updatesCount,
            'Products still missing SEO fields' => count($missing),
            'SEO draft tasks' => count($draftQueue),
        ];

        $this->writeReports($stats, $missing, $draftQueue);
        $this->table(['Metric', 'Count'], collect($stats)->map(fn ($count, string $metric): array => [$metric, $count])->values());

        return self::SUCCESS;
    }

    private function writeReports(array $stats, array $missing, array $draftQueue): void
    {
        $root = dirname(base_path());
        $lines = ['# SEO_RECOVERY_REPORT', '', 'Дата проверки: 2026-06-17', '', '| Метрика | Количество |', '| --- | ---: |'];

        foreach ($stats as $metric => $count) {
            $lines[] = '| '.$metric.' | '.$count.' |';
        }

        File::put($root.DIRECTORY_SEPARATOR.'SEO_RECOVERY_REPORT.md', implode(PHP_EOL, $lines).PHP_EOL);
        $this->writeCsv($root.DIRECTORY_SEPARATOR.'SEO_MISSING.csv', [['product_id', 'paloma_sku', 'product_name', 'missing_slug', 'missing_meta_title', 'missing_meta_description'], ...$missing]);
        $this->writeCsv($root.DIRECTORY_SEPARATOR.'SEO_DRAFT_QUEUE.csv', [['product_id', 'product_name', 'brand', 'category', 'action'], ...$draftQueue]);
    }

    private function writeCsv(string $path, array $rows): void
    {
        $file = fopen($path, 'wb');
        foreach ($rows as $row) {
            fputcsv($file, $row);
        }
        fclose($file);
    }
}
