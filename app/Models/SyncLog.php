<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'payload_summary' => 'array',
        'diagnostics' => 'array',
        'raw_payload' => 'array',
    ];
}
