<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\KaspiImportReceipt;
use App\Models\Product;
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

    public function test_protected_products_are_excluded_by_default_from_candidates(): void
    {
        $this->product('protected', ['description' => '', 'photos_are_manual' => true]);

        $response = $this->withToken('secret-token')->getJson('/api/internal/kaspi-content/candidates?limit=10');

        $response->assertOk();
        $this->assertNotContains('protected', collect($response->json('data'))->pluck('sku')->all());
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
        $product = $this->product('aut_608', ['description_is_manual' => true, 'description' => 'Manual']);

        $this->withToken('secret-token')
            ->postJson('/api/internal/kaspi-content/import', $this->payload('aut_608'))
            ->assertStatus(409)
            ->assertJsonPath('error', 'manual_content_protected');

        $this->assertSame('Manual', $product->refresh()->description);
        $this->assertSame(0, ProductImage::query()->count());
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
