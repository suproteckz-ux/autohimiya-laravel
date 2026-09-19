<?php

namespace App\Models;

use App\Enums\KaspiOrderInternalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KaspiOrder extends Model
{
    protected $guarded = [];

    protected $casts = [
        'kaspi_created_at' => 'datetime',
        'kaspi_updated_at' => 'datetime',
        'courier_transmission_planning_date' => 'datetime',
        'courier_transmission_date' => 'datetime',
        'handoff_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'raw_payload' => 'array',
        'baseline_ignored' => 'boolean',
        'number_of_space' => 'integer',
        'internal_stock_status' => KaspiOrderInternalStatus::class,
    ];

    public function items(): HasMany
    {
        return $this->hasMany(KaspiOrderItem::class);
    }

    public function isHandoffCandidate(): bool
    {
        return $this->courier_transmission_date !== null
            && $this->kaspi_state === 'KASPI_DELIVERY';
    }

    public function isHandoffConfirmed(): bool
    {
        return $this->handoff_at !== null;
    }

    public function affectsStock(): bool
    {
        if ($this->baseline_ignored) {
            return false;
        }

        return $this->internal_stock_status?->affectsStock() ?? false;
    }
}
