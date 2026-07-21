<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Kaspi\KaspiProductDiscoveryService;
use Illuminate\Console\Command;

class KaspiDiscoverProductsCommand extends Command
{
    protected $signature = 'kaspi:discover-products {--limit=10} {--dry-run}';

    protected $description = 'Discover Kaspi product URLs by Product SKU.';

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
                $rows[] = [$product->id, $product->sku, $result['url'] ?? '', $result['status'], $result['error'] ?? ''];
            });

        $this->table(['Product ID', 'SKU', 'Found/Planned URL', 'Status', 'Error'], $rows);

        return self::SUCCESS;
    }
}
