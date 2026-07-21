<?php

namespace App\Models;

use App\Support\StorefrontText;
use App\Support\ProductStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:2',
        'old_price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'quantity' => 'integer',
        'availability' => 'boolean',
        'kaspi_credit_enabled' => 'boolean',
        'kaspi_available' => 'boolean',
        'kaspi_price' => 'integer',
        'kaspi_quantity' => 'integer',
        'kaspi_last_sync_at' => 'datetime',
        'name_is_manual' => 'boolean',
        'category_is_manual' => 'boolean',
        'description_is_manual' => 'boolean',
        'photos_are_manual' => 'boolean',
        'attributes_are_manual' => 'boolean',
        'seo_is_manual' => 'boolean',
        'auto_content_locked' => 'boolean',
        'content_verified_at' => 'datetime',
        'match_confidence' => 'integer',
        'badges' => 'array',
        'is_featured' => 'boolean',
        'is_hit' => 'boolean',
        'is_new' => 'boolean',
        'is_sale' => 'boolean',
        'last_synced_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)->withTimestamps();
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class)->orderBy('sort_order');
    }

    public function enrichmentTasks(): HasMany
    {
        return $this->hasMany(CatalogEnrichmentTask::class);
    }

    public function kaspiEnrichmentTasks(): HasMany
    {
        return $this->hasMany(KaspiEnrichmentTask::class);
    }

    public function kaspiSyncLogs(): HasMany
    {
        return $this->hasMany(KaspiSyncLog::class);
    }

    public function canShowKaspiCreditButton(): bool
    {
        return filled($this->sku)
            && filled(config('services.kaspi.merchant_code'))
            && filled(config('services.kaspi.city_code'));
    }

    public function scopeWithKaspiButton(Builder $query): void
    {
        if (filled(config('services.kaspi.merchant_code')) && filled(config('services.kaspi.city_code'))) {
            $query->whereNotNull('sku')->where('sku', '<>', '');
        } else {
            $query->whereRaw('1 = 0');
        }
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function scopeVisibleOnStorefront(Builder $query): Builder
    {
        return $query
            ->whereIn('product_status', ProductStatus::visibleValues())
            ->where('availability', true)
            ->where('quantity', '>', 0)
            ->where('price', '>', 0)
            ->whereNotNull('category_id')
            ->whereNotNull('slug')
            ->where('slug', '<>', '');
    }

    public function scopeEligibleForKaspiEnrichment(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $inner) => $inner
                ->whereNotNull('sku')->where('sku', '<>', '')
                ->orWhere(fn (Builder $fallback) => $fallback->whereNotNull('kaspi_merchant_sku')->where('kaspi_merchant_sku', '<>', '')))
            ->where('price', '>', 0)
            ->where(fn (Builder $inner) => $inner->where('quantity', '>', 0)->orWhere('availability', true))
            ->whereIn('product_status', ProductStatus::kaspiEnrichmentValues());
    }

    public function isAvailableForStorefront(): bool
    {
        return ProductStatus::isVisibleStatus((string) $this->product_status)
            && (bool) $this->availability
            && (int) $this->quantity > 0
            && (float) $this->price > 0
            && filled($this->category_id)
            && filled($this->slug);
    }

    public function storefrontImagePath(): ?string
    {
        return $this->primaryImage?->storefrontCardImagePath() ?: $this->primary_image;
    }

    public function storefrontOriginalImagePath(): ?string
    {
        return $this->primaryImage?->path ?: $this->primary_image;
    }

    public function getDisplayNameAttribute(): string
    {
        return StorefrontText::plain($this->name, 'Product '.$this->id);
    }

    public function getDisplayH1Attribute(): string
    {
        return StorefrontText::plain($this->h1 ?: $this->name, $this->display_name);
    }

    public function getSafeDescriptionAttribute(): string
    {
        return StorefrontText::html($this->description);
    }
}
