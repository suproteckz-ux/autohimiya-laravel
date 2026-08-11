<?php

namespace Tests\Feature\Ozon;

use App\Console\Commands\OzonEmergencyStorageCleanupCommand as Cleanup;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationType;
use App\Enums\OzonOperationStatus;
use App\Enums\OzonOperationType;
use App\Models\AutomationRun;
use App\Models\OzonAccount;
use App\Models\OzonOperation;
use App\Models\OzonTaxonomyAttribute;
use App\Models\OzonTaxonomyNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OzonEmergencyStorageCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_incomplete_guard_combinations_are_read_only(): void
    {
        $this->fixtures();
        $before = $this->counts();

        self::assertSame(0, Artisan::call('ozon:emergency-storage-cleanup'));
        self::assertStringContainsString('NO CHANGES WERE MADE', Artisan::output());
        self::assertSame($before, $this->counts());

        self::assertSame(2, Artisan::call('ozon:emergency-storage-cleanup', ['--execute' => true]));
        self::assertSame($before, $this->counts());

        self::assertSame(0, Artisan::call('ozon:emergency-storage-cleanup', ['--confirm' => Cleanup::CONFIRMATION]));
        self::assertSame($before, $this->counts());

        self::assertSame(2, Artisan::call('ozon:emergency-storage-cleanup', ['--execute' => true, '--confirm' => Cleanup::CONFIRMATION]));
        self::assertSame($before, $this->counts());
    }

    public function test_preflight_aborts_for_missing_nodes(): void
    {
        $this->fixtures();
        OzonTaxonomyNode::query()->delete();
        self::assertSame(1, $this->execute());
        self::assertTrue(Schema::hasTable(Cleanup::LIVE));
    }

    public function test_preflight_aborts_for_active_taxonomy_run(): void
    {
        $this->fixtures();
        $this->automationRun(AutomationRunStatus::Pending, now());
        self::assertSame(1, $this->execute());
        self::assertFalse(Schema::hasTable(Cleanup::OLD));
    }

    public function test_preflight_requires_allow_flag_for_stale_taxonomy_run(): void
    {
        $this->fixtures();
        $this->automationRun(AutomationRunStatus::Running, now()->subHours(3));
        self::assertSame(1, $this->execute(allowStale: false));
        self::assertFalse(Schema::hasTable(Cleanup::OLD));
    }

    public function test_preflight_aborts_for_unexpected_replacement_state(): void
    {
        $this->fixtures();
        Schema::create(Cleanup::OLD, fn ($table) => $table->id());
        Schema::create(Cleanup::COMPACT, fn ($table) => $table->id());
        self::assertSame(1, $this->execute());
        self::assertDatabaseCount('ozon_taxonomy_attributes', 1);
    }

    public function test_stage_one_empties_attributes_preserves_nodes_products_operations_ids_and_keeps_old(): void
    {
        $ids = $this->fixtures();
        $nodes = DB::table('ozon_taxonomy_nodes')->count();
        $products = DB::table('ozon_products')->count();

        self::assertSame(0, $this->execute());

        self::assertDatabaseCount('ozon_taxonomy_attributes', 0);
        self::assertSame($nodes, DB::table('ozon_taxonomy_nodes')->count());
        self::assertSame($products, DB::table('ozon_products')->count());
        self::assertTrue(Schema::hasTable(Cleanup::OLD));
        self::assertFalse(Schema::hasTable(Cleanup::COMPACT));
        self::assertDatabaseMissing(Cleanup::LIVE, ['operation_type' => 'taxonomy_sync', 'status' => 'completed']);

        foreach (['taxonomy_failed', 'taxonomy_running', 'product_export', 'status_check', 'warehouse_sync', 'connection_check', 'unknown'] as $key) {
            self::assertDatabaseHas(Cleanup::LIVE, ['id' => $ids[$key]]);
        }
        self::assertTrue(Cleanup::shouldPreserveOperation('taxonomy_sync', 'pending'));
        self::assertDatabaseHas(Cleanup::OLD, ['id' => $ids['taxonomy_completed']]);
        self::assertGreaterThanOrEqual((int) DB::table(Cleanup::LIVE)->max('id'), (int) DB::table('sqlite_sequence')->where('name', Cleanup::LIVE)->value('seq'));

        $liveIndexes = $this->normalizedIndexes(Cleanup::LIVE);
        $oldIndexes = $this->normalizedIndexes(Cleanup::OLD);
        self::assertSame($oldIndexes, $liveIndexes);
        self::assertSame(0, $this->execute());
        self::assertTrue(Schema::hasTable(Cleanup::OLD));
    }

    public function test_drop_old_requires_guards_validates_then_is_idempotent(): void
    {
        $this->fixtures();
        self::assertSame(0, $this->execute());

        self::assertSame(2, Artisan::call('ozon:emergency-storage-cleanup', ['--execute' => true, '--confirm' => Cleanup::CONFIRMATION, '--drop-old' => true]));
        self::assertTrue(Schema::hasTable(Cleanup::OLD));

        self::assertSame(0, $this->execute(dropOld: true));
        self::assertFalse(Schema::hasTable(Cleanup::OLD));
        self::assertSame(0, $this->execute(dropOld: true));
    }

    public function test_rollback_restores_original_and_keeps_compact_copy(): void
    {
        $this->fixtures();
        self::assertSame(0, $this->execute());
        self::assertSame(0, $this->execute(rollback: true));
        self::assertTrue(Schema::hasTable(Cleanup::ROLLBACK_COPY));
        self::assertFalse(Schema::hasTable(Cleanup::OLD));
        self::assertDatabaseHas(Cleanup::LIVE, ['operation_type' => 'taxonomy_sync', 'status' => 'completed']);

    }

    public function test_rollback_is_unavailable_after_drop(): void
    {
        $this->fixtures();
        self::assertSame(0, $this->execute());
        self::assertSame(0, $this->execute(dropOld: true));
        self::assertSame(1, $this->execute(rollback: true));
        self::assertFalse(Schema::hasTable(Cleanup::OLD));
    }

    public function test_simulated_copy_count_failure_never_renames(): void
    {
        $this->fixtures();
        self::assertSame(1, $this->execute(simulate: 'copy-count'));
        self::assertTrue(Schema::hasTable(Cleanup::LIVE));
        self::assertTrue(Schema::hasTable(Cleanup::COMPACT));
        self::assertFalse(Schema::hasTable(Cleanup::OLD));
        self::assertDatabaseHas(Cleanup::LIVE, ['operation_type' => 'taxonomy_sync', 'status' => 'completed']);
    }

    public function test_simulated_fingerprint_failure_never_renames(): void
    {
        $this->fixtures();
        self::assertSame(1, $this->execute(simulate: 'fingerprint'));
        self::assertTrue(Schema::hasTable(Cleanup::LIVE));
        self::assertTrue(Schema::hasTable(Cleanup::COMPACT));
        self::assertFalse(Schema::hasTable(Cleanup::OLD));
        self::assertDatabaseHas(Cleanup::LIVE, ['operation_type' => 'taxonomy_sync', 'status' => 'completed']);
    }

    public function test_failed_attributes_verification_stops_before_operations_stage(): void
    {
        $this->fixtures();
        self::assertSame(1, $this->execute(simulate: 'attributes'));
        self::assertFalse(Schema::hasTable(Cleanup::COMPACT));
        self::assertFalse(Schema::hasTable(Cleanup::OLD));
        self::assertDatabaseHas(Cleanup::LIVE, ['operation_type' => 'taxonomy_sync', 'status' => 'completed']);
    }

    private function execute(bool $allowStale = true, bool $dropOld = false, bool $rollback = false, ?string $simulate = null): int
    {
        return Artisan::call('ozon:emergency-storage-cleanup', array_filter([
            '--execute' => true,
            '--confirm' => Cleanup::CONFIRMATION,
            '--maintenance-confirmed' => true,
            '--allow-stale-taxonomy-running' => $allowStale,
            '--drop-old' => $dropOld,
            '--rollback' => $rollback,
            '--simulate-failure' => $simulate,
        ], fn (mixed $value): bool => $value !== false && $value !== null));
    }

    private function fixtures(): array
    {
        $account = OzonAccount::factory()->create();
        $node = OzonTaxonomyNode::query()->create([
            'ozon_account_id' => $account->id,
            'description_category_id' => '10',
            'category_name' => 'Category',
            'type_id' => '20',
            'type_name' => 'Type',
            'synced_at' => now(),
        ]);
        OzonTaxonomyAttribute::query()->create([
            'ozon_taxonomy_node_id' => $node->id,
            'attribute_id' => '30',
            'name' => 'Annotation',
            'values_payload' => [['id' => 1, 'value' => str_repeat('x', 1000)]],
            'raw_payload' => ['large' => str_repeat('y', 1000)],
            'synced_at' => now(),
        ]);

        $definitions = [
            'taxonomy_completed' => ['taxonomy_sync', 'completed'],
            'taxonomy_failed' => ['taxonomy_sync', 'failed'],
            'taxonomy_running' => ['taxonomy_sync', 'running'],
            'product_export' => ['product_export', 'completed'],
            'status_check' => ['status_check', 'completed'],
            'warehouse_sync' => ['warehouse_sync', 'completed'],
            'connection_check' => ['connection_check', 'completed'],
            'unknown' => ['future_non_taxonomy', 'completed'],
        ];
        $ids = [];
        foreach ($definitions as $key => [$type, $status]) {
            $ids[$key] = DB::table('ozon_operations')->insertGetId([
                'ozon_account_id' => $account->id,
                'operation_key' => 'emergency-'.$key,
                'operation_type' => $type,
                'status' => $status,
                'attempt' => 1,
                'created_at' => now()->subHours(3),
                'updated_at' => now()->subHours(3),
            ]);
        }
        return $ids;
    }

    private function automationRun(AutomationRunStatus $status, $heartbeat): AutomationRun
    {
        return AutomationRun::query()->create([
            'type' => AutomationType::OzonTaxonomySync->value,
            'source' => 'system',
            'status' => $status->value,
            'requested_at' => now()->subHours(3),
            'started_at' => now()->subHours(3),
            'heartbeat_at' => $heartbeat,
            'lock_key' => 'automation:'.AutomationType::OzonTaxonomySync->value,
        ]);
    }

    private function counts(): array
    {
        return [
            'nodes' => DB::table('ozon_taxonomy_nodes')->count(),
            'attributes' => DB::table('ozon_taxonomy_attributes')->count(),
            'operations' => DB::table('ozon_operations')->count(),
        ];
    }

    private function normalizedIndexes(string $table): array
    {
        return collect(Schema::getIndexes($table))->map(fn (array $index): array => [
            'columns' => $index['columns'],
            'unique' => $index['unique'],
            'primary' => $index['primary'],
        ])->sortBy(fn (array $index): string => json_encode($index))->values()->all();
    }
}
