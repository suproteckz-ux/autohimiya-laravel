<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Services\CategoryTreeService;
use App\Support\StorefrontCategories;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function show(Request $request, string $slug): View
    {
        $category = Category::query()
            ->with(['parent.parent', 'children'])
            ->where('slug', $slug)
            ->where('status', 'active')
            ->firstOrFail();

        $service = app(CategoryTreeService::class);

        // Single DB query for all descendant IDs — no recursive per-level queries
        $descendantIds = $service->getDescendantIds($category->id, onlyActive: true);
        $categoryIds = [$category->id, ...$descendantIds];

        $sort = (string) $request->query('sort', 'popular');

        $productsQuery = Product::query()
            ->with(['brand', 'primaryImage'])
            ->visibleOnStorefront()
            ->withCount('images')
            ->where(function ($query) use ($categoryIds): void {
                // Include products assigned via FK (primary) OR via pivot (secondary)
                $query->whereIn('category_id', $categoryIds)
                    ->orWhereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds));
            });

        match ($sort) {
            'new' => $productsQuery->orderByDesc('created_at')->orderByDesc('updated_at'),
            'price_asc' => $productsQuery->orderBy('price')->orderBy('name'),
            'price_desc' => $productsQuery->orderByDesc('price')->orderBy('name'),
            'name' => $productsQuery->orderBy('name'),
            default => $productsQuery
                ->orderByDesc('images_count')
                ->orderByRaw('CASE WHEN description IS NULL OR description = "" THEN 0 ELSE 1 END DESC')
                ->orderByDesc('updated_at'),
        };

        $products = $productsQuery->paginate(24)->withQueryString();

        // Show ALL active direct children — never hide based on product count.
        // Counts come from the combined FK+pivot service so they are accurate.
        $countsMap = $service->getProductCountsMap();
        $subcategories = $category->children()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->filter(fn (Category $sub): bool => $sub->has_human_name)
            ->each(fn (Category $sub) => $sub->products_count = $countsMap[$sub->id] ?? 0)
            ->values();

        // Pass showOnlyNonEmpty:false so the sidebar can expand the full active branch,
        // including zero-product leaf categories (e.g. "На дефлектор").
        $categoryTree = StorefrontCategories::roots(showOnlyNonEmpty: false);

        return view('storefront.category', compact('category', 'subcategories', 'products', 'categoryTree', 'sort'));
    }
}
