<?php

namespace App\Models;

use Database\Factories\OzonAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OzonAccount extends Model
{
    /** @use HasFactory<OzonAccountFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
            'is_test_mode' => 'boolean',
            'sync_prices_enabled' => 'boolean',
            'sync_stocks_enabled' => 'boolean',
            'default_price_multiplier' => 'decimal:4',
            'last_connection_check_at' => 'datetime',
        ];
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(OzonWarehouse::class);
    }

    public function ozonProducts(): HasMany
    {
        return $this->hasMany(OzonProduct::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(OzonOperation::class);
    }

    public function taxonomyNodes(): HasMany
    {
        return $this->hasMany(OzonTaxonomyNode::class);
    }
}
