<?php

namespace Tests\Feature\Ozon;

use App\Filament\Pages\OzonProductExportPage;
use App\Models\OzonAccount;
use App\Models\OzonTaxonomyNode;
use App\Models\OzonWarehouse;
use App\Models\Category;
use App\Models\Product;
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
            ->assertSee($warehouse->name)
            ->assertSee('Сохранить подготовленные товары')
            ->assertSee('Вернуться к настройкам')
            ->assertSee('Отмена');

        $rows=$component->get('previewRows');
        $this->assertCount(1,$rows);
        $this->assertSame($product->id,$rows[0]['product_id']);
        $this->assertSame($warehouse->name,$rows[0]['snapshot']['warehouse_name']);
        $this->assertSame($node->type_id,$rows[0]['snapshot']['type_id']);
        $this->assertIsArray($rows[0]['snapshot']);
        $this->assertSame($before,$product->fresh()->only(array_keys($before)));
        $this->assertDatabaseCount('ozon_products',0);
        $this->assertDatabaseCount('ozon_operations',0);
        $this->assertDatabaseCount('automation_runs',0);
        Http::assertNothingSent();
    }

    public function test_manual_dry_run_works_and_internal_error_gets_safe_notification(): void
    {
        [$product,$account,$warehouse,,$category]=$this->dryRunFixture();
        $manual=array_merge($this->taxonomyActionData($account,$warehouse,null),[
            'taxonomy_mode'=>'manual','description_category_id'=>'manual-category','description_category_name'=>'Ручная категория','type_id'=>'manual-type','type_name'=>'Ручной тип',
        ]);
        unset($manual['ozon_taxonomy_node_id']);

        Livewire::test(OzonProductExportPage::class)->filterTable('category',$category->id)->callTableBulkAction('prepare',[$product],$manual)->assertNotified('Dry-run готов');
        $this->mock(OzonProductPreparationService::class)->shouldReceive('prepareBatch')->once()->andThrow(new \RuntimeException('internal detail must not reach the user'));
        Livewire::test(OzonProductExportPage::class)->filterTable('category',$category->id)->callTableBulkAction('prepare',[$product],$manual)->assertNotified('Dry-run не выполнен')->assertSet('previewRows',[])->assertDontSee('internal detail must not reach the user');
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
        DB::table('product_images')->insert(['product_id'=>$product->id,'path'=>'https://www.xn--80aesatk1az7g.kz/storage/products/oz-dry.jpg','role'=>'primary','is_primary'=>true,'sort_order'=>0,'created_at'=>now(),'updated_at'=>now()]);
        return [$product,$account,$warehouse,$node,$category];
    }

    private function taxonomyActionData(OzonAccount $account,OzonWarehouse $warehouse,?OzonTaxonomyNode $node): array
    {
        return ['ozon_account_id'=>$account->id,'taxonomy_mode'=>'taxonomy','ozon_taxonomy_node_id'=>$node?->id,'ozon_warehouse_id'=>$warehouse->id,'price_multiplier'=>1,'rounding_rule'=>'none','price_sync_enabled'=>true,'stock_sync_enabled'=>true];
    }
}
