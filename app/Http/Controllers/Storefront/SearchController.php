<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function index(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));

        $products = Product::query()
            ->with(['brand', 'primaryImage'])
            ->visibleOnStorefront()
            ->when($query !== '', function ($builder) use ($query): void {
                $like = '%'.$query.'%';

                $builder->where(function ($productQuery) use ($like): void {
                    $productQuery->where('name', 'like', $like)
                        ->orWhere('sku', 'like', $like)
                        ->orWhere('paloma_sku', 'like', $like)
                        ->orWhere('model', 'like', $like)
                        ->orWhereHas('brand', fn ($brandQuery) => $brandQuery->where('name', 'like', $like));
                });
            })
            ->withCount('images')
            ->orderByDesc('images_count')
            ->orderBy('name')
            ->paginate(24)
            ->withQueryString();

        return view('storefront.search', compact('query', 'products'));
    }
}
