<?php

namespace App\Services\Ozon;

use App\Models\Product;

class OzonStockCalculator
{
    public function calculate(Product $product, ?int $limit = null): int
    {
        $available = max(0, (int) ($product->quantity ?? 0));
        return $limit === null ? $available : min($available, max(0, $limit));
    }
}
