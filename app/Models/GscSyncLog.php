<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GscSyncLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'days' => 'integer',
        'pages_count' => 'integer',
        'queries_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'payload_json' => 'array',
    ];
}
