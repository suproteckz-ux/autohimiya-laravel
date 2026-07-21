<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GscPage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'ctr' => 'decimal:4',
        'position' => 'decimal:2',
        'payload_json' => 'array',
    ];

    public function queries(): HasMany
    {
        return $this->hasMany(GscQuery::class);
    }
}
