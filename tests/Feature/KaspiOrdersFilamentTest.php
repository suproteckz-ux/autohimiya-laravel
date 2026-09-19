<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KaspiOrdersFilamentTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_kaspi_orders_list_opens_with_empty_db(): void
    {
        $this->actingAs($this->adminUser());

        $response = $this->get('/admin/kaspi-orders');

        $response->assertStatus(200);
    }

    public function test_kaspi_orders_dashboard_opens_without_token(): void
    {
        config(['services.kaspi.partner_api_token' => null]);

        $this->actingAs($this->adminUser());

        $response = $this->get('/admin/kaspi-orders-dashboard');

        $response->assertStatus(200);
    }

    public function test_kaspi_orders_list_no_filament_action_class_error(): void
    {
        $this->actingAs($this->adminUser());

        $response = $this->get('/admin/kaspi-orders');

        // Class not found errors produce a 500; assert it doesn't
        $response->assertStatus(200);
        $response->assertDontSee('Class "Filament\Tables\Actions\ViewAction" not found');
    }

    public function test_kaspi_orders_list_renders_empty_state(): void
    {
        $this->actingAs($this->adminUser());

        $response = $this->get('/admin/kaspi-orders');

        $response->assertStatus(200);
        // Table renders without error (no orders exist)
        $response->assertSee('kaspi');
    }
}
