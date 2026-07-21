<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\ProductStatus;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CatalogQaCommand extends Command
{
    protected $signature = 'catalog:qa';

    protected $description = 'Run read-only storefront data QA reports.';

    public function handle(): int
    {
        $reportsPath = storage_path('app/reports');

        if (! is_dir($reportsPath)) {
            mkdir($reportsPath, 0775, true);
        }

        $productsWithoutCategory = Product::query()
            ->whereNull('category_id')
            ->whereDoesntHave('categories')
            ->orderBy('id')
            ->get();

        $productsWithoutBrand = Product::query()
            ->whereNull('brand_id')
            ->orderBy('id')
            ->get();

        $productsWithoutImage = Product::query()
            ->whereNull('primary_image')
            ->whereDoesntHave('images')
            ->orderBy('id')
            ->get();

        $productsWithoutDescription = Product::query()
            ->where(function (Builder $query): void {
                $query->whereNull('description')->orWhere('description', '');
            })
            ->orderBy('id')
            ->get();

        $productsWithoutSeo = Product::query()
            ->where(function (Builder $query): void {
                $query->whereNull('slug')
                    ->orWhere('slug', '')
                    ->orWhereNull('meta_title')
                    ->orWhere('meta_title', '')
                    ->orWhereNull('meta_description')
                    ->orWhere('meta_description', '');
            })
            ->orderBy('id')
            ->get();

        $needsReview = Product::query()
            ->where('product_status', ProductStatus::NEEDS_REVIEW)
            ->orderBy('id')
            ->get();

        $duplicateSlugRows = Product::query()
            ->select('slug', DB::raw('COUNT(*) as products_count'))
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->groupBy('slug')
            ->having('products_count', '>', 1)
            ->orderBy('slug')
            ->get();

        $brokenImageProducts = $this->brokenImageProducts();

        $categoriesWithoutProducts = Category::query()
            ->whereDoesntHave('products')
            ->whereDoesntHave('allProducts')
            ->count();

        $brandsWithoutProducts = Brand::query()
            ->whereDoesntHave('products')
            ->count();

        $stats = [
            'Products total' => Product::query()->count(),
            ProductStatus::label(ProductStatus::ACTIVE_SYNCED) => Product::query()->where('product_status', ProductStatus::ACTIVE_SYNCED)->count(),
            'Needs review' => $needsReview->count(),
            'Products without category' => $productsWithoutCategory->count(),
            'Products without brand' => $productsWithoutBrand->count(),
            'Products without image' => $productsWithoutImage->count(),
            'Products without description' => $productsWithoutDescription->count(),
            'Products without SEO' => $productsWithoutSeo->count(),
            'Products with broken image path' => $brokenImageProducts->count(),
            'Products with duplicate slug' => (int) $duplicateSlugRows->sum('products_count'),
            'Categories without products' => $categoriesWithoutProducts,
            'Brands without products' => $brandsWithoutProducts,
        ];

        $this->writeProductCsv($reportsPath.'/QA_PRODUCTS_WITHOUT_CATEGORY.csv', $productsWithoutCategory);
        $this->writeProductCsv($reportsPath.'/QA_PRODUCTS_WITHOUT_IMAGE.csv', $productsWithoutImage);
        $this->writeProductCsv($reportsPath.'/QA_PRODUCTS_WITHOUT_DESCRIPTION.csv', $productsWithoutDescription);
        $this->writeProductCsv($reportsPath.'/QA_PRODUCTS_WITHOUT_BRAND.csv', $productsWithoutBrand);
        $this->writeProductCsv($reportsPath.'/QA_PRODUCTS_NEEDS_REVIEW.csv', $needsReview);
        $this->writeDuplicateSlugCsv($reportsPath.'/QA_DUPLICATE_SLUGS.csv', $duplicateSlugRows);

        $this->table(['Metric', 'Count'], collect($stats)->map(fn (int $count, string $metric): array => [$metric, $count])->values());
        $this->info('QA CSV reports written to: '.$reportsPath);

        return self::SUCCESS;
    }

    private function brokenImageProducts(): Collection
    {
        $productIds = collect();

        ProductImage::query()
            ->select(['product_id', 'path'])
            ->whereNotNull('path')
            ->orderBy('id')
            ->chunk(200, function (Collection $images) use (&$productIds): void {
                foreach ($images as $image) {
                    if (! Storage::disk('public')->exists($image->path)) {
                        $productIds->push($image->product_id);
                    }
                }
            });

        Product::query()
            ->select(['id', 'primary_image'])
            ->whereNotNull('primary_image')
            ->orderBy('id')
            ->chunk(200, function (Collection $products) use (&$productIds): void {
                foreach ($products as $product) {
                    if (! Storage::disk('public')->exists($product->primary_image)) {
                        $productIds->push($product->id);
                    }
                }
            });

        return Product::query()
            ->whereIn('id', $productIds->unique()->values())
            ->orderBy('id')
            ->get();
    }

    private function writeProductCsv(string $path, Collection $products): void
    {
        $file = fopen($path, 'wb');
        fputcsv($file, ['id', 'paloma_sku', 'sku', 'model', 'name', 'slug', 'product_status', 'sync_status']);

        foreach ($products as $product) {
            fputcsv($file, [
                $product->id,
                $product->paloma_sku,
                $product->sku,
                $product->model,
                $product->name,
                $product->slug,
                $product->product_status,
                $product->sync_status,
            ]);
        }

        fclose($file);
    }

    private function writeDuplicateSlugCsv(string $path, Collection $duplicates): void
    {
        $file = fopen($path, 'wb');
        fputcsv($file, ['slug', 'products_count', 'product_ids']);

        foreach ($duplicates as $duplicate) {
            $ids = Product::query()
                ->where('slug', $duplicate->slug)
                ->orderBy('id')
                ->pluck('id')
                ->implode('|');

            fputcsv($file, [$duplicate->slug, $duplicate->products_count, $ids]);
        }

        fclose($file);
    }
}
