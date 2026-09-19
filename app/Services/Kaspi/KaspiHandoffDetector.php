<?php

namespace App\Services\Kaspi;

use App\Models\KaspiOrder;

class KaspiHandoffDetector
{
    /**
     * Whether automatic handoff transitions via courierTransmissionDate are enabled.
     * Controlled by KASPI_HANDOFF_BY_COURIER_DATE env flag.
     * Default: false — must be explicitly enabled after verifying on real production orders.
     */
    public function isHandoffFlagEnabled(): bool
    {
        return (bool) config('services.kaspi.handoff_by_courier_date', false);
    }

    /**
     * Returns true if the order shows signs of physical handoff based on API data.
     * Does NOT depend on the feature flag — this is pure observation.
     */
    public function isHandoffCandidate(KaspiOrder $order): bool
    {
        if ($order->kaspi_state === 'KASPI_DELIVERY') {
            return $order->courier_transmission_date !== null;
        }

        // PICKUP / DELIVERY: COMPLETED means goods left the store
        if (in_array($order->kaspi_state, ['PICKUP', 'DELIVERY'], true)) {
            return in_array($order->kaspi_status, ['COMPLETED'], true);
        }

        return false;
    }

    /**
     * Returns true if the handoff should be applied to the stock engine.
     * Requires both: candidate signals present AND feature flag enabled.
     */
    public function shouldApplyHandoff(KaspiOrder $order): bool
    {
        if (! $this->isHandoffFlagEnabled()) {
            return false;
        }

        return $this->isHandoffCandidate($order);
    }

    /**
     * Determine the handoff timestamp from order data.
     */
    public function resolveHandoffAt(KaspiOrder $order): ?\Illuminate\Support\Carbon
    {
        if ($order->kaspi_state === 'KASPI_DELIVERY' && $order->courier_transmission_date !== null) {
            return $order->courier_transmission_date;
        }

        if (in_array($order->kaspi_state, ['PICKUP', 'DELIVERY'], true) && $order->completed_at !== null) {
            return $order->completed_at;
        }

        return null;
    }
}
