<?php

namespace App\Models;

use App\Enums\OzonProductStatus;
use Database\Factories\OzonProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OzonProduct extends Model
{
    /** @use HasFactory<OzonProductFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $attributes = [
        'status' => OzonProductStatus::Draft->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => OzonProductStatus::class,
            'prepared_images' => 'array',
            'prepared_attributes' => 'array',
            'prepared_payload' => 'array',
            'last_response' => 'array',
            'price_sync_enabled' => 'boolean',
            'stock_sync_enabled' => 'boolean',
            'content_sync_enabled' => 'boolean',
            'manual_ozon_price' => 'decimal:2',
            'price_multiplier' => 'decimal:4',
            'calculated_price' => 'decimal:2',
            'last_sent_price' => 'decimal:2',
            'calculated_stock' => 'integer',
            'last_sent_stock' => 'integer',
            'first_exported_at' => 'datetime',
            'last_exported_at' => 'datetime',
            'last_status_checked_at' => 'datetime',
            'published_at' => 'datetime',
            'last_price_synced_at' => 'datetime',
            'last_stock_synced_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(OzonAccount::class, 'ozon_account_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function siteCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'site_category_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(OzonWarehouse::class, 'ozon_warehouse_id');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(OzonOperation::class);
    }
}
