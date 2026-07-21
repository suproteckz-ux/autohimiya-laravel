<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Product;
use App\Services\CatalogRecovery\OpenCartCatalogData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BrandRecoveryCommand extends Command
{
    protected $signature = 'catalog:brand-recovery {--dry-run} {--apply}';

    protected $description = 'Recover product brand_id from OpenCart manufacturer_id and write suggestions.';

    public function handle(OpenCartCatalogData $openCart): int
    {
        if ($this->option('dry-run') === $this->option('apply')) {
            $this->error('Run exactly one mode: --dry-run or --apply.');

            return self::FAILURE;
        }

        $data = $openCart->all();
        $products = Product::query()->whereNull('brand_id')->whereNotNull('opencart_product_id')->get();
        $brandsByOpenCart = Brand::query()->whereNotNull('opencart_manufacturer_id')->get()->keyBy('opencart_manufacturer_id');
        $brands = Brand::query()->orderByDesc('name')->get();
        $recoverable = 0;
        $suggestions = [];

        foreach ($products as $product) {
            $openCartProduct = $data['products'][(int) $product->opencart_product_id] ?? null;
            $manufacturerId = (int) ($openCartProduct['manufacturer_id'] ?? 0);
            $brand = $manufacturerId > 0 ? ($brandsByOpenCart[$manufacturerId] ?? null) : null;

            if ($brand) {
                $recoverable++;

                if ($this->option('apply')) {
                    $product->update(['brand_id' => $brand->id]);
                }

                continue;
            }

            foreach ($brands as $candidate) {
                if (stripos($product->display_name, $candidate->display_name) !== false) {
                    $suggestions[] = [$product->id, $product->paloma_sku, $product->display_name, $candidate->display_name, 'manual_review'];
                    break;
                }
            }
        }

        $stillWithout = Product::query()->whereNull('brand_id')->count() - ($this->option('dry-run') ? $recoverable : 0);
        $stats = [
            'Products without brand' => Product::query()->whereNull('brand_id')->count(),
            'Brands recoverable by manufacturer_id' => $recoverable,
            'Brands suggested by name' => count($suggestions),
            'Products still without brand' => max(0, $stillWithout),
        ];

        $this->writeReports($stats, $suggestions);
        $this->table(['Metric', 'Count'], collect($stats)->map(fn ($count, string $metric): array => [$metric, $count])->values());

        return self::SUCCESS;
    }

    private function writeReports(array $stats, array $suggestions): void
    {
        $root = dirname(base_path());
        $lines = ['# BRAND_RECOVERY_REPORT', '', 'Дата проверки: 2026-06-17', '', '| Метрика | Количество |', '| --- | ---: |'];

        foreach ($stats as $metric => $count) {
            $lines[] = '| '.$metric.' | '.$count.' |';
        }

        File::put($root.DIRECTORY_SEPARATOR.'BRAND_RECOVERY_REPORT.md', implode(PHP_EOL, $lines).PHP_EOL);
        $this->writeCsv($root.DIRECTORY_SEPARATOR.'BRAND_SUGGESTIONS.csv', [['product_id', 'paloma_sku', 'product_name', 'suggested_brand', 'action'], ...$suggestions]);
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
