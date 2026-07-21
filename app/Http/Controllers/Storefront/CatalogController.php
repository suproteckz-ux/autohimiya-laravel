<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Support\StorefrontCategories;
use App\Support\StorefrontText;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function index(Request $request): View
    {
        $showAllCategories = $request->boolean('show_all_categories');

        $categoryTree = StorefrontCategories::roots($showAllCategories ? null : 12);

        $sort = (string) $request->query('sort', 'popular');
        $queryText = trim((string) $request->query('q', ''));

        $productsQuery = Product::query()
            ->with(['brand', 'primaryImage'])
            ->visibleOnStorefront()
            ->withCount('images');

        if ($queryText !== '') {
            $like = '%'.$queryText.'%';
            $productsQuery->where(function ($query) use ($like): void {
                $query->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('paloma_sku', 'like', $like)
                    ->orWhere('model', 'like', $like)
                    ->orWhereHas('brand', fn ($brandQuery) => $brandQuery->where('name', 'like', $like));
            });
        }

        match ($sort) {
            'price_asc' => $productsQuery->orderBy('price')->orderBy('name'),
            'price_desc' => $productsQuery->orderByDesc('price')->orderBy('name'),
            'name' => $productsQuery->orderBy('name'),
            default => $productsQuery
                ->orderByDesc('images_count')
                ->orderByRaw('CASE WHEN description IS NULL OR description = "" THEN 0 ELSE 1 END DESC')
                ->orderByDesc('updated_at'),
        };

        $popularProducts = $productsQuery->limit(12)->get();

        return view('storefront.catalog', compact('categoryTree', 'popularProducts', 'sort', 'queryText', 'showAllCategories'));
    }
}
