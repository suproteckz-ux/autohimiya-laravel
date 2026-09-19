<?php

namespace App\Services\Kaspi;

use App\Enums\KaspiOrderInternalStatus;
use App\Models\KaspiOrder;
use App\Models\KaspiOrderItem;
use App\Models\KaspiStockEventLog;
use Illuminate\Support\Facades\DB;

class KaspiReservationEngine
{
    public function __construct(private readonly KaspiHandoffDetector $handoffDetector) {}

    /**
     * Transition order to RESERVED state.
     * Only if active mode is enabled and order is not baseline-ignored.
     */
    public function reserve(KaspiOrder $order): void
    {
        if ($order->baseline_ignored) {
            return;
        }

        if (! $this->isActiveMode()) {
            return;
        }

        if ($order->internal_stock_status === KaspiOrderInternalStatus::Reserved) {
            return;
        }

        DB::transaction(function () use ($order) {
            $old = $order->internal_stock_status?->value;
            $order->update(['internal_stock_status' => KaspiOrderInternalStatus::Reserved]);

            KaspiStockEventLog::record(
                'RESERVE_CREATED',
                kaspiOrderId: $order->id,
                oldStatus: $old,
                newStatus: KaspiOrderInternalStatus::Reserved->value,
            );
        });
    }

    /**
     * Mark order as handoff candidate (observe only — no reserve change).
     */
    public function markHandoffCandidate(KaspiOrder $order): void
    {
        if ($order->internal_stock_status === KaspiOrderInternalStatus::HandoffCandidate) {
            return;
        }

        $old = $order->internal_stock_status?->value;
        $order->update(['internal_stock_status' => KaspiOrderInternalStatus::HandoffCandidate]);

        KaspiStockEventLog::record(
            'HANDOFF_CANDIDATE_DETECTED',
            kaspiOrderId: $order->id,
            oldStatus: $old,
            newStatus: KaspiOrderInternalStatus::HandoffCandidate->value,
        );
    }

    /**
     * Transition RESERVED → HANDED_OFF_PENDING_PALOMA.
     * Only in active mode, only when handoff is confirmed.
     */
    public function applyHandoff(KaspiOrder $order, \Illuminate\Support\Carbon $handoffAt): void
    {
        if ($order->baseline_ignored) {
            return;
        }

        if (! $this->isActiveMode()) {
            return;
        }

        if ($order->internal_stock_status === KaspiOrderInternalStatus::HandedOffPendingPaloma) {
            return;
        }

        DB::transaction(function () use ($order, $handoffAt) {
            $old = $order->internal_stock_status?->value;
            $order->update([
                'internal_stock_status' => KaspiOrderInternalStatus::HandedOffPendingPaloma,
                'handoff_at' => $handoffAt,
            ]);

            KaspiStockEventLog::record(
                'RESERVE_MOVED_TO_PENDING',
                kaspiOrderId: $order->id,
                oldStatus: $old,
                newStatus: KaspiOrderInternalStatus::HandedOffPendingPaloma->value,
                metadata: ['handoff_at' => $handoffAt->toIso8601String()],
            );
        });
    }

    /**
     * Cancel reserve for an order that hasn't been physically handed off.
     */
    public function cancelBeforeHandoff(KaspiOrder $order): void
    {
        if ($order->baseline_ignored) {
            return;
        }

        if (! $this->isActiveMode()) {
            return;
        }

        $cancelableStatuses = [
            KaspiOrderInternalStatus::Reserved,
            KaspiOrderInternalStatus::HandoffCandidate,
            KaspiOrderInternalStatus::Observed,
        ];

        if (! in_array($order->internal_stock_status, $cancelableStatuses, true)) {
            return;
        }

        DB::transaction(function () use ($order) {
            $old = $order->internal_stock_status?->value;
            $order->update([
                'internal_stock_status' => KaspiOrderInternalStatus::CancelledBeforeHandoff,
                'cancelled_at' => $order->cancelled_at ?? now(),
            ]);

            KaspiStockEventLog::record(
                'CANCELLED_BEFORE_HANDOFF',
                kaspiOrderId: $order->id,
                oldStatus: $old,
                newStatus: KaspiOrderInternalStatus::CancelledBeforeHandoff->value,
            );
        });
    }

    /**
     * Handle cancellation after goods were already handed off.
     * Does NOT release stock — returns only an event.
     */
    public function cancelAfterHandoff(KaspiOrder $order): void
    {
        if ($order->baseline_ignored) {
            return;
        }

        DB::transaction(function () use ($order) {
            $old = $order->internal_stock_status?->value;
            $order->update([
                'internal_stock_status' => KaspiOrderInternalStatus::CancelledAfterHandoff,
                'cancelled_at' => $order->cancelled_at ?? now(),
            ]);

            KaspiStockEventLog::record(
                'CANCELLED_AFTER_HANDOFF',
                kaspiOrderId: $order->id,
                oldStatus: $old,
                newStatus: KaspiOrderInternalStatus::CancelledAfterHandoff->value,
                metadata: ['note' => 'No automatic stock return — manual Paloma adjustment required'],
            );
        });
    }

    public function isActiveMode(): bool
    {
        return config('services.kaspi.orders_mode', 'observe') === 'active';
    }

    public function isObserveMode(): bool
    {
        return ! $this->isActiveMode();
    }
}
