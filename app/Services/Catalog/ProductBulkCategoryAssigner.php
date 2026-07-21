<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductBulkCategoryAssigner
{
    public function assign(Collection $products, int $categoryId): int
    {
        $category = Category::query()
            ->whereKey($categoryId)
            ->where('status', 'active')
            ->first();

        if (! $category) {
            throw (new ModelNotFoundException())->setModel(Category::class, [$categoryId]);
        }

        $defaultCategoryId = $this->newProductsCategoryId();
        $updated = 0;

        DB::transaction(function () use ($products, $category, $defaultCategoryId, &$updated): void {
            $products->each(function (Product $product) use ($category, $defaultCategoryId, &$updated): void {
                $product->forceFill([
                    'category_id' => $category->id,
                    'category_is_manual' => true,
                ])->save();

                $product->categories()->syncWithoutDetaching([$category->id]);

                if ($defaultCategoryId !== null && $defaultCategoryId !== (int) $category->id) {
                    $product->categories()->detach($defaultCategoryId);
                }

                $updated++;
            });
        });

        return $updated;
    }

    private function newProductsCategoryId(): ?int
    {
        $id = Category::withTrashed()
            ->where(function ($query): void {
                $query->where('slug', DefaultCategoryResolver::NEW_PRODUCTS_SLUG)
                    ->orWhere('name', DefaultCategoryResolver::NEW_PRODUCTS_NAME);
            })
            ->value('id');

        return $id ? (int) $id : null;
    }
}