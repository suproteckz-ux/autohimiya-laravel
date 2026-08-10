<?php
namespace App\Services\Ozon;
use App\Enums\OzonOperationType;
use App\Models\AutomationRun;
use App\Models\OzonAccount;
use App\Models\OzonTaxonomyAttribute;
use App\Models\OzonTaxonomyNode;
use Illuminate\Support\Facades\DB;
class OzonTaxonomyService
{
    public function __construct(private readonly OzonApiClient $client) {}
    public function syncTree(OzonAccount $account, ?AutomationRun $run=null): array
    {
        $response=$this->client->post($account,'/v1/description-category/tree',['language'=>'DEFAULT'],OzonOperationType::TaxonomySync,$run);
        if (! array_key_exists('result',$response) || ! is_array($response['result'])) throw new \UnexpectedValueException('Ozon taxonomy response has invalid schema.');
        $count=DB::transaction(fn(): int=>$this->storeNodes($account,$response['result'],null));
        return ['successful'=>true,'processed_items'=>$count];
    }
    public function syncAttributes(OzonTaxonomyNode $node, ?AutomationRun $run=null): array
    {
        $payload=['description_category_id'=>(int)$node->description_category_id,'type_id'=>(int)$node->type_id,'language'=>'DEFAULT'];
        $response=$this->client->post($node->account,'/v1/description-category/attribute',$payload,OzonOperationType::TaxonomySync,$run);
        if (! array_key_exists('result',$response) || ! is_array($response['result'])) throw new \UnexpectedValueException('Ozon attribute response has invalid schema.');
        $prepared=[];
        foreach((array)($response['result'] ?? []) as $item) {
            $dictionaryId=(string)($item['dictionary_id'] ?? '');
            $prepared[]=['item'=>$item,'dictionary_id'=>$dictionaryId,'values'=>(int)$dictionaryId>0?$this->loadValues($node,(string)($item['id'] ?? ''),$run):null];
        }
        DB::transaction(function() use($node,$prepared): void { foreach($prepared as $row) { OzonTaxonomyAttribute::query()->updateOrCreate(['ozon_taxonomy_node_id'=>$node->id,'attribute_id'=>(string)($row['item']['id'] ?? '')],['name'=>(string)($row['item']['name'] ?? ''),'type'=>$row['item']['type'] ?? null,'dictionary_id'=>$row['dictionary_id'],'is_required'=>(bool)($row['item']['is_required'] ?? false),'is_collection'=>(bool)($row['item']['is_collection'] ?? false),'values_payload'=>$row['values'],'raw_payload'=>$row['item'],'synced_at'=>now()]); } });
        return ['successful'=>true,'processed_items'=>count($prepared)];
    }
    public function syncAllAttributes(OzonAccount $account, ?AutomationRun $run=null): array
    {
        $types=OzonTaxonomyNode::query()->where('ozon_account_id',$account->id)->where('is_disabled',false)->whereNotNull('type_id')->where('type_id','!=','')->where('type_id','!=','0')->orderBy('id')->get();
        $attributes=0;
        foreach($types as $node) $attributes += (int)$this->syncAttributes($node,$run)['processed_items'];
        return ['successful'=>true,'processed_items'=>$attributes,'processed_types'=>$types->count()];
    }
    private function loadValues(OzonTaxonomyNode $node,string $attributeId,?AutomationRun $run): array
    {
        $last=0; $values=[];
        do {
            $response=$this->client->post($node->account,'/v1/description-category/attribute/values',['description_category_id'=>(int)$node->description_category_id,'type_id'=>(int)$node->type_id,'attribute_id'=>(int)$attributeId,'language'=>'DEFAULT','limit'=>5000,'last_value_id'=>$last],OzonOperationType::TaxonomySync,$run);
            if (! array_key_exists('result',$response) || ! is_array($response['result'])) throw new \UnexpectedValueException('Ozon attribute values response has invalid schema.');
            $items=$response['result']; $values=array_merge($values,$items); $last=(int)($response['last_value_id'] ?? 0);
        } while($last>0 && $items!==[]);
        return $values;
    }
    private function storeNodes(OzonAccount $account,array $items,?OzonTaxonomyNode $parent): int
    {
        $count=0;
        foreach($items as $item) {
            $typeId=(string)($item['type_id'] ?? '0');
            $hasType=(int)$typeId>0;
            $categoryId=(string)($item['description_category_id'] ?? ($hasType ? $parent?->description_category_id : ''));
            $categoryName=(string)($item['category_name'] ?? ($hasType ? $parent?->category_name : $categoryId));
            if($categoryId==='') { $count += $this->storeNodes($account,(array)($item['children'] ?? []),$parent); continue; }
            $node=OzonTaxonomyNode::query()->updateOrCreate(['ozon_account_id'=>$account->id,'description_category_id'=>$categoryId,'type_id'=>$typeId],['parent_id'=>$parent?->id,'category_name'=>$categoryName,'type_name'=>(string)($item['type_name'] ?? ''),'is_disabled'=>(bool)($item['disabled'] ?? false),'raw_payload'=>$item,'synced_at'=>now()]);
            $count++; $count += $this->storeNodes($account,(array)($item['children'] ?? []),$node);
        }
        return $count;
    }
}
