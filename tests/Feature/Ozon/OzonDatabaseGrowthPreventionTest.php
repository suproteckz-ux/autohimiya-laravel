<?php

namespace Tests\Feature\Ozon;

use App\Enums\OzonOperationStatus;
use App\Enums\OzonOperationType;
use App\Exceptions\OzonApiException;
use App\Models\OzonAccount;
use App\Models\OzonOperation;
use App\Services\Ozon\OzonApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OzonDatabaseGrowthPreventionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_successful_taxonomy_operation_stores_compact_metadata_only(): void
    {
        $account = OzonAccount::factory()->create();
        Http::fake([
            OzonApiClient::BASE_URL.'/v1/description-category/attribute' => Http::response([
                'result' => [['id' => 1, 'name' => 'Huge', 'payload' => str_repeat('x', 500_000)]],
            ], 200, ['x-request-id' => 'request-1']),
        ]);

        app(OzonApiClient::class)->post($account, '/v1/description-category/attribute', [
            'description_category_id' => 10,
            'type_id' => 20,
            'language' => 'DEFAULT',
        ], OzonOperationType::TaxonomySync);

        $operation = OzonOperation::query()->sole();
        self::assertSame('taxonomy_compact', $operation->response_payload['logging_policy']);
        self::assertTrue($operation->response_payload['payload_omitted']);
        self::assertSame(1, $operation->response_payload['result_count']);
        self::assertLessThan(1_000, strlen((string) json_encode($operation->response_payload)));
        self::assertSame(10, $operation->request_payload['identifiers']['description_category_id']);
        self::assertSame(200, $operation->http_status);
        self::assertSame('request-1', $operation->request_id);
    }

    public function test_failed_taxonomy_operation_stores_bounded_truncated_body_without_secrets(): void
    {
        $account = OzonAccount::factory()->create(['client_id' => 'growth-client', 'api_key' => 'growth-secret']);
        Http::fake([
            OzonApiClient::BASE_URL.'/v1/description-category/attribute' => Http::response([
                'message' => 'invalid taxonomy request',
                'detail' => str_repeat('growth-secret'.str_repeat('z', 100), 500),
            ], 400),
        ]);

        try {
            app(OzonApiClient::class)->post($account, '/v1/description-category/attribute', [
                'description_category_id' => 10,
                'type_id' => 20,
            ], OzonOperationType::TaxonomySync);
            self::fail('OzonApiException expected.');
        } catch (OzonApiException) {
            $operation = OzonOperation::query()->sole();
            self::assertSame('taxonomy_bounded_error', $operation->response_payload['logging_policy']);
            self::assertTrue($operation->response_payload['truncated']);
            self::assertLessThanOrEqual(12_000, strlen($operation->response_payload['body']));
            self::assertStringNotContainsString('growth-secret', json_encode($operation->response_payload));
            self::assertSame(400, $operation->http_status);
        }
    }

    public function test_dictionary_page_response_is_never_persisted_in_full(): void
    {
        $account = OzonAccount::factory()->create();
        Http::fake([
            OzonApiClient::BASE_URL.'/v1/description-category/attribute/values' => Http::response([
                'result' => collect(range(1, 500))->map(fn (int $id): array => ['id' => $id, 'value' => str_repeat('v', 1000)])->all(),
                'last_value_id' => 500,
            ], 200),
        ]);

        app(OzonApiClient::class)->post($account, '/v1/description-category/attribute/values', [
            'description_category_id' => 10,
            'type_id' => 20,
            'attribute_id' => 30,
            'limit' => 5000,
            'last_value_id' => 0,
        ], OzonOperationType::TaxonomySync);

        $payload = OzonOperation::query()->sole()->response_payload;
        self::assertSame('taxonomy_compact', $payload['logging_policy']);
        self::assertSame(500, $payload['result_count']);
        self::assertArrayNotHasKey('result', $payload);
        self::assertLessThan(1_000, strlen((string) json_encode($payload)));
    }

    public function test_prune_dry_run_reports_eligible_taxonomy_rows_and_deletes_nothing(): void
    {
        $account = OzonAccount::factory()->create();
        foreach ([
            [OzonOperationType::TaxonomySync, OzonOperationStatus::Completed, 30],
            [OzonOperationType::TaxonomySync, OzonOperationStatus::Failed, 120],
            [OzonOperationType::ProductExport, OzonOperationStatus::Completed, 400],
        ] as $index => [$type, $status, $age]) {
            OzonOperation::query()->create([
                'ozon_account_id' => $account->id,
                'operation_key' => 'growth-prune-'.$index,
                'operation_type' => $type,
                'status' => $status,
                'created_at' => now()->subDays($age),
                'updated_at' => now()->subDays($age),
            ]);
        }

        self::assertSame(0, Artisan::call('ozon:operations-prune', ['--dry-run' => true]));
        $output = Artisan::output();
        self::assertStringContainsString('Dry-run completed. No rows were deleted.', $output);
        self::assertStringContainsString('taxonomy success', $output);
        self::assertStringContainsString('taxonomy failed', $output);
        self::assertDatabaseCount('ozon_operations', 3);
    }
}
