<?php

namespace App\Services\Catalog;

use App\Models\Category;
use Illuminate\Support\Str;

class DefaultCategoryResolver
{
    public const NEW_PRODUCTS_NAME = 'Новые товары';
    public const NEW_PRODUCTS_SLUG = 'novye-tovary';

    public function getOrCreateNewProductsCategoryId(): int
    {
        $category = Category::withTrashed()
            ->where('name', self::NEW_PRODUCTS_NAME)
            ->first();

        if ($category) {
            if (method_exists($category, 'restore') && $category->trashed()) {
                $category->restore();
            }

            $category->forceFill(['status' => 'active'])->save();

            return (int) $category->id;
        }

        return (int) Category::query()->create([
            'name' => self::NEW_PRODUCTS_NAME,
            'slug' => $this->uniqueSlug(),
            'parent_id' => null,
            'status' => 'active',
            'sort_order' => 0,
            'show_on_homepage' => false,
        ])->id;
    }

    private function uniqueSlug(): string
    {
        $slug = self::NEW_PRODUCTS_SLUG;
        $counter = 2;

        while (Category::withTrashed()->where('slug', $slug)->exists()) {
            $slug = self::NEW_PRODUCTS_SLUG.'-'.$counter++;
        }

        return Str::lower($slug);
    }
}
