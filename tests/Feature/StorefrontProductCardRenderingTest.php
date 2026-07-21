<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Support\ProductStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontProductCardRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_card_uses_storefront_card_thumbnail_path(): void
    {
        $product = Product::query()->create([
            'name' => 'Detailer',
            'slug' => 'detailer',
            'product_status' => ProductStatus::ACTIVE_MANUAL,
            'availability' => true,
            'quantity' => 1,
            'price' => 1000,
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/original.webp',
            'card_thumb_path' => 'products/card.webp',
            'sort_order' => 1,
            'is_primary' => true,
        ]);

        $html = (string) view('components.product-card', ['product' => $product->fresh(['brand', 'primaryImage'])]);

        $this->assertStringContainsString('storage/products/card.webp', $html);
        $this->assertStringContainsString('class="image-fit-product"', $html);
    }
}