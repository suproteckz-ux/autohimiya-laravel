<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomepageBlock extends Model
{
    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
        'items' => 'array',
        'is_active' => 'boolean',
    ];
}
