<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PalomaStockConfirmation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'paloma_import_completed_at' => 'datetime',
        'pending_orders_count' => 'integer',
        'pending_items_qty' => 'integer',
        'metadata' => 'array',
    ];

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function palomaSyncLog(): BelongsTo
    {
        return $this->belongsTo(SyncLog::class, 'paloma_sync_log_id');
    }
}
