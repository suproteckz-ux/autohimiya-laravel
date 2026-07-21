<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;

class ProductImageUrlResolver
{
    public static function productAdminUrl(Product $product): ?string
    {
        $image = $product->primaryImage;

        if ($image instanceof ProductImage) {
            return self::imageAdminUrl($image);
        }

        return self::pathUrl($product->primary_image);
    }

    public static function imageAdminUrl(ProductImage $image): ?string
    {
        return self::pathUrl($image->card_thumb_path)
            ?? self::pathUrl($image->path)
            ?? self::pathUrl($image->original_path);
    }

    public static function pathUrl(?string $path): ?string
    {
        $normalized = self::normalizePath($path);

        if ($normalized === null) {
            return null;
        }

        foreach (self::candidateStoragePaths($normalized) as $candidate) {
            if (Storage::disk('public')->exists($candidate)) {
                return asset('storage/'.$candidate);
            }
        }

        return null;
    }

    public static function normalizePath(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#^/+#', '', $path);
        $path = preg_replace('#^(public/)?storage/#', '', $path);
        $path = preg_replace('#^image/#', '', $path);

        return $path ?: null;
    }

    public static function candidateStoragePaths(string $path): array
    {
        $basename = basename($path);
        $paths = [
            $path,
            'products/'.$path,
            'products/opencart/'.$path,
            'catalog/'.$basename,
            'products/opencart/'.$basename,
        ];

        if (str_starts_with($path, 'catalog/')) {
            $paths[] = $path;
        }

        if (str_starts_with($path, 'data/')) {
            $paths[] = $path;
        }

        return collect($paths)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
