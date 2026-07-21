<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskCard extends Model
{
    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
    ];
}
