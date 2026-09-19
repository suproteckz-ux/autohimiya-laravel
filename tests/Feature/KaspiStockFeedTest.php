<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\KaspiOrder;
use App\Models\KaspiOrderItem;
use App\Enums\KaspiOrderInternalStatus;
use App\Services\Kaspi\KaspiStockFeedGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KaspiStockFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_returns_valid_xml(): void
    {
        config(['services.kaspi.orders_mode' => 'observe']);

        $xml = app(KaspiStockFeedGenerator::class)->generate();

        $this->assertNotEmpty($xml);
        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc, 'Feed must be valid XML');
    }

    public function test_feed_uses_paloma_quantity_in_observe_mode(): void
    {
        config(['services.kaspi.orders_mode' => 'observe']);

        Product::factory()->create([
            'sku' => 'aut_feed_001',
            'quantity' => 7,
            'kaspi_available' => true,
            'kaspi_price' => 5000,
        ]);

        $xml = app(KaspiStockFeedGenerator::class)->generate();
        $doc = simplexml_load_string($xml);

        $matches = $doc->xpath('//offer[@sku="aut_feed_001"]');

        // In observe mode the offer must appear in the feed
        $this->assertNotEmpty($matches);
    }

    public function test_feed_uses_calculated_stock_in_active_mode(): void
    {
        config(['services.kaspi.orders_mode' => 'active']);

        $product = Product::factory()->create([
            'sku' => 'aut_feed_002',
            'quantity' => 10,
            'kaspi_available' => true,
            'kaspi_price' => 3000,
        ]);

        // Add a reservation
        $order = KaspiOrder::create([
            'kaspi_order_id' => 'feed-order-1',
            'kaspi_code' => 'FEED-001',
            'internal_stock_status' => KaspiOrderInternalStatus::Reserved->value,
            'baseline_ignored' => false,
            'last_synced_at' => now(),
        ]);

        KaspiOrderItem::create([
            'kaspi_order_id' => $order->id,
            'kaspi_entry_id' => 'feed-entry-1',
            'product_id' => $product->id,
            'merchant_sku' => 'aut_feed_002',
            'qty' => 3,
            'sku_match_status' => 'matched',
        ]);

        $xml = app(KaspiStockFeedGenerator::class)->generate();
        $doc = simplexml_load_string($xml);

        $offers = [];
        foreach ($doc->offer as $offer) {
            $offers[(string) $offer['sku']] = (int) $offer['availableCount'];
        }

        $this->assertEquals(7, $offers['aut_feed_002'] ?? null);
    }

    public function test_feed_floor_is_zero(): void
    {
        config(['services.kaspi.orders_mode' => 'active']);

        $product = Product::factory()->create([
            'sku' => 'aut_feed_003',
            'quantity' => 1,
            'kaspi_available' => true,
            'kaspi_price' => 2000,
        ]);

        $order = KaspiOrder::create([
            'kaspi_order_id' => 'feed-order-2',
            'kaspi_code' => 'FEED-002',
            'internal_stock_status' => KaspiOrderInternalStatus::Reserved->value,
            'baseline_ignored' => false,
            'last_synced_at' => now(),
        ]);

        KaspiOrderItem::create([
            'kaspi_order_id' => $order->id,
            'kaspi_entry_id' => 'feed-entry-2',
            'product_id' => $product->id,
            'merchant_sku' => 'aut_feed_003',
            'qty' => 5,
            'sku_match_status' => 'matched',
        ]);

        $xml = app(KaspiStockFeedGenerator::class)->generate();
        $doc = simplexml_load_string($xml);

        $offers = [];
        foreach ($doc->offer as $offer) {
            $offers[(string) $offer['sku']] = (int) $offer['availableCount'];
        }

        $this->assertEquals(0, $offers['aut_feed_003'] ?? null);
    }

    public function test_feed_route_is_accessible(): void
    {
        config(['services.kaspi.orders_mode' => 'observe']);

        $response = $this->get(route('kaspi.stock.feed'));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    }
}
