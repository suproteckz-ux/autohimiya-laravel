<?php

namespace Tests\Feature\Ozon;

use App\Enums\OzonProductStatus;
use App\Filament\Pages\OzonProductExportPage;
use App\Models\OzonAccount;
use App\Models\OzonTaxonomyNode;
use App\Models\OzonWarehouse;
use App\Models\OzonProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\User;
use App\Services\Ozon\OzonProductPreparationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class OzonFilamentSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actingAs(User::query()->create([
            'name' => 'Ozon test admin',
            'email' => 'ozon-filament@example.test',
            'password' => 'test-password',
            'is_admin' => true,
        ]));
    }

    public function test_all_phase_two_pages_render_without_server_errors(): void
    {
        $this->get('/admin/ozon-accounts')->assertOk()->assertSee('Настройки кабинета');
        $this->get('/admin/ozon-product-export')->assertOk()->assertSee('Выберите категорию сайта, чтобы загрузить товары.');
        $this->get('/admin/ozon-products')->assertOk()->assertSee('Черновики')->assertSee('Готовы');
    }

    public function test_edit_page_never_renders_decrypted_api_key(): void
    {
        $account = OzonAccount::factory()->create(['api_key' => 'phase21-secret-never-render']);

        $this->get('/admin/ozon-accounts/'.$account->id.'/edit')
            ->assertOk()
            ->assertDontSee('phase21-secret-never-render');
    }

    public function test_empty_valid_type_options_show_manual_fallback(): void
    {
        $account=OzonAccount::factory()->create();
        OzonTaxonomyNode::query()->create(['ozon_account_id'=>$account->id,'description_category_id'=>'17000000','category_name'=>'Автохимия','type_id'=>'0','type_name'=>'','is_disabled'=>false,'synced_at'=>now()]);

        $page=Livewire::test(OzonProductExportPage::class)->instance();
        $method=new \ReflectionMethod($page,'preparationForm');
        $components=collect($method->invoke($page))->keyBy(fn($component)=>$component->getName());

        $this->assertSame([],$page->taxonomyOptions($account->id));
        $this->assertSame('Типы Ozon ещё не загружены. Для теста можно ввести category/type вручную.',$page->taxonomyFallbackMessage());
        $this->assertSame(['taxonomy'=>'Выбрать из Ozon','manual'=>'Ввести вручную'],$components->get('taxonomy_mode')->getOptions());
        $this->assertSame('taxonomy',$components->get('taxonomy_mode')->getDefaultState());
        foreach(['description_category_id','description_category_name','type_id','type_name'] as $field) $this->assertTrue($components->has($field));
    }

    public function test_valid_imported_type_is_available_in_taxonomy_select(): void
    {
        $account=OzonAccount::factory()->create();
        $node=OzonTaxonomyNode::query()->create(['ozon_account_id'=>$account->id,'description_category_id'=>'10','category_name'=>'Присадки в масло','type_id'=>'20','type_name'=>'Присадка в моторное масло','is_disabled'=>false,'synced_at'=>now()]);
        $page=Livewire::test(OzonProductExportPage::class)->instance();

        $this->assertSame([$node->id=>'Присадки в масло — Присадка в моторное масло'],$page->taxonomyOptions($account->id));
    }

    public function test_production_like_taxonomy_dry_run_returns_serializable_preview_without_writes(): void
    {
        [$product,$account,$warehouse,$node,$category]=$this->dryRunFixture();
        $before=$product->fresh()->only(['name','sku','category_id','price','quantity']);

        $component=Livewire::test(OzonProductExportPage::class)
            ->filterTable('category',$category->id)
            ->callTableBulkAction('prepare',[$product],$this->taxonomyActionData($account,$warehouse,$node))
            ->assertHasNoErrors()
            ->assertNotified('Dry-run готов')
            ->assertSee('Dry-run выполнен локально. Данные в Ozon не отправлялись.')
            ->assertSee('Готово к сохранению: 1')
            ->assertSee('С предупреждениями: 0')
            ->assertSee('С ошибками: 0')
            ->assertSee($warehouse->name)
            ->assertSee('Сохранить подготовленные товары')
            ->assertSee('Вернуться к настройкам')
            ->assertSee('Отмена');

        $rows=$component->get('previewRows');
        $this->assertArrayHasKey('html',$component->effects,'Livewire effects: '.json_encode(array_keys($component->effects)));
        $this->assertArrayNotHasKey('partials',$component->effects,'The dry-run response must force a full component render so preview outside the table partial reaches the browser.');
        $settings=$component->get('preparationSettings');
        $this->assertCount(1,$rows);
        $this->assertSame($product->id,$rows[0]['product_id']);
        $this->assertSame($warehouse->name,$rows[0]['snapshot']['warehouse_name']);
        $this->assertSame($node->type_id,$rows[0]['snapshot']['type_id']);
        $this->assertIsArray($rows[0]['snapshot']);
        $this->assertSame($before,$product->fresh()->only(array_keys($before)));
        $this->assertDatabaseCount('ozon_products',0);
        $this->assertDatabaseCount('ozon_operations',0);
        $this->assertDatabaseCount('automation_runs',0);

        $component->call('savePreparedProducts')->assertNotified('Товар подготовлен и сохранён локально.')->assertSet('previewRows',[])->assertSet('selectedTableRecords',[]);
        $this->assertDatabaseCount('ozon_products',1);
        $saved=OzonProduct::query()->sole();
        $this->assertSame(OzonProductStatus::Ready,$saved->status);
        $this->assertSame($product->sku,$saved->offer_id);
        $this->assertNull($saved->ozon_product_id);
        $this->assertNull($saved->ozon_sku);
        $this->assertNull($saved->ozon_task_id);
        $this->assertNotEmpty($saved->prepared_payload);
        $this->assertSame($account->id,$saved->ozon_account_id);
        $this->assertSame($product->id,$saved->product_id);
        $this->assertSame($category->id,$saved->site_category_id);
        $this->assertSame($warehouse->id,$saved->ozon_warehouse_id);
        $this->assertSame($node->description_category_id,$saved->description_category_id);
        $this->assertSame($node->category_name,$saved->description_category_name);
        $this->assertSame($node->type_id,$saved->type_id);
        $this->assertSame($node->type_name,$saved->type_name);
        $this->assertSame($product->name,$saved->prepared_name);
        $this->assertNotEmpty($saved->prepared_description);
        $this->assertCount(2,$saved->prepared_images);
        $this->assertCount(1,$saved->prepared_attributes);
        $this->assertTrue($saved->price_sync_enabled);
        $this->assertTrue($saved->stock_sync_enabled);
        $this->assertSame('1.0000',$saved->price_multiplier);
        $this->assertSame('none',$saved->rounding_rule);
        $this->assertSame('3402',$saved->tnved_code);
        $this->assertSame('1000.00',$saved->calculated_price);
        $this->assertSame(5,$saved->calculated_stock);
        $this->assertSame($before,$product->fresh()->only(array_keys($before)));
        $this->get('/admin/ozon-products')->assertOk()->assertSee($product->name)->assertSee($product->sku);

        $component->set('previewRows',$rows)->set('preparationSettings',$settings)->call('savePreparedProducts');
        $this->assertDatabaseCount('ozon_products',1);
        $this->assertSame($product->sku,$saved->fresh()->offer_id);
        Http::assertNothingSent();
    }

    public function test_manual_dry_run_works_and_internal_error_gets_safe_notification(): void
    {
        [$product,$account,$warehouse,,$category]=$this->dryRunFixture();
        $manual=array_merge($this->taxonomyActionData($account,$warehouse,null),[
            'taxonomy_mode'=>'manual','description_category_id'=>'manual-category','description_category_name'=>'Ручная категория','type_id'=>'manual-type','type_name'=>'Ручной тип',
        ]);
        unset($manual['ozon_taxonomy_node_id']);

        $manualComponent=Livewire::test(OzonProductExportPage::class)->filterTable('category',$category->id)->callTableBulkAction('prepare',[$product],$manual)->assertNotified('Dry-run готов');
        $manualComponent->call('savePreparedProducts')->assertNotified('Товар подготовлен и сохранён локально.');
        $this->assertSame(OzonProductStatus::Draft,OzonProduct::query()->sole()->status);
        $this->mock(OzonProductPreparationService::class)->shouldReceive('prepareBatch')->once()->andThrow(new \RuntimeException('internal detail must not reach the user'));
        Livewire::test(OzonProductExportPage::class)->filterTable('category',$category->id)->callTableBulkAction('prepare',[$product],$manual)->assertNotified('Dry-run не выполнен')->assertSet('previewRows',[])->assertDontSee('internal detail must not reach the user');
        Http::assertNothingSent();
    }

    public function test_critical_preview_is_not_saved(): void
    {
        [$product,$account,$warehouse,$node,$category]=$this->dryRunFixture();
        $component=Livewire::test(OzonProductExportPage::class)->filterTable('category',$category->id)->callTableBulkAction('prepare',[$product],$this->taxonomyActionData($account,$warehouse,$node));
        $rows=$component->get('previewRows');
        $rows[0]['errors']=['Критическая ошибка'];
        $rows[0]['is_ready']=false;
        $component->set('previewRows',$rows)->call('savePreparedProducts')->assertNotified('Товар подготовлен и сохранён локально.');

        $this->assertDatabaseCount('ozon_products',0);
        $this->assertSame('Тестовый товар',$product->fresh()->name);
        Http::assertNothingSent();
    }

    private function dryRunFixture(): array
    {
        config(['app.url'=>'https://www.xn--80aesatk1az7g.kz']);
        $category=Category::query()->create(['name'=>'Присадки','slug'=>'ozon-dry-run','status'=>'active']);
        $account=OzonAccount::factory()->create(['is_active'=>true]);
        $warehouse=OzonWarehouse::factory()->create(['ozon_account_id'=>$account->id,'name'=>'API склад','is_active'=>true,'is_api_confirmed'=>true]);
        $node=OzonTaxonomyNode::query()->create(['ozon_account_id'=>$account->id,'description_category_id'=>'17000000','category_name'=>'Присадки в масло','type_id'=>'17001','type_name'=>'Присадка в моторное масло','is_disabled'=>false,'synced_at'=>now()]);
        $product=Product::query()->create(['name'=>'Тестовый товар','slug'=>'ozon-dry-product','sku'=>'OZ-DRY-1','category_id'=>$category->id,'price'=>'1000.00','quantity'=>5,'description'=>'Описание','primary_image'=>'https://www.xn--80aesatk1az7g.kz/storage/products/oz-dry.jpg']);
        DB::table('product_images')->insert([['product_id'=>$product->id,'path'=>'https://www.xn--80aesatk1az7g.kz/storage/products/oz-dry.jpg','role'=>'primary','is_primary'=>true,'sort_order'=>0,'created_at'=>now(),'updated_at'=>now()],['product_id'=>$product->id,'path'=>'https://www.xn--80aesatk1az7g.kz/storage/products/oz-dry-2.jpg','role'=>'gallery','is_primary'=>false,'sort_order'=>1,'created_at'=>now(),'updated_at'=>now()]]);
        ProductAttribute::query()->create(['product_id'=>$product->id,'name'=>'Объём','value'=>'100','unit'=>'мл']);
        return [$product,$account,$warehouse,$node,$category];
    }

    private function taxonomyActionData(OzonAccount $account,OzonWarehouse $warehouse,?OzonTaxonomyNode $node): array
    {
        return ['ozon_account_id'=>$account->id,'taxonomy_mode'=>'taxonomy','ozon_taxonomy_node_id'=>$node?->id,'ozon_warehouse_id'=>$warehouse->id,'price_multiplier'=>1,'rounding_rule'=>'none','tnved_code'=>'3402','price_sync_enabled'=>true,'stock_sync_enabled'=>true];
    }
}
