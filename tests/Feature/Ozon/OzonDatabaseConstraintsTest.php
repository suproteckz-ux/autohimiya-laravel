<?php

namespace Tests\Feature\Ozon;

use App\Models\AutomationRun;
use App\Models\Category;
use App\Models\OzonAccount;
use App\Models\OzonOperation;
use App\Models\OzonProduct;
use App\Models\OzonWarehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OzonDatabaseConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_external_id_is_unique_per_account_only(): void
    {
        $first = OzonAccount::factory()->create();
        $second = OzonAccount::factory()->create();
        OzonWarehouse::factory()->for($first, 'account')->create(['ozon_warehouse_id' => '9223372036854775808123']);
        OzonWarehouse::factory()->for($second, 'account')->create(['ozon_warehouse_id' => '9223372036854775808123']);

        $this->expectException(QueryException::class);
        OzonWarehouse::factory()->for($first, 'account')->create(['ozon_warehouse_id' => '9223372036854775808123']);
    }

    public function test_product_and_offer_are_unique_per_account_but_allowed_across_accounts(): void
    {
        $first = OzonAccount::factory()->create();
        $second = OzonAccount::factory()->create();
        $link = OzonProduct::factory()->for($first, 'account')->create(['offer_id' => 'SAME-OFFER']);
        OzonProduct::factory()->for($second, 'account')->create([
            'product_id' => $link->product_id,
            'offer_id' => 'SAME-OFFER',
        ]);

        try {
            OzonProduct::factory()->for($first, 'account')->create(['product_id' => $link->product_id]);
            $this->fail('Duplicate account/product must be rejected.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->expectException(QueryException::class);
        OzonProduct::factory()->for($first, 'account')->create(['offer_id' => 'SAME-OFFER']);
    }

    public function test_operation_key_is_globally_unique(): void
    {
        OzonOperation::factory()->create(['operation_key' => 'fixed-operation-key']);

        $this->expectException(QueryException::class);
        OzonOperation::factory()->create(['operation_key' => 'fixed-operation-key']);
    }

    public function test_warehouse_deletion_nulls_link_and_account_deletion_cascades_warehouses(): void
    {
        $account = OzonAccount::factory()->create();
        $warehouse = OzonWarehouse::factory()->for($account, 'account')->create();
        $link = OzonProduct::factory()->for($account, 'account')->create(['ozon_warehouse_id' => $warehouse->id]);

        $warehouse->delete();
        $this->assertNull($link->fresh()->ozon_warehouse_id);

        $emptyAccount = OzonAccount::factory()->create();
        $emptyWarehouse = OzonWarehouse::factory()->for($emptyAccount, 'account')->create();
        $emptyAccount->delete();
        $this->assertDatabaseMissing('ozon_warehouses', ['id' => $emptyWarehouse->id]);
    }

    public function test_site_category_deletion_nulls_reporting_reference(): void
    {
        $category = Category::query()->create(['name' => 'Временная категория', 'slug' => 'temporary-ozon-category']);
        $link = OzonProduct::factory()->create(['site_category_id' => $category->id]);

        $category->forceDelete();

        $this->assertNull($link->fresh()->site_category_id);
    }

    public function test_linked_product_and_account_cannot_be_deleted_silently(): void
    {
        $link = OzonProduct::factory()->create();

        try {
            $link->product->forceDelete();
            $this->fail('A linked Product must not be physically deleted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('ozon_products', ['id' => $link->id]);
        }

        $this->expectException(QueryException::class);
        $link->account->delete();
    }

    public function test_operation_deletion_behaviour_preserves_links_and_removes_child_logs(): void
    {
        $link = OzonProduct::factory()->create();
        $run = AutomationRun::query()->create([
            'type' => 'ozon_phase_one_test', 'source' => 'system', 'status' => 'pending',
            'requested_at' => now(), 'lock_key' => 'automation:ozon-phase-one-test',
        ]);
        $operation = OzonOperation::factory()->for($link->account, 'account')->create([
            'ozon_product_id' => $link->id,
            'automation_run_id' => $run->id,
        ]);

        $run->delete();
        $this->assertNull($operation->fresh()->automation_run_id);

        $link->delete();
        $this->assertDatabaseMissing('ozon_operations', ['id' => $operation->id]);
    }
}
