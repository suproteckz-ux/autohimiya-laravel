<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KaspiStockEventLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'qty' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function kaspiOrder(): BelongsTo
    {
        return $this->belongsTo(KaspiOrder::class);
    }

    public function kaspiOrderItem(): BelongsTo
    {
        return $this->belongsTo(KaspiOrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(
        string $eventType,
        ?int $kaspiOrderId = null,
        ?int $kaspiOrderItemId = null,
        ?int $productId = null,
        ?string $sku = null,
        ?int $qty = null,
        ?string $oldStatus = null,
        ?string $newStatus = null,
        ?int $userId = null,
        array $metadata = [],
    ): self {
        return self::create([
            'kaspi_order_id' => $kaspiOrderId,
            'kaspi_order_item_id' => $kaspiOrderItemId,
            'product_id' => $productId,
            'event_type' => $eventType,
            'sku' => $sku,
            'qty' => $qty,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'user_id' => $userId,
            'metadata' => $metadata ?: null,
        ]);
    }
}
