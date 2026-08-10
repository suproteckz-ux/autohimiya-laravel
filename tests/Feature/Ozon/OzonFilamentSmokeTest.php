<?php

namespace Tests\Feature\Ozon;

use App\Filament\Pages\OzonProductExportPage;
use App\Models\OzonAccount;
use App\Models\OzonTaxonomyNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
