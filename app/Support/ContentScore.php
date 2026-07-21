<?php

namespace App\Support;

use App\Models\Product;
use App\Services\Catalog\ProductProblemDetector;

class ContentScore
{
    public static function hasPhoto(Product $product): bool
    {
        return app(ProductProblemDetector::class)->hasImage($product);
    }

    public static function hasDescription(Product $product): bool
    {
        return app(ProductProblemDetector::class)->hasDescription($product);
    }

    public static function hasSeo(Product $product): bool
    {
        return app(ProductProblemDetector::class)->hasSeo($product);
    }

    public static function hasBrand(Product $product): bool
    {
        return app(ProductProblemDetector::class)->hasBrand($product);
    }

    public static function hasCategory(Product $product): bool
    {
        return app(ProductProblemDetector::class)->hasCategory($product);
    }

    public static function score(Product $product): int
    {
        return app(ProductProblemDetector::class)->getScore($product);
    }

    public static function filledCount(Product $product): int
    {
        return (int) (self::score($product) / 20);
    }

    public static function priority(Product $product): string
    {
        return app(ProductProblemDetector::class)->getPriority($product);
    }

    public static function priorityLabel(Product $product): string
    {
        return match (self::priority($product)) {
            'high' => 'Высокий',
            'medium' => 'Средний',
            default => 'Низкий',
        };
    }

    public static function problems(Product $product): array
    {
        return app(ProductProblemDetector::class)->getProblemLabels($product);
    }
}
