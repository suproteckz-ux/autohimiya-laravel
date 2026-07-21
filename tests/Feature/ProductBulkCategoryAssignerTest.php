<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\DefaultCategoryResolver;
use App\Services\Catalog\ProductBulkCategoryAssigner;
use App\Support\ProductStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductBulkCategoryAssignerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_assigns_primary_category_marks_manual_and_updates_pivot(): void
    {
        $default = Category::query()->create([
            'name' => DefaultCategoryResolver::NEW_PRODUCTS_NAME,
            'slug' => DefaultCategoryResolver::NEW_PRODUCTS_SLUG,
            'status' => 'active',
        ]);
        $target = Category::query()->create(['name' => 'Polish', 'slug' => 'polish', 'status' => 'active']);
        $product = Product::query()->create([
            'name' => 'Wax',
            'slug' => 'wax',
            'category_id' => $default->id,
            'product_status' => ProductStatus::ACTIVE_MANUAL,
            'availability' => true,
            'quantity' => 1,
            'price' => 1000,
        ]);
        $product->categories()->attach($default->id);

        $updated = app(ProductBulkCategoryAssigner::class)->assign(collect([$product]), $target->id);

        $product->refresh();
        $this->assertSame(1, $updated);
        $this->assertSame($target->id, $product->category_id);
        $this->assertTrue($product->category_is_manual);
        $this->assertTrue($product->categories()->whereKey($target->id)->exists());
        $this->assertFalse($product->categories()->whereKey($default->id)->exists());
    }
}