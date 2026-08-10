<?php
namespace App\Services\Ozon;
use App\Enums\OzonOperationType;
use App\Models\AutomationRun;
use App\Models\OzonAccount;
class OzonConnectionService
{
    public function __construct(private readonly OzonApiClient $client) {}
    public function check(OzonAccount $account, ?AutomationRun $run = null): array
    {
        $response = $this->client->postEmptyJsonObject($account, '/v1/seller/info', OzonOperationType::ConnectionCheck, $run);
        $account->update(['last_connection_check_at'=>now(), 'last_connection_error'=>null]);
        return ['successful'=>true, 'seller_visible'=>filled($response['company'] ?? $response['result'] ?? $response)];
    }
}
