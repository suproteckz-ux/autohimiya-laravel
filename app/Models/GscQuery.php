<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GscQuery extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'ctr' => 'decimal:4',
        'position' => 'decimal:2',
        'payload_json' => 'array',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(GscPage::class, 'gsc_page_id');
    }
}
