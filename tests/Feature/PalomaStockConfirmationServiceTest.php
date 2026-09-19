<?php

namespace Tests\Feature;

use App\Models\KaspiOrder;
use App\Models\SyncLog;
use App\Services\Kaspi\PalomaStockConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class PalomaStockConfirmationServiceTest extends TestCase
{
    use RefreshDatabase;

    // Test A: newer 'warning' sync is returned over older 'success' sync
    public function test_warning_sync_returned_over_older_success_sync(): void
    {
        SyncLog::create([
            'source' => 'paloma',
            'mode' => 'sync-remains',
            'status' => 'success',
            'started_at' => Carbon::now()->subHours(5),
            'finished_at' => Carbon::now()->subHours(5),
        ]);

        $recent = SyncLog::create([
            'source' => 'paloma',
            'mode' => 'sync-remains',
            'status' => 'warning',
            'started_at' => Carbon::now()->subMinutes(30),
            'finished_at' => Carbon::now()->subMinutes(30),
        ]);

        $service = app(PalomaStockConfirmationService::class);
        $found = $service->latestSuccessfulPalomaSyncLog();

        $this->assertNotNull($found);
        $this->assertSame($recent->id, $found->id);
    }

    // Test B: latestSuccessfulPalomaSyncLog uses started_at — same field AutomationStatus shows
    public function test_latest_sync_uses_started_at_matching_automation_status_source(): void
    {
        $expected = Carbon::now()->subMinutes(10);

        SyncLog::create([
            'source' => 'paloma',
            'mode' => 'sync-remains',
            'status' => 'warning',
            'started_at' => $expected,
            'finished_at' => $expected->copy()->addSeconds(30),
        ]);

        $service = app(PalomaStockConfirmationService::class);
        $found = $service->latestSuccessfulPalomaSyncLog();

        $this->assertNotNull($found);
        $this->assertSame(0, (int) abs($found->started_at->diffInSeconds($expected)));
    }

    // Test C: failed syncs are not returned
    public function test_failed_sync_is_ignored(): void
    {
        SyncLog::create([
            'source' => 'paloma',
            'mode' => 'sync-remains',
            'status' => 'failed',
            'started_at' => Carbon::now()->subMinutes(5),
            'finished_at' => Carbon::now()->subMinutes(5),
        ]);

        $service = app(PalomaStockConfirmationService::class);
        $this->assertNull($service->latestSuccessfulPalomaSyncLog());
    }

    // Test D: pending=0 blocks confirmation
    public function test_zero_pending_orders_blocks_confirmation(): void
    {
        SyncLog::create([
            'source' => 'paloma',
            'mode' => 'sync-remains',
            'status' => 'success',
            'started_at' => Carbon::now()->subMinutes(5),
            'finished_at' => Carbon::now()->subMinutes(5),
        ]);

        // No KaspiOrder rows with HandedOffPendingPaloma status → pending = 0
        $service = app(PalomaStockConfirmationService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pending = 0');

        $service->assertCanConfirm();
    }

    // Bonus: stale sync (> 2h) blocks confirmation even with pending orders present
    public function test_stale_sync_blocks_confirmation_when_pending_orders_exist(): void
    {
        SyncLog::create([
            'source' => 'paloma',
            'mode' => 'sync-remains',
            'status' => 'success',
            'started_at' => Carbon::now()->subHours(3),
            'finished_at' => Carbon::now()->subHours(3),
        ]);

        KaspiOrder::create([
            'kaspi_order_id' => 'order-stale-001',
            'kaspi_code' => 'stale-001',
            'kaspi_state' => 'ARCHIVE',
            'kaspi_status' => 'COMPLETED',
            'internal_stock_status' => 'HANDED_OFF_PENDING_PALOMA',
            'baseline_ignored' => false,
            'handoff_at' => Carbon::now()->subHours(4),
        ]);

        $service = app(PalomaStockConfirmationService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('устарел');

        $service->assertCanConfirm();
    }
}
