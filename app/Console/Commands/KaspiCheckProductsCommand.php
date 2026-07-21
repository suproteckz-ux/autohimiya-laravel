<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Kaspi\KaspiProductDiscoveryService;
use Illuminate\Console\Command;

class KaspiCheckProductsCommand extends Command
{
    protected $signature = 'kaspi:check-products {--limit=10} {--dry-run}';

    protected $description = 'Find public Kaspi product cards by Product SKU.';

    public function handle(KaspiProductDiscoveryService $discovery): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        Product::query()
            ->whereNotNull('sku')
            ->where('sku', '<>', '')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (Product $product) use ($discovery, $dryRun, &$rows): void {
                $result = $discovery->discover($product, $dryRun);
                $rows[] = [$product->id, $product->display_name, $product->sku, $result['status'], $result['url'] ?? '', $result['error'] ?? ''];
            });

        $this->table(['ID', 'Product', 'Product SKU / Kaspi SKU', 'Status', 'URL', 'Error'], $rows);

        return self::SUCCESS;
    }
}
