<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Support\ProductStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontRouteSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_required_storefront_and_admin_routes_respond(): void
    {
        config(['app.url' => 'https://xn--80aesatk1az7g.kz', 'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);

        $category = Category::query()->create([
            'name' => 'Interior care',
            'slug' => 'interior-care',
            'status' => 'active',
        ]);

        Product::query()->create([
            'name' => 'Interior Cleaner',
            'slug' => 'interior-cleaner',
            'category_id' => $category->id,
            'product_status' => ProductStatus::ACTIVE_MANUAL,
            'availability' => true,
            'quantity' => 3,
            'price' => 2500,
        ]);

        $this->get('/')->assertOk();
        $this->get('/catalog')->assertOk();
        $this->get('/category/interior-care')->assertOk();
        $this->get('/product/interior-cleaner')->assertOk();
        $this->get('/admin/products')->assertRedirect('/admin/login');
    }
}