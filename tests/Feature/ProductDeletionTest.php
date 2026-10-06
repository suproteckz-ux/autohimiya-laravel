<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\Category;
use App\Models\KaspiEnrichmentTask;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_can_be_soft_deleted_from_the_table(): void
    {
        $product = Product::factory()->create(['category_id' => null]);

        Livewire::test(ListProducts::class)
            ->callTableAction('delete', $product)
            ->assertNotified('Товар удалён');

        $this->assertSoftDeleted($product);
    }

    public function test_multiple_products_can_be_soft_deleted_in_one_bulk_action(): void
    {
        $products = Product::factory()->count(3)->create();

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('delete', $products)
            ->assertNotified('Выбранные товары удалены');

        $products->each(fn (Product $product) => $this->assertSoftDeleted($product));
    }

    public function test_soft_delete_preserves_related_product_content(): void
    {
        $category = Category::query()->create(['name' => 'Test', 'slug' => 'test', 'status' => 'active']);
        $product = Product::factory()->create(['category_id' => $category->id]);
        $image = ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/test.jpg',
            'role' => 'primary',
            'is_primary' => true,
            'sort_order' => 0,
        ]);
        $task = KaspiEnrichmentTask::query()->create([
            'product_id' => $product->id,
            'kaspi_merchant_sku' => $product->sku,
            'status' => 'pending',
            'source' => 'test',
        ]);

        Livewire::test(ListProducts::class)->callTableAction('delete', $product);

        $this->assertSoftDeleted($product);
        $this->assertDatabaseHas('product_images', ['id' => $image->id, 'product_id' => $product->id]);
        $this->assertDatabaseHas('kaspi_enrichment_tasks', ['id' => $task->id, 'product_id' => $product->id]);
    }
}
