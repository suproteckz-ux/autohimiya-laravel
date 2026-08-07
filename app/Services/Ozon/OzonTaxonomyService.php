<?php
namespace App\Services\Ozon;
use App\Enums\OzonOperationType;
use App\Models\AutomationRun;
use App\Models\OzonAccount;
use App\Models\OzonTaxonomyAttribute;
use App\Models\OzonTaxonomyNode;
class OzonTaxonomyService
{
    public function __construct(private readonly OzonApiClient $client) {}
    public function syncTree(OzonAccount $account, ?AutomationRun $run=null): array
    {
        $response=$this->client->post($account,'/v1/description-category/tree',['language'=>'DEFAULT'],OzonOperationType::TaxonomySync,$run);
        $count=$this->storeNodes($account,(array)($response['result'] ?? []),null);
        return ['successful'=>true,'processed_items'=>$count];
    }
    public function syncAttributes(OzonTaxonomyNode $node, ?AutomationRun $run=null): array
    {
        $payload=['description_category_id'=>(int)$node->description_category_id,'type_id'=>(int)$node->type_id,'language'=>'DEFAULT'];
        $response=$this->client->post($node->account,'/v1/description-category/attribute',$payload,OzonOperationType::TaxonomySync,$run);
        $count=0;
        foreach((array)($response['result'] ?? []) as $item) {
            $attribute=OzonTaxonomyAttribute::query()->updateOrCreate(['ozon_taxonomy_node_id'=>$node->id,'attribute_id'=>(string)($item['id'] ?? '')],['name'=>(string)($item['name'] ?? ''),'type'=>$item['type'] ?? null,'dictionary_id'=>(string)($item['dictionary_id'] ?? ''),'is_required'=>(bool)($item['is_required'] ?? false),'is_collection'=>(bool)($item['is_collection'] ?? false),'raw_payload'=>$item,'synced_at'=>now()]);
            if((int)$attribute->dictionary_id>0) $this->syncValues($node,$attribute,$run);
            $count++;
        }
        return ['successful'=>true,'processed_items'=>$count];
    }
    private function syncValues(OzonTaxonomyNode $node,OzonTaxonomyAttribute $attribute,?AutomationRun $run): void
    {
        $last=0; $values=[];
        do {
            $response=$this->client->post($node->account,'/v1/description-category/attribute/values',['description_category_id'=>(int)$node->description_category_id,'type_id'=>(int)$node->type_id,'attribute_id'=>(int)$attribute->attribute_id,'language'=>'DEFAULT','limit'=>5000,'last_value_id'=>$last],OzonOperationType::TaxonomySync,$run);
            $items=(array)($response['result'] ?? []); $values=array_merge($values,$items); $last=(int)($response['last_value_id'] ?? 0);
        } while($last>0 && $items!==[]);
        $attribute->update(['values_payload'=>$values]);
    }
    private function storeNodes(OzonAccount $account,array $items,?OzonTaxonomyNode $parent): int
    {
        $count=0;
        foreach($items as $item) {
            $categoryId=(string)($item['description_category_id'] ?? '');
            $typeId=(string)($item['type_id'] ?? '0');
            if($categoryId==='') { $count += $this->storeNodes($account,(array)($item['children'] ?? []),$parent); continue; }
            $node=OzonTaxonomyNode::query()->updateOrCreate(['ozon_account_id'=>$account->id,'description_category_id'=>$categoryId,'type_id'=>$typeId],['parent_id'=>$parent?->id,'category_name'=>(string)($item['category_name'] ?? $categoryId),'type_name'=>(string)($item['type_name'] ?? ''),'is_disabled'=>(bool)($item['disabled'] ?? false),'raw_payload'=>$item,'synced_at'=>now()]);
            $count++; $count += $this->storeNodes($account,(array)($item['children'] ?? []),$node);
        }
        return $count;
    }
}
