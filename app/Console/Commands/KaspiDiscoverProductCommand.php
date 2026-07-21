<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Kaspi\KaspiProductDiscoveryService;
use Illuminate\Console\Command;

class KaspiDiscoverProductCommand extends Command
{
    protected $signature = 'kaspi:discover-product {product_id} {--dry-run}';

    protected $description = 'Discover a Kaspi product URL by Product SKU.';

    public function handle(KaspiProductDiscoveryService $discovery): int
    {
        $product = Product::query()->findOrFail((int) $this->argument('product_id'));
        $result = $discovery->discover($product, (bool) $this->option('dry-run'));

        $this->table(['Metric', 'Value'], collect($result)->map(fn ($value, $key) => [$key, $value ?? ''])->all());

        return self::SUCCESS;
    }
}
