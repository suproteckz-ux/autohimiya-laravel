<?php

namespace Tests\Feature\Ozon;

use App\Enums\AutomationRunSource;
use App\Enums\AutomationType;
use App\Enums\OzonOperationType;
use App\Exceptions\OzonApiException;
use App\Models\OzonAccount;
use App\Models\AutomationRun;
use App\Models\OzonOperation;
use App\Models\OzonTaxonomyAttribute;
use App\Models\OzonTaxonomyNode;
use App\Models\OzonWarehouse;
use App\Services\Automation\AutomationRunService;
use App\Services\Automation\AutomationRunner;
use App\Services\Ozon\OzonApiClient;
use App\Services\Ozon\OzonConnectionService;
use App\Services\Ozon\OzonTaxonomyService;
use App\Services\Ozon\OzonWarehouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use UnexpectedValueException;

class OzonReadOnlyApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); Http::preventStrayRequests(); }

    public function test_connection_uses_current_seller_info_contract_and_redacts_operation(): void
    {
        $account=OzonAccount::factory()->create(['client_id'=>'123','api_key'=>'super-secret']);
        Http::fake([OzonApiClient::BASE_URL.'/v1/seller/info'=>Http::response(['company'=>['name'=>'Seller']],200,['x-request-id'=>'req-1'])]);
        $result=app(OzonConnectionService::class)->check($account);
        $this->assertTrue($result['successful']);
        Http::assertSent(function(Request $request): bool {
            $body=$request->body();
            return $request->url()===OzonApiClient::BASE_URL.'/v1/seller/info'
                &&$request->method()==='POST'
                &&$body==='{}'
                &&str_starts_with($body,'{')
                &&!str_starts_with($body,'[')
                &&$request->hasHeader('Content-Type','application/json')
                &&$request->hasHeader('Client-Id','123')
                &&$request->hasHeader('Api-Key','super-secret');
        });
        $operation=OzonOperation::query()->firstOrFail();
        $this->assertSame('POST',$operation->http_method); $this->assertSame('req-1',$operation->request_id);
        $this->assertStringNotContainsString('super-secret',$operation->toJson()); $this->assertStringNotContainsString('123',$operation->toJson());
    }

    #[DataProvider('authorizationErrors')]
    public function test_authorization_errors_are_normalized(int $status, string $code, string $message): void
    {
        $account=OzonAccount::factory()->create(['api_key'=>'secret-value']); Http::fake([OzonApiClient::BASE_URL.'/*'=>Http::response(['message'=>$message], $status)]);
        try { app(OzonApiClient::class)->post($account,'/v1/seller/info',[],OzonOperationType::ConnectionCheck); $this->fail('Exception expected'); }
        catch(OzonApiException $e) { $this->assertSame($status,$e->httpStatus); $this->assertSame($code,$e->errorCode); $this->assertStringNotContainsString('secret-value',$e->getMessage()); }
    }
    public static function authorizationErrors(): array { return [[401,'invalid_client_id','Invalid Client-Id'],[401,'invalid_api_key','Invalid Api-Key'],[403,'insufficient_permissions','Access denied']]; }

    public function test_obsolete_400_is_not_retried_and_has_safe_diagnostic(): void
    {
        $account=OzonAccount::factory()->create(['api_key'=>'obsolete-secret']); Http::fake([OzonApiClient::BASE_URL.'/*'=>Http::response(['message'=>'obsolete method cannot be used'],400)]);
        try { app(OzonApiClient::class)->post($account,'/v1/seller/info',[],OzonOperationType::ConnectionCheck); $this->fail('Exception expected'); }
        catch(OzonApiException $e) { $this->assertSame('obsolete_method',$e->errorCode); $this->assertStringContainsString('Ozon method is obsolete: POST /v1/seller/info',$e->getMessage()); }
        Http::assertSentCount(1); $operation=OzonOperation::query()->firstOrFail(); $this->assertSame('obsolete_method',$operation->error_code); $this->assertStringNotContainsString('obsolete-secret',$operation->toJson());
    }

    public function test_proto_syntax_error_is_stored_without_credentials(): void
    {
        $account=OzonAccount::factory()->create(['client_id'=>'proto-client','api_key'=>'proto-secret']);
        Http::fake([OzonApiClient::BASE_URL.'/v1/seller/info'=>Http::response(['message'=>'proto: syntax error (line 1:1): unexpected token ['],400)]);

        try { app(OzonConnectionService::class)->check($account); $this->fail('Exception expected'); }
        catch(OzonApiException $e) { $this->assertSame(400,$e->httpStatus); }

        $operation=OzonOperation::query()->firstOrFail();
        $this->assertSame('ozon_api_error',$operation->error_code);
        $this->assertStringContainsString('proto: syntax error',$operation->error_message);
        $this->assertStringNotContainsString('proto-secret',$operation->toJson());
        $this->assertStringNotContainsString('proto-client',$operation->toJson());
    }

    public function test_429_and_5xx_have_bounded_retry_and_retry_after_support(): void
    {
        $account=OzonAccount::factory()->create(); Http::fakeSequence()->push(['message'=>'slow'],429,['Retry-After'=>'0'])->push(['message'=>'down'],502)->push(['company'=>['name'=>'Seller']],200);
        app(OzonApiClient::class)->post($account,'/v1/seller/info',[],OzonOperationType::ConnectionCheck);
        Http::assertSentCount(3); $this->assertSame(3,OzonOperation::query()->first()->attempt);
    }

    #[DataProvider('serverErrors')]
    public function test_each_supported_server_error_is_retried_at_most_three_times(int $status): void
    {
        $account=OzonAccount::factory()->create();
        Http::fake([OzonApiClient::BASE_URL.'/*'=>Http::response(['message'=>'temporary'], $status)]);

        try { app(OzonApiClient::class)->post($account,'/v1/seller/info',[],OzonOperationType::ConnectionCheck); $this->fail('Exception expected'); }
        catch(OzonApiException $e) { $this->assertSame('ozon_unavailable',$e->errorCode); }

        Http::assertSentCount(3);
    }

    public static function serverErrors(): array { return [[500],[502],[503]]; }

    public function test_http_200_business_error_is_not_reported_as_success(): void
    {
        $account=OzonAccount::factory()->create();
        Http::fake([OzonApiClient::BASE_URL.'/*'=>Http::response(['message'=>'Business rule failed'],200)]);

        $this->expectException(OzonApiException::class);
        try { app(OzonApiClient::class)->post($account,'/v1/seller/info',[],OzonOperationType::ConnectionCheck); }
        finally { Http::assertSentCount(1); $this->assertSame('failed',OzonOperation::query()->firstOrFail()->status->value); }
    }

    public function test_timeout_retry_is_bounded_and_secret_safe(): void
    {
        $account=OzonAccount::factory()->create(['api_key'=>'timeout-secret']); Http::fake(fn()=>Http::failedConnection('timed out'));
        $this->expectException(OzonApiException::class);
        try { app(OzonApiClient::class)->post($account,'/v1/seller/info',[],OzonOperationType::ConnectionCheck); }
        finally { Http::assertSentCount(3); $this->assertStringNotContainsString('timeout-secret',(string)OzonOperation::query()->first()?->error_message); }
    }

    public function test_invalid_or_obsolete_endpoint_is_blocked_before_http(): void
    {
        $account=OzonAccount::factory()->create(); Http::fake();
        foreach(['/v1/warehouse/list','/v3/product/import'] as $endpoint) { try { app(OzonApiClient::class)->post($account,$endpoint,[],OzonOperationType::ConnectionCheck); $this->fail('Exception expected'); } catch(OzonApiException $e) { $this->assertStringContainsString('not permitted',$e->getMessage()); } }
        Http::assertNothingSent();
    }

    public function test_warehouse_v2_cursor_import_updates_existing_and_preserves_local_state(): void
    {
        $account=OzonAccount::factory()->create();
        $existing=OzonWarehouse::factory()->create(['ozon_account_id'=>$account->id,'ozon_warehouse_id'=>'10','name'=>'Old','is_default'=>true]);
        $manual=OzonWarehouse::factory()->create(['ozon_account_id'=>$account->id,'ozon_warehouse_id'=>'manual','is_api_confirmed'=>false]);
        Http::fakeSequence()->push(['warehouses'=>[['warehouse_id'=>10,'name'=>'New','status'=>'ACTIVE','warehouse_type'=>'FBS'],['warehouse_id'=>20,'name'=>'Paused','status'=>['state'=>'INACTIVE']]],'cursor'=>'next','has_next'=>true],200)->push(['warehouses'=>[['warehouse_id'=>30,'name'=>'Third','is_archived'=>true]],'cursor'=>'','has_next'=>false],200);
        $result=app(OzonWarehouseService::class)->sync($account);
        $this->assertSame(2,$result['created']); $this->assertSame(1,$result['updated']); $this->assertSame(3,$result['seen']);
        $this->assertSame('New',$existing->refresh()->name); $this->assertTrue($existing->is_default); $this->assertTrue($existing->is_api_confirmed);
        $this->assertFalse(OzonWarehouse::query()->where('ozon_warehouse_id','20')->firstOrFail()->is_active); $this->assertFalse(OzonWarehouse::query()->where('ozon_warehouse_id','30')->firstOrFail()->is_active);
        $this->assertDatabaseHas('ozon_warehouses',['id'=>$manual->id,'is_api_confirmed'=>false]);
        Http::assertSent(fn(Request $request)=>$request->url()===OzonApiClient::BASE_URL.'/v2/warehouse/list'&&str_starts_with($request->body(),'{')&&$request['limit']===100&&array_key_exists('cursor',$request->data()));
    }

    public function test_empty_warehouse_response_is_valid_but_malformed_response_is_rejected(): void
    {
        $account=OzonAccount::factory()->create(); Http::fakeSequence()->push(['warehouses'=>[],'cursor'=>'','has_next'=>false],200)->push(['result'=>[]],200);
        $this->assertSame(0,app(OzonWarehouseService::class)->sync($account)['seen']);
        $this->expectException(UnexpectedValueException::class); app(OzonWarehouseService::class)->sync($account);
    }

    public function test_taxonomy_is_idempotent_and_heavy_attribute_payloads_are_not_cached(): void
    {
        $account=OzonAccount::factory()->create();
        $tree=['result'=>[['description_category_id'=>1,'category_name'=>'Присадки в масло','disabled'=>false,'children'=>[['type_id'=>2,'type_name'=>'Присадка в моторное масло','disabled'=>false,'children'=>[]]]]]];
        Http::fakeSequence()->push($tree,200)->push($tree,200)->push(['result'=>[['id'=>3,'name'=>'Бренд','dictionary_id'=>4,'is_required'=>true,'is_collection'=>false,'type'=>'String','large_metadata'=>str_repeat('x',10000)]]],200);
        $service=app(OzonTaxonomyService::class); $service->syncTree($account); $service->syncTree($account); $node=OzonTaxonomyNode::query()->where('type_id','2')->firstOrFail(); $service->syncAttributes($node);
        $attribute=$node->attributes()->firstOrFail();
        $this->assertSame(2,OzonTaxonomyNode::query()->count()); $this->assertSame('1',$node->description_category_id); $this->assertSame('Присадки в масло',$node->category_name); $this->assertSame('Присадка в моторное масло',$node->type_name); $this->assertNull($attribute->values_payload); $this->assertNull($attribute->raw_payload);
        Http::assertSent(fn(Request $request)=>str_ends_with($request->url(),'/v1/description-category/tree')&&str_starts_with($request->body(),'{')&&$request['language']==='DEFAULT');
        Http::assertSent(fn(Request $request)=>str_ends_with($request->url(),'/v1/description-category/attribute')&&$request['description_category_id']===1&&$request['type_id']===2);
        Http::assertNotSent(fn(Request $request)=>str_ends_with($request->url(),'/v1/description-category/attribute/values'));
    }

    public function test_attribute_refresh_removes_previous_heavy_cached_payloads(): void
    {
        $account=OzonAccount::factory()->create(); $node=OzonTaxonomyNode::query()->create(['ozon_account_id'=>$account->id,'description_category_id'=>'1','category_name'=>'Old','type_id'=>'2','type_name'=>'Old','synced_at'=>now()]);
        $attribute=OzonTaxonomyAttribute::query()->create(['ozon_taxonomy_node_id'=>$node->id,'attribute_id'=>'3','name'=>'Old','dictionary_id'=>'4','values_payload'=>[['id'=>1,'value'=>'Old']],'synced_at'=>now()]);
        Http::fakeSequence()->push(['result'=>[['id'=>3,'name'=>'New','dictionary_id'=>4]]],200);
        $result=app(OzonTaxonomyService::class)->syncAttributes($node);
        $this->assertSame(1,$result['attributes_saved']);
        $this->assertCount(0,$result['warnings']);
        $this->assertSame('New',$attribute->refresh()->name);
        $this->assertNull($attribute->values_payload);
        $this->assertNull($attribute->raw_payload);
    }

    public function test_er5_type_node_receives_all_attributes_and_annotation_is_resolvable(): void
    {
        $account=OzonAccount::factory()->create();
        Http::fakeSequence()
            ->push(['result'=>[['description_category_id'=>17028752,'category_name'=>'Присадки в масло','children'=>[['type_id'=>92258,'type_name'=>'Присадка в моторное масло','disabled'=>false,'children'=>[]]]]]],200)
            ->push(['result'=>[
                ['id'=>71001,'name'=>'Аннотация','dictionary_id'=>0,'is_required'=>false,'is_collection'=>false,'type'=>'String'],
                ['id'=>9048,'name'=>'Название модели','dictionary_id'=>0,'is_required'=>true,'is_collection'=>false,'type'=>'String'],
                ['id'=>71003,'name'=>'Класс опасности','dictionary_id'=>0,'is_required'=>false,'is_collection'=>false,'type'=>'String'],
                ['id'=>71004,'name'=>'Нужен код маркировки','dictionary_id'=>0,'is_required'=>false,'is_collection'=>false,'type'=>'Boolean'],
                ['id'=>71005,'name'=>'ТН ВЭД коды ЕАЭС','dictionary_id'=>0,'is_required'=>false,'is_collection'=>true,'type'=>'String'],
            ]],200);

        $service=app(OzonTaxonomyService::class);
        $service->syncTree($account);
        $result=$service->syncAllAttributes($account);
        $node=OzonTaxonomyNode::query()->where('description_category_id','17028752')->where('type_id','92258')->firstOrFail();

        $this->assertSame(1,$result['type_nodes_total']);
        $this->assertSame(1,$result['type_nodes_processed']);
        $this->assertSame(5,$result['attributes_saved']);
        $this->assertSame(0,$result['failed_nodes']);
        $this->assertCount(5,$node->attributes);
        $this->assertSame('71001',$node->attributes()->where('name','Аннотация')->firstOrFail()->attribute_id);
    }

    public function test_failed_type_node_does_not_stop_remaining_attribute_batch(): void
    {
        $account=OzonAccount::factory()->create();
        OzonTaxonomyNode::query()->create(['ozon_account_id'=>$account->id,'description_category_id'=>'10','category_name'=>'One','type_id'=>'20','type_name'=>'One','is_disabled'=>false,'synced_at'=>now()]);
        $second=OzonTaxonomyNode::query()->create(['ozon_account_id'=>$account->id,'description_category_id'=>'11','category_name'=>'Two','type_id'=>'21','type_name'=>'Two','is_disabled'=>false,'synced_at'=>now()]);
        Http::fakeSequence()
            ->push(['message'=>'invalid node'],400)
            ->push(['result'=>[['id'=>31,'name'=>'Аннотация','dictionary_id'=>0,'type'=>'String']]],200);

        $result=app(OzonTaxonomyService::class)->syncAllAttributes($account);

        $this->assertSame(2,$result['type_nodes_total']);
        $this->assertSame(2,$result['type_nodes_processed']);
        $this->assertSame(1,$result['failed_nodes']);
        $this->assertSame(1,$result['attributes_saved']);
        $this->assertDatabaseHas('ozon_taxonomy_attributes',['ozon_taxonomy_node_id'=>$second->id,'attribute_id'=>'31']);
    }

    public function test_taxonomy_automation_loads_nodes_only(): void
    {
        $account=OzonAccount::factory()->create();
        Http::fakeSequence()
            ->push(['result'=>[['description_category_id'=>10,'category_name'=>'Присадки в масло','children'=>[['type_id'=>20,'type_name'=>'Присадка в моторное масло','children'=>[]]]]]],200);
        $run=app(AutomationRunService::class)->request(AutomationType::OzonTaxonomySync,AutomationRunSource::Admin,null,['ozon_account_id'=>$account->id])['run'];

        app(AutomationRunner::class)->runPending(runId:$run->id,limit:1);

        $this->assertSame('completed',$run->refresh()->status);
        $type=OzonTaxonomyNode::query()->where('type_id','20')->firstOrFail();
        $this->assertSame('10',$type->description_category_id);
        $this->assertSame('Присадки в масло',$type->category_name);
        $this->assertSame('Присадка в моторное масло',$type->type_name);
        $this->assertDatabaseCount('ozon_taxonomy_attributes',0);
        Http::assertSentCount(1);
    }

    public function test_repeated_taxonomy_sync_skips_fresh_nodes_and_never_starts_full_attributes(): void
    {
        $account=OzonAccount::factory()->create();
        Http::fake([OzonApiClient::BASE_URL.'/v1/description-category/tree'=>Http::response(['result'=>[['description_category_id'=>500,'category_name'=>'Category','children'=>[['type_id'=>1001,'type_name'=>'Type 1','children'=>[]]]]]],200)]);
        $runs=app(AutomationRunService::class);
        $first=$runs->request(AutomationType::OzonTaxonomySync,AutomationRunSource::Admin,null,['ozon_account_id'=>$account->id])['run'];

        app(AutomationRunner::class)->runPending(runId:$first->id,limit:1);
        $second=$runs->request(AutomationType::OzonTaxonomySync,AutomationRunSource::Admin,null,['ozon_account_id'=>$account->id])['run'];
        app(AutomationRunner::class)->runPending(runId:$second->id,limit:1);

        $this->assertSame('completed',$first->refresh()->status);
        $this->assertSame('completed',$second->refresh()->status);
        $this->assertDatabaseCount('ozon_taxonomy_nodes',2);
        $this->assertDatabaseCount('ozon_taxonomy_attributes',0);
        $this->assertDatabaseCount('automation_runs',2);
        Http::assertSentCount(1);
    }

    public function test_taxonomy_continuation_is_duplicate_protected(): void
    {
        $account=OzonAccount::factory()->create();
        $current=app(AutomationRunService::class)->request(AutomationType::OzonTaxonomySync,AutomationRunSource::Admin,null,['ozon_account_id'=>$account->id])['run'];
        $service=app(AutomationRunService::class);
        $context=['ozon_account_id'=>$account->id,'last_processed_node_id'=>10,'processed_nodes'=>20];

        $first=$service->requestContinuation($current,$context);
        $second=$service->requestContinuation($current,$context);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['run']->id,$second['run']->id);
        $this->assertDatabaseCount('automation_runs',2);
        Http::assertNothingSent();
    }

    public function test_allow_list_contains_only_current_read_only_endpoints(): void
    {
        $endpoints=OzonApiClient::allowedEndpoints();
        $this->assertContains('/v1/seller/info',$endpoints); $this->assertContains('/v2/warehouse/list',$endpoints); $this->assertContains('/v1/product/import/info',$endpoints); $this->assertNotContains('/v1/warehouse/list',$endpoints);
        foreach(['/v3/product/import','/products/stocks','/product/import/prices','/posting/','/order/','archive','delete'] as $forbidden) $this->assertNotContains($forbidden,$endpoints);
    }

    public function test_automation_request_is_pending_duplicate_protected_and_runner_completes(): void
    {
        $account=OzonAccount::factory()->create(); $service=app(AutomationRunService::class);
        $first=$service->request(AutomationType::OzonConnectionCheck,AutomationRunSource::Admin,null,['ozon_account_id'=>$account->id]); $second=$service->request(AutomationType::OzonConnectionCheck,AutomationRunSource::Admin,null,['ozon_account_id'=>$account->id]);
        $this->assertTrue($first['created']); $this->assertFalse($second['created']); $this->assertSame('pending',$first['run']->status);
        Http::fake([OzonApiClient::BASE_URL.'/v1/seller/info'=>Http::response(['company'=>['name'=>'Seller']],200)]); app(AutomationRunner::class)->runPending(runId:$first['run']->id,limit:1);
        $this->assertSame('completed',$first['run']->refresh()->status); $this->assertNotNull($account->refresh()->last_connection_check_at);
    }
}
