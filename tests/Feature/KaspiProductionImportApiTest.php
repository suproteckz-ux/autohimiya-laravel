<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\KaspiImportReceipt;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductImage;
use App\Support\ProductStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KaspiProductionImportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.kaspi.production_import_token' => 'secret-token']);
        config(['services.kaspi.image_max_bytes' => 1024 * 1024]);
        Storage::fake('public');
    }

    public function test_valid_token_and_payload_imports_content_without_touching_protected_business_fields(): void
    {
        $category = Category::query()->create(['name' => 'Cat', 'slug' => 'cat']);
        $brand = Brand::query()->create(['name' => 'Brand', 'slug' => 'brand']);
        $product = $this->product('aut_608', [
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'price' => 777,
            'quantity' => 12,
            'stock_quantity' => 12,
        ]);
        Http::fake(['resources.cdn-kaspi.kz/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);

        $response = $this->withToken('secret-token')->postJson('/api/internal/kaspi-content/import', $this->payload('aut_608'));

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('status', 'imported');
        $product->refresh();
        $this->assertSame('<p>Kaspi description</p>', $product->description);
        $this->assertSame('777.00', $product->price);
        $this->assertSame(12, $product->quantity);
        $this->assertSame(12, $product->stock_quantity);
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame($brand->id, $product->brand_id);
        $this->assertSame('aut_608', $product->sku);
        $this->assertSame(1, $product->images()->where('source', 'kaspi')->count());
        $this->assertSame(1, $product->attributes()->where('group_name', 'Kaspi')->count());
    }

    public function test_missing_or_invalid_token_returns_401_and_does_not_log_token(): void
    {
        Log::spy();
        $this->product('aut_608');

        $this->postJson('/api/internal/kaspi-content/import', $this->payload('aut_608'))->assertUnauthorized();
        $this->withToken('wrong-token')->postJson('/api/internal/kaspi-content/import', $this->payload('aut_608'))->assertUnauthorized();

        Log::shouldNotHaveReceived('error');
        $this->assertSame(0, KaspiImportReceipt::query()->count());
    }

    public function test_candidate_endpoint_requires_token_and_returns_only_missing_content_products(): void
    {
        $noImage = $this->product('no_image', ['description' => 'Has description']);
        $emptyDescription = $this->product('empty_description', ['description' => null]);
        ProductImage::query()->create(['product_id' => $emptyDescription->id, 'path' => 'products/existing.jpg']);
        $complete = $this->product('complete', ['description' => 'Has description']);
        ProductImage::query()->create(['product_id' => $complete->id, 'path' => 'products/existing-complete.jpg']);

        $this->getJson('/api/internal/kaspi-content/candidates')->assertUnauthorized();

        $response = $this->withToken('secret-token')->getJson('/api/internal/kaspi-content/candidates?limit=10');

        $response->assertOk();
        $skus = collect($response->json('data'))->pluck('sku')->all();
        $this->assertContains('no_image', $skus);
        $this->assertContains('empty_description', $skus);
        $this->assertNotContains('complete', $skus);
        $this->assertSame([
            'sku',
            'name',
            'kaspi_product_url',
            'has_images',
            'has_description',
            'has_attributes',
            'manual_content_protected',
        ], array_keys($response->json('data.0')));
        $this->assertArrayNotHasKey('price', $response->json('data.0'));
        $this->assertArrayNotHasKey('stock_quantity', $response->json('data.0'));
    }

    public function test_auto_locked_products_are_excluded_by_default_from_candidates(): void
    {
        $this->product('protected', ['description' => '', 'auto_content_locked' => true]);

        $response = $this->withToken('secret-token')->getJson('/api/internal/kaspi-content/candidates?limit=10');

        $response->assertOk();
        $this->assertNotContains('protected', collect($response->json('data'))->pluck('sku')->all());
    }

    public function test_empty_manual_photo_and_description_fields_remain_candidates(): void
    {
        $noImagesManual = $this->product('no_images_manual', ['description' => 'Has description', 'photos_are_manual' => true]);
        $emptyDescriptionManual = $this->product('empty_description_manual', ['description' => '<p><br></p>', 'description_is_manual' => true]);
        ProductImage::query()->create(['product_id' => $emptyDescriptionManual->id, 'path' => 'products/existing.jpg']);
        $completeManual = $this->product('complete_manual', ['description' => 'Manual description', 'photos_are_manual' => true, 'description_is_manual' => true]);
        ProductImage::query()->create(['product_id' => $completeManual->id, 'path' => 'products/existing-complete.jpg']);
        $this->product('auto_locked_missing', ['description' => '', 'auto_content_locked' => true]);

        $response = $this->withToken('secret-token')->getJson('/api/internal/kaspi-content/candidates?limit=10');

        $response->assertOk();
        $rows = collect($response->json('data'))->keyBy('sku');
        $this->assertTrue($rows->has('no_images_manual'));
        $this->assertTrue($rows->has('empty_description_manual'));
        $this->assertFalse($rows->has('complete_manual'));
        $this->assertFalse($rows->has('auto_locked_missing'));
        $this->assertFalse($rows['no_images_manual']['has_images']);
        $this->assertTrue($rows['no_images_manual']['has_description']);
        $this->assertFalse($rows['empty_description_manual']['has_description']);
    }

    public function test_candidate_debug_diagnostics_explain_filter_rejections_and_requested_sku(): void
    {
        $this->product('manual_debug', ['description' => '', 'auto_content_locked' => true]);
        $complete = $this->product('aut_608', ['description' => 'Has description']);
        ProductImage::query()->create(['product_id' => $complete->id, 'path' => 'products/existing.jpg']);
        ProductAttribute::query()->create(['product_id' => $complete->id, 'name' => 'Volume', 'value' => '1 L']);
        $this->product('missing_url', ['description' => 'Has description', 'kaspi_product_url' => null]);

        $global = $this->withToken('secret-token')->getJson('/api/internal/kaspi-content/candidates?limit=10&debug=true');
        $global->assertOk();
        $this->assertSame(3, $global->json('diagnostics.total_products'));
        $this->assertSame(1, $global->json('diagnostics.rejected.manual_content_protected'));
        $this->assertSame(1, $global->json('diagnostics.rejected.has_images'));
        $this->assertSame(1, $global->json('diagnostics.rejected.has_description'));
        $this->assertSame(0, $global->json('diagnostics.rejected.has_attributes'));
        $this->assertSame(0, $global->json('diagnostics.rejected.missing_kaspi_url'));

        $sku = $this->withToken('secret-token')->getJson('/api/internal/kaspi-content/candidates?sku[]=aut_608&limit=1&debug=true');
        $sku->assertOk();
        $this->assertSame([], $sku->json('data'));
        $this->assertSame(2, $sku->json('diagnostics.rejected.sku_filter'));
        $this->assertSame('aut_608', $sku->json('diagnostics.requested_skus.0.sku'));
        $this->assertFalse($sku->json('diagnostics.requested_skus.0.manual_content_protected'));
        $this->assertTrue($sku->json('diagnostics.requested_skus.0.has_images'));
        $this->assertTrue($sku->json('diagnostics.requested_skus.0.has_description'));
        $this->assertTrue($sku->json('diagnostics.requested_skus.0.has_attributes'));
        $this->assertSame('present', $sku->json('diagnostics.requested_skus.0.kaspi_url'));
        $this->assertSame('has_images_and_has_description', $sku->json('diagnostics.requested_skus.0.excluded_because'));
    }

    public function test_candidate_pagination_cursor_does_not_duplicate_products(): void
    {
        $this->product('page_1', ['description' => '']);
        $this->product('page_2', ['description' => '']);
        $this->product('page_3', ['description' => '']);

        $first = $this->withToken('secret-token')->getJson('/api/internal/kaspi-content/candidates?limit=2')->assertOk();
        $second = $this->withToken('secret-token')->getJson('/api/internal/kaspi-content/candidates?limit=2&cursor='.$first->json('next_cursor'))->assertOk();

        $all = collect($first->json('data'))->pluck('sku')->merge(collect($second->json('data'))->pluck('sku'))->all();
        $this->assertCount(count(array_unique($all)), $all);
        $this->assertContains('page_3', $all);
    }

    public function test_invalid_payload_returns_422(): void
    {
        $response = $this->withToken('secret-token')->postJson('/api/internal/kaspi-content/import', [
            'version' => 2,
            'request_id' => 'not-a-uuid',
            'sku' => '',
            'kaspi_url' => 'http://example.test',
        ]);

        $response->assertStatus(422)->assertJsonPath('error', 'validation_failed');
    }

    public function test_unknown_and_duplicate_normalized_sku_are_safe_errors(): void
    {
        $this->withToken('secret-token')
            ->postJson('/api/internal/kaspi-content/import', $this->payload('missing'))
            ->assertNotFound()
            ->assertJsonPath('error', 'product_not_found');

        $this->product('AUT 608');
        $this->product('aut608');

        $this->withToken('secret-token')
            ->postJson('/api/internal/kaspi-content/import', $this->payload('aut_608', ['sku' => 'aut608', 'request_id' => '8fb91896-7e3d-4f74-bf7f-b0d9bf471234']))
            ->assertStatus(409)
            ->assertJsonPath('error', 'duplicate_sku_conflict');
    }

    public function test_normalized_sku_matching_works(): void
    {
        $this->product('AUT 608');
        Http::fake(['resources.cdn-kaspi.kz/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);

        $this->withToken('secret-token')
            ->postJson('/api/internal/kaspi-content/import', $this->payload('aut608', ['content' => ['images' => []]]))
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_manual_content_protection_returns_409_without_partial_update(): void
    {
        $product = $this->product('aut_608', ['description_is_manual' => true, 'attributes_are_manual' => true, 'description' => 'Manual']);
        ProductImage::query()->create(['product_id' => $product->id, 'path' => 'products/existing.jpg', 'source' => 'manual']);

        $this->withToken('secret-token')
            ->postJson('/api/internal/kaspi-content/import', $this->payload('aut_608'))
            ->assertStatus(409)
            ->assertJsonPath('error', 'manual_content_protected');

        $this->assertSame('Manual', $product->refresh()->description);
        $this->assertSame(1, ProductImage::query()->count());
    }

    public function test_manual_empty_fields_can_be_filled_without_overwriting_existing_manual_content(): void
    {
        Http::fake(['resources.cdn-kaspi.kz/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);

        $noImages = $this->product('no_images', ['description' => 'Manual description', 'photos_are_manual' => true, 'description_is_manual' => true]);
        $this->withToken('secret-token')
            ->postJson('/api/internal/kaspi-content/import', $this->payload('no_images', ['request_id' => '8fb91896-7e3d-4f74-bf7f-b0d9bf471001']))
            ->assertOk()
            ->assertJsonPath('status', 'imported');
        $this->assertSame('Manual description', $noImages->refresh()->description);
        $this->assertSame(1, $noImages->images()->where('source', 'kaspi')->count());

        $emptyDescription = $this->product('empty_manual_description', ['description' => '<p><br></p>', 'description_is_manual' => true, 'photos_are_manual' => true]);
        ProductImage::query()->create(['product_id' => $emptyDescription->id, 'path' => 'products/existing.jpg', 'source' => 'manual']);
        $this->withToken('secret-token')
            ->postJson('/api/internal/kaspi-content/import', $this->payload('empty_manual_description', ['request_id' => '8fb91896-7e3d-4f74-bf7f-b0d9bf471002']))
            ->assertOk()
            ->assertJsonPath('status', 'imported');
        $emptyDescription->refresh();
        $this->assertSame('<p>Kaspi description</p>', $emptyDescription->description);
        $this->assertSame(1, $emptyDescription->images()->count());
        $this->assertSame('products/existing.jpg', $emptyDescription->images()->first()->path);
    }

    public function test_empty_manual_attributes_can_be_filled_from_kaspi(): void
    {
        $product = $this->product('manual_empty_attributes', ['attributes_are_manual' => true]);
        Http::fake(['resources.cdn-kaspi.kz/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);

        $this->withToken('secret-token')
            ->postJson('/api/internal/kaspi-content/import', $this->payload('manual_empty_attributes', [
                'request_id' => '8fb91896-7e3d-4f74-bf7f-b0d9bf471003',
            ]))
            ->assertOk()
            ->assertJsonPath('result.attributes_updated', 1);

        $attribute = $product->refresh()->attributes()->first();
        $this->assertNotNull($attribute);
        $this->assertSame('Kaspi', $attribute->group_name);
        $this->assertSame('Объем', $attribute->name);
        $this->assertSame('1 л', $attribute->value);
    }

    public function test_existing_manual_attributes_are_preserved_from_kaspi_mass_import(): void
    {
        $product = $this->product('manual_existing_attributes', ['attributes_are_manual' => true]);
        ProductAttribute::query()->create([
            'product_id' => $product->id,
            'group_name' => 'Manual',
            'name' => 'Existing',
            'value' => 'Keep me',
            'sort_order' => 0,
        ]);
        Http::fake(['resources.cdn-kaspi.kz/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);

        $this->withToken('secret-token')
            ->postJson('/api/internal/kaspi-content/import', $this->payload('manual_existing_attributes', [
                'request_id' => '8fb91896-7e3d-4f74-bf7f-b0d9bf471004',
            ]))
            ->assertOk()
            ->assertJsonPath('result.attributes_updated', 0);

        $attributes = $product->refresh()->attributes()->get();
        $this->assertCount(1, $attributes);
        $this->assertSame('Existing', $attributes->first()->name);
        $this->assertSame('Keep me', $attributes->first()->value);
    }

    public function test_non_manual_empty_attributes_still_import_from_kaspi(): void
    {
        $product = $this->product('regular_empty_attributes', ['attributes_are_manual' => false]);
        Http::fake(['resources.cdn-kaspi.kz/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);

        $this->withToken('secret-token')
            ->postJson('/api/internal/kaspi-content/import', $this->payload('regular_empty_attributes', [
                'request_id' => '8fb91896-7e3d-4f74-bf7f-b0d9bf471005',
            ]))
            ->assertOk()
            ->assertJsonPath('result.attributes_updated', 1);

        $this->assertSame(1, $product->refresh()->attributes()->where('group_name', 'Kaspi')->count());
    }

    public function test_auto_locked_product_with_empty_attributes_remains_blocked(): void
    {
        $product = $this->product('locked_empty_attributes', ['auto_content_locked' => true]);
        Http::fake(['resources.cdn-kaspi.kz/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);

        $this->withToken('secret-token')
            ->postJson('/api/internal/kaspi-content/import', $this->payload('locked_empty_attributes', [
                'request_id' => '8fb91896-7e3d-4f74-bf7f-b0d9bf471006',
            ]))
            ->assertStatus(409)
            ->assertJsonPath('error', 'manual_content_protected')
            ->assertJsonPath('reason', 'auto_content_locked');

        $this->assertSame(0, $product->refresh()->attributes()->count());
    }

    public function test_empty_kaspi_attribute_payload_creates_no_attribute_rows(): void
    {
        $product = $this->product('empty_attribute_payload');
        $payload = $this->payload('empty_attribute_payload', [
            'request_id' => '8fb91896-7e3d-4f74-bf7f-b0d9bf471007',
        ]);
        $payload['content']['attributes'] = [];
        $payload['content']['images'] = [];
        $payload['content']['description'] = null;

        $this->withToken('secret-token')
            ->postJson('/api/internal/kaspi-content/import', $payload)
            ->assertOk()
            ->assertJsonPath('result.attributes_updated', 0);

        $this->assertSame(0, $product->refresh()->attributes()->count());
    }

    public function test_aut_163_style_attribute_import_regression(): void
    {
        $product = $this->product('aut_163');
        $payload = $this->payload('aut_163', [
            'request_id' => '8fb91896-7e3d-4f74-bf7f-b0d9bf471008',
        ]);
        $payload['content']['attributes'] = [
            ['name' => 'Применение', 'value' => 'выхлопная система, корпус, моторный отсек, скрытые полости'],
            ['name' => 'Способ нанесения', 'value' => 'аэрозоль'],
            ['name' => 'Объем', 'value' => '0.4 л'],
        ];
        $payload['content']['images'] = [];
        $payload['content']['description'] = null;

        $this->withToken('secret-token')
            ->postJson('/api/internal/kaspi-content/import', $payload)
            ->assertOk()
            ->assertJsonPath('result.attributes_updated', 3);

        $this->assertSame(
            ['Применение', 'Способ нанесения', 'Объем'],
            $product->refresh()->attributes()->orderBy('sort_order')->pluck('name')->all()
        );
    }

    public function test_storefront_characteristics_block_is_visible_after_attributes_are_persisted(): void
    {
        $category = Category::query()->create(['name' => 'Cat', 'slug' => 'cat']);
        $product = $this->product('visible_attributes', [
            'category_id' => $category->id,
            'description' => null,
        ]);
        ProductAttribute::query()->create([
            'product_id' => $product->id,
            'group_name' => 'Kaspi',
            'name' => 'Объем упаковки, л',
            'value' => '0.02 л',
            'sort_order' => 0,
        ]);

        $this->get(route('products.show', $product->slug))
            ->assertOk()
            ->assertSee('class="attributes"', false)
            ->assertSeeText('Объем упаковки, л')
            ->assertSeeText('0.02 л');
    }

    public function test_empty_html_description_detection_for_candidates(): void
    {
        foreach (['<p></p>', '<p><br></p>', '<div>&nbsp;</div>'] as $index => $description) {
            $product = $this->product('empty_html_'.$index, ['description' => $description, 'description_is_manual' => true]);
            ProductImage::query()->create(['product_id' => $product->id, 'path' => 'products/existing-'.$index.'.jpg']);
        }

        $meaningful = $this->product('meaningful_html', ['description' => '<p>Описание товара</p>', 'description_is_manual' => true]);
        ProductImage::query()->create(['product_id' => $meaningful->id, 'path' => 'products/meaningful.jpg']);

        $response = $this->withToken('secret-token')->getJson('/api/internal/kaspi-content/candidates?limit=10');

        $response->assertOk();
        $skus = collect($response->json('data'))->pluck('sku')->all();
        $this->assertContains('empty_html_0', $skus);
        $this->assertContains('empty_html_1', $skus);
        $this->assertContains('empty_html_2', $skus);
        $this->assertNotContains('meaningful_html', $skus);
    }

    public function test_same_request_id_is_idempotent_and_identical_content_is_unchanged(): void
    {
        $this->product('aut_608');
        Http::fake(['resources.cdn-kaspi.kz/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);
        $payload = $this->payload('aut_608');

        $this->withToken('secret-token')->postJson('/api/internal/kaspi-content/import', $payload)->assertOk();
        $this->withToken('secret-token')->postJson('/api/internal/kaspi-content/import', $payload)->assertOk()->assertJsonPath('status', 'imported');

        $secondPayload = $payload;
        $secondPayload['request_id'] = '8fb91896-7e3d-4f74-bf7f-b0d9bf471111';
        $this->withToken('secret-token')->postJson('/api/internal/kaspi-content/import', $secondPayload)->assertOk()->assertJsonPath('status', 'unchanged');

        $this->assertSame(1, ProductImage::query()->count());
        Http::assertSentCount(1);
    }

    public function test_image_security_rejects_bad_hosts_private_ips_redirects_oversized_and_invalid_mime(): void
    {
        $this->product('aut_608');

        $badHost = $this->payload('aut_608', ['image_url' => 'https://example.test/image.png']);
        $this->withToken('secret-token')->postJson('/api/internal/kaspi-content/import', $badHost)->assertStatus(422);

        $privateIp = $this->payload('aut_608', ['image_url' => 'https://127.0.0.1/image.png']);
        $this->withToken('secret-token')->postJson('/api/internal/kaspi-content/import', $privateIp)->assertStatus(422);

        Http::fake(['resources.cdn-kaspi.kz/redirect.png' => Http::response($this->png(), 200, [
            'Content-Type' => 'image/png',
            'X-Guzzle-Redirect-History' => 'https://example.test/image.png',
            'X-Guzzle-Redirect-Status-History' => '302',
        ])]);
        $this->withToken('secret-token')->postJson('/api/internal/kaspi-content/import', $this->payload('aut_608', ['image_url' => 'https://resources.cdn-kaspi.kz/redirect.png', 'request_id' => '8fb91896-7e3d-4f74-bf7f-b0d9bf472222']))->assertStatus(422);

        config(['services.kaspi.image_max_bytes' => 10]);
        Http::fake(['resources.cdn-kaspi.kz/large.png' => Http::response(str_repeat('x', 20), 200, ['Content-Type' => 'image/png'])]);
        $this->withToken('secret-token')->postJson('/api/internal/kaspi-content/import', $this->payload('aut_608', ['image_url' => 'https://resources.cdn-kaspi.kz/large.png', 'request_id' => '8fb91896-7e3d-4f74-bf7f-b0d9bf473333']))->assertStatus(422);

        config(['services.kaspi.image_max_bytes' => 1024 * 1024]);
        Http::fake(['resources.cdn-kaspi.kz/html.png' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html'])]);
        $this->withToken('secret-token')->postJson('/api/internal/kaspi-content/import', $this->payload('aut_608', ['image_url' => 'https://resources.cdn-kaspi.kz/html.png', 'request_id' => '8fb91896-7e3d-4f74-bf7f-b0d9bf474444']))->assertStatus(422);

        $this->assertSame(0, ProductImage::query()->count());
    }

    public function test_partial_image_failure_leaves_no_broken_image_set(): void
    {
        $this->product('aut_608');
        Http::fake([
            'resources.cdn-kaspi.kz/ok.png' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
            'resources.cdn-kaspi.kz/bad.png' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
        ]);
        $payload = $this->payload('aut_608', ['image_url' => 'https://resources.cdn-kaspi.kz/ok.png']);
        $payload['content']['images'][] = ['url' => 'https://resources.cdn-kaspi.kz/bad.png', 'position' => 2];

        $this->withToken('secret-token')->postJson('/api/internal/kaspi-content/import', $payload)->assertStatus(422);

        $this->assertSame(0, ProductImage::query()->count());
    }

    public function test_production_state_is_rechecked_before_publishing_and_filled_content_is_not_overwritten(): void
    {
        $product = $this->product('aut_608', ['description' => 'Existing production description']);
        ProductImage::query()->create(['product_id' => $product->id, 'path' => 'products/existing.jpg', 'source' => 'manual']);
        Http::fake(['resources.cdn-kaspi.kz/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);

        $this->withToken('secret-token')->postJson('/api/internal/kaspi-content/import', $this->payload('aut_608'))->assertOk();

        $product->refresh();
        $this->assertSame('Existing production description', $product->description);
        $this->assertSame(1, $product->images()->count());
        $this->assertSame('products/existing.jpg', $product->images()->first()->path);
    }

    private function product(string $sku, array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'name' => 'Product '.$sku,
            'slug' => 'product-'.str_replace(['_', ' '], '-', mb_strtolower($sku)).'-'.uniqid(),
            'sku' => $sku,
            'kaspi_merchant_sku' => $sku,
            'kaspi_product_url' => 'https://kaspi.kz/shop/p/product-'.$sku.'/',
            'price' => 1000,
            'quantity' => 5,
            'stock_quantity' => 5,
            'availability' => true,
            'availability_status' => 'in_stock',
            'product_status' => ProductStatus::ACTIVE_SYNCED,
            'sync_status' => 'matched',
        ], $overrides));
    }

    private function payload(string $sku, array $overrides = []): array
    {
        $payload = [
            'version' => 1,
            'request_id' => $overrides['request_id'] ?? '8fb91896-7e3d-4f74-bf7f-b0d9bf470000',
            'collected_at' => '2026-08-03T20:00:00+05:00',
            'sku' => $overrides['sku'] ?? $sku,
            'kaspi_url' => 'https://kaspi.kz/shop/p/product-'.$sku.'/',
            'content' => [
                'name' => 'Kaspi product',
                'description' => '<p>Kaspi description</p>',
                'attributes' => [['name' => 'Объем', 'value' => '1 л']],
                'images' => [[
                    'url' => $overrides['image_url'] ?? 'https://resources.cdn-kaspi.kz/img/m/p/test/product/image.png',
                    'position' => 1,
                ]],
            ],
            'source' => ['collector' => 'local-playwright', 'parser_version' => '1'],
        ];

        if (isset($overrides['content'])) {
            $payload['content'] = array_replace_recursive($payload['content'], $overrides['content']);
        }

        return $payload;
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/lW5rWQAAAABJRU5ErkJggg==');
    }
}
