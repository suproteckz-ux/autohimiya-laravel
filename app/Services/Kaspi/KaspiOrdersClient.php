<?php

namespace App\Services\Kaspi;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class KaspiOrdersClient
{
    private const BASE_URL_FALLBACK = 'https://kaspi.kz/shop/api/v2';
    private const CONNECT_TIMEOUT = 10;
    private const TIMEOUT = 30;
    private const MAX_PAGE_SIZE = 100;

    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.kaspi.partner_api_url', self::BASE_URL_FALLBACK), '/');
    }

    /**
     * Fetch a page of orders.
     * Returns ['data' => [...], 'meta' => [...]] or throws.
     */
    public function getOrders(array $filter = [], int $page = 0, int $pageSize = 100): array
    {
        $params = [
            'page[number]' => $page,
            'page[size]' => min($pageSize, self::MAX_PAGE_SIZE),
        ];

        foreach ($filter as $key => $value) {
            $params["filter[orders][{$key}]"] = $value;
        }

        $response = $this->client()->get('/orders', $params);
        $this->assertSuccess($response, 'GET /orders');

        return $response->json() ?? [];
    }

    /**
     * Fetch all orders matching a filter, paginating automatically.
     * Yields batches to avoid unbounded memory.
     *
     * @return iterable<array>
     */
    public function getAllOrders(array $filter = [], int $pageSize = 100): iterable
    {
        $page = 0;
        do {
            $result = $this->getOrders($filter, $page, $pageSize);
            $data = $result['data'] ?? [];
            $meta = $result['meta'] ?? [];

            yield $data;

            $page++;
            $totalPages = (int) ($meta['pageCount'] ?? 1);
        } while ($page < $totalPages);
    }

    /**
     * Fetch order entries (items) for a given order ID.
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
     * Fetch the product record for an order entry.
     * Returns the masterproduct attributes.
     */
    public function getProductForEntry(string $entryId): array
    {
        $response = $this->client()->get("/orderentries/{$entryId}/product");
        $this->assertSuccess($response, "GET /orderentries/{$entryId}/product");

        return $response->json('data') ?? [];
    }

    /**
     * Fetch the merchant product (contains `code` = merchant SKU).
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
                'type' => 'orders',
                'id' => $orderId,
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
                'X-Auth-Token' => $token,
                'Content-Type' => 'application/vnd.api+json',
                'Accept' => 'application/vnd.api+json',
            ])
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT);
    }

    private function assertSuccess(\Illuminate\Http\Client\Response $response, string $context): void
    {
        if ($response->failed()) {
            throw new RuntimeException(
                "Kaspi Partner API request failed [{$context}]: HTTP {$response->status()}"
            );
        }
    }
}
