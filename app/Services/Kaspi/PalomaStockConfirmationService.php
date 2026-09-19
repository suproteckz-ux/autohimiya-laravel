<?php

namespace App\Services\Kaspi;

use App\Enums\KaspiOrderInternalStatus;
use App\Models\KaspiOrder;
use App\Models\KaspiStockEventLog;
use App\Models\PalomaStockConfirmation;
use App\Models\SyncLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PalomaStockConfirmationService
{
    /**
     * Check whether confirmation is safe to perform.
     * Throws RuntimeException with a human-readable reason if not safe.
     */
    public function assertCanConfirm(): void
    {
        $stats = $this->pendingStats();
        if ($stats['orders_count'] === 0) {
            throw new RuntimeException('Нет заказов, ожидающих подтверждения Paloma (pending = 0). Подтверждение не требуется.');
        }

        $latestSuccessful = $this->latestSuccessfulPalomaSyncLog();

        if (! $latestSuccessful) {
            throw new RuntimeException('Нет завершённого Paloma sync. Выполните синхронизацию остатков перед подтверждением.');
        }

        if ($latestSuccessful->started_at && $latestSuccessful->started_at->lt(now()->subHours(2))) {
            throw new RuntimeException(
                'Последний Paloma sync устарел ('
                . $latestSuccessful->started_at->diffForHumans()
                . '). Выполните обновлённую синхронизацию.'
            );
        }
    }

    /**
     * Count pending items that would be cleared by confirmation.
     */
    public function pendingStats(): array
    {
        $orders = KaspiOrder::query()
            ->where('internal_stock_status', KaspiOrderInternalStatus::HandedOffPendingPaloma->value)
            ->where('baseline_ignored', false)
            ->get();

        $ordersCount = $orders->count();
        $itemsQty = (int) $orders->flatMap->items->sum('qty');

        return [
            'orders_count' => $ordersCount,
            'items_qty' => $itemsQty,
        ];
    }

    /**
     * Perform the confirmation:
     * - Close all HANDED_OFF_PENDING_PALOMA orders created BEFORE this confirmation timestamp
     * - Record a PalomaStockConfirmation checkpoint
     */
    public function confirm(int $userId): PalomaStockConfirmation
    {
        $this->assertCanConfirm();

        $confirmAt = Carbon::now();
        $syncLog = $this->latestSuccessfulPalomaSyncLog();
        $stats = $this->pendingStats();

        return DB::transaction(function () use ($userId, $confirmAt, $syncLog, $stats) {
            $checkpoint = PalomaStockConfirmation::create([
                'confirmed_at' => $confirmAt,
                'confirmed_by_user_id' => $userId,
                'paloma_sync_log_id' => $syncLog?->id,
                'paloma_import_completed_at' => $syncLog?->finished_at,
                'pending_orders_count' => $stats['orders_count'],
                'pending_items_qty' => $stats['items_qty'],
                'metadata' => [
                    'mode' => config('services.kaspi.orders_mode', 'observe'),
                ],
            ]);

            // Clear all pending orders created before this confirmation
            $pendingOrders = KaspiOrder::query()
                ->where('internal_stock_status', KaspiOrderInternalStatus::HandedOffPendingPaloma->value)
                ->where('baseline_ignored', false)
                ->where('handoff_at', '<=', $confirmAt)
                ->get();

            foreach ($pendingOrders as $order) {
                $old = $order->internal_stock_status->value;
                $order->update(['internal_stock_status' => KaspiOrderInternalStatus::PalomaConfirmed]);

                KaspiStockEventLog::record(
                    'PENDING_CLEARED',
                    kaspiOrderId: $order->id,
                    oldStatus: $old,
                    newStatus: KaspiOrderInternalStatus::PalomaConfirmed->value,
                    userId: $userId,
                    metadata: ['confirmation_id' => $checkpoint->id],
                );
            }

            KaspiStockEventLog::record(
                'PALOMA_CONFIRMED',
                userId: $userId,
                metadata: [
                    'confirmation_id' => $checkpoint->id,
                    'orders_cleared' => $pendingOrders->count(),
                    'items_qty_cleared' => $stats['items_qty'],
                ],
            );

            return $checkpoint;
        });
    }

    public function latestSuccessfulPalomaSyncLog(): ?SyncLog
    {
        return SyncLog::query()
            ->where('source', 'paloma')
            ->whereIn('status', ['success', 'warning'])
            ->latest('started_at')
            ->first();
    }
}
