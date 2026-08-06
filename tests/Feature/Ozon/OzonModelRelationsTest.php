<?php

namespace Tests\Feature\Ozon;

use App\Enums\AutomationRunStatus;
use App\Enums\OzonOperationType;
use App\Models\AutomationRun;
use App\Models\Category;
use App\Models\OzonAccount;
use App\Models\OzonOperation;
use App\Models\OzonProduct;
use App\Models\OzonWarehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OzonModelRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_ozon_relations_resolve_to_expected_models(): void
    {
        $account = OzonAccount::factory()->create();
        $warehouse = OzonWarehouse::factory()->for($account, 'account')->create();
        $category = Category::query()->create(['name' => 'Присадки', 'slug' => 'prisadki']);
        $ozonProduct = OzonProduct::factory()->for($account, 'account')->create([
            'site_category_id' => $category->id,
            'ozon_warehouse_id' => $warehouse->id,
        ]);
        $run = AutomationRun::query()->create([
            'type' => 'ozon_phase_one_test', 'source' => 'system',
            'status' => AutomationRunStatus::Pending->value, 'requested_at' => now(),
            'lock_key' => 'automation:ozon-phase-one-test',
        ]);
        $operation = OzonOperation::factory()->for($account, 'account')->create([
            'ozon_product_id' => $ozonProduct->id,
            'automation_run_id' => $run->id,
            'operation_type' => OzonOperationType::ProductPrepare,
        ]);

        $this->assertTrue($account->warehouses->contains($warehouse));
        $this->assertTrue($account->ozonProducts->contains($ozonProduct));
        $this->assertTrue($account->operations->contains($operation));
        $this->assertTrue($warehouse->account->is($account));
        $this->assertTrue($warehouse->ozonProducts->contains($ozonProduct));
        $this->assertTrue($ozonProduct->account->is($account));
        $this->assertTrue($ozonProduct->product->ozonProducts->contains($ozonProduct));
        $this->assertTrue($ozonProduct->siteCategory->is($category));
        $this->assertTrue($category->ozonProducts->contains($ozonProduct));
        $this->assertTrue($ozonProduct->warehouse->is($warehouse));
        $this->assertTrue($ozonProduct->operations->contains($operation));
        $this->assertTrue($operation->account->is($account));
        $this->assertTrue($operation->ozonProduct->is($ozonProduct));
        $this->assertTrue($operation->automationRun->is($run));
        $this->assertTrue($run->ozonOperations->contains($operation));
    }
}
