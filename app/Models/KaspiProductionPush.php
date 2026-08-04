<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KaspiProductionPush extends Model
{
    protected $guarded = [];

    protected $casts = [
        'collected_payload' => 'array',
        'response_summary' => 'array',
        'collected_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
