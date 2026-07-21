<?php

namespace App\Services\Kaspi;

use App\Models\Product;

class KaspiClient
{
    public function __construct(private readonly KaspiProductDiscoveryService $discovery)
    {
    }

    public function checkProduct(Product $product, bool $dryRun = true): array
    {
        return $this->discovery->discover($product, $dryRun);
    }
}
