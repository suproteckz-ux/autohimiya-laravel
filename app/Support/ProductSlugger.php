<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Str;

class ProductSlugger
{
    public static function fromName(string $name, ?string $fallback = null): string
    {
        $source = trim($name) !== '' ? $name : (string) $fallback;
        $slug = Str::of($source)->ascii('ru')->slug('-')->lower()->toString();

        return $slug !== '' ? $slug : 'product-'.Str::lower(Str::random(8));
    }

    public static function unique(string $baseSlug, ?int $ignoreProductId = null): string
    {
        $baseSlug = self::normalizeSlug($baseSlug) ?: 'product-'.Str::lower(Str::random(8));
        $slug = $baseSlug;
        $counter = 2;

        while (Product::query()
            ->where('slug', $slug)
            ->when($ignoreProductId, fn ($query) => $query->whereKeyNot($ignoreProductId))
            ->exists()) {
            $slug = $baseSlug.'-'.$counter++;
        }

        return $slug;
    }

    public static function uniqueFromName(string $name, ?string $fallback = null, ?int $ignoreProductId = null): string
    {
        return self::unique(self::fromName($name, $fallback), $ignoreProductId);
    }

    public static function isBad(?string $slug, Product $product): bool
    {
        $normalizedSlug = self::normalizeSlug((string) $slug);

        if ($normalizedSlug === '') {
            return true;
        }

        if (str_contains((string) $slug, '_')) {
            return true;
        }

        if (preg_match('/^aut[-_][0-9]+$/i', (string) $slug)) {
            return true;
        }

        if (preg_match('/^[0-9]+$/', $normalizedSlug)) {
            return true;
        }

        foreach ([(string) $product->sku, (string) $product->paloma_sku] as $sku) {
            if ($sku !== '' && $normalizedSlug === self::normalizeSlug($sku)) {
                return true;
            }
        }

        $nameSlug = self::fromName((string) $product->name, (string) ($product->model ?: $product->sku));
        $modelSlug = self::normalizeSlug((string) $product->model);

        if ($modelSlug !== '' && $normalizedSlug === $modelSlug && $nameSlug !== $normalizedSlug && self::nameHasBetterText((string) $product->name)) {
            return true;
        }

        return mb_strlen($normalizedSlug) <= 6 && self::nameHasBetterText((string) $product->name);
    }

    public static function normalizeSlug(string $value): string
    {
        return Str::of($value)
            ->replace('_', '-')
            ->ascii('ru')
            ->slug('-')
            ->lower()
            ->toString();
    }

    private static function nameHasBetterText(string $name): bool
    {
        $name = trim($name);

        return $name !== '' && (bool) preg_match('/[\\p{L}]{3,}/u', $name) && str_contains($name, ' ');
    }
}
