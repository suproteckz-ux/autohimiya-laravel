<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Str;

class CategorySuggestionService
{
    public function suggest(Product $product): array
    {
        if ($product->category_id && $product->category) {
            return [
                'category_id' => $product->category_id,
                'category_name' => $product->category->display_name,
                'confidence' => 100,
                'reason' => 'Product already has category.',
                'source' => 'current_product',
            ];
        }

        $name = Str::lower($product->display_name);

        $category = Category::query()
            ->whereNotNull('name')
            ->orderByRaw('LENGTH(name) DESC')
            ->get()
            ->first(function (Category $category) use ($name): bool {
                $categoryName = Str::lower($category->display_name);

                return filled($categoryName) && str_contains($name, $categoryName);
            });

        if ($category) {
            return [
                'category_id' => $category->id,
                'category_name' => $category->display_name,
                'confidence' => 75,
                'reason' => 'Category name found in product name.',
                'source' => 'category_name_rule',
            ];
        }

        return [
            'category_id' => null,
            'category_name' => '',
            'confidence' => 0,
            'reason' => 'No category candidate found.',
            'source' => 'category_name_rule',
        ];
    }
}
