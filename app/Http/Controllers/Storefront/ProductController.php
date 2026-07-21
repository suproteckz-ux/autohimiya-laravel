<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\ProductSlugger;
use App\Support\StorefrontCategories;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class ProductController extends Controller
{
    public function show(string $slug): View|RedirectResponse
    {
        $product = Product::query()
            ->with(['brand', 'category.parent.parent', 'categories.parent.parent', 'primaryImage', 'images', 'attributes'])
            ->visibleOnStorefront()
            ->where('slug', $slug)
            ->first();

        if (! $product) {
            $redirect = $this->redirectFromOldSkuSlug($slug);

            if ($redirect) {
                return $redirect;
            }

            abort(404);
        }

        $category = $product->category ?: $product->categories->first();
        $categoryIds = $product->categories->pluck('id');

        if ($product->category_id) {
            $categoryIds->push($product->category_id);
        }

        $related = Product::query()
            ->with(['brand', 'primaryImage'])
            ->whereKeyNot($product->id)
            ->visibleOnStorefront()
            ->where(function ($query) use ($product, $categoryIds): void {
                $query->whereIn('category_id', $categoryIds->unique()->values())
                    ->orWhereHas('categories', fn ($categoryQuery) => $categoryQuery->whereIn('categories.id', $categoryIds->unique()->values()));

                if ($product->brand_id) {
                    $query->orWhere('brand_id', $product->brand_id);
                }
            })
            ->withCount('images')
            ->orderByDesc('images_count')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get();

        $categoryWall = StorefrontCategories::siblingsOrRoots($category ?? null, 8);

        return view('storefront.product', compact('product', 'related', 'categoryWall', 'category'));
    }

    private function redirectFromOldSkuSlug(string $slug): ?RedirectResponse
    {
        $normalized = ProductSlugger::normalizeSlug($slug);
        $skuCandidate = str_replace('-', '_', $normalized);

        $product = Product::query()
            ->visibleOnStorefront()
            ->where(function ($query) use ($slug, $normalized, $skuCandidate): void {
                $query->where('sku', $slug)
                    ->orWhere('sku', $normalized)
                    ->orWhere('sku', $skuCandidate)
                    ->orWhere('paloma_sku', $slug)
                    ->orWhere('paloma_sku', $normalized)
                    ->orWhere('paloma_sku', $skuCandidate)
                    ->orWhere('model', $slug)
                    ->orWhere('model', $normalized)
                    ->orWhere('model', $skuCandidate);
            })
            ->first();

        if (! $product || $product->slug === $slug) {
            return null;
        }

        return redirect()->route('products.show', $product->slug, 301);
    }
}
