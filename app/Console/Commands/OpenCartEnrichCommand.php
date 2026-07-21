<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductImage;
use App\Services\OpenCart\OpenCartDumpReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class OpenCartEnrichCommand extends Command
{
    protected $signature = 'opencart:enrich {--dry-run : Analyze enrichment without database writes} {--apply : Apply OpenCart enrichment} {--only=all : all, categories, brands, descriptions, seo, images, attributes}';

    protected $description = 'Enrich matched Paloma products with OpenCart content and structure.';

    private array $stats = [];
    private array $reports = [];

    public function handle(): int
    {
        if ($this->option('dry-run') === $this->option('apply')) {
            $this->error('Run exactly one mode: --dry-run or --apply.');

            return self::FAILURE;
        }

        $only = (string) $this->option('only');
        $allowed = ['all', 'categories', 'brands', 'descriptions', 'seo', 'images', 'attributes'];

        if (! in_array($only, $allowed, true)) {
            $this->error('Unsupported --only value. Use: '.implode(', ', $allowed));

            return self::FAILURE;
        }

        $reader = new OpenCartDumpReader(
            path: config('services.opencart.sql_dump'),
            prefix: config('services.opencart.db_prefix', 'oc_'),
        );

        $diagnostics = $reader->diagnostics();

        if (! $diagnostics['file_exists']) {
            $this->table(['Check', 'Value'], [
                ['Configured SQL dump path', $diagnostics['configured_path']],
                ['Resolved SQL dump path', $diagnostics['resolved_path']],
                ['SQL dump found', 'no'],
                ['OpenCart DB prefix', $diagnostics['db_prefix']],
            ]);
            $this->error('OpenCart SQL dump is not available. Set OPENCART_SQL_DUMP and run php artisan config:clear.');

            return self::FAILURE;
        }

        $data = $this->loadData($reader);
        $matchedProducts = Product::query()
            ->whereNotNull('opencart_product_id')
            ->get();

        $this->stats = [
            'matched_products_count' => $matchedProducts->count(),
            'categories_found' => count($data['categories']),
            'categories_to_create' => 0,
            'brands_found' => count($data['manufacturers']),
            'brands_to_create' => 0,
            'product_category_links_to_create' => 0,
            'products_with_category' => 0,
            'products_without_category' => 0,
            'descriptions_to_import' => 0,
            'seo_urls_to_import' => 0,
            'images_to_import' => 0,
            'broken_images' => 0,
            'attributes_to_import' => 0,
            'opencart_only_products_skipped' => max(0, count($data['products']) - $matchedProducts->count()),
            'conflicts' => 0,
            'errors' => 0,
        ];

        if ($this->runs($only, 'categories')) {
            $this->enrichCategories($data);
        }

        if ($this->runs($only, 'brands')) {
            $this->enrichBrands($data);
        }

        if ($this->runs($only, 'descriptions')) {
            $this->enrichDescriptions($matchedProducts, $data);
        }

        if ($this->runs($only, 'seo')) {
            $this->enrichSeo($matchedProducts, $data);
        }

        if ($this->runs($only, 'images')) {
            $this->enrichImages($matchedProducts, $data);
        }

        if ($this->runs($only, 'attributes')) {
            $this->enrichAttributes($matchedProducts, $data);
        }

        if ($this->runs($only, 'categories')) {
            $this->linkProductCategories($matchedProducts, $data);
        }

        if ($this->runs($only, 'brands')) {
            $this->linkProductBrands($matchedProducts, $data);
        }

        $this->writeReports($this->option('apply') ? 'apply' : 'dry-run');
        $this->table(['Metric', 'Value'], $this->statsRows());

        $this->info($this->option('apply')
            ? 'OpenCart enrichment apply complete.'
            : 'OpenCart enrichment dry-run complete. No database writes were made.');

        return self::SUCCESS;
    }

    private function runs(string $only, string $section): bool
    {
        return $only === 'all' || $only === $section;
    }

    private function loadData(OpenCartDumpReader $reader): array
    {
        $products = collect($reader->tableRows('product'))->keyBy(fn (array $row): int => (int) $row['product_id'])->all();
        $descriptions = collect($reader->tableRows('product_description'))->keyBy(fn (array $row): int => (int) $row['product_id'])->all();
        $categories = collect($reader->tableRows('category'))->keyBy(fn (array $row): int => (int) $row['category_id'])->all();
        $categoryDescriptions = collect($reader->tableRows('category_description'))->keyBy(fn (array $row): int => (int) $row['category_id'])->all();
        $manufacturers = collect($reader->tableRows('manufacturer'))->keyBy(fn (array $row): int => (int) $row['manufacturer_id'])->all();
        $aliases = array_replace(
            $this->aliasesByQuery($reader->tableRows('url_alias')),
            $this->aliasesByQuery($reader->tableRows('seo_url')),
        );

        return [
            'products' => $products,
            'descriptions' => $descriptions,
            'categories' => $categories,
            'category_descriptions' => $categoryDescriptions,
            'category_paths' => $reader->tableRows('category_path'),
            'manufacturers' => $manufacturers,
            'product_to_category' => $reader->tableRows('product_to_category'),
            'url_aliases' => $aliases,
            'product_images' => $reader->tableRows('product_image'),
            'product_attributes' => $reader->tableRows('product_attribute'),
            'attribute_descriptions' => collect($reader->tableRows('attribute_description'))->keyBy(fn (array $row): int => (int) $row['attribute_id'])->all(),
        ];
    }

    private function enrichCategories(array $data): void
    {
        foreach ($data['categories'] as $opencartId => $category) {
            $description = $data['category_descriptions'][$opencartId] ?? [];
            $existing = Category::query()->where('opencart_category_id', $opencartId)->first();
            $name = $description['name'] ?? null;

            if (! $existing) {
                $this->stats['categories_to_create']++;
            }

            if (! $this->option('apply')) {
                continue;
            }

            Category::query()->updateOrCreate(
                ['opencart_category_id' => $opencartId],
                [
                    'name' => filled($name) ? $name : 'Category '.$opencartId,
                    'slug' => $this->uniqueSlug(Category::class, $data['url_aliases']['category_id='.$opencartId] ?? $name ?? 'category-'.$opencartId, $existing),
                    'image' => filled($category['image'] ?? null) ? $category['image'] : null,
                    'description' => filled($description['description'] ?? null) ? $description['description'] : null,
                    'meta_title' => filled($description['meta_title'] ?? null) ? $description['meta_title'] : null,
                    'meta_description' => filled($description['meta_description'] ?? null) ? $description['meta_description'] : null,
                    'h1' => filled($description['meta_h1'] ?? null) ? $description['meta_h1'] : $name,
                    'sort_order' => (int) ($category['sort_order'] ?? 0),
                    'status' => ((int) ($category['status'] ?? 1)) === 1 ? 'active' : 'inactive',
                ],
            );
        }

        if (! $this->option('apply')) {
            return;
        }

        foreach ($data['categories'] as $opencartId => $category) {
            $parentOpenCartId = (int) ($category['parent_id'] ?? 0);

            if ($parentOpenCartId <= 0) {
                continue;
            }

            $parent = Category::query()->where('opencart_category_id', $parentOpenCartId)->first();
            $child = Category::query()->where('opencart_category_id', $opencartId)->first();

            if ($parent && $child) {
                $child->update(['parent_id' => $parent->id]);
            }
        }
    }

    private function enrichBrands(array $data): void
    {
        foreach ($data['manufacturers'] as $opencartId => $manufacturer) {
            $existing = Brand::query()->where('opencart_manufacturer_id', $opencartId)->first();

            if (! $existing) {
                $this->stats['brands_to_create']++;
            }

            if (! $this->option('apply')) {
                continue;
            }

            Brand::query()->updateOrCreate(
                ['opencart_manufacturer_id' => $opencartId],
                [
                    'name' => filled($manufacturer['name'] ?? null) ? $manufacturer['name'] : 'Brand '.$opencartId,
                    'slug' => $this->uniqueSlug(Brand::class, $data['url_aliases']['manufacturer_id='.$opencartId] ?? $manufacturer['name'] ?? 'brand-'.$opencartId, $existing),
                    'logo' => filled($manufacturer['image'] ?? null) ? $manufacturer['image'] : null,
                    'sort_order' => (int) ($manufacturer['sort_order'] ?? 0),
                    'status' => 'active',
                ],
            );
        }
    }

    private function linkProductCategories($matchedProducts, array $data): void
    {
        $links = collect($data['product_to_category'])->groupBy(fn (array $row): int => (int) $row['product_id']);
        $depth = collect($data['category_paths'])
            ->groupBy(fn (array $row): int => (int) $row['category_id'])
            ->map(fn ($rows): int => collect($rows)->max(fn (array $row): int => (int) ($row['level'] ?? 0)));

        $defaultCategoryId = \App\Services\Catalog\DefaultCategoryResolver::getOrCreateNewProductsCategoryId();

        foreach ($matchedProducts as $product) {
            $rows = $links[(int) $product->opencart_product_id] ?? collect();

            if ($rows->isEmpty()) {
                $this->stats['products_without_category']++;
                $this->reports['without_category'][] = [$product->paloma_sku, $product->name, $product->opencart_product_id];
                continue;
            }

            $this->stats['products_with_category']++;
            $this->stats['product_category_links_to_create'] += $rows->count();

            if (! $this->option('apply')) {
                continue;
            }

            $categoryIds = [];

            foreach ($rows as $row) {
                $category = Category::query()->where('opencart_category_id', (int) $row['category_id'])->first();

                if ($category) {
                    $categoryIds[] = $category->id;
                }
            }

            if ($categoryIds === []) {
                continue;
            }

            $product->categories()->syncWithoutDetaching($categoryIds);

            $primaryOpenCartCategoryId = collect($rows)
                ->sortByDesc(fn (array $row): int => $depth[(int) $row['category_id']] ?? 0)
                ->first()['category_id'];
            $primary = Category::query()->where('opencart_category_id', (int) $primaryOpenCartCategoryId)->first();

            if ($primary) {
                $product->update(['category_id' => $primary->id]);

                // Remove the default "Новые товары" pivot entry when the product
                // has been assigned a real category and OpenCart did not explicitly
                // map it to "Новые товары".
                if (! in_array($defaultCategoryId, $categoryIds, true)) {
                    $product->categories()->detach($defaultCategoryId);
                }
            }
        }
    }

    private function linkProductBrands($matchedProducts, array $data): void
    {
        foreach ($matchedProducts as $product) {
            $openCartProduct = $data['products'][(int) $product->opencart_product_id] ?? null;
            $manufacturerId = (int) ($openCartProduct['manufacturer_id'] ?? 0);

            if ($manufacturerId <= 0) {
                continue;
            }

            if (! $this->option('apply')) {
                continue;
            }

            $brand = Brand::query()->where('opencart_manufacturer_id', $manufacturerId)->first();

            if ($brand) {
                $product->update(['brand_id' => $brand->id]);
            }
        }
    }

    private function enrichDescriptions($matchedProducts, array $data): void
    {
        foreach ($matchedProducts as $product) {
            $description = $data['descriptions'][(int) $product->opencart_product_id] ?? null;

            if (! $description) {
                continue;
            }

            $fields = [
                'description' => $description['description'] ?? null,
                'meta_title' => $description['meta_title'] ?? null,
                'meta_description' => $description['meta_description'] ?? null,
                'h1' => $description['meta_h1'] ?? ($description['name'] ?? null),
            ];
            $updates = [];

            foreach ($fields as $field => $value) {
                if (blank($product->{$field}) && filled($value)) {
                    $updates[$field] = $value;
                }
            }

            if ($updates !== []) {
                $this->stats['descriptions_to_import']++;
            }

            if ($this->option('apply') && $updates !== []) {
                $product->update($updates);
            }
        }
    }

    private function enrichSeo($matchedProducts, array $data): void
    {
        foreach ($data['categories'] as $opencartId => $categoryRow) {
            $keyword = $data['url_aliases']['category_id='.$opencartId] ?? null;

            if (blank($keyword)) {
                continue;
            }

            $category = Category::query()->where('opencart_category_id', $opencartId)->first();

            if (! $category) {
                continue;
            }

            $this->stats['seo_urls_to_import']++;

            if ($this->option('apply')) {
                $category->update(['slug' => $this->uniqueSlug(Category::class, $keyword, $category)]);
            }
        }

        foreach ($matchedProducts as $product) {
            $keyword = $data['url_aliases']['product_id='.$product->opencart_product_id] ?? null;

            if (blank($keyword)) {
                $this->reports['without_seo'][] = [$product->paloma_sku, $product->name, $product->opencart_product_id];
                continue;
            }

            $this->stats['seo_urls_to_import']++;

            if (! $this->option('apply')) {
                continue;
            }

            $product->update(['slug' => $this->uniqueSlug(Product::class, $keyword, $product)]);
        }
    }

    private function enrichImages($matchedProducts, array $data): void
    {
        $gallery = collect($data['product_images'])->groupBy(fn (array $row): int => (int) $row['product_id']);
        $projectRoot = trim((string) config('services.opencart.project_root', base_path('..')), "\"'");

        foreach ($matchedProducts as $product) {
            $openCartProduct = $data['products'][(int) $product->opencart_product_id] ?? [];
            $paths = [];

            if (filled($openCartProduct['image'] ?? null)) {
                $paths[] = ['id' => null, 'path' => $openCartProduct['image'], 'primary' => true, 'sort' => 0];
            }

            foreach (($gallery[(int) $product->opencart_product_id] ?? collect()) as $image) {
                $paths[] = ['id' => $image['product_image_id'] ?? null, 'path' => $image['image'] ?? null, 'primary' => false, 'sort' => (int) ($image['sort_order'] ?? 0)];
            }

            if ($paths === []) {
                $this->reports['without_image'][] = [$product->paloma_sku, $product->name, $product->opencart_product_id];
                continue;
            }

            foreach ($paths as $image) {
                if (blank($image['path'])) {
                    continue;
                }

                $source = $projectRoot.DIRECTORY_SEPARATOR.'image'.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $image['path']);

                if (! is_file($source)) {
                    $this->stats['broken_images']++;
                    $this->reports['broken_images'][] = [$product->paloma_sku, $product->opencart_product_id, $image['path'], $source];
                    continue;
                }

                $this->stats['images_to_import']++;

                if (! $this->option('apply')) {
                    continue;
                }

                $target = 'products/opencart/'.$product->id.'/'.basename((string) $image['path']);
                $targetFullPath = storage_path('app/public/'.$target);

                File::ensureDirectoryExists(dirname($targetFullPath));

                if (! is_file($targetFullPath)) {
                    File::copy($source, $targetFullPath);
                }

                ProductImage::query()->updateOrCreate(
                    ['product_id' => $product->id, 'original_path' => $image['path']],
                    [
                        'opencart_image_id' => $image['id'],
                        'path' => $target,
                        'role' => $image['primary'] ? 'primary' : 'gallery',
                        'sort_order' => $image['sort'],
                        'is_primary' => $image['primary'],
                    ],
                );

                if ($image['primary'] && blank($product->primary_image)) {
                    $product->update(['primary_image' => $target]);
                }
            }
        }
    }

    private function enrichAttributes($matchedProducts, array $data): void
    {
        $attributes = collect($data['product_attributes'])->groupBy(fn (array $row): int => (int) $row['product_id']);

        foreach ($matchedProducts as $product) {
            foreach (($attributes[(int) $product->opencart_product_id] ?? collect()) as $row) {
                $attributeId = (int) ($row['attribute_id'] ?? 0);
                $name = $data['attribute_descriptions'][$attributeId]['name'] ?? null;

                if (blank($name) || blank($row['text'] ?? null)) {
                    continue;
                }

                $this->stats['attributes_to_import']++;

                if (! $this->option('apply')) {
                    continue;
                }

                ProductAttribute::query()->updateOrCreate(
                    ['product_id' => $product->id, 'opencart_attribute_id' => $attributeId, 'name' => $name],
                    ['value' => $row['text'], 'sort_order' => 0],
                );
            }
        }
    }

    private function aliasesByQuery(array $rows): array
    {
        $aliases = [];

        foreach ($rows as $row) {
            if (filled($row['query'] ?? null) && filled($row['keyword'] ?? null)) {
                $aliases[$row['query']] = $row['keyword'];
            }
        }

        return $aliases;
    }

    private function uniqueSlug(string $modelClass, string $source, ?object $existing = null): string
    {
        $base = Str::slug($this->seoSlugSource($source)) ?: Str::uuid()->toString();
        $slug = $base;
        $counter = 2;

        while ($modelClass::query()
            ->where('slug', $slug)
            ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))
            ->exists()) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    private function seoSlugSource(string $source): string
    {
        $source = trim($source);
        $source = strtok($source, '?#') ?: $source;
        $source = trim($source, "/\\ \t\n\r\0\x0B");

        if (str_contains($source, '/') || str_contains($source, '\\')) {
            $source = basename(str_replace('\\', '/', $source));
        }

        return $source;
    }

    private function writeReports(string $mode): void
    {
        $dir = storage_path('app/reports');
        File::ensureDirectoryExists($dir);

        $this->writeCsv($dir.'/OPENCART_ENRICH_'.strtoupper($mode === 'apply' ? 'APPLY' : 'DRY_RUN').'_REPORT.csv', [
            ['metric', 'value'],
            ...array_map(fn (array $row): array => [$row[0], $row[1]], $this->statsRows()),
        ]);
        $this->writeCsv($dir.'/OPENCART_BROKEN_IMAGES.csv', [['paloma_sku', 'opencart_product_id', 'image', 'resolved_path'], ...($this->reports['broken_images'] ?? [])]);
        $this->writeCsv($dir.'/OPENCART_PRODUCTS_WITHOUT_CATEGORY.csv', [['paloma_sku', 'name', 'opencart_product_id'], ...($this->reports['without_category'] ?? [])]);
        $this->writeCsv($dir.'/OPENCART_PRODUCTS_WITHOUT_IMAGE.csv', [['paloma_sku', 'name', 'opencart_product_id'], ...($this->reports['without_image'] ?? [])]);
        $this->writeCsv($dir.'/OPENCART_PRODUCTS_WITHOUT_SEO.csv', [['paloma_sku', 'name', 'opencart_product_id'], ...($this->reports['without_seo'] ?? [])]);
    }

    private function writeCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new \RuntimeException('Unable to write report: '.$path);
        }

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
    }

    private function statsRows(): array
    {
        return [
            ['matched products count', $this->stats['matched_products_count']],
            ['categories found', $this->stats['categories_found']],
            ['categories to create', $this->stats['categories_to_create']],
            ['brands found', $this->stats['brands_found']],
            ['brands to create', $this->stats['brands_to_create']],
            ['product-category links to create', $this->stats['product_category_links_to_create']],
            ['products with category', $this->stats['products_with_category']],
            ['products without category', $this->stats['products_without_category']],
            ['descriptions to import', $this->stats['descriptions_to_import']],
            ['SEO URLs to import', $this->stats['seo_urls_to_import']],
            ['images to import', $this->stats['images_to_import']],
            ['broken images', $this->stats['broken_images']],
            ['attributes to import', $this->stats['attributes_to_import']],
            ['OpenCart-only products skipped', $this->stats['opencart_only_products_skipped']],
            ['conflicts', $this->stats['conflicts']],
            ['errors', $this->stats['errors']],
        ];
    }
}
