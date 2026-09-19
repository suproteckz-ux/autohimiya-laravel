<?php

namespace App\Services\Kaspi;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class KaspiStockFeedGenerator
{
    public function __construct(private readonly KaspiStockCalculator $calculator) {}

    /**
     * Generate Kaspi XML stock feed.
     * Uses KaspiStockCalculator in active mode; falls back to products.quantity in observe mode.
     */
    public function generate(): string
    {
        $isActive = config('services.kaspi.orders_mode', 'observe') === 'active';
        $merchantCode = config('services.kaspi.merchant_code');

        $products = Product::query()
            ->whereNotNull('sku')
            ->where('kaspi_available', true)
            ->whereNull('deleted_at')
            ->select(['id', 'sku', 'kaspi_price', 'price', 'quantity'])
            ->get();

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $offers = $dom->createElement('offers');
        $dom->appendChild($offers);

        foreach ($products as $product) {
            if ($isActive) {
                $qty = $this->calculator->calculateAvailable($product);
            } else {
                $qty = max(0, (int) $product->quantity);
            }

            $price = (int) ($product->kaspi_price ?: $product->price);

            if ($price <= 0) {
                continue;
            }

            $offer = $dom->createElement('offer');
            $offer->setAttribute('sku', htmlspecialchars((string) $product->sku, ENT_XML1, 'UTF-8'));
            $offer->setAttribute('availableCount', (string) $qty);

            $priceEl = $dom->createElement('price', (string) $price);
            $offer->appendChild($priceEl);

            $offers->appendChild($offer);
        }

        return $dom->saveXML();
    }
}
