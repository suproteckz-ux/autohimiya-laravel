<?php

namespace Tests\Feature;

use App\Enums\KaspiOrderInternalStatus;
use App\Models\KaspiOrder;
use App\Models\KaspiOrderItem;
use App\Models\Product;
use App\Models\SyncLog;
use App\Services\Kaspi\KaspiHandoffDetector;
use App\Services\Kaspi\KaspiReservationEngine;
use App\Services\Kaspi\KaspiStockCalculator;
use App\Services\Kaspi\PalomaStockConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KaspiOrdersReservationTest extends TestCase
{
    use RefreshDatabase;

    // ─── OBSERVE MODE ──────────────────────────────────────────────────────────

    public function test_observe_mode_does_not_change_product_quantity(): void
    {
        config(['services.kaspi.orders_mode' => 'observe']);

        $product = Product::factory()->create(['quantity' => 5, 'sku' => 'aut_001']);
        $order = $this->makeOrder(KaspiOrderInternalStatus::Observed);
        $this->makeItem($order, $product, qty: 2);

        $engine = app(KaspiReservationEngine::class);
        $engine->reserve($order);

        // In observe mode, reserve() is a no-op
        $order->refresh();
        $this->assertEquals(KaspiOrderInternalStatus::Observed, $order->internal_stock_status);
        $this->assertEquals(5, $product->fresh()->quantity);
    }

    // ─── BASELINE ──────────────────────────────────────────────────────────────

    public function test_baseline_order_has_zero_stock_impact(): void
    {
        $product = Product::factory()->create(['quantity' => 10, 'sku' => 'aut_002']);
        $order = $this->makeOrder(KaspiOrderInternalStatus::BaselineIgnored, baselineIgnored: true);
        $this->makeItem($order, $product, qty: 3);

        $calculator = app(KaspiStockCalculator::class);
        $snapshot = $calculator->snapshot($product);

        $this->assertEquals(0, $snapshot['active_reservations']);
        $this->assertEquals(0, $snapshot['pending_paloma']);
        $this->assertEquals(10, $snapshot['kaspi_available']);
    }

    // ─── RESERVE AFTER CUTOVER ─────────────────────────────────────────────────

    public function test_new_order_after_cutover_gets_reserved(): void
    {
        config(['services.kaspi.orders_mode' => 'active']);

        $product = Product::factory()->create(['quantity' => 5, 'sku' => 'aut_003']);
        $order = $this->makeOrder(KaspiOrderInternalStatus::Observed);
        $this->makeItem($order, $product, qty: 2);

        $engine = app(KaspiReservationEngine::class);
        $engine->reserve($order);

        $order->refresh();
        $this->assertEquals(KaspiOrderInternalStatus::Reserved, $order->internal_stock_status);
    }

    // ─── STOCK CALCULATOR ──────────────────────────────────────────────────────

    public function test_stock_calculator_subtracts_active_reservations(): void
    {
        config(['services.kaspi.orders_mode' => 'active']);

        $product = Product::factory()->create(['quantity' => 10, 'sku' => 'aut_004']);
        $order = $this->makeOrder(KaspiOrderInternalStatus::Reserved);
        $this->makeItem($order, $product, qty: 3);

        $calculator = app(KaspiStockCalculator::class);
        $snapshot = $calculator->snapshot($product);

        $this->assertEquals(3, $snapshot['active_reservations']);
        $this->assertEquals(0, $snapshot['pending_paloma']);
        $this->assertEquals(7, $snapshot['kaspi_available']);
    }

    public function test_stock_calculator_subtracts_pending_paloma(): void
    {
        $product = Product::factory()->create(['quantity' => 10, 'sku' => 'aut_005']);
        $order = $this->makeOrder(KaspiOrderInternalStatus::HandedOffPendingPaloma);
        $this->makeItem($order, $product, qty: 4);

        $calculator = app(KaspiStockCalculator::class);
        $snapshot = $calculator->snapshot($product);

        $this->assertEquals(0, $snapshot['active_reservations']);
        $this->assertEquals(4, $snapshot['pending_paloma']);
        $this->assertEquals(6, $snapshot['kaspi_available']);
    }

    public function test_stock_calculator_floors_at_zero(): void
    {
        $product = Product::factory()->create(['quantity' => 1, 'sku' => 'aut_006']);
        $order = $this->makeOrder(KaspiOrderInternalStatus::Reserved);
        $this->makeItem($order, $product, qty: 5);

        $calculator = app(KaspiStockCalculator::class);
        $available = $calculator->calculateAvailable($product);

        $this->assertEquals(0, $available);
    }

    // ─── CANCEL BEFORE HANDOFF ─────────────────────────────────────────────────

    public function test_cancel_before_handoff_releases_reserve(): void
    {
        config(['services.kaspi.orders_mode' => 'active']);

        $product = Product::factory()->create(['quantity' => 5, 'sku' => 'aut_007']);
        $order = $this->makeOrder(KaspiOrderInternalStatus::Reserved);
        $this->makeItem($order, $product, qty: 2);

        $engine = app(KaspiReservationEngine::class);
        $engine->cancelBeforeHandoff($order);

        $order->refresh();
        $this->assertEquals(KaspiOrderInternalStatus::CancelledBeforeHandoff, $order->internal_stock_status);

        $calculator = app(KaspiStockCalculator::class);
        $this->assertEquals(0, $calculator->snapshot($product)['active_reservations']);
        $this->assertEquals(5, $calculator->calculateAvailable($product));
    }

    // ─── ASSEMBLE STAYS RESERVED ───────────────────────────────────────────────

    public function test_assemble_status_does_not_change_reserve(): void
    {
        config(['services.kaspi.orders_mode' => 'active']);

        $product = Product::factory()->create(['quantity' => 5, 'sku' => 'aut_008']);
        $order = $this->makeOrder(KaspiOrderInternalStatus::Reserved, kaspiStatus: 'ASSEMBLE');
        $this->makeItem($order, $product, qty: 2);

        // ASSEMBLE should not trigger handoff
        $detector = app(KaspiHandoffDetector::class);
        $this->assertFalse($detector->isHandoffCandidate($order));

        $calculator = app(KaspiStockCalculator::class);
        $this->assertEquals(2, $calculator->snapshot($product)['active_reservations']);
    }

    // ─── HANDOFF CANDIDATE (flag off) ──────────────────────────────────────────

    public function test_handoff_candidate_detected_but_not_applied_when_flag_off(): void
    {
        config(['services.kaspi.orders_mode' => 'active']);
        config(['services.kaspi.handoff_by_courier_date' => false]);

        $product = Product::factory()->create(['quantity' => 5, 'sku' => 'aut_009']);
        $order = $this->makeOrder(
            KaspiOrderInternalStatus::Reserved,
            kaspiState: 'KASPI_DELIVERY',
            courierTransmissionDate: now(),
        );
        $this->makeItem($order, $product, qty: 2);

        $engine = app(KaspiReservationEngine::class);
        $detector = app(KaspiHandoffDetector::class);

        $this->assertTrue($detector->isHandoffCandidate($order));
        $this->assertFalse($detector->shouldApplyHandoff($order));

        $engine->markHandoffCandidate($order);
        $order->refresh();
        $this->assertEquals(KaspiOrderInternalStatus::HandoffCandidate, $order->internal_stock_status);

        // Still counts as reservation? No — HandoffCandidate does NOT count as active reserve
        $calculator = app(KaspiStockCalculator::class);
        $this->assertEquals(0, $calculator->snapshot($product)['active_reservations']);
    }

    // ─── HANDOFF APPLIED (flag on) ─────────────────────────────────────────────

    public function test_handoff_applied_when_flag_enabled(): void
    {
        config(['services.kaspi.orders_mode' => 'active']);
        config(['services.kaspi.handoff_by_courier_date' => true]);

        $product = Product::factory()->create(['quantity' => 5, 'sku' => 'aut_010']);
        $order = $this->makeOrder(
            KaspiOrderInternalStatus::Reserved,
            kaspiState: 'KASPI_DELIVERY',
            courierTransmissionDate: now(),
        );
        $this->makeItem($order, $product, qty: 2);

        $engine = app(KaspiReservationEngine::class);
        $detector = app(KaspiHandoffDetector::class);

        $this->assertTrue($detector->shouldApplyHandoff($order));
        $handoffAt = $detector->resolveHandoffAt($order);

        $engine->applyHandoff($order, $handoffAt);
        $order->refresh();

        $this->assertEquals(KaspiOrderInternalStatus::HandedOffPendingPaloma, $order->internal_stock_status);
        $this->assertNotNull($order->handoff_at);

        $calculator = app(KaspiStockCalculator::class);
        $this->assertEquals(0, $calculator->snapshot($product)['active_reservations']);
        $this->assertEquals(2, $calculator->snapshot($product)['pending_paloma']);
        $this->assertEquals(3, $calculator->calculateAvailable($product));
    }

    // ─── CANCEL AFTER HANDOFF ──────────────────────────────────────────────────

    public function test_cancel_after_handoff_does_not_return_stock(): void
    {
        $product = Product::factory()->create(['quantity' => 5, 'sku' => 'aut_011']);
        $order = $this->makeOrder(KaspiOrderInternalStatus::HandedOffPendingPaloma, handoffAt: now());
        $this->makeItem($order, $product, qty: 2);

        $engine = app(KaspiReservationEngine::class);
        $engine->cancelAfterHandoff($order);

        $order->refresh();
        $this->assertEquals(KaspiOrderInternalStatus::CancelledAfterHandoff, $order->internal_stock_status);

        // Pending was under HANDED_OFF; now order is CANCELLED_AFTER_HANDOFF — not counted
        $calculator = app(KaspiStockCalculator::class);
        $this->assertEquals(0, $calculator->snapshot($product)['pending_paloma']);
        // But stock is STILL not returned — paloma stock is unchanged
        $this->assertEquals(5, $product->fresh()->quantity);
    }

    // ─── PENDING WITHOUT TTL ───────────────────────────────────────────────────

    public function test_pending_paloma_persists_without_ttl(): void
    {
        $product = Product::factory()->create(['quantity' => 5, 'sku' => 'aut_012']);
        $order = $this->makeOrder(
            KaspiOrderInternalStatus::HandedOffPendingPaloma,
            handoffAt: now()->subDays(3),
        );
        $this->makeItem($order, $product, qty: 2);

        $calculator = app(KaspiStockCalculator::class);
        $this->assertEquals(2, $calculator->snapshot($product)['pending_paloma']);
        $this->assertEquals(3, $calculator->calculateAvailable($product));
    }

    // ─── MULTIPLE ORDERS SAME SKU ──────────────────────────────────────────────

    public function test_multiple_orders_same_sku_sum_reserves(): void
    {
        config(['services.kaspi.orders_mode' => 'active']);

        $product = Product::factory()->create(['quantity' => 10, 'sku' => 'aut_013']);

        $orderA = $this->makeOrder(KaspiOrderInternalStatus::Reserved);
        $this->makeItem($orderA, $product, qty: 2);

        $orderB = $this->makeOrder(KaspiOrderInternalStatus::Reserved);
        $this->makeItem($orderB, $product, qty: 3);

        $calculator = app(KaspiStockCalculator::class);
        $this->assertEquals(5, $calculator->snapshot($product)['active_reservations']);
        $this->assertEquals(5, $calculator->calculateAvailable($product));
    }

    // ─── DUPLICATE SYNC / IDEMPOTENCY ──────────────────────────────────────────

    public function test_engine_reserve_is_idempotent(): void
    {
        config(['services.kaspi.orders_mode' => 'active']);

        $product = Product::factory()->create(['quantity' => 5, 'sku' => 'aut_014']);
        $order = $this->makeOrder(KaspiOrderInternalStatus::Observed);
        $this->makeItem($order, $product, qty: 2);

        $engine = app(KaspiReservationEngine::class);
        $engine->reserve($order);
        $engine->reserve($order); // second call — should not double-count

        $calculator = app(KaspiStockCalculator::class);
        $this->assertEquals(2, $calculator->snapshot($product)['active_reservations']);
    }

    // ─── PALOMA CONFIRMATION ───────────────────────────────────────────────────

    public function test_paloma_confirmation_clears_pending(): void
    {
        $user = \App\Models\User::factory()->create();

        // Create a fresh Paloma sync log
        SyncLog::create([
            'source' => 'paloma',
            'mode' => 'auto',
            'status' => 'success',
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(5),
        ]);

        $product = Product::factory()->create(['quantity' => 5, 'sku' => 'aut_015']);
        $order = $this->makeOrder(KaspiOrderInternalStatus::HandedOffPendingPaloma, handoffAt: now()->subHour());
        $this->makeItem($order, $product, qty: 2);

        $service = app(PalomaStockConfirmationService::class);
        $checkpoint = $service->confirm($user->id);

        $order->refresh();
        $this->assertEquals(KaspiOrderInternalStatus::PalomaConfirmed, $order->internal_stock_status);
        $this->assertEquals(1, $checkpoint->pending_orders_count);

        $calculator = app(KaspiStockCalculator::class);
        $this->assertEquals(0, $calculator->snapshot($product)['pending_paloma']);
    }

    public function test_paloma_confirmation_blocked_without_sync_log(): void
    {
        $this->expectException(\RuntimeException::class);
        $service = app(PalomaStockConfirmationService::class);
        $service->assertCanConfirm();
    }

    // ─── RETURN = NO STOCK ─────────────────────────────────────────────────────

    public function test_return_does_not_auto_add_stock(): void
    {
        $product = Product::factory()->create(['quantity' => 5, 'sku' => 'aut_016']);
        $order = $this->makeOrder(KaspiOrderInternalStatus::CancelledAfterHandoff);
        $this->makeItem($order, $product, qty: 2);

        // Stock should be unchanged — no automatic +qty for returns
        $this->assertEquals(5, $product->fresh()->quantity);
        $calculator = app(KaspiStockCalculator::class);
        $this->assertEquals(0, $calculator->snapshot($product)['pending_paloma']);
    }

    // ─── UNMATCHED SKU ─────────────────────────────────────────────────────────

    public function test_unmatched_sku_item_does_not_affect_stock(): void
    {
        $product = Product::factory()->create(['quantity' => 5, 'sku' => 'aut_017']);
        $order = $this->makeOrder(KaspiOrderInternalStatus::Reserved);
        // Item with no product_id (unmatched)
        KaspiOrderItem::create([
            'kaspi_order_id' => $order->id,
            'kaspi_entry_id' => 'entry-unmatched-001',
            'product_id' => null,
            'merchant_sku' => 'UNKNOWN_SKU',
            'qty' => 3,
            'sku_match_status' => 'unmatched',
        ]);

        $calculator = app(KaspiStockCalculator::class);
        $this->assertEquals(0, $calculator->snapshot($product)['active_reservations']);
        $this->assertEquals(5, $calculator->calculateAvailable($product));
    }

    // ─── HELPERS ───────────────────────────────────────────────────────────────

    private function makeOrder(
        KaspiOrderInternalStatus $status,
        bool $baselineIgnored = false,
        string $kaspiState = 'KASPI_DELIVERY',
        string $kaspiStatus = 'ACCEPTED_BY_MERCHANT',
        ?\Carbon\Carbon $courierTransmissionDate = null,
        ?\Carbon\Carbon $handoffAt = null,
    ): KaspiOrder {
        static $seq = 0;
        $seq++;

        return KaspiOrder::create([
            'kaspi_order_id' => 'test-order-' . $seq,
            'kaspi_code' => 'ORDER-' . $seq,
            'kaspi_status' => $kaspiStatus,
            'kaspi_state' => $kaspiState,
            'internal_stock_status' => $status->value,
            'baseline_ignored' => $baselineIgnored,
            'courier_transmission_date' => $courierTransmissionDate,
            'handoff_at' => $handoffAt,
            'last_synced_at' => now(),
        ]);
    }

    private function makeItem(KaspiOrder $order, Product $product, int $qty): KaspiOrderItem
    {
        static $itemSeq = 0;
        $itemSeq++;

        return KaspiOrderItem::create([
            'kaspi_order_id' => $order->id,
            'kaspi_entry_id' => 'entry-' . $itemSeq,
            'product_id' => $product->id,
            'merchant_sku' => $product->sku,
            'qty' => $qty,
            'sku_match_status' => 'matched',
        ]);
    }
}
