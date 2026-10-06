<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\ProductStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorefrontProductSortingTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_sort_prioritizes_stock_then_image(): void
    {
        [$withStockAndImage, $withStockNoImage, $noStockWithImage, $noStockNoImage] = $this->sortingFixtures();

        $orderedIds = Product::query()
            ->visibleOnStorefront()
            ->orderByStorefrontPriority()
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([
            $withStockAndImage->id,
            $withStockNoImage->id,
            $noStockWithImage->id,
            $noStockNoImage->id,
        ], $orderedIds);
    }

    public function test_catalog_default_sort_uses_storefront_priority(): void
    {
        [$withStockAndImage, $withStockNoImage, $noStockWithImage, $noStockNoImage] = $this->sortingFixtures();

        $this->get(route('catalog.index'))
            ->assertOk()
            ->assertSeeInOrder([
                $withStockAndImage->name,
                $withStockNoImage->name,
                $noStockWithImage->name,
                $noStockNoImage->name,
            ]);
    }

    public function test_all_storefront_listings_use_the_same_default_priority(): void
    {
        [$withStockAndImage, $withStockNoImage, $noStockWithImage, $noStockNoImage] = $this->sortingFixtures();
        $expectedOrder = [
            $withStockAndImage->name,
            $withStockNoImage->name,
            $noStockWithImage->name,
            $noStockNoImage->name,
        ];

        foreach ([
            route('home'),
            route('catalog.index'),
            route('categories.show', 'sorting-category'),
            route('search.index'),
        ] as $url) {
            $this->get($url)->assertOk()->assertSeeInOrder($expectedOrder);
        }

        $this->get(route('products.show', $withStockAndImage->slug))
            ->assertOk()
            ->assertSeeInOrder(array_slice($expectedOrder, 1));
    }

    public function test_explicit_price_sort_is_preserved(): void
    {
        [$withStockAndImage, $withStockNoImage, $noStockWithImage, $noStockNoImage] = $this->sortingFixtures();

        $this->get(route('catalog.index', ['sort' => 'price_asc']))
            ->assertOk()
            ->assertSeeInOrder([
                $noStockNoImage->name,
                $noStockWithImage->name,
                $withStockNoImage->name,
                $withStockAndImage->name,
            ]);
    }

    public function test_negative_quantity_is_out_of_stock(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/negative-stock.jpg', 'image');

        $category = Category::query()->create([
            'name' => 'Quantity edge cases',
            'slug' => 'quantity-edge-cases',
            'status' => 'active',
        ]);

        $inStock = $this->product($category, 'Positive quantity no image', 1, 1000);
        $negativeStock = $this->product($category, 'Negative quantity with image', -2, 1000);

        ProductImage::query()->create(['product_id' => $negativeStock->id, 'path' => 'products/negative-stock.jpg', 'is_primary' => true]);

        $this->assertSame([
            $inStock->id,
            $negativeStock->id,
        ], Product::query()->orderByStorefrontPriority()->orderBy('id')->pluck('id')->all());
    }

    public function test_empty_image_path_is_treated_as_no_image(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/valid.jpg', 'image');

        $category = Category::query()->create([
            'name' => 'Image edge cases',
            'slug' => 'image-edge-cases',
            'status' => 'active',
        ]);

        $emptyPath = $this->product($category, 'Empty image path', 1, 1000);
        $validPath = $this->product($category, 'Valid image path', 1, 1000);

        ProductImage::query()->create(['product_id' => $emptyPath->id, 'path' => '', 'is_primary' => true]);
        ProductImage::query()->create(['product_id' => $validPath->id, 'path' => 'products/valid.jpg', 'is_primary' => true]);

        $this->assertSame([
            $validPath->id,
            $emptyPath->id,
        ], Product::query()->orderByStorefrontPriority()->orderBy('id')->pluck('id')->all());
    }

    private function sortingFixtures(): array
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/with-stock.jpg', 'image');
        Storage::disk('public')->put('products/no-stock.jpg', 'image');

        $category = Category::query()->create([
            'name' => 'Sorting category',
            'slug' => 'sorting-category',
            'status' => 'active',
        ]);

        $withStockAndImage = $this->product($category, 'A in stock with image', 5, 4000);
        $withStockNoImage = $this->product($category, 'B in stock no image', 3, 3000);
        $noStockWithImage = $this->product($category, 'C no stock with image', 0, 2000);
        $noStockNoImage = $this->product($category, 'D no stock no image', 0, 1000);

        ProductImage::query()->create([
            'product_id' => $withStockAndImage->id,
            'path' => 'products/with-stock.jpg',
            'is_primary' => true,
        ]);
        ProductImage::query()->create([
            'product_id' => $noStockWithImage->id,
            'path' => 'products/no-stock.jpg',
            'is_primary' => true,
        ]);

        return [$withStockAndImage, $withStockNoImage, $noStockWithImage, $noStockNoImage];
    }

    private function product(Category $category, string $name, ?int $quantity, int $price): Product
    {
        return Product::query()->create([
            'name' => $name,
            'slug' => str($name)->slug(),
            'category_id' => $category->id,
            'product_status' => ProductStatus::ACTIVE_MANUAL,
            'availability' => $quantity > 0,
            'quantity' => $quantity,
            'price' => $price,
        ]);
    }
}
