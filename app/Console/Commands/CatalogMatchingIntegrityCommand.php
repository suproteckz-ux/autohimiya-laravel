<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\CatalogRecovery\OpenCartCatalogData;
use App\Support\ProductStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CatalogMatchingIntegrityCommand extends Command
{
    protected $signature = 'catalog:matching-integrity';

    protected $description = 'Audit Paloma to OpenCart matching integrity without changing data.';

    public function handle(OpenCartCatalogData $openCart): int
    {
        $data = $openCart->all();

        $stats = [
            'products total' => Product::query()->count(),
            'matched products' => Product::query()->where('sync_status', 'matched')->count(),
            'products with opencart_product_id' => Product::query()->whereNotNull('opencart_product_id')->count(),
            'products without opencart_product_id' => Product::query()->whereNull('opencart_product_id')->count(),
            'matched_by_model' => Product::query()->where('match_method', 'matched_by_model')->count(),
            'matched_by_sku_fallback' => Product::query()->whereIn('match_method', ['matched_by_sku', 'matched_by_sku_fallback'])->count(),
            'conflicts' => Product::query()->where('sync_status', 'conflict')->orWhere('match_method', 'conflict')->count(),
            'needs_review' => Product::query()->where('product_status', ProductStatus::NEEDS_REVIEW)->count(),
        ];

        $sampleRows = Product::query()
            ->with('brand')
            ->whereNotNull('opencart_product_id')
            ->inRandomOrder()
            ->limit(50)
            ->get()
            ->map(function (Product $product) use ($data): array {
                $openCartProduct = $data['products'][(int) $product->opencart_product_id] ?? [];
                $openCartDescription = $data['descriptions'][(int) $product->opencart_product_id] ?? [];

                return [
                    $product->id,
                    $product->display_name,
                    $product->paloma_sku,
                    $product->opencart_product_id,
                    $product->match_method,
                    $product->match_confidence,
                    $openCartProduct['model'] ?? '',
                    $openCartProduct['sku'] ?? '',
                    $openCartDescription['name'] ?? '',
                    $openCartProduct['image'] ?? '',
                    $openCartProduct['manufacturer_id'] ?? '',
                    strlen((string) ($openCartDescription['description'] ?? '')),
                    $openCartDescription['meta_title'] ?? '',
                ];
            })
            ->all();

        $this->writeReport($stats);
        $this->writeCsv(dirname(base_path()).DIRECTORY_SEPARATOR.'MATCHING_INTEGRITY_SAMPLE.csv', [
            ['laravel_product_id', 'product_name', 'paloma_sku', 'opencart_product_id', 'match_method', 'match_confidence', 'opencart_model', 'opencart_sku', 'opencart_name', 'opencart_image', 'opencart_manufacturer_id', 'opencart_description_length', 'opencart_meta_title'],
            ...$sampleRows,
        ]);

        $this->table(['Metric', 'Count'], collect($stats)->map(fn (int $count, string $metric): array => [$metric, $count])->values());

        return self::SUCCESS;
    }

    private function writeReport(array $stats): void
    {
        $lines = ['# MATCHING_INTEGRITY_AUDIT', '', 'Дата проверки: '.now()->toDateString(), '', '| Метрика | Количество |', '| --- | ---: |'];

        foreach ($stats as $metric => $count) {
            $lines[] = '| '.$metric.' | '.$count.' |';
        }

        $lines[] = '';
        $lines[] = 'Данные не изменялись. Проверка использует только текущие товары Laravel и локальный OpenCart SQL dump.';

        File::put(dirname(base_path()).DIRECTORY_SEPARATOR.'MATCHING_INTEGRITY_AUDIT.md', implode(PHP_EOL, $lines).PHP_EOL);
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
