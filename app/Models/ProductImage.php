<?php

namespace App\Models;

use App\Services\Catalog\ProductThumbnailGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProductImage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_primary' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function storefrontCardImagePath(): ?string
    {
        return $this->card_thumb_path ?: $this->path;
    }

    protected static function booted(): void
    {
        static::saved(function (ProductImage $image): void {
            if ($image->path && (! $image->card_thumb_path || $image->wasChanged('path'))) {
                try {
                    app(ProductThumbnailGenerator::class)->make($image, true);
                    $image->refresh();
                } catch (Throwable $exception) {
                    Log::warning('Product thumbnail generation failed.', [
                        'product_image_id' => $image->id,
                        'product_id' => $image->product_id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            if ($image->is_primary) {
                if ($image->role !== 'primary') {
                    $image->forceFill(['role' => 'primary'])->saveQuietly();
                }

                static::query()
                    ->where('product_id', $image->product_id)
                    ->whereKeyNot($image->id)
                    ->update(['is_primary' => false, 'role' => 'gallery']);

                $image->product?->update(['primary_image' => $image->path]);

                return;
            }

            static::syncProductPrimaryImage((int) $image->product_id);
        });

        static::deleted(fn (ProductImage $image): bool => static::syncProductPrimaryImage((int) $image->product_id));
    }

    public static function syncProductPrimaryImage(int $productId): bool
    {
        $primary = static::query()
            ->where('product_id', $productId)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->first();

        if (! $primary) {
            return (bool) Product::query()->whereKey($productId)->update(['primary_image' => null]);
        }

        if (! $primary->is_primary) {
            $primary->forceFill(['is_primary' => true, 'role' => 'primary'])->saveQuietly();
        }

        return (bool) Product::query()->whereKey($productId)->update(['primary_image' => $primary->path]);
    }
}
