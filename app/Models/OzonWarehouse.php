<?php

namespace App\Models;

use Database\Factories\OzonWarehouseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OzonWarehouse extends Model
{
    /** @use HasFactory<OzonWarehouseFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'is_api_confirmed' => 'boolean',
            'api_confirmed_at' => 'datetime',
            'raw_payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(OzonAccount::class, 'ozon_account_id');
    }

    public function ozonProducts(): HasMany
    {
        return $this->hasMany(OzonProduct::class, 'ozon_warehouse_id');
    }
}
