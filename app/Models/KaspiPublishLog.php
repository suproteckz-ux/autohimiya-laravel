<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KaspiPublishLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'published_fields' => 'array',
        'dry_run' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(KaspiEnrichmentTask::class, 'kaspi_enrichment_task_id');
    }
}
