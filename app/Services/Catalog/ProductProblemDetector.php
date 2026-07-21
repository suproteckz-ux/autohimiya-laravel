<?php

namespace App\Services\Catalog;

use App\Models\Product;

class ProductProblemDetector
{
    public const MISSING_IMAGE = 'missing_image';
    public const MISSING_DESCRIPTION = 'missing_description';
    public const MISSING_SEO = 'missing_seo';
    public const MISSING_BRAND = 'missing_brand';
    public const MISSING_CATEGORY = 'missing_category';

    public function detect(Product $product): array
    {
        $problems = [];

        if (! $this->hasImage($product)) {
            $problems[] = self::MISSING_IMAGE;
        }

        if (! $this->hasDescription($product)) {
            $problems[] = self::MISSING_DESCRIPTION;
        }

        if (! $this->hasSeo($product)) {
            $problems[] = self::MISSING_SEO;
        }

        if (! $this->hasBrand($product)) {
            $problems[] = self::MISSING_BRAND;
        }

        if (! $this->hasCategory($product)) {
            $problems[] = self::MISSING_CATEGORY;
        }

        return $problems;
    }

    public function hasImage(Product $product): bool
    {
        return filled($product->primary_image)
            || (int) ($product->images_count ?? 0) > 0
            || ($product->relationLoaded('images') && $product->images->isNotEmpty())
            || ($product->relationLoaded('primaryImage') && filled($product->primaryImage?->path));
    }

    public function hasDescription(Product $product): bool
    {
        return filled(strip_tags((string) $product->description));
    }

    public function hasSeo(Product $product): bool
    {
        return filled($product->meta_title) && filled($product->meta_description);
    }

    public function hasBrand(Product $product): bool
    {
        return filled($product->brand_id);
    }

    public function hasCategory(Product $product): bool
    {
        return filled($product->category_id)
            || ($product->relationLoaded('categories') && $product->categories->isNotEmpty());
    }

    public function getProblems(Product $product): array
    {
        return $this->detect($product);
    }

    public function getPriority(Product $product): string
    {
        $problems = $this->detect($product);

        if (array_intersect($problems, [self::MISSING_IMAGE, self::MISSING_DESCRIPTION, self::MISSING_SEO])) {
            return 'high';
        }

        if (array_intersect($problems, [self::MISSING_BRAND, self::MISSING_CATEGORY])) {
            return 'medium';
        }

        return 'low';
    }

    public function getScore(Product $product): int
    {
        return 100 - (count($this->detect($product)) * 20);
    }

    public function getProblemLabels(Product $product): array
    {
        return array_map(fn (string $code): string => $this->label($code), $this->detect($product));
    }

    public function label(string $code): string
    {
        return match ($code) {
            self::MISSING_IMAGE => 'Нет фото',
            self::MISSING_DESCRIPTION => 'Нет описания',
            self::MISSING_SEO => 'Нет SEO',
            self::MISSING_BRAND => 'Нет бренда',
            self::MISSING_CATEGORY => 'Нет категории',
            default => $code,
        };
    }
}
