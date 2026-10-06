<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontMetrikaGoalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_storefront_exposes_safe_metrika_goal_helper(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('window.reachMetrikaGoal', false);
        $response->assertSee("'whatsapp_click'", false);
        $response->assertSee("'kaspi_click'", false);
        $response->assertSee("'add_to_cart'", false);
        $response->assertSee("'order_success'", false);
        $response->assertSee("'storefront:add-to-cart:success'", false);
        $response->assertSee("'storefront:order:success'", false);
    }

    public function test_metrika_goal_parameters_do_not_allow_pii(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertDontSee("'phone'", false);
        $response->assertDontSee("'name'", false);
        $response->assertDontSee("'email'", false);
    }
}
