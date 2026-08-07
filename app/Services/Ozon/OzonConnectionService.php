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
        $response = $this->client->post($account, '/v1/warehouse/list', ['limit'=>1,'offset'=>0], OzonOperationType::ConnectionCheck, $run);
        $account->update(['last_connection_check_at'=>now(), 'last_connection_error'=>null]);
        return ['successful'=>true, 'warehouse_visible'=>count($response['result'] ?? []) > 0];
    }
}
