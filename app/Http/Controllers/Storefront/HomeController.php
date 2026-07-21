<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\StorefrontText;
use App\Support\StorefrontCategories;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        $categories = StorefrontCategories::roots(12);

        $newProducts = Product::query()
            ->with(['brand', 'primaryImage'])
            ->visibleOnStorefront()
            ->where(function ($query): void {
                $query->whereNotNull('primary_image')->orWhereHas('images');
            })
            ->withCount('images')
            ->orderByDesc('created_at')
            ->orderByDesc('updated_at')
            ->limit(6)
            ->get();

        $featuredProducts = Product::query()
            ->with(['brand', 'primaryImage'])
            ->visibleOnStorefront()
            ->where(function ($query): void {
                $query->whereNotNull('primary_image')->orWhereHas('images');
            })
            ->where(function ($query): void {
                $query->where('is_hit', true)->orWhere('is_featured', true);
            })
            ->whereNotIn('id', $newProducts->pluck('id'))
            ->withCount('images')
            ->orderByDesc('is_hit')
            ->orderByDesc('is_featured')
            ->orderByDesc('images_count')
            ->orderByRaw('CASE WHEN description IS NULL OR description = "" THEN 0 ELSE 1 END DESC')
            ->orderByDesc('updated_at')
            ->limit(6)
            ->get();

        if ($featuredProducts->isEmpty()) {
            $featuredProducts = Product::query()
                ->with(['brand', 'primaryImage'])
                ->visibleOnStorefront()
                ->where(function ($query): void {
                    $query->whereNotNull('primary_image')->orWhereHas('images');
                })
                ->whereNotIn('id', $newProducts->pluck('id'))
                ->withCount('images')
                ->orderByDesc('images_count')
                ->orderByRaw('CASE WHEN description IS NULL OR description = "" THEN 0 ELSE 1 END DESC')
                ->orderByDesc('quantity')
                ->orderByDesc('updated_at')
                ->limit(6)
                ->get();
        }

        $brands = Brand::query()
            ->where('status', 'active')
            ->whereHas('products')
            ->withCount('products')
            ->orderByDesc('show_on_homepage')
            ->orderByDesc('products_count')
            ->orderBy('homepage_sort_order')
            ->orderBy('name')
            ->limit(12)
            ->get();

        $products = $newProducts->concat($featuredProducts)->unique('id')->values();
        $heroProducts = $products->take(5)->values();

        return view('storefront.home', compact('categories', 'products', 'newProducts', 'featuredProducts', 'brands', 'heroProducts'));
    }
}
