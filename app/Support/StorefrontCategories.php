<?php

namespace App\Support;

use App\Models\Category;
use App\Services\CategoryTreeService;
use Illuminate\Support\Collection;

class StorefrontCategories
{
    /**
     * Load root categories with their active children, attaching the correct
     * product count (combined FK + pivot, including all active descendants).
     *
     * @param  int|null  $limit  Maximum number of root categories to return.
     * @param  bool  $showOnlyNonEmpty  Filter out categories whose combined count is 0.
     *                                  Pass false for the sidebar, which must show all active
     *                                  categories so the current-branch can expand fully.
     */
    public static function roots(?int $limit = null, bool $showOnlyNonEmpty = true): Collection
    {
        $countsMap = app(CategoryTreeService::class)->getProductCountsMap();

        $query = Category::query()
            ->where('status', 'active')
            ->whereNull('parent_id')
            ->with([
                'children' => fn ($q) => $q
                    ->where('status', 'active')
                    ->orderBy('sort_order')
                    ->orderBy('name'),
            ])
            ->orderByRaw("CASE WHEN slug LIKE '%nov%' OR slug LIKE '%new%' OR name LIKE '%Новые%' THEN 1 ELSE 0 END")
            ->orderByDesc('show_on_homepage')
            ->orderBy('sort_order')
            ->orderBy('name');

        $categories = $query->get()
            ->filter(fn (Category $category): bool => $category->has_human_name)
            ->each(function (Category $category) use ($countsMap, $showOnlyNonEmpty): void {
                $category->products_count = $countsMap[$category->id] ?? 0;

                $category->setRelation(
                    'children',
                    $category->children
                        ->filter(fn (Category $child): bool => $child->has_human_name)
                        ->filter(fn (Category $child): bool => ! $showOnlyNonEmpty || ($countsMap[$child->id] ?? 0) > 0)
                        ->each(fn (Category $child) => $child->products_count = $countsMap[$child->id] ?? 0)
                        ->values()
                );
            })
            ->filter(fn (Category $category): bool => ! $showOnlyNonEmpty || (int) $category->products_count > 0)
            ->values();

        return $limit ? $categories->take($limit)->values() : $categories;
    }

    /**
     * Siblings of the given category (or root categories if no parent), with correct counts.
     */
    public static function siblingsOrRoots(?Category $category, int $limit = 8): Collection
    {
        if ($category?->parent) {
            $countsMap = app(CategoryTreeService::class)->getProductCountsMap();

            $siblings = Category::query()
                ->where('status', 'active')
                ->where('parent_id', $category->parent_id)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->filter(fn (Category $item): bool => $item->has_human_name)
                ->each(fn (Category $item) => $item->products_count = $countsMap[$item->id] ?? 0)
                ->filter(fn (Category $item): bool => (int) $item->products_count > 0)
                ->values();

            return $siblings->isNotEmpty() ? $siblings->take($limit)->values() : self::roots($limit);
        }

        return self::roots($limit);
    }
}
