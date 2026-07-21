<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Kaspi\KaspiProductDiscoveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckKaspiProductJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $productId, public bool $dryRun = true)
    {
    }

    public function handle(KaspiProductDiscoveryService $discovery): void
    {
        $product = Product::query()->find($this->productId);

        if ($product) {
            $discovery->discover($product, $this->dryRun);
        }
    }
}
