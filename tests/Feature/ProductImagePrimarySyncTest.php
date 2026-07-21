<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Support\ProductStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductImagePrimarySyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_image_sync_selects_sorted_first_image_when_none_is_flagged(): void
    {
        $product = Product::query()->create([
            'name' => 'Cleaner',
            'slug' => 'cleaner',
            'product_status' => ProductStatus::ACTIVE_MANUAL,
            'availability' => true,
            'quantity' => 1,
            'price' => 1000,
        ]);

        ProductImage::withoutEvents(function () use ($product): void {
            ProductImage::query()->create(['product_id' => $product->id, 'path' => 'products/second.webp', 'sort_order' => 20, 'is_primary' => false]);
            ProductImage::query()->create(['product_id' => $product->id, 'path' => 'products/first.webp', 'sort_order' => 10, 'is_primary' => false]);
        });

        ProductImage::syncProductPrimaryImage($product->id);

        $product->refresh();
        $this->assertSame('products/first.webp', $product->primary_image);
        $this->assertTrue((bool) ProductImage::query()->where('path', 'products/first.webp')->value('is_primary'));
        $this->assertFalse((bool) ProductImage::query()->where('path', 'products/second.webp')->value('is_primary'));
    }

    public function test_setting_second_image_primary_clears_previous_primary(): void
    {
        $product = Product::query()->create([
            'name' => 'Shampoo',
            'slug' => 'shampoo',
            'product_status' => ProductStatus::ACTIVE_MANUAL,
            'availability' => true,
            'quantity' => 1,
            'price' => 1000,
        ]);

        $first = ProductImage::query()->create(['product_id' => $product->id, 'path' => 'products/first.webp', 'sort_order' => 10, 'is_primary' => true]);
        $second = ProductImage::query()->create(['product_id' => $product->id, 'path' => 'products/second.webp', 'sort_order' => 20, 'is_primary' => true]);

        $first->refresh();
        $second->refresh();
        $product->refresh();

        $this->assertFalse($first->is_primary);
        $this->assertTrue($second->is_primary);
        $this->assertSame('products/second.webp', $product->primary_image);
    }
}