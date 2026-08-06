<?php

namespace Tests\Feature\Ozon;

use App\Models\OzonAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
}
