<?php

namespace Tests\Feature;

use App\Services\Kaspi\KaspiOrdersClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class KaspiOrdersClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.kaspi.partner_api_token' => 'test-token-12345']);
        config(['services.kaspi.partner_api_url' => 'https://kaspi.kz/shop/api/v2']);
    }

    public function test_get_orders_sends_correct_auth_header(): void
    {
        Http::fake([
            'kaspi.kz/shop/api/v2/orders*' => Http::response([
                'data' => [],
                'meta' => ['pageCount' => 1, 'totalCount' => 0],
            ], 200),
        ]);

        $client = new KaspiOrdersClient();
        $client->getOrders();

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('X-Auth-Token', 'test-token-12345')
                && $request->hasHeader('Content-Type', 'application/vnd.api+json');
        });
    }

    public function test_get_orders_does_not_leak_token_in_url(): void
    {
        Http::fake([
            'kaspi.kz/shop/api/v2/orders*' => Http::response(['data' => [], 'meta' => ['pageCount' => 1]], 200),
        ]);

        $client = new KaspiOrdersClient();
        $client->getOrders();

        Http::assertSent(function (Request $request) {
            return ! str_contains($request->url(), 'test-token');
        });
    }

    public function test_get_order_entries_uses_correct_endpoint(): void
    {
        Http::fake([
            'kaspi.kz/shop/api/v2/orders/order-123/entries' => Http::response(['data' => []], 200),
        ]);

        $client = new KaspiOrdersClient();
        $result = $client->getOrderEntries('order-123');

        $this->assertIsArray($result);
    }

    public function test_throws_when_token_is_missing(): void
    {
        config(['services.kaspi.partner_api_token' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('KASPI_PARTNER_API_TOKEN is not configured');

        $client = new KaspiOrdersClient();
        $client->getOrders();
    }

    public function test_throws_on_http_error(): void
    {
        Http::fake([
            'kaspi.kz/shop/api/v2/orders*' => Http::response([], 401),
        ]);

        $this->expectException(RuntimeException::class);

        $client = new KaspiOrdersClient();
        $client->getOrders();
    }

    public function test_get_all_orders_paginates(): void
    {
        Http::fake([
            'kaspi.kz/shop/api/v2/orders*' => Http::sequence()
                ->push(['data' => [['id' => 'order-1']], 'meta' => ['pageCount' => 2, 'totalCount' => 2]], 200)
                ->push(['data' => [['id' => 'order-2']], 'meta' => ['pageCount' => 2, 'totalCount' => 2]], 200),
        ]);

        $client = new KaspiOrdersClient();
        $collected = [];
        foreach ($client->getAllOrders() as $batch) {
            $collected = array_merge($collected, $batch);
        }

        $this->assertCount(2, $collected);
    }
}
