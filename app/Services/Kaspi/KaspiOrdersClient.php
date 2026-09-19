<?php

namespace App\Services\Kaspi;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class KaspiOrdersClient
{
    private const BASE_URL_FALLBACK = 'https://kaspi.kz/shop/api/v2';
    private const CONNECT_TIMEOUT = 10;
    private const TIMEOUT = 30;
    private const MAX_PAGE_SIZE = 100;
    private const MAX_PAGES = 100;

    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.kaspi.partner_api_url', self::BASE_URL_FALLBACK), '/');
    }

    /**
     * Fetch a page of orders.
     *
     * Builds literal bracket-notation query params that Kaspi Partner API expects:
     *   page[number], page[size]
     *   filter[orders][state]
     *   filter[orders][creationDate][$ge]
     *   filter[orders][creationDate][$le]
     *   filter[orders][code]
     */
    public function getOrders(
        ?string $state = null,
        ?int $fromMs = null,
        ?int $toMs = null,
        ?string $code = null,
        int $page = 0,
        int $pageSize = 100
    ): array {
        $params = [
            'page[number]' => $page,
            'page[size]'   => min($pageSize, self::MAX_PAGE_SIZE),
        ];

        if ($state !== null) {
            $params['filter[orders][state]'] = $state;
        }
        if ($fromMs !== null) {
            $params['filter[orders][creationDate][$ge]'] = $fromMs;
        }
        if ($toMs !== null) {
            $params['filter[orders][creationDate][$le]'] = $toMs;
        }
        if ($code !== null) {
            $params['filter[orders][code]'] = $code;
        }

        $response = $this->client()->get('/orders', $params);
        $this->assertSuccess($response, 'GET /orders', $params);

        return $response->json() ?? [];
    }

    /**
     * Fetch all orders matching the given filter, paginating automatically.
     * Yields one page at a time to avoid unbounded memory.
     *
     * @return iterable<array>
     */
    public function getAllOrders(
        ?string $state = null,
        ?int $fromMs = null,
        ?int $toMs = null,
        ?string $code = null,
        int $pageSize = 100
    ): iterable {
        $page = 0;

        do {
            $result = $this->getOrders($state, $fromMs, $toMs, $code, $page, $pageSize);
            $data = $result['data'] ?? [];
            $meta = $result['meta'] ?? [];

            yield $data;

            $page++;
            $totalPages = (int) ($meta['pageCount'] ?? 1);
        } while ($page < $totalPages && $page < self::MAX_PAGES);
    }

    /**
     * Fetch order entries (line items) for a given Kaspi order ID.
     */
    public function getOrderEntries(string $orderId): array
    {
        $response = $this->client()->get("/orders/{$orderId}/entries");
        $this->assertSuccess($response, "GET /orders/{$orderId}/entries");

        return $response->json('data') ?? [];
    }

    /**
     * Fetch a single order entry by its entry ID.
     */
    public function getOrderEntry(string $entryId): array
    {
        $response = $this->client()->get("/orderentries/{$entryId}");
        $this->assertSuccess($response, "GET /orderentries/{$entryId}");

        return $response->json('data') ?? [];
    }

    /**
     * Fetch the masterproduct record for an order entry.
     */
    public function getProductForEntry(string $entryId): array
    {
        $response = $this->client()->get("/orderentries/{$entryId}/product");
        $this->assertSuccess($response, "GET /orderentries/{$entryId}/product");

        return $response->json('data') ?? [];
    }

    /**
     * Fetch the merchant product (contains `code` = merchant SKU).
     * NOTE: direct /merchantproducts/{id} returns HTTP 500 — use this path instead.
     */
    public function getMerchantProduct(string $masterProductId): array
    {
        $response = $this->client()->get("/masterproducts/{$masterProductId}/merchantProduct");
        $this->assertSuccess($response, "GET /masterproducts/{$masterProductId}/merchantProduct");

        return $response->json('data') ?? [];
    }

    /**
     * POST a status change to an order.
     * WRITE OPERATION — call only when explicitly triggered by a user action.
     */
    public function changeOrderStatus(string $orderId, string $orderCode, string $status, array $extra = []): array
    {
        $attributes = array_merge(['code' => $orderCode, 'status' => $status], $extra);

        $response = $this->client()->post('/orders', [
            'data' => [
                'type'       => 'orders',
                'id'         => $orderId,
                'attributes' => $attributes,
            ],
        ]);

        $this->assertSuccess($response, "POST /orders (status={$status})");

        return $response->json() ?? [];
    }

    /**
     * Create a waybill by setting status ASSEMBLE with numberOfSpace.
     * WRITE OPERATION — call only from explicit user action (not automated).
     */
    public function createWaybill(string $orderId, string $orderCode, int $numberOfSpace = 1): array
    {
        return $this->changeOrderStatus($orderId, $orderCode, 'ASSEMBLE', [
            'numberOfSpace' => (string) $numberOfSpace,
        ]);
    }

    private function client(): PendingRequest
    {
        $token = config('services.kaspi.partner_api_token');

        if (empty($token)) {
            throw new RuntimeException('KASPI_PARTNER_API_TOKEN is not configured.');
        }

        return Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'X-Auth-Token'  => $token,
                'Content-Type'  => 'application/vnd.api+json',
                'Accept'        => 'application/vnd.api+json',
            ])
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT);
    }

    private function assertSuccess(Response $response, string $context, array $params = []): void
    {
        if (! $response->failed()) {
            return;
        }

        $lines = ["Kaspi Partner API request failed [{$context}]: HTTP {$response->status()}"];

        if ($params) {
            $queryLines = [];
            foreach ($params as $k => $v) {
                $queryLines[] = "{$k}={$v}";
            }
            $lines[] = "\nQuery:\n" . implode("\n", $queryLines);
        }

        $body = (string) $response->body();
        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                // Remove any customer-identifying fields from error responses
                unset($decoded['customer'], $decoded['data']['attributes']['customer']);
                $body = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
            $lines[] = "\nResponse:\n" . mb_substr((string) $body, 0, 500);
        }

        throw new RuntimeException(implode('', $lines));
    }
}
