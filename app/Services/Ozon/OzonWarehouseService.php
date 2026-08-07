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
        $cursor=''; $limit=100; $created=0; $updated=0; $seen=[];
        do {
            $response=$this->client->post($account, '/v2/warehouse/list', ['limit'=>$limit,'cursor'=>$cursor], OzonOperationType::WarehouseSync, $run);
            if (! array_key_exists('warehouses',$response) || ! is_array($response['warehouses'])) throw new \UnexpectedValueException('Ozon warehouse response has invalid schema.');
            $items=$response['warehouses'];
            foreach($items as $item) {
                $externalId=(string)($item['warehouse_id'] ?? ''); if($externalId==='') continue;
                $warehouse=OzonWarehouse::query()->firstOrNew(['ozon_account_id'=>$account->id,'ozon_warehouse_id'=>$externalId]);
                $exists=$warehouse->exists;
                $status=is_array($item['status'] ?? null) ? (string)($item['status']['state'] ?? '') : (string)($item['status'] ?? '');
                $isArchived=(bool)($item['is_archived'] ?? false);
                $warehouse->fill(['name'=>(string)($item['name'] ?? $externalId),'status'=>$status!==''?$status:($isArchived?'ARCHIVED':null),'is_active'=>!$isArchived&&!in_array(strtoupper($status),['DISABLED','ARCHIVED','INACTIVE'],true),'is_api_confirmed'=>true,'api_confirmed_at'=>now(),'raw_payload'=>$item,'synced_at'=>now()])->save();
                $exists ? $updated++ : $created++; $seen[]=$externalId;
            }
            $nextCursor=(string)($response['cursor'] ?? '');
            $hasNext=(bool)($response['has_next'] ?? false);
            if($hasNext && ($nextCursor==='' || $nextCursor===$cursor)) throw new \UnexpectedValueException('Ozon warehouse pagination cursor is invalid.');
            $cursor=$nextCursor;
        } while($hasNext);
        return ['successful'=>true,'created'=>$created,'updated'=>$updated,'seen'=>count($seen)];
    }
}
