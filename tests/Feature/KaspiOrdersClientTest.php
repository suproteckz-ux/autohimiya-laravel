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

    // ─── TEST D: X-Auth-Token header present ──────────────────────────────────

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

    // ─── TEST E: Accept/Content-Type correct ─────────────────────────────────

    public function test_get_orders_sends_correct_accept_header(): void
    {
        Http::fake([
            'kaspi.kz/shop/api/v2/orders*' => Http::response(['data' => [], 'meta' => ['pageCount' => 1]], 200),
        ]);

        $client = new KaspiOrdersClient();
        $client->getOrders();

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Accept', 'application/vnd.api+json');
        });
    }

    // ─── TEST S: token never appears in URL ───────────────────────────────────

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

    // ─── TEST A: GET /orders builds exact compatible query keys ──────────────

    public function test_get_orders_builds_correct_literal_bracket_query_keys(): void
    {
        Http::fake([
            'kaspi.kz/shop/api/v2/orders*' => Http::response(['data' => [], 'meta' => ['pageCount' => 1]], 200),
        ]);

        $fromMs = 1_700_000_000_000;
        $toMs   = 1_700_100_000_000;

        $client = new KaspiOrdersClient();
        $client->getOrders(state: 'KASPI_DELIVERY', fromMs: $fromMs, toMs: $toMs);

        Http::assertSent(function (Request $request) use ($fromMs, $toMs) {
            $raw = urldecode($request->url());
            return str_contains($raw, 'filter[orders][state]=KASPI_DELIVERY')
                && str_contains($raw, 'filter[orders][creationDate][$ge]=' . $fromMs)
                && str_contains($raw, 'filter[orders][creationDate][$le]=' . $toMs)
                && str_contains($raw, 'page[number]=0')
                && str_contains($raw, 'page[size]=100');
        });
    }

    // ─── TEST B: creation dates are Unix milliseconds ─────────────────────────

    public function test_creation_dates_are_unix_milliseconds(): void
    {
        Http::fake([
            'kaspi.kz/shop/api/v2/orders*' => Http::response(['data' => [], 'meta' => ['pageCount' => 1]], 200),
        ]);

        $nowMs = (int) (time() * 1000); // current time in milliseconds

        $client = new KaspiOrdersClient();
        $client->getOrders(fromMs: $nowMs - 1_209_600_000, toMs: $nowMs); // 14 days in ms

        Http::assertSent(function (Request $request) use ($nowMs) {
            $raw = urldecode($request->url());
            // Values must be 13-digit millisecond timestamps
            preg_match('/\$ge\]=(\d+)/', $raw, $geMatch);
            preg_match('/\$le\]=(\d+)/', $raw, $leMatch);
            if (! $geMatch || ! $leMatch) {
                return false;
            }
            $ge = (int) $geMatch[1];
            $le = (int) $leMatch[1];
            return strlen((string) $ge) === 13
                && strlen((string) $le) === 13
                && $ge < $le;
        });
    }

    // ─── TEST C: state = KASPI_DELIVERY ───────────────────────────────────────

    public function test_state_filter_is_kaspi_delivery(): void
    {
        Http::fake([
            'kaspi.kz/shop/api/v2/orders*' => Http::response(['data' => [], 'meta' => ['pageCount' => 1]], 200),
        ]);

        $client = new KaspiOrdersClient();
        $client->getOrders(state: 'KASPI_DELIVERY');

        Http::assertSent(function (Request $request) {
            return str_contains(urldecode($request->url()), 'filter[orders][state]=KASPI_DELIVERY');
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

    // ─── TEST R: HTTP 400 diagnostic safe ─────────────────────────────────────

    public function test_throws_on_http_error_with_safe_diagnostic(): void
    {
        Http::fake([
            'kaspi.kz/shop/api/v2/orders*' => Http::response(
                ['errors' => [['title' => 'Invalid filter', 'detail' => 'Bad request']]],
                400
            ),
        ]);

        try {
            $client = new KaspiOrdersClient();
            $client->getOrders(state: 'KASPI_DELIVERY', fromMs: 1_700_000_000_000, toMs: 1_700_100_000_000);
            $this->fail('Expected RuntimeException not thrown.');
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('HTTP 400', $msg);
            $this->assertStringContainsString('GET /orders', $msg);
            // Query params appear in message
            $this->assertStringContainsString('filter[orders][state]=KASPI_DELIVERY', $msg);
            // Token must NOT appear
            $this->assertStringNotContainsString('test-token-12345', $msg);
        }
    }

    // ─── TEST S (exception): token never appears in exception message ─────────

    public function test_token_never_appears_in_exception_message(): void
    {
        Http::fake([
            'kaspi.kz/shop/api/v2/orders*' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        try {
            (new KaspiOrdersClient())->getOrders();
            $this->fail('Expected exception');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('test-token-12345', $e->getMessage());
        }
    }

    // ─── TEST T: customer PII does not appear in diagnostic output ────────────

    public function test_customer_pii_stripped_from_error_response(): void
    {
        $responseWithPii = [
            'errors' => [['title' => 'Bad request']],
            'customer' => ['name' => 'John Doe', 'phone' => '+77001234567'],
        ];

        Http::fake([
            'kaspi.kz/shop/api/v2/orders*' => Http::response($responseWithPii, 400),
        ]);

        try {
            (new KaspiOrdersClient())->getOrders();
            $this->fail('Expected exception');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('John Doe', $e->getMessage());
            $this->assertStringNotContainsString('+77001234567', $e->getMessage());
        }
    }

    // ─── TEST F: pagination: pageCount=2 → requests page 0 and 1 ─────────────

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

    public function test_pagination_stops_at_page_count(): void
    {
        $requestCount = 0;
        Http::fake([
            'kaspi.kz/shop/api/v2/orders*' => Http::sequence()
                ->push(['data' => [['id' => 'o1']], 'meta' => ['pageCount' => 3]], 200)
                ->push(['data' => [['id' => 'o2']], 'meta' => ['pageCount' => 3]], 200)
                ->push(['data' => [['id' => 'o3']], 'meta' => ['pageCount' => 3]], 200),
        ]);

        $client = new KaspiOrdersClient();
        foreach ($client->getAllOrders() as $batch) {
            $requestCount++;
        }

        $this->assertSame(3, $requestCount);
    }
}
