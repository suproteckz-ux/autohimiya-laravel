<?php

namespace Tests\Feature\Ozon;

use App\Enums\AutomationRunSource;
use App\Enums\AutomationType;
use App\Enums\OzonOperationType;
use App\Exceptions\OzonApiException;
use App\Models\OzonAccount;
use App\Models\OzonOperation;
use App\Models\OzonTaxonomyNode;
use App\Models\OzonWarehouse;
use App\Services\Automation\AutomationRunService;
use App\Services\Ozon\OzonApiClient;
use App\Services\Ozon\OzonTaxonomyService;
use App\Services\Ozon\OzonWarehouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OzonReadOnlyApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); Http::preventStrayRequests(); }

    public function test_successful_authorization_uses_required_headers_and_redacts_operation(): void
    {
        $account=OzonAccount::factory()->create(['client_id'=>'123','api_key'=>'super-secret']);
        Http::fake([OzonApiClient::BASE_URL.'/*'=>Http::response(['result'=>[]],200,['x-request-id'=>'req-1'])]);
        app(OzonApiClient::class)->post($account,'/v1/warehouse/list',['limit'=>1,'offset'=>0],OzonOperationType::ConnectionCheck);
        Http::assertSent(fn(Request $request)=>$request->hasHeader('Client-Id','123')&&$request->hasHeader('Api-Key','super-secret'));
        $serialized=OzonOperation::query()->first()->toJson();
        $this->assertStringNotContainsString('super-secret',$serialized);
        $this->assertStringNotContainsString('123',$serialized);
    }

    #[DataProvider('authorizationErrors')]
    public function test_authorization_errors_are_normalized(int $status): void
    {
        $account=OzonAccount::factory()->create(['api_key'=>'secret-value']); Http::fake([OzonApiClient::BASE_URL.'/*'=>Http::response(['message'=>'Access denied'], $status)]);
        try { app(OzonApiClient::class)->post($account,'/v1/warehouse/list',[],OzonOperationType::ConnectionCheck); $this->fail('Exception expected'); }
        catch(OzonApiException $e) { $this->assertSame($status,$e->httpStatus); $this->assertStringNotContainsString('secret-value',$e->getMessage()); }
    }
    public static function authorizationErrors(): array { return [[401],[403]]; }

    public function test_429_and_5xx_have_bounded_retry_and_retry_after_support(): void
    {
        $account=OzonAccount::factory()->create(); Http::fakeSequence()->push(['message'=>'slow'],429,['Retry-After'=>'0'])->push(['message'=>'down'],500)->push(['result'=>[]],200);
        app(OzonApiClient::class)->post($account,'/v1/warehouse/list',[],OzonOperationType::ConnectionCheck);
        Http::assertSentCount(3); $this->assertSame(3,OzonOperation::query()->first()->attempt);
    }

    public function test_timeout_retry_is_bounded_and_secret_safe(): void
    {
        $account=OzonAccount::factory()->create(['api_key'=>'timeout-secret']); Http::fake(fn()=>Http::failedConnection('timed out'));
        $this->expectException(OzonApiException::class);
        try { app(OzonApiClient::class)->post($account,'/v1/warehouse/list',[],OzonOperationType::ConnectionCheck); }
        finally { Http::assertSentCount(3); $this->assertStringNotContainsString('timeout-secret',(string)OzonOperation::query()->first()?->error_message); }
    }

    public function test_warehouse_import_updates_existing_and_preserves_manual_rows(): void
    {
        $account=OzonAccount::factory()->create(); $existing=OzonWarehouse::factory()->create(['ozon_account_id'=>$account->id,'ozon_warehouse_id'=>'10','name'=>'Old']); $manual=OzonWarehouse::factory()->create(['ozon_account_id'=>$account->id,'ozon_warehouse_id'=>'manual','is_api_confirmed'=>false]);
        Http::fake([OzonApiClient::BASE_URL.'/*'=>Http::response(['result'=>[['warehouse_id'=>10,'name'=>'New','status'=>'ACTIVE'],['warehouse_id'=>20,'name'=>'Second','status'=>'ACTIVE']]],200)]);
        $result=app(OzonWarehouseService::class)->sync($account);
        $this->assertSame(1,$result['created']); $this->assertSame(1,$result['updated']); $this->assertSame('New',$existing->refresh()->name); $this->assertTrue($existing->is_api_confirmed); $this->assertDatabaseHas('ozon_warehouses',['id'=>$manual->id,'is_api_confirmed'=>false]);
    }

    public function test_taxonomy_tree_attributes_and_dictionary_values_are_cached(): void
    {
        $account=OzonAccount::factory()->create();
        Http::fakeSequence()->push(['result'=>[['description_category_id'=>1,'category_name'=>'Автохимия','type_id'=>2,'type_name'=>'Очиститель','children'=>[]]]],200)->push(['result'=>[['id'=>3,'name'=>'Бренд','dictionary_id'=>4,'is_required'=>true,'is_collection'=>false,'type'=>'String']]],200)->push(['result'=>[['id'=>5,'value'=>'Example']],'last_value_id'=>0],200);
        $service=app(OzonTaxonomyService::class); $service->syncTree($account); $node=OzonTaxonomyNode::query()->firstOrFail(); $service->syncAttributes($node);
        $this->assertSame('Автохимия',$node->category_name); $this->assertSame('Очиститель',$node->type_name); $this->assertSame('Example',$node->attributes()->first()->values_payload[0]['value']);
    }

    public function test_allow_list_contains_no_write_product_price_stock_or_order_endpoint(): void
    {
        $endpoints=implode(' ',OzonApiClient::allowedEndpoints());
        foreach(['/product/import','/products/stocks','/product/import/prices','/posting/','/order/'] as $forbidden) $this->assertStringNotContainsString($forbidden,$endpoints);
    }

    public function test_automation_request_is_pending_and_duplicate_protected(): void
    {
        $account=OzonAccount::factory()->create(); $service=app(AutomationRunService::class);
        $first=$service->request(AutomationType::OzonWarehouseSync,AutomationRunSource::Admin,null,['ozon_account_id'=>$account->id]); $second=$service->request(AutomationType::OzonWarehouseSync,AutomationRunSource::Admin,null,['ozon_account_id'=>$account->id]);
        $this->assertTrue($first['created']); $this->assertFalse($second['created']); $this->assertSame('pending',$first['run']->status); $this->assertSame($first['run']->id,$second['run']->id);
    }
}
