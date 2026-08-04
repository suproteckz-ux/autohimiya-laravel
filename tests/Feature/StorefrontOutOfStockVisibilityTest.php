<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Support\ProductStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontOutOfStockVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_in_stock_product_page_remains_public(): void
    {
        $product = $this->product([
            'slug' => 'in-stock-product',
            'quantity' => 5,
            'stock_quantity' => 5,
            'availability' => true,
        ]);

        $this->get('/product/'.$product->slug)
            ->assertOk()
            ->assertSee('&#1042; &#1085;&#1072;&#1083;&#1080;&#1095;&#1080;&#1080;', false);
    }

    public function test_out_of_stock_product_page_remains_public(): void
    {
        $product = $this->product([
            'name' => 'Zero Stock Product',
            'slug' => 'zero-stock-product',
            'sku' => 'zero-stock-sku',
            'quantity' => 0,
            'stock_quantity' => 0,
            'availability' => false,
            'availability_status' => 'out_of_stock',
        ]);

        $this->get('/product/'.$product->slug)
            ->assertOk()
            ->assertSee('&#1053;&#1077;&#1090; &#1074; &#1085;&#1072;&#1083;&#1080;&#1095;&#1080;&#1080;', false);
    }

    public function test_inactive_product_page_is_not_public(): void
    {
        $product = $this->product([
            'slug' => 'inactive-product',
            'product_status' => ProductStatus::INACTIVE,
            'quantity' => 5,
            'stock_quantity' => 5,
            'availability' => true,
        ]);

        $this->get('/product/'.$product->slug)->assertNotFound();
    }

    public function test_deleted_product_page_is_not_public(): void
    {
        $product = $this->product([
            'slug' => 'deleted-product',
            'quantity' => 5,
            'stock_quantity' => 5,
            'availability' => true,
        ]);
        $product->delete();

        $this->get('/product/'.$product->slug)->assertNotFound();
    }

    public function test_out_of_stock_products_remain_in_category_listing_and_search(): void
    {
        $product = $this->product([
            'name' => 'Visible Zero Stock Search Product',
            'slug' => 'visible-zero-stock-search-product',
            'sku' => 'visible-zero-stock-sku',
            'quantity' => 0,
            'stock_quantity' => 0,
            'availability' => false,
            'availability_status' => 'out_of_stock',
        ]);

        $this->get('/category/'.$product->category->slug)
            ->assertOk()
            ->assertSee($product->name);

        $this->get('/search?q=visible-zero-stock-sku')
            ->assertOk()
            ->assertSee($product->name);
    }

    private function product(array $overrides = []): Product
    {
        $category = $overrides['category'] ?? Category::query()->create([
            'name' => 'Visibility Category',
            'slug' => 'visibility-category-'.str()->random(8),
            'status' => 'active',
        ]);

        unset($overrides['category']);

        return Product::query()->create(array_replace([
            'category_id' => $category->id,
            'name' => 'Storefront Product '.str()->random(8),
            'slug' => 'storefront-product-'.str()->random(8),
            'sku' => 'sku-'.str()->random(8),
            'product_status' => ProductStatus::ACTIVE_MANUAL,
            'availability' => true,
            'availability_status' => 'in_stock',
            'quantity' => 1,
            'stock_quantity' => 1,
            'price' => 2500,
        ], $overrides));
    }
}
