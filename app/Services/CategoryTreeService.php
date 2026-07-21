<?php

namespace App\Services;

use App\Support\ProductStatus;
use Illuminate\Support\Facades\DB;

class CategoryTreeService
{
    private ?array $countsCache = null;

    /**
     * Returns a map of category_id -> total storefront-visible product count.
     *
     * "Total" means: direct products (via FK or pivot) + products in all active
     * descendant categories. Duplicates across parent/child are counted once.
     *
     * Result is memoised for the lifetime of this service instance (request scope
     * when registered as a singleton).
     */
    public function getProductCountsMap(): array
    {
        if ($this->countsCache !== null) {
            return $this->countsCache;
        }

        $statuses = ProductStatus::visibleValues();

        // One query: all storefront-visible products with their FK category
        $visibleProducts = DB::table('products')
            ->whereNull('deleted_at')
            ->whereIn('product_status', $statuses)
            ->where('availability', true)
            ->where('quantity', '>', 0)
            ->where('price', '>', 0)
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->get(['id', 'category_id']);

        $visibleProductIds = $visibleProducts->pluck('id')->toArray();

        // Pivot assignments for those products
        $pivotRows = $visibleProductIds !== []
            ? DB::table('category_product')
                ->whereIn('product_id', $visibleProductIds)
                ->get(['category_id', 'product_id'])
            : collect();

        // Build direct sets: category_id -> { product_id => true }
        // Using product IDs as keys so union automatically deduplicates
        $direct = [];
        foreach ($visibleProducts as $p) {
            if ($p->category_id !== null) {
                $direct[(int) $p->category_id][(int) $p->id] = true;
            }
        }
        foreach ($pivotRows as $row) {
            $direct[(int) $row->category_id][(int) $row->product_id] = true;
        }

        // Load active category tree (one query)
        $allCats = DB::table('categories')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->get(['id', 'parent_id']);

        $childrenMap = [];
        foreach ($allCats as $cat) {
            if ($cat->parent_id !== null) {
                $childrenMap[(int) $cat->parent_id][] = (int) $cat->id;
            }
        }

        // Recursive accumulation: each category's set = its own products ∪ descendants'
        $cache = [];

        $compute = function (int $id) use (&$compute, &$cache, $direct, $childrenMap): array {
            if (array_key_exists($id, $cache)) {
                return $cache[$id];
            }

            // Prevent infinite recursion on malformed trees
            $cache[$id] = [];

            $set = $direct[$id] ?? [];
            foreach ($childrenMap[$id] ?? [] as $childId) {
                $set += $compute($childId); // array union keeps unique product IDs
            }

            return $cache[$id] = $set;
        };

        $totals = [];
        foreach ($allCats as $cat) {
            $id = (int) $cat->id;
            $totals[$id] = count($compute($id));
        }

        return $this->countsCache = $totals;
    }

    /**
     * Returns all active descendant category IDs for the given category ID.
     * Uses a single DB query + BFS — no recursive DB calls.
     */
    public function getDescendantIds(int $categoryId, bool $onlyActive = true): array
    {
        $query = DB::table('categories')->whereNull('deleted_at');
        if ($onlyActive) {
            $query->where('status', 'active');
        }
        $allCats = $query->get(['id', 'parent_id']);

        $childrenMap = [];
        foreach ($allCats as $cat) {
            if ($cat->parent_id !== null) {
                $childrenMap[(int) $cat->parent_id][] = (int) $cat->id;
            }
        }

        $descendants = [];
        $visited = [$categoryId => true];
        $queue = $childrenMap[$categoryId] ?? [];

        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            $descendants[] = $id;
            foreach ($childrenMap[$id] ?? [] as $childId) {
                if (! isset($visited[$childId])) {
                    $queue[] = $childId;
                }
            }
        }

        return $descendants;
    }

    /**
     * Returns [$categoryId, ...all active descendant IDs].
     */
    public function getCategoryAndDescendantIds(int $categoryId, bool $onlyActive = true): array
    {
        return [$categoryId, ...$this->getDescendantIds($categoryId, $onlyActive)];
    }
}
