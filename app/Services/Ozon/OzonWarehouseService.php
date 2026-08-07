<?php
namespace App\Services\Ozon;
use App\Enums\OzonOperationType;
use App\Models\AutomationRun;
use App\Models\OzonAccount;
use App\Models\OzonWarehouse;
class OzonWarehouseService
{
    public function __construct(private readonly OzonApiClient $client) {}
    public function sync(OzonAccount $account, ?AutomationRun $run = null): array
    {
        $offset=0; $limit=100; $created=0; $updated=0; $seen=[];
        do {
            $response=$this->client->post($account, '/v1/warehouse/list', ['limit'=>$limit,'offset'=>$offset], OzonOperationType::WarehouseSync, $run);
            $items=(array)($response['result'] ?? []);
            foreach($items as $item) {
                $externalId=(string)($item['warehouse_id'] ?? ''); if($externalId==='') continue;
                $warehouse=OzonWarehouse::query()->firstOrNew(['ozon_account_id'=>$account->id,'ozon_warehouse_id'=>$externalId]);
                $exists=$warehouse->exists;
                $warehouse->fill(['name'=>(string)($item['name'] ?? $externalId),'status'=>$item['status'] ?? null,'is_active'=>!in_array(strtoupper((string)($item['status'] ?? '')),['DISABLED','ARCHIVED'],true),'is_api_confirmed'=>true,'api_confirmed_at'=>now(),'raw_payload'=>$item,'synced_at'=>now()])->save();
                $exists ? $updated++ : $created++; $seen[]=$externalId;
            }
            $offset += count($items);
        } while(count($items)===$limit);
        return ['successful'=>true,'created'=>$created,'updated'=>$updated,'seen'=>count($seen)];
    }
}
