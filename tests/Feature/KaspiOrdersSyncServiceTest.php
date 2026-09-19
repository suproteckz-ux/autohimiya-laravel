<?php

namespace Tests\Feature;

use App\Enums\KaspiOrderInternalStatus;
use App\Models\KaspiOrder;
use App\Models\KaspiOrderItem;
use App\Models\Product;
use App\Services\Kaspi\KaspiHandoffDetector;
use App\Services\Kaspi\KaspiOrdersClient;
use App\Services\Kaspi\KaspiOrdersSyncService;
use App\Support\ProductStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KaspiOrdersSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.kaspi.partner_api_token' => 'test-token-sync']);
        config(['services.kaspi.partner_api_url' => 'https://kaspi.kz/shop/api/v2']);
        config(['services.kaspi.orders_mode' => 'observe']);
        config(['services.kaspi.handoff_by_courier_date' => false]);
    }

    // ─── TEST G: offer.code matches products.sku ──────────────────────────────

    public function test_offer_code_matches_product_sku(): void
    {
        $product = $this->makeProduct('aut_1392');

        $this->fakeOrderWithEntry('kaspi-order-g', [
            'attributes' => [
                'offer' => ['code' => 'aut_1392', 'name' => 'Some product'],
                'quantity' => 1,
            ],
        ]);

        $service = app(KaspiOrdersSyncService::class);
        $result = $service->sync(['dry_run' => false]);

        $this->assertSame(1, $result['sku_matched']);
        $this->assertSame(0, $result['sku_unmatched']);

        $item = KaspiOrderItem::where('merchant_sku', 'aut_1392')->first();
        $this->assertNotNull($item);
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame('matched', $item->sku_match_status);
    }

    // ─── TEST H: fallback to products.kaspi_merchant_sku ──────────────────────

    public function test_offer_code_falls_back_to_kaspi_merchant_sku(): void
    {
        $product = $this->makeProduct('internal_sku', kaspiMerchantSku: 'aut_alias_99');

        $this->fakeOrderWithEntry('kaspi-order-h', [
            'attributes' => [
                'offer' => ['code' => 'aut_alias_99', 'name' => 'Alias product'],
                'quantity' => 2,
            ],
        ]);

        $service = app(KaspiOrdersSyncService::class);
        $result = $service->sync(['dry_run' => false]);

        $this->assertSame(1, $result['sku_matched']);

        $item = KaspiOrderItem::where('merchant_sku', 'aut_alias_99')->first();
        $this->assertNotNull($item);
        $this->assertSame($product->id, $item->product_id);
    }

    // ─── TEST I: quantity persisted correctly ─────────────────────────────────

    public function test_entry_quantity_persisted_correctly(): void
    {
        $this->makeProduct('aut_qty_test');

        $this->fakeOrderWithEntry('kaspi-order-i', [
            'attributes' => [
                'offer' => ['code' => 'aut_qty_test'],
                'quantity' => 3,
            ],
        ]);

        app(KaspiOrdersSyncService::class)->sync(['dry_run' => false]);

        $item = KaspiOrderItem::where('merchant_sku', 'aut_qty_test')->first();
        $this->assertNotNull($item);
        $this->assertSame(3, $item->qty);
    }

    // ─── TEST J: dry-run writes nothing ───────────────────────────────────────

    public function test_dry_run_writes_nothing_to_database(): void
    {
        $this->makeProduct('aut_dry');

        $this->fakeOrderWithEntry('kaspi-order-j', [
            'attributes' => [
                'offer' => ['code' => 'aut_dry'],
                'quantity' => 1,
            ],
        ]);

        $result = app(KaspiOrdersSyncService::class)->sync(['dry_run' => true]);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, KaspiOrder::count());
        $this->assertSame(0, KaspiOrderItem::count());
        // But matching stats are calculated
        $this->assertSame(1, $result['sku_matched']);
    }

    // ─── TEST K: repeat sync is idempotent ────────────────────────────────────

    public function test_repeat_sync_does_not_duplicate_orders_or_items(): void
    {
        $this->makeProduct('aut_idem');

        $fakeStubs = [
            'kaspi.kz/shop/api/v2/orders/kaspi-order-k/entries' => Http::response([
                'data' => [['id' => 'entry-k-1', 'attributes' => ['offer' => ['code' => 'aut_idem'], 'quantity' => 1]]],
            ], 200),
            'kaspi.kz/shop/api/v2/orders*' => Http::response([
                'data' => [['id' => 'kaspi-order-k', 'attributes' => $this->orderAttrs()]],
                'meta' => ['pageCount' => 1, 'totalCount' => 1],
            ], 200),
        ];

        $service = app(KaspiOrdersSyncService::class);

        Http::fake($fakeStubs);
        $service->sync(['dry_run' => false]);

        Http::fake($fakeStubs);
        $service->sync(['dry_run' => false]);

        $this->assertSame(1, KaspiOrder::count());
        $this->assertSame(1, KaspiOrderItem::where('kaspi_entry_id', 'entry-k-1')->count());
    }

    // ─── HANDOFF RULE TESTS (L-Q) ─────────────────────────────────────────────

    // TEST L: courierTransmissionDate = null → NOT handed off

    public function test_null_courier_transmission_date_is_not_handoff_candidate(): void
    {
        $order = $this->dbOrder('KASPI_DELIVERY', courierTransmissionDate: null);

        $detector = app(KaspiHandoffDetector::class);
        $this->assertFalse($detector->isHandoffCandidate($order));
    }

    // TEST M: courierTransmissionDate != null + flag=false → candidate only

    public function test_courier_transmission_date_with_flag_off_is_candidate_only(): void
    {
        config(['services.kaspi.handoff_by_courier_date' => false]);

        $order = $this->dbOrder('KASPI_DELIVERY', courierTransmissionDate: now());

        $detector = app(KaspiHandoffDetector::class);
        $this->assertTrue($detector->isHandoffCandidate($order));
        $this->assertFalse($detector->shouldApplyHandoff($order));
    }

    // TEST N: courierTransmissionDate != null + flag=true → handoff recognized

    public function test_courier_transmission_date_with_flag_on_triggers_handoff(): void
    {
        config(['services.kaspi.handoff_by_courier_date' => true]);

        $order = $this->dbOrder('KASPI_DELIVERY', courierTransmissionDate: now());

        $detector = app(KaspiHandoffDetector::class);
        $this->assertTrue($detector->isHandoffCandidate($order));
        $this->assertTrue($detector->shouldApplyHandoff($order));
        $this->assertNotNull($detector->resolveHandoffAt($order));
    }

    // TEST O: assembled=true but courierTransmissionDate=null → NOT handed off

    public function test_assembled_without_courier_date_is_not_handoff(): void
    {
        $order = $this->dbOrder(
            'KASPI_DELIVERY',
            kaspiStatus: 'ASSEMBLE',
            courierTransmissionDate: null
        );

        $detector = app(KaspiHandoffDetector::class);
        $this->assertFalse($detector->isHandoffCandidate($order));
    }

    // TEST P: waybill exists but courierTransmissionDate=null → NOT handed off

    public function test_waybill_without_courier_date_is_not_handoff(): void
    {
        $order = $this->dbOrder(
            'KASPI_DELIVERY',
            courierTransmissionDate: null,
            waybill: 'https://kaspi.kz/waybill/123'
        );

        $detector = app(KaspiHandoffDetector::class);
        $this->assertFalse($detector->isHandoffCandidate($order));
    }

    // TEST Q: planningDate exists but courierTransmissionDate=null → NOT handed off

    public function test_planning_date_without_courier_date_is_not_handoff(): void
    {
        $order = $this->dbOrder(
            'KASPI_DELIVERY',
            courierTransmissionDate: null,
            courierTransmissionPlanningDate: now()->addDay()
        );

        $detector = app(KaspiHandoffDetector::class);
        $this->assertFalse($detector->isHandoffCandidate($order));
    }

    // ─── TEST: kaspiDelivery nested payload is parsed correctly ───────────────

    public function test_courier_transmission_date_read_from_kaspi_delivery_nested(): void
    {
        $this->makeProduct('aut_nested');

        $handoffMs = (int) (strtotime('2026-09-10 10:00:00') * 1000);
        $planMs    = (int) (strtotime('2026-09-09 10:00:00') * 1000);

        Http::fake([
            'kaspi.kz/shop/api/v2/orders/kaspi-order-nested/entries' => Http::response([
                'data' => [['id' => 'entry-n', 'attributes' => ['offer' => ['code' => 'aut_nested'], 'quantity' => 1]]],
            ], 200),
            'kaspi.kz/shop/api/v2/orders*' => Http::response([
                'data' => [[
                    'id' => 'kaspi-order-nested',
                    'attributes' => array_merge($this->orderAttrs(), [
                        'kaspiDelivery' => [
                            'courierTransmissionDate'         => $handoffMs,
                            'courierTransmissionPlanningDate' => $planMs,
                            'waybill'       => 'https://kaspi.kz/waybill/abc',
                            'waybillNumber' => 'WB-001',
                        ],
                    ]),
                ]],
                'meta' => ['pageCount' => 1, 'totalCount' => 1],
            ], 200),
        ]);

        app(KaspiOrdersSyncService::class)->sync(['dry_run' => false]);

        $order = KaspiOrder::where('kaspi_order_id', 'kaspi-order-nested')->first();
        $this->assertNotNull($order);
        $this->assertNotNull($order->courier_transmission_date);
        $this->assertNotNull($order->courier_transmission_planning_date);
        $this->assertSame('WB-001', $order->waybill_number);
        $this->assertSame('https://kaspi.kz/waybill/abc', $order->waybill);
    }

    // ─── HELPERS ─────────────────────────────────────────────────────────────

    private function makeProduct(string $sku, ?string $kaspiMerchantSku = null): Product
    {
        return Product::create([
            'name'           => 'Product ' . $sku,
            'slug'           => 'product-' . $sku,
            'sku'            => $sku,
            'kaspi_merchant_sku' => $kaspiMerchantSku,
            'price'          => 1000,
            'quantity'       => 10,
            'stock_quantity' => 10,
            'availability'   => true,
            'availability_status' => 'in_stock',
            'product_status' => ProductStatus::ACTIVE_SYNCED,
            'sync_status'    => 'matched',
        ]);
    }

    private function orderAttrs(): array
    {
        return [
            'code'          => 'ORDER-TEST',
            'status'        => 'ACCEPTED_BY_MERCHANT',
            'state'         => 'KASPI_DELIVERY',
            'creationDate'  => (int) (strtotime('2026-09-01') * 1000),
            'deliveryMode'  => 'DELIVERY_MODE_KASPI',
            'kaspiDelivery' => [],
        ];
    }

    private function fakeOrderWithEntry(string $orderId, array $entryAttrs): void
    {
        // Specific entries pattern MUST come before the wildcard orders pattern
        // so it is matched first — orders* would otherwise consume the entries URL
        Http::fake([
            "kaspi.kz/shop/api/v2/orders/{$orderId}/entries" => Http::response([
                'data' => [array_merge(['id' => 'entry-' . $orderId], $entryAttrs)],
            ], 200),
            'kaspi.kz/shop/api/v2/orders*' => Http::response([
                'data' => [['id' => $orderId, 'attributes' => $this->orderAttrs()]],
                'meta' => ['pageCount' => 1, 'totalCount' => 1],
            ], 200),
        ]);
    }

    private function dbOrder(
        string $state,
        ?string $kaspiStatus = 'ACCEPTED_BY_MERCHANT',
        ?\Carbon\Carbon $courierTransmissionDate = null,
        ?\Carbon\Carbon $courierTransmissionPlanningDate = null,
        ?string $waybill = null,
    ): KaspiOrder {
        static $seq = 0;
        $seq++;

        return KaspiOrder::create([
            'kaspi_order_id'                    => 'db-order-' . $seq,
            'kaspi_code'                        => 'DB-ORDER-' . $seq,
            'kaspi_status'                      => $kaspiStatus,
            'kaspi_state'                       => $state,
            'internal_stock_status'             => KaspiOrderInternalStatus::Observed->value,
            'baseline_ignored'                  => false,
            'courier_transmission_date'         => $courierTransmissionDate,
            'courier_transmission_planning_date' => $courierTransmissionPlanningDate,
            'waybill'                           => $waybill,
            'last_synced_at'                    => now(),
        ]);
    }
}
