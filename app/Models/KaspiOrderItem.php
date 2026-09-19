<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KaspiOrderItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'qty' => 'integer',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'raw_payload' => 'array',
    ];

    public function kaspiOrder(): BelongsTo
    {
        return $this->belongsTo(KaspiOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isMatched(): bool
    {
        return $this->sku_match_status === 'matched' && $this->product_id !== null;
    }
}
