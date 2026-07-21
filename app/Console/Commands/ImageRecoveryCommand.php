<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\CatalogRecovery\OpenCartCatalogData;
use App\Services\CatalogRecovery\OpenCartImageResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ImageRecoveryCommand extends Command
{
    protected $signature = 'catalog:image-recovery {--dry-run} {--apply}';

    protected $description = 'Recover missing product images from OpenCart image paths.';

    public function handle(OpenCartCatalogData $openCart): int
    {
        if ($this->option('dry-run') === $this->option('apply')) {
            $this->error('Run exactly one mode: --dry-run or --apply.');

            return self::FAILURE;
        }

        $data = $openCart->all();
        $resolver = new OpenCartImageResolver();
        $products = Product::query()
            ->whereNull('primary_image')
            ->whereDoesntHave('images')
            ->whereNotNull('opencart_product_id')
            ->get();

        $imported = [];
        $missing = [];
        $debugRows = [];
        $productsWithOpenCartPaths = 0;

        foreach ($products as $product) {
            $openCartProduct = $data['products'][(int) $product->opencart_product_id] ?? [];
            $paths = [];

            if (filled($openCartProduct['image'] ?? null)) {
                $paths[] = ['path' => $openCartProduct['image'], 'primary' => true, 'sort' => 0, 'id' => null];
            }

            foreach (($data['product_images'][(int) $product->opencart_product_id] ?? collect()) as $imageRow) {
                if (filled($imageRow['image'] ?? null)) {
                    $paths[] = ['path' => $imageRow['image'], 'primary' => false, 'sort' => (int) ($imageRow['sort_order'] ?? 0), 'id' => $imageRow['product_image_id'] ?? null];
                }
            }

            if ($paths !== []) {
                $productsWithOpenCartPaths++;
            }

            $found = false;

            foreach ($paths as $image) {
                $source = $resolver->resolve($image['path']);

                if (! $source) {
                    continue;
                }

                $found = true;
                $target = 'products/opencart/'.$product->id.'/'.basename($source);
                $imported[] = [$product->id, $product->paloma_sku, $image['path'], $target];

                if (! $this->option('apply')) {
                    continue;
                }

                $targetFullPath = storage_path('app/public/'.$target);
                File::ensureDirectoryExists(dirname($targetFullPath));

                if (! is_file($targetFullPath)) {
                    File::copy($source, $targetFullPath);
                }

                ProductImage::query()->updateOrCreate(
                    ['product_id' => $product->id, 'original_path' => $image['path']],
                    [
                        'opencart_image_id' => $image['id'],
                        'path' => $target,
                        'role' => $image['primary'] ? 'primary' : 'gallery',
                        'sort_order' => $image['sort'],
                        'is_primary' => $image['primary'],
                    ],
                );

                if ($image['primary'] || blank($product->primary_image)) {
                    $product->update(['primary_image' => $target]);
                }
            }

            if ($this->option('dry-run') && count($debugRows) < 20) {
                $debugRows[] = [
                    'product_id' => $product->id,
                    'opencart_product_id' => $product->opencart_product_id,
                    'original_paths' => array_column($paths, 'path'),
                    'attempted_paths' => $this->attemptedPaths($resolver, array_column($paths, 'path')),
                    'found' => $found,
                ];
            }

            if (! $found) {
                $missing[] = [$product->id, $product->paloma_sku, $product->display_name, implode('|', array_column($paths, 'path'))];
            }
        }

        $stats = [
            'Products without image before' => $products->count(),
            'Products with OpenCart image path' => $productsWithOpenCartPaths,
            'Images recoverable' => count($imported),
            'Products missing source image' => count($missing),
        ];

        $this->writeReports($stats, $imported, $missing);
        $this->writePathAudit($data, $resolver, $debugRows);
        $this->table(['Metric', 'Count'], collect($stats)->map(fn ($count, string $metric): array => [$metric, $count])->values());

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('Image path debug for first 20 products without photo:');
            foreach ($debugRows as $row) {
                $this->line('Product '.$row['product_id'].' / OpenCart '.$row['opencart_product_id'].' / found: '.($row['found'] ? 'yes' : 'no'));
                $this->line('Original: '.($row['original_paths'] === [] ? '(empty)' : implode(' | ', $row['original_paths'])));
                $this->line('Attempted: '.($row['attempted_paths'] === [] ? '(none)' : implode(' | ', array_slice($row['attempted_paths'], 0, 6))));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function attemptedPaths(OpenCartImageResolver $resolver, array $paths): array
    {
        $attempted = [];

        foreach ($paths as $path) {
            foreach ($resolver->candidates($path) as $candidate) {
                $attempted[] = $candidate;
            }
        }

        return array_values(array_unique($attempted));
    }

    private function writeReports(array $stats, array $imported, array $missing): void
    {
        $root = dirname(base_path());
        $lines = ['# IMAGE_RECOVERY_REPORT', '', 'Дата проверки: 2026-06-17', '', '| Метрика | Количество |', '| --- | ---: |'];

        foreach ($stats as $metric => $count) {
            $lines[] = '| '.$metric.' | '.$count.' |';
        }

        File::put($root.DIRECTORY_SEPARATOR.'IMAGE_RECOVERY_REPORT.md', implode(PHP_EOL, $lines).PHP_EOL);
        $this->writeCsv($root.DIRECTORY_SEPARATOR.'IMAGE_RECOVERY_IMPORTED.csv', [['product_id', 'paloma_sku', 'original_path', 'target_path'], ...$imported]);
        $this->writeCsv($root.DIRECTORY_SEPARATOR.'IMAGE_RECOVERY_MISSING.csv', [['product_id', 'paloma_sku', 'product_name', 'opencart_paths'], ...$missing]);
    }

    private function writePathAudit(array $data, OpenCartImageResolver $resolver, array $debugRows): void
    {
        $root = dirname(base_path());
        $productImages = [];

        foreach ($data['product_images'] as $rows) {
            foreach ($rows as $row) {
                if (filled($row['image'] ?? null)) {
                    $productImages[] = $row['image'];
                }
            }
        }

        $lines = [
            '# IMAGE_PATH_AUDIT',
            '',
            'Дата проверки: 2026-06-17',
            '',
            'OpenCart project root: `'.config('services.opencart.project_root').'`',
            '',
            '## Первые 50 oc_product.image',
            '',
        ];

        foreach (array_slice(array_values(array_filter(array_column($data['products'], 'image'))), 0, 50) as $index => $path) {
            $lines[] = ($index + 1).'. `'.$path.'`';
        }

        $lines[] = '';
        $lines[] = '## Первые 50 oc_product_image.image';
        $lines[] = '';

        foreach (array_slice($productImages, 0, 50) as $index => $path) {
            $lines[] = ($index + 1).'. `'.$path.'`';
        }

        $lines[] = '';
        $lines[] = '## Проверка resolver на первых SQL paths';
        $lines[] = '';

        foreach (array_slice(array_values(array_filter(array_column($data['products'], 'image'))), 0, 10) as $path) {
            $lines[] = '### `'.$path.'`';
            foreach ($resolver->candidates($path) as $candidate) {
                $lines[] = '- '.(is_file($candidate) ? '[found] ' : '[missing] ').'`'.$candidate.'`';
            }
            $lines[] = '';
        }

        $lines[] = '## Debug первых 20 товаров без фото';
        $lines[] = '';

        foreach ($debugRows as $row) {
            $lines[] = '### Product '.$row['product_id'].' / OpenCart '.$row['opencart_product_id'];
            $lines[] = '- Original image path from SQL: '.($row['original_paths'] === [] ? '(empty)' : '`'.implode('`, `', $row['original_paths']).'`');
            $lines[] = '- Found: '.($row['found'] ? 'yes' : 'no');
            $lines[] = '- Attempted full paths:';

            if ($row['attempted_paths'] === []) {
                $lines[] = '  - (none)';
            } else {
                foreach ($row['attempted_paths'] as $attemptedPath) {
                    $lines[] = '  - '.(is_file($attemptedPath) ? '[found] ' : '[missing] ').'`'.$attemptedPath.'`';
                }
            }

            $lines[] = '';
        }

        File::put($root.DIRECTORY_SEPARATOR.'IMAGE_PATH_AUDIT.md', implode(PHP_EOL, $lines).PHP_EOL);
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
