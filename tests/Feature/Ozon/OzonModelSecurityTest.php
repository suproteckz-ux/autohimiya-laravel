<?php

namespace Tests\Feature\Ozon;

use App\Enums\OzonOperationStatus;
use App\Enums\OzonOperationType;
use App\Enums\OzonProductStatus;
use App\Models\OzonAccount;
use App\Models\OzonOperation;
use App\Models\OzonProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class OzonModelSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_key_is_encrypted_and_hidden_from_serialization(): void
    {
        $plain = 'ozon-super-secret-api-key';
        $account = OzonAccount::factory()->create(['api_key' => $plain]);
        $stored = DB::table('ozon_accounts')->where('id', $account->id)->value('api_key');

        $this->assertNotSame($plain, $stored);
        $this->assertSame($plain, $account->fresh()->api_key);
        $this->assertArrayNotHasKey('api_key', $account->toArray());
        $this->assertStringNotContainsString($plain, $account->toJson());
    }

    public function test_api_key_is_not_exposed_by_model_logging_or_exception_text(): void
    {
        Log::spy();
        $plain = 'never-log-this-ozon-key';
        $account = OzonAccount::factory()->create(['api_key' => $plain]);
        Log::info('Ozon account saved.', $account->toArray());

        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $message, array $context): bool => ! str_contains($message.json_encode($context), $plain)
        );
        $this->assertStringNotContainsString($plain, (string) new \RuntimeException('Ozon account configuration error.'));
    }

    public function test_json_decimal_boolean_datetime_and_enum_casts_are_applied(): void
    {
        $account = OzonAccount::factory()->create([
            'default_price_multiplier' => 1.2345,
            'is_active' => 1,
            'last_connection_check_at' => '2026-08-06 10:00:00',
        ]);
        $ozonProduct = OzonProduct::factory()->for($account, 'account')->create([
            'status' => OzonProductStatus::Ready,
            'prepared_images' => ['https://example.test/image.jpg'],
            'prepared_attributes' => [['id' => 1]],
            'calculated_price' => 1250.50,
        ]);
        $operation = OzonOperation::factory()->for($account, 'account')->create([
            'operation_type' => OzonOperationType::ProductExport,
            'status' => OzonOperationStatus::Running,
            'request_payload' => ['offer_id' => $ozonProduct->offer_id],
            'started_at' => '2026-08-06 10:01:00',
        ]);

        $this->assertTrue($account->is_active);
        $this->assertSame('1.2345', $account->default_price_multiplier);
        $this->assertNotNull($account->last_connection_check_at);
        $this->assertSame(OzonProductStatus::Ready, $ozonProduct->status);
        $this->assertSame(['https://example.test/image.jpg'], $ozonProduct->prepared_images);
        $this->assertSame('1250.50', $ozonProduct->calculated_price);
        $this->assertSame(OzonOperationType::ProductExport, $operation->operation_type);
        $this->assertSame(OzonOperationStatus::Running, $operation->status);
        $this->assertSame(['offer_id' => $ozonProduct->offer_id], $operation->request_payload);
        $this->assertNotNull($operation->started_at);
    }
}
