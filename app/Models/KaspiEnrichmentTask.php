<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KaspiEnrichmentTask extends Model
{
    public const STATUSES = [
        'pending',
        'running',
        'parsed',
        'draft',
        'approved',
        'published',
        'rejected',
        'failed',
        'needs_manual_url',
        'resolved_from_widget',
        'widget_not_found',
        'widget_timeout',
        'kaspi_js_not_loaded',
        'kaspi_button_not_found',
        'kaspi_url_not_opened',
        'invalid_kaspi_url',
        'error',
        // Content-import statuses (set by kaspi:import-content)
        'kaspi_imported', // all available content types imported
        'kaspi_partial',  // only some content types imported
        'kaspi_no_data',  // page fetched but no usable content found
        'kaspi_blocked',  // HTTP failed (403 / captcha / rate-limited)
    ];

    public const IMPORT_STATUSES = [
        'kaspi_imported',
        'kaspi_partial',
        'kaspi_no_data',
        'kaspi_blocked',
    ];

    protected $guarded = [];

    protected $casts = [
        'missing_photo' => 'boolean',
        'missing_description' => 'boolean',
        'missing_attributes' => 'boolean',
        'parsed_title' => 'array',
        'parsed_images' => 'array',
        'parsed_attributes' => 'array',
        'raw_payload' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
