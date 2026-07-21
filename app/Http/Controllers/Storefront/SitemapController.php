<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $products = Product::query()
            ->visibleOnStorefront()
            ->orderBy('updated_at', 'desc')
            ->get(['slug', 'updated_at']);

        $categories = Category::query()
            ->where('status', 'active')
            ->whereNotNull('slug')
            ->orderBy('updated_at', 'desc')
            ->get(['slug', 'updated_at']);

        return response()
            ->view('storefront.sitemap', compact('products', 'categories'))
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
