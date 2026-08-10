<?php

namespace Tests\Feature\Ozon;

use App\Enums\AutomationRunSource;
use App\Enums\AutomationType;
use App\Enums\OzonOperationStatus;
use App\Enums\OzonProductStatus;
use App\Filament\Resources\OzonProductResource\Pages\ListOzonProducts;
use App\Models\AutomationRun;
use App\Models\OzonAccount;
use App\Models\OzonOperation;
use App\Models\OzonProduct;
use App\Models\OzonTaxonomyAttribute;
use App\Models\OzonTaxonomyNode;
use App\Models\OzonWarehouse;
use App\Models\User;
use App\Services\Automation\AutomationRunner;
use App\Services\Automation\AutomationRunService;
use App\Services\Ozon\OzonApiClient;
use App\Services\Ozon\OzonProductPayloadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OzonSingleProductExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actingAs(User::query()->create(['name'=>'Admin','email'=>'phase4@example.test','password'=>'secret','is_admin'=>true]));
    }

    public function test_write_allowlist_contains_only_product_import(): void
    {
        $this->assertSame(['/v3/product/import'], OzonApiClient::writeEndpoints());
        $this->assertContains('/v1/product/import/info', OzonApiClient::allowedEndpoints());
        $all = [...OzonApiClient::writeEndpoints(), ...OzonApiClient::allowedEndpoints()];
        foreach (['stocks','prices','archive','delete','orders','postings'] as $forbidden) $this->assertFalse(collect($all)->contains(fn($endpoint)=>str_contains($endpoint,$forbidden)));
    }

    public function test_payload_uses_persisted_snapshot_and_omits_null_tnved(): void
    {
        $product=$this->product();
        $payload=app(OzonProductPayloadBuilder::class)->build($product);
        $item=$payload['items'][0];
        $this->assertSame('aut_737',$item['offer_id']);
        $this->assertSame('ER5 148мл',$item['name']);
        $this->assertSame(17028752,$item['description_category_id']);
        $this->assertSame(92258,$item['type_id']);
        $this->assertSame('12600.00',$item['price']);
        $this->assertSame(['https://example.test/er5.jpg'],$item['images']);
        $this->assertSame(350,$item['weight']);
        $this->assertSame('g',$item['weight_unit']);
        $this->assertSame('mm',$item['dimension_unit']);
        $this->assertArrayNotHasKey('tnved_code',$item);
        $this->assertArrayNotHasKey('description',$item);
        $this->assertSame(771234,$item['attributes'][0]['id']);
        $this->assertSame('Persisted description',$item['attributes'][0]['values'][0]['value']);
        $this->assertSame(0,$item['attributes'][0]['values'][0]['dictionary_value_id']);
    }

    public function test_description_uses_er5_taxonomy_annotation_without_truncation_or_guessed_id(): void
    {
        $description = str_repeat('Полное описание ER5. ', 300);
        $product = $this->product(['prepared_description' => $description]);

        $item = app(OzonProductPayloadBuilder::class)->build($product)['items'][0];
        $annotation = collect($item['attributes'])->sole(fn (array $attribute): bool => $attribute['id'] === 771234);

        $this->assertArrayNotHasKey('description', $item);
        $this->assertSame(0, $annotation['complex_id']);
        $this->assertSame(0, $annotation['values'][0]['dictionary_value_id']);
        $this->assertSame($description, $annotation['values'][0]['value']);
        $this->assertNotSame(4191, $annotation['id']);
        $this->assertNotSame(9048, $annotation['id']);
    }

    public function test_missing_annotation_taxonomy_attribute_blocks_export_before_http(): void
    {
        $product = $this->product();
        OzonTaxonomyAttribute::query()->delete();

        try {
            app(OzonProductPayloadBuilder::class)->build($product);
            $this->fail('Expected missing annotation validation error.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Для выбранной категории Ozon не загружена характеристика «Аннотация». Обновите taxonomy.',
                $exception->errors()['ozon_product'][0],
            );
        }

        Http::assertNothingSent();
    }

    public function test_model_attribute_remains_independent_from_annotation(): void
    {
        $product = $this->product();
        $node = OzonTaxonomyNode::query()->where('ozon_account_id', $product->ozon_account_id)->sole();
        OzonTaxonomyAttribute::query()->create([
            'ozon_taxonomy_node_id' => $node->id,
            'attribute_id' => '9048',
            'name' => 'Название модели',
            'type' => 'String',
            'is_required' => true,
            'is_collection' => false,
            'synced_at' => now(),
        ]);

        $attributes = app(OzonProductPayloadBuilder::class)->build($product)['items'][0]['attributes'];

        $this->assertSame([771234], array_column($attributes, 'id'));
    }

    public function test_annotation_is_appended_without_overwriting_existing_ozon_attributes(): void
    {
        $existing = [
            'id' => 123456,
            'complex_id' => 0,
            'values' => [['dictionary_value_id' => 55, 'value' => 'Existing value']],
        ];
        $product = $this->product(['prepared_attributes' => [$existing]]);

        $attributes = app(OzonProductPayloadBuilder::class)->build($product)['items'][0]['attributes'];

        $this->assertCount(2, $attributes);
        $this->assertSame($existing, $attributes[0]);
        $this->assertSame(771234, $attributes[1]['id']);
    }

    public function test_non_er5_prepared_product_builds_export_payload(): void
    {
        $product=$this->product(['offer_id'=>'aut_1224','prepared_name'=>'Lucky Top New']);

        $item=app(OzonProductPayloadBuilder::class)->build($product)['items'][0];

        $this->assertSame('aut_1224',$item['offer_id']);
        $this->assertSame('Lucky Top New',$item['name']);
        Http::assertNothingSent();
    }

    public function test_invalid_non_er5_product_is_still_blocked_before_http(): void
    {
        $product=$this->product(['offer_id'=>'aut_1224','prepared_name'=>'']);
        $this->expectException(ValidationException::class);
        try { app(OzonProductPayloadBuilder::class)->build($product); } finally { Http::assertNothingSent(); }
    }

    public function test_lucky_top_action_creates_pending_run_for_exact_product_without_http(): void
    {
        $product=$this->product(['offer_id'=>'aut_1224','prepared_name'=>'Lucky Top New']);

        Livewire::test(ListOzonProducts::class)
            ->callTableAction('export',$product)
            ->assertNotified('Отправка поставлена в очередь.');

        $run=AutomationRun::query()->sole();
        $this->assertSame(AutomationType::OzonProductExport->value,$run->type);
        $this->assertSame('pending',$run->status);
        $this->assertSame($product->id,$run->context['ozon_product_id']);
        $this->assertSame(OzonProductStatus::Queued,$product->fresh()->status);
        $this->assertDatabaseCount('ozon_operations',0);
        Http::assertNothingSent();
    }

    public function test_filament_action_only_queues_and_handler_sends_once(): void
    {
        $product=$this->product();
        Http::fake([OzonApiClient::BASE_URL.'/v3/product/import'=>Http::response(['result'=>['task_id'=>778899]],200)]);
        Livewire::test(ListOzonProducts::class)->callTableAction('export',$product)->assertNotified('Отправка поставлена в очередь.');
        $this->assertSame(OzonProductStatus::Queued,$product->fresh()->status);
        $this->assertDatabaseCount('automation_runs',1);
        $this->assertDatabaseCount('ozon_operations',0);
        Http::assertNothingSent();

        $run=AutomationRun::query()->sole();
        app(AutomationRunner::class)->runPending(runId:$run->id,limit:1);
        $fresh=$product->fresh();
        $this->assertSame(OzonProductStatus::Processing,$fresh->status);
        $this->assertSame('778899',$fresh->ozon_task_id);
        $operation=OzonOperation::query()->sole();
        $this->assertSame($product->id,$operation->ozon_product_id);
        $this->assertSame(OzonOperationStatus::Completed,$operation->status);
        $this->assertSame('aut_737',$operation->request_payload['items'][0]['offer_id']);
        $this->assertStringNotContainsString((string)$product->account->api_key,json_encode($operation->toArray()));
        Http::assertSentCount(1);
    }

    public function test_http_success_without_task_id_is_failure(): void
    {
        $product=$this->product();
        Http::fake([OzonApiClient::BASE_URL.'/v3/product/import'=>Http::response(['result'=>[]],200)]);
        $run=$this->requestExport($product);
        app(AutomationRunner::class)->runPending(runId:$run->id);
        $this->assertSame(OzonProductStatus::Failed,$product->fresh()->status);
        $this->assertStringContainsString('without task_id',$product->fresh()->last_error);
        $this->assertSame(OzonOperationStatus::Failed,OzonOperation::query()->sole()->status);
    }

    public function test_http_and_item_errors_are_persisted_safely(): void
    {
        $product=$this->product();
        Http::fake([OzonApiClient::BASE_URL.'/v3/product/import'=>Http::response(['message'=>'bad item'],400)]);
        $run=$this->requestExport($product);
        app(AutomationRunner::class)->runPending(runId:$run->id);
        $this->assertSame(400,OzonOperation::query()->sole()->http_status);
        $this->assertStringContainsString('bad item',$product->fresh()->last_error);

        OzonOperation::query()->delete();
        $product->update(['status'=>OzonProductStatus::Processing,'ozon_task_id'=>'123','last_error'=>null]);
        Http::fake([OzonApiClient::BASE_URL.'/v1/product/import/info'=>Http::response(['result'=>['items'=>[['errors'=>[['code'=>'ATTRIBUTE_REQUIRED','message'=>'Required attribute missing']]]]]],200)]);
        $run=app(AutomationRunService::class)->request(AutomationType::OzonProductExportStatus,AutomationRunSource::Admin,auth()->user(),['ozon_product_id'=>$product->id])['run'];
        app(AutomationRunner::class)->runPending(runId:$run->id);
        $this->assertSame(OzonProductStatus::Failed,$product->fresh()->status);
        $this->assertStringContainsString('ATTRIBUTE_REQUIRED',$product->fresh()->last_error);
    }

    public function test_existing_task_and_duplicate_pending_are_blocked(): void
    {
        $product=$this->product();
        $first=$this->requestExport($product);
        $second=app(AutomationRunService::class)->request(AutomationType::OzonProductExport,AutomationRunSource::Admin,auth()->user(),['ozon_product_id'=>$product->id]);
        $this->assertFalse($second['created']);
        $this->assertSame($first->id,$second['run']->id);
        $product->update(['ozon_task_id'=>'999']);
        Http::fake();
        app(AutomationRunner::class)->runPending(runId:$first->id);
        Http::assertNothingSent();
    }

    #[DataProvider('retryableStatuses')]
    public function test_write_retries_are_bounded_for_429_and_5xx(int $status): void
    {
        $product=$this->product();
        Http::fake([OzonApiClient::BASE_URL.'/v3/product/import'=>Http::response(['message'=>'temporary'], $status)]);
        $run=$this->requestExport($product);
        app(AutomationRunner::class)->runPending(runId:$run->id);
        Http::assertSentCount(3);
    }

    public static function retryableStatuses(): array { return [[429],[500]]; }

    public function test_write_timeout_retry_is_bounded(): void
    {
        $product=$this->product();
        Http::fake(fn()=>Http::failedConnection());
        $run=$this->requestExport($product);
        app(AutomationRunner::class)->runPending(runId:$run->id);
        Http::assertSentCount(3);
        $this->assertStringNotContainsString('phase4-secret',(string)$product->fresh()->last_error);
    }

    public function test_auth_error_is_redacted_and_source_product_is_unchanged(): void
    {
        $product=$this->product();
        $before=$product->product->fresh()->getAttributes();
        Http::fake([OzonApiClient::BASE_URL.'/v3/product/import'=>Http::response(['message'=>'phase4-secret denied'],401)]);
        $run=$this->requestExport($product);
        app(AutomationRunner::class)->runPending(runId:$run->id);
        $operation=OzonOperation::query()->sole();
        $this->assertStringNotContainsString('phase4-secret',(string)$operation->error_message);
        $this->assertStringNotContainsString('phase4-secret',json_encode($operation->response_payload));
        $this->assertSame($before,$product->product->fresh()->getAttributes());
    }

    public function test_resource_has_no_bulk_export(): void
    {
        $component=Livewire::test(ListOzonProducts::class);
        $this->assertFalse(collect($component->instance()->getTable()->getBulkActions())->contains(fn($action)=>str_contains(strtolower($action->getName()),'export')));
    }

    private function requestExport(OzonProduct $product): AutomationRun
    {
        $result=app(AutomationRunService::class)->request(AutomationType::OzonProductExport,AutomationRunSource::Admin,auth()->user(),['ozon_product_id'=>$product->id]);
        $product->update(['status'=>OzonProductStatus::Queued]);
        return $result['run'];
    }

    private function product(array $overrides=[]): OzonProduct
    {
        $account=OzonAccount::factory()->create(['is_active'=>true,'api_key'=>'phase4-secret']);
        $warehouse=OzonWarehouse::factory()->create(['ozon_account_id'=>$account->id,'is_active'=>true,'is_api_confirmed'=>true]);
        $product=OzonProduct::factory()->create(array_merge(['ozon_account_id'=>$account->id,'ozon_warehouse_id'=>$warehouse->id,'offer_id'=>'aut_737','prepared_name'=>'ER5 148мл','description_category_id'=>'17028752','description_category_name'=>'Автохимия','type_id'=>'92258','type_name'=>'Присадка','prepared_description'=>'Persisted description','prepared_images'=>['https://example.test/er5.jpg'],'prepared_attributes'=>[['name'=>'Объём','value'=>'148 мл']],'prepared_payload'=>['source'=>'persisted'],'calculated_price'=>'12600.00','calculated_stock'=>10,'weight_g'=>350,'width_mm'=>150,'height_mm'=>100,'depth_mm'=>100,'tnved_code'=>null,'status'=>OzonProductStatus::Ready],$overrides));
        $node=OzonTaxonomyNode::query()->create(['ozon_account_id'=>$account->id,'description_category_id'=>$product->description_category_id,'category_name'=>$product->description_category_name,'type_id'=>$product->type_id,'type_name'=>$product->type_name,'is_disabled'=>false,'synced_at'=>now()]);
        OzonTaxonomyAttribute::query()->create(['ozon_taxonomy_node_id'=>$node->id,'attribute_id'=>'771234','name'=>'Аннотация','type'=>'String','dictionary_id'=>'0','is_required'=>false,'is_collection'=>false,'raw_payload'=>['max_value_count'=>1],'synced_at'=>now()]);
        return $product;
    }
}
