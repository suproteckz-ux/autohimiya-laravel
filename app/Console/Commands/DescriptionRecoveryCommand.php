<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\CatalogRecovery\OpenCartCatalogData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class DescriptionRecoveryCommand extends Command
{
    protected $signature = 'catalog:description-recovery {--dry-run} {--apply}';

    protected $description = 'Recover missing product descriptions from OpenCart descriptions.';

    public function handle(OpenCartCatalogData $openCart): int
    {
        if ($this->option('dry-run') === $this->option('apply')) {
            $this->error('Run exactly one mode: --dry-run or --apply.');

            return self::FAILURE;
        }

        $data = $openCart->all();
        $products = Product::query()
            ->where(fn ($query) => $query->whereNull('description')->orWhere('description', ''))
            ->whereNotNull('opencart_product_id')
            ->with(['brand', 'category'])
            ->get();

        $recovered = 0;
        $missing = [];
        $draftQueue = [];

        foreach ($products as $product) {
            $description = $data['descriptions'][(int) $product->opencart_product_id] ?? null;
            $text = $description['description'] ?? null;

            if (filled($text)) {
                $recovered++;

                if ($this->option('apply')) {
                    $product->update(['description' => $text]);
                }

                continue;
            }

            $missing[] = [$product->id, $product->paloma_sku, $product->display_name];
            $draftQueue[] = [$product->id, $product->display_name, $product->brand?->display_name, $product->category?->display_name, 'draft_needed'];
        }

        $stats = [
            'Products without description before' => $products->count(),
            'Descriptions recoverable from OpenCart' => $recovered,
            'Draft tasks created' => count($draftQueue),
        ];

        $this->writeReports($stats, $missing, $draftQueue);
        $this->table(['Metric', 'Count'], collect($stats)->map(fn ($count, string $metric): array => [$metric, $count])->values());

        return self::SUCCESS;
    }

    private function writeReports(array $stats, array $missing, array $draftQueue): void
    {
        $root = dirname(base_path());
        $lines = ['# DESCRIPTION_RECOVERY_REPORT', '', 'Дата проверки: 2026-06-17', '', '| Метрика | Количество |', '| --- | ---: |'];

        foreach ($stats as $metric => $count) {
            $lines[] = '| '.$metric.' | '.$count.' |';
        }

        File::put($root.DIRECTORY_SEPARATOR.'DESCRIPTION_RECOVERY_REPORT.md', implode(PHP_EOL, $lines).PHP_EOL);
        $this->writeCsv($root.DIRECTORY_SEPARATOR.'DESCRIPTION_MISSING.csv', [['product_id', 'paloma_sku', 'product_name'], ...$missing]);
        $this->writeCsv($root.DIRECTORY_SEPARATOR.'DESCRIPTION_DRAFT_QUEUE.csv', [['product_id', 'product_name', 'brand', 'category', 'action'], ...$draftQueue]);
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
