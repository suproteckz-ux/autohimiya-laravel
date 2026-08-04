<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KaspiImportReceipt extends Model
{
    protected $guarded = [];

    protected $casts = [
        'received_at' => 'datetime',
        'collected_at' => 'datetime',
        'result_summary' => 'array',
    ];
}
