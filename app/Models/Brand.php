<?php

namespace App\Models;

use App\Support\StorefrontText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Brand extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'show_on_homepage' => 'boolean',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return StorefrontText::plain($this->name, 'Brand '.$this->id);
    }
}
