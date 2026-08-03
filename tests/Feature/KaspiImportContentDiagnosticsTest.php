<?php

namespace Tests\Feature;

use App\Models\KaspiEnrichmentTask;
use App\Models\Product;
use App\Services\Kaspi\KaspiContentImportService;
use App\Support\ProductStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KaspiImportContentDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_blocked_http_response_returns_safe_diagnostics(): void
    {
        $this->createProduct('LN7706', 'https://kaspi.kz/shop/p/ln7706/');
        Http::fake([
            'kaspi.kz/*' => Http::response('blocked body with token=secret and cookie=value', 429, [
                'Content-Type' => 'text/html; charset=utf-8',
                'Retry-After' => '120',
                'Set-Cookie' => 'session=secret-cookie',
                'X-Token' => 'secret-token',
            ]),
        ]);

        $result = app(KaspiContentImportService::class)->import([
            'sku' => 'LN7706',
            'limit' => 1,
            'dry_run' => true,
        ]);

        $this->assertSame(1, $result['metrics']['blocked']);
        $this->assertSame(0, $result['metrics']['errors']);
        $this->assertSame(0, $result['metrics']['noData']);
        $this->assertCount(1, $result['blocked_diagnostics']);
        $diagnostic = $result['blocked_diagnostics'][0];

        $this->assertSame('LN7706', $diagnostic['sku']);
        $this->assertSame(429, $diagnostic['http_status']);
        $this->assertSame('https://kaspi.kz/shop/p/ln7706/', $diagnostic['effective_url']);
        $this->assertSame('text/html; charset=utf-8', $diagnostic['content_type']);
        $this->assertSame(strlen('blocked body with token=secret and cookie=value'), $diagnostic['response_size']);
        $this->assertSame('120', $diagnostic['retry_after']);
        $this->assertSame('', $diagnostic['location']);
        $this->assertSame('rate_limited', $diagnostic['classification']);
        $this->assertStringNotContainsString('secret', json_encode($diagnostic));
        $this->assertSame(0, KaspiEnrichmentTask::query()->count());
    }

    public function test_import_content_command_prints_blocked_diagnostics_without_body_or_cookie_headers(): void
    {
        $this->createProduct('LN7706', 'https://kaspi.kz/shop/p/ln7706/');
        Http::fake([
            'kaspi.kz/*' => Http::response('forbidden secret body', 403, [
                'Content-Type' => 'text/html',
                'Location' => 'https://kaspi.kz/',
                'Set-Cookie' => 'session=secret-cookie',
            ]),
        ]);

        $exitCode = Artisan::call('kaspi:import-content', [
            '--sku' => 'LN7706',
            '--limit' => 1,
            '--dry-run' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('LN7706', $output);
        $this->assertStringContainsString('403', $output);
        $this->assertStringContainsString('text/html', $output);
        $this->assertStringContainsString('https://kaspi.kz/shop/p/ln7706/', $output);
        $this->assertStringContainsString('https://kaspi.kz/', $output);
        $this->assertStringContainsString('forbidden', $output);
        $this->assertStringNotContainsString('forbidden secret body', $output);
        $this->assertStringNotContainsString('secret-cookie', $output);
    }

    public function test_block_classifications_are_reported_for_common_non_success_statuses(): void
    {
        $service = app(KaspiContentImportService::class);
        $method = new \ReflectionMethod($service, 'blockClassification');
        $method->setAccessible(true);
        $requestedUrl = 'https://kaspi.kz/shop/p/sku/';

        $this->assertSame(
            ['forbidden', 'rate_limited', 'redirected', 'not_found', 'server_error', 'other'],
            [
                $method->invoke($service, 403, '', $requestedUrl, $requestedUrl),
                $method->invoke($service, 429, '', $requestedUrl, $requestedUrl),
                $method->invoke($service, 302, 'https://kaspi.kz/redirected/', $requestedUrl, $requestedUrl),
                $method->invoke($service, 404, '', $requestedUrl, $requestedUrl),
                $method->invoke($service, 500, '', $requestedUrl, $requestedUrl),
                $method->invoke($service, 418, '', $requestedUrl, $requestedUrl),
            ]
        );
    }

    private function createProduct(string $sku, string $kaspiUrl): Product
    {
        return Product::query()->create([
            'name' => 'Product '.$sku,
            'slug' => 'product-'.$sku,
            'sku' => $sku,
            'kaspi_merchant_sku' => $sku,
            'kaspi_product_url' => $kaspiUrl,
            'price' => 1000,
            'quantity' => 1,
            'stock_quantity' => 1,
            'availability' => true,
            'availability_status' => 'in_stock',
            'product_status' => ProductStatus::ACTIVE_SYNCED,
            'sync_status' => 'matched',
        ]);
    }
}
