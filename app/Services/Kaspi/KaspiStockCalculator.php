<?php

namespace App\Services\Kaspi;

use App\Enums\KaspiOrderInternalStatus;
use App\Models\KaspiOrderItem;
use App\Models\Product;

class KaspiStockCalculator
{
    /**
     * Calculate available stock for a product considering active reservations and pending Paloma writeoffs.
     *
     * Formula: max(0, paloma_stock - active_reservations - pending_paloma_writeoffs)
     */
    public function calculateAvailable(Product $product): int
    {
        $palomaStock = (int) $product->quantity;
        $activeReserved = $this->sumQty($product->id, KaspiOrderInternalStatus::Reserved);
        $pendingPaloma = $this->sumQty($product->id, KaspiOrderInternalStatus::HandedOffPendingPaloma);

        return max(0, $palomaStock - $activeReserved - $pendingPaloma);
    }

    /**
     * Return a diagnostic snapshot for a product.
     */
    public function snapshot(Product $product): array
    {
        $palomaStock = (int) $product->quantity;
        $activeReserved = $this->sumQty($product->id, KaspiOrderInternalStatus::Reserved);
        $pendingPaloma = $this->sumQty($product->id, KaspiOrderInternalStatus::HandedOffPendingPaloma);
        $available = max(0, $palomaStock - $activeReserved - $pendingPaloma);

        return [
            'sku' => $product->sku,
            'paloma_stock' => $palomaStock,
            'active_reservations' => $activeReserved,
            'pending_paloma' => $pendingPaloma,
            'kaspi_available' => $available,
        ];
    }

    /**
     * Sum qty of items belonging to orders with a given internal status.
     * Only counts non-baseline-ignored orders with matched SKUs.
     */
    private function sumQty(int $productId, KaspiOrderInternalStatus $status): int
    {
        return (int) KaspiOrderItem::query()
            ->where('product_id', $productId)
            ->where('sku_match_status', 'matched')
            ->whereHas('kaspiOrder', function ($q) use ($status) {
                $q->where('internal_stock_status', $status->value)
                    ->where('baseline_ignored', false);
            })
            ->sum('qty');
    }
}
