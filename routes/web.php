<?php

use App\Http\Controllers\Storefront\CatalogController;
use App\Http\Controllers\Storefront\CategoryController;
use App\Http\Controllers\Storefront\ContactController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\ProductController;
use App\Http\Controllers\Storefront\RobotsController;
use App\Http\Controllers\Storefront\SearchController;
use App\Http\Controllers\Storefront\SitemapController;
use App\Http\Controllers\KaspiStockFeedController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('/contacts', ContactController::class)->name('contacts');
Route::get('/category/{slug}', [CategoryController::class, 'show'])->name('categories.show');
Route::get('/product/{slug}', [ProductController::class, 'show'])->name('products.show');
Route::get('/search', [SearchController::class, 'index'])->name('search.index');
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/robots.txt', RobotsController::class)->name('robots');

Route::redirect('/admin/enrichment-tasks', '/admin/catalog-enrichment-tasks');

// Kaspi stock feed — public, no auth (Kaspi reads this)
// In OBSERVE mode this generates hypothetical stock (not yet live).
// Switch to production Kaspi settings ONLY after manual verification.
Route::get('/kaspi-feed.xml', KaspiStockFeedController::class)->name('kaspi.stock.feed');
