<?php

namespace App\Models;

use App\Support\StorefrontText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'show_on_homepage' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function allProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withTimestamps();
    }

    public function getDisplayNameAttribute(): string
    {
        return StorefrontText::plain($this->name, 'Category '.$this->id);
    }

    public function getDisplayH1Attribute(): string
    {
        return StorefrontText::plain($this->h1 ?: $this->name, $this->display_name);
    }

    public function getSafeDescriptionAttribute(): string
    {
        return StorefrontText::html($this->description);
    }

    // Short description for top of category page (falls back to legacy description)
    public function getSafeShortDescriptionAttribute(): string
    {
        $text = filled($this->short_description) ? $this->short_description : $this->description;

        return $text ? StorefrontText::html((string) $text) : '';
    }

    // SEO description for bottom of category page (no fallback — avoids duplication)
    public function getSafeSeoDescriptionAttribute(): string
    {
        return filled($this->seo_description) ? StorefrontText::html((string) $this->seo_description) : '';
    }

    // Unified image path for storefront: prefers image_path, falls back to image (legacy)
    public function getStorefrontImagePathAttribute(): ?string
    {
        foreach ([$this->image_path, $this->image] as $val) {
            $val = trim((string) ($val ?? ''));
            if ($val === '' || str_starts_with($val, 'http://') || str_starts_with($val, 'https://')) {
                continue;
            }
            if (Storage::disk('public')->exists($val)) {
                return $val;
            }
        }

        return null;
    }

    public function getHasHumanNameAttribute(): bool
    {
        return StorefrontText::hasHumanName($this->name);
    }

    public function ancestors(): array
    {
        $ancestors = [];
        $category = $this->parent;

        while ($category) {
            array_unshift($ancestors, $category);
            $category = $category->parent;
        }

        return $ancestors;
    }
}
