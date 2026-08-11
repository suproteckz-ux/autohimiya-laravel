<?php

namespace Tests\Feature\Ozon;

use App\Console\Commands\OzonEmergencyStorageCleanupCommand as Cleanup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OzonEmergencyStorageCleanupMariaDbTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $database = getenv('OZON_REHEARSAL_DB') ?: '';
        if ($database === '') {
            $this->markTestSkipped('Set OZON_REHEARSAL_DB to run the destructive MySQL/MariaDB rehearsal.');
        }

        if ($database !== 'autohimiya_cleanup_rehearsal') {
            self::fail('The integration test only permits the isolated autohimiya_cleanup_rehearsal database.');
        }

        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.ozon_cleanup_rehearsal' => [
            'driver' => 'mysql',
            'host' => getenv('OZON_REHEARSAL_HOST') ?: '127.0.0.1',
            'port' => getenv('OZON_REHEARSAL_PORT') ?: '3306',
            'database' => $database,
            'username' => getenv('OZON_REHEARSAL_USERNAME') ?: 'root',
            'password' => getenv('OZON_REHEARSAL_PASSWORD') ?: '',
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
        ]]);
        DB::purge('ozon_cleanup_rehearsal');
        DB::setDefaultConnection('ozon_cleanup_rehearsal');

        self::assertSame('autohimiya_cleanup_rehearsal', DB::getDatabaseName());
        $this->rebuildSchemaAndFixtures();
    }

    protected function tearDown(): void
    {
        if (isset($this->originalConnection)) {
            $this->dropFixtureTables();
            DB::disconnect('ozon_cleanup_rehearsal');
            DB::setDefaultConnection($this->originalConnection);
        }

        parent::tearDown();
    }

    public function test_destructive_cleanup_lifecycle_on_mysql_or_mariadb(): void
    {
        self::assertContains(DB::getDriverName(), ['mysql', 'mariadb']);
        self::assertNotSame('', (string) DB::selectOne('SELECT VERSION() AS version')->version);

        $before = $this->snapshot();
        self::assertSame(0, Artisan::call('ozon:emergency-storage-cleanup'));
        self::assertSame($before, $this->snapshot());
        self::assertFalse(Schema::hasTable(Cleanup::COMPACT));
        self::assertFalse(Schema::hasTable(Cleanup::OLD));

        DB::statement('CREATE TABLE `ozon_operations_like_probe` LIKE `ozon_operations`');
        self::assertSame([], $this->foreignKeys('ozon_operations_like_probe'), 'CREATE TABLE LIKE must not be assumed to copy foreign keys.');
        Schema::drop('ozon_operations_like_probe');
        $ddlTimings = $this->benchmarkDdl();
        $physicalBefore = $this->tableSizes();

        self::assertSame(1, $this->execute(), 'A pending taxonomy operation must block destructive execution.');
        DB::table('ozon_operations')->where('operation_key', 'taxonomy-pending')->update(['status' => 'failed']);

        $originalIds = DB::table(Cleanup::LIVE)->orderBy('id')->pluck('id')->all();
        $preservedIds = DB::table(Cleanup::LIVE)
            ->where(fn ($query) => $query->where('operation_type', '!=', 'taxonomy_sync')->orWhere('status', '!=', 'completed'))
            ->orderBy('id')->pluck('id')->all();
        $nodes = DB::table('ozon_taxonomy_nodes')->count();
        $products = DB::table('ozon_products')->count();
        $attributesSchema = $this->schemaFingerprint('ozon_taxonomy_attributes');

        $stageOneStarted = hrtime(true);
        self::assertSame(0, $this->execute());
        $stageOneMs = round((hrtime(true) - $stageOneStarted) / 1_000_000, 3);
        self::assertSame(0, DB::table('ozon_taxonomy_attributes')->count());
        $resetId = DB::table('ozon_taxonomy_attributes')->insertGetId([
            'ozon_taxonomy_node_id' => DB::table('ozon_taxonomy_nodes')->min('id'),
            'attribute_id' => 'after-truncate',
            'name' => 'After truncate',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        self::assertSame(1, $resetId, 'The first insert after TRUNCATE must restart AUTO_INCREMENT at 1.');
        DB::statement('TRUNCATE TABLE `ozon_taxonomy_attributes`');
        self::assertSame($attributesSchema, $this->schemaFingerprint('ozon_taxonomy_attributes'));
        self::assertSame($nodes, DB::table('ozon_taxonomy_nodes')->count());
        self::assertSame($products, DB::table('ozon_products')->count());
        self::assertSame($preservedIds, DB::table(Cleanup::LIVE)->orderBy('id')->pluck('id')->all());
        self::assertSame($originalIds, DB::table(Cleanup::OLD)->orderBy('id')->pluck('id')->all());
        self::assertFalse(DB::table(Cleanup::LIVE)->where('operation_type', 'taxonomy_sync')->where('status', 'completed')->exists());
        self::assertTrue(DB::table(Cleanup::LIVE)->where('operation_type', 'future_operation')->exists());
        self::assertSame($this->schemaFingerprint(Cleanup::OLD), $this->schemaFingerprint(Cleanup::LIVE));
        self::assertCount(3, $this->foreignKeys(Cleanup::LIVE));
        self::assertGreaterThan((int) DB::table(Cleanup::LIVE)->max('id'), $this->autoIncrement(Cleanup::LIVE));
        $physicalAfterStageOne = $this->tableSizes();

        $stageOneSnapshot = $this->snapshot();
        self::assertSame(0, $this->execute());
        self::assertSame($stageOneSnapshot, $this->snapshot());

        self::assertSame(0, $this->execute(rollback: true));
        self::assertSame($originalIds, DB::table(Cleanup::LIVE)->orderBy('id')->pluck('id')->all());
        self::assertTrue(Schema::hasTable(Cleanup::ROLLBACK_COPY));
        self::assertFalse(Schema::hasTable(Cleanup::OLD));
        self::assertSame($this->schemaFingerprint(Cleanup::LIVE), $this->schemaFingerprint(Cleanup::ROLLBACK_COPY));

        $this->rebuildSchemaAndFixtures();
        DB::table('ozon_operations')->where('operation_key', 'taxonomy-pending')->update(['status' => 'failed']);
        self::assertSame(1, $this->execute(simulate: 'copy-count'));
        self::assertTrue(Schema::hasTable(Cleanup::COMPACT));
        self::assertFalse(Schema::hasTable(Cleanup::OLD));
        self::assertSame(0, $this->execute(), 'A verified live + compact state must resume at validation/rename.');
        self::assertTrue(Schema::hasTable(Cleanup::OLD));
        self::assertFalse(Schema::hasTable(Cleanup::COMPACT));

        Schema::create(Cleanup::COMPACT, fn (Blueprint $table) => $table->id());
        $ambiguous = $this->snapshot();
        self::assertSame(1, $this->execute());
        self::assertSame($ambiguous, $this->snapshot());
        Schema::drop(Cleanup::COMPACT);

        $stageTwoStarted = hrtime(true);
        self::assertSame(0, $this->execute(dropOld: true));
        $stageTwoMs = round((hrtime(true) - $stageTwoStarted) / 1_000_000, 3);
        self::assertFalse(Schema::hasTable(Cleanup::OLD));
        self::assertFalse(DB::table(Cleanup::LIVE)->where('operation_type', 'taxonomy_sync')->where('status', 'completed')->exists());
        self::assertTrue(DB::table(Cleanup::LIVE)->where('operation_type', 'future_operation')->exists());
        self::assertCount(3, $this->foreignKeys(Cleanup::LIVE));
        self::assertSame(0, $this->execute(dropOld: true));
        self::assertSame(1, $this->execute(rollback: true));

        fwrite(STDOUT, PHP_EOL.'OZON_REHEARSAL_METRICS='.json_encode([
            'ddl_ms' => $ddlTimings,
            'stage_one_ms' => $stageOneMs,
            'stage_two_ms' => $stageTwoMs,
            'physical_before' => $physicalBefore,
            'physical_after_stage_one' => $physicalAfterStageOne,
            'physical_after_stage_two' => $this->tableSizes(),
        ], JSON_THROW_ON_ERROR).PHP_EOL);
    }

    private function execute(bool $dropOld = false, bool $rollback = false, ?string $simulate = null): int
    {
        return Artisan::call('ozon:emergency-storage-cleanup', array_filter([
            '--execute' => true,
            '--confirm' => Cleanup::CONFIRMATION,
            '--maintenance-confirmed' => true,
            '--allow-stale-taxonomy-running' => true,
            '--drop-old' => $dropOld,
            '--rollback' => $rollback,
            '--simulate-failure' => $simulate,
        ], fn (mixed $value): bool => $value !== false && $value !== null));
    }

    private function rebuildSchemaAndFixtures(): void
    {
        $this->dropFixtureTables();

        Schema::create('ozon_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('automation_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->index();
            $table->string('status')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
        Schema::create('ozon_products', function (Blueprint $table): void {
            $table->id();
            $table->string('offer_id')->unique();
            $table->timestamps();
        });
        Schema::create('ozon_taxonomy_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ozon_account_id');
            $table->string('description_category_id');
            $table->string('category_name');
            $table->string('type_id');
            $table->string('type_name');
            $table->timestamps();
            $table->foreign('ozon_account_id', 'rehearsal_tax_node_account_fk')->references('id')->on('ozon_accounts')->cascadeOnDelete();
        });
        Schema::create('ozon_taxonomy_attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ozon_taxonomy_node_id');
            $table->string('attribute_id');
            $table->string('name');
            $table->json('values_payload')->nullable();
            $table->longText('raw_payload')->nullable();
            $table->timestamps();
            $table->foreign('ozon_taxonomy_node_id', 'rehearsal_tax_attr_node_fk')->references('id')->on('ozon_taxonomy_nodes')->cascadeOnDelete();
            $table->unique(['ozon_taxonomy_node_id', 'attribute_id'], 'rehearsal_tax_attr_uq');
        });
        Schema::create(Cleanup::LIVE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ozon_account_id');
            $table->foreignId('ozon_product_id')->nullable();
            $table->foreignId('automation_run_id')->nullable();
            $table->string('operation_key')->unique();
            $table->string('operation_type')->index();
            $table->string('status')->index();
            $table->string('endpoint')->nullable();
            $table->string('http_method', 8)->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('request_id')->nullable()->index();
            $table->unsignedInteger('attempt')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->string('error_code', 64)->nullable()->index();
            $table->timestamps();
            $table->foreign('ozon_account_id', 'rehearsal_ops_account_fk')->references('id')->on('ozon_accounts')->restrictOnDelete();
            $table->foreign('ozon_product_id', 'rehearsal_ops_product_fk')->references('id')->on('ozon_products')->cascadeOnDelete();
            $table->foreign('automation_run_id', 'rehearsal_ops_run_fk')->references('id')->on('automation_runs')->nullOnDelete();
            $table->index(['operation_type', 'status', 'created_at'], 'rehearsal_ops_type_status_created_idx');
            $table->index(['ozon_account_id', 'created_at'], 'rehearsal_ops_account_created_idx');
        });

        $accountId = DB::table('ozon_accounts')->insertGetId(['name' => 'Rehearsal', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ozon_products')->insert(['offer_id' => 'fixture-product', 'created_at' => now(), 'updated_at' => now()]);
        foreach (range(1, 3) as $number) {
            $nodeId = DB::table('ozon_taxonomy_nodes')->insertGetId([
                'ozon_account_id' => $accountId,
                'description_category_id' => '17028'.$number,
                'category_name' => 'Category '.$number,
                'type_id' => '9225'.$number,
                'type_name' => 'Type '.$number,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('ozon_taxonomy_attributes')->insert([
                'ozon_taxonomy_node_id' => $nodeId,
                'attribute_id' => '419'.$number,
                'name' => 'Annotation '.$number,
                'values_payload' => json_encode([['id' => $number, 'value' => str_repeat('v', 4096)]]),
                'raw_payload' => json_encode(['payload' => str_repeat('x', 256 * 1024)]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $definitions = [
            ['taxonomy-completed-1', 'taxonomy_sync', 'completed'],
            ['taxonomy-completed-2', 'taxonomy_sync', 'completed'],
            ['taxonomy-completed-3', 'taxonomy_sync', 'completed'],
            ['taxonomy-completed-4', 'taxonomy_sync', 'completed'],
            ['taxonomy-failed', 'taxonomy_sync', 'failed'],
            ['taxonomy-running', 'taxonomy_sync', 'running'],
            ['taxonomy-pending', 'taxonomy_sync', 'pending'],
            ['product-export', 'product_export', 'completed'],
            ['status-check', 'status_check', 'completed'],
            ['warehouse-sync', 'warehouse_sync', 'completed'],
            ['connection-check', 'connection_check', 'completed'],
            ['future-operation', 'future_operation', 'completed'],
        ];
        foreach ($definitions as [$key, $type, $status]) {
            DB::table(Cleanup::LIVE)->insert([
                'ozon_account_id' => $accountId,
                'operation_key' => $key,
                'operation_type' => $type,
                'status' => $status,
                'request_payload' => json_encode(['fixture' => str_repeat($key, 128)]),
                'attempt' => 1,
                'created_at' => now()->subHours(3),
                'updated_at' => now()->subHours(3),
            ]);
        }
    }

    private function dropFixtureTables(): void
    {
        if (! isset($this->originalConnection) || DB::getDatabaseName() !== 'autohimiya_cleanup_rehearsal') {
            return;
        }

        Schema::disableForeignKeyConstraints();
        foreach ([Cleanup::COMPACT, Cleanup::OLD, Cleanup::ROLLBACK_COPY, 'ozon_operations_like_probe', 'rehearsal_attr_bench', 'rehearsal_ops_source', 'rehearsal_ops_compact', 'rehearsal_ops_old', Cleanup::LIVE, 'ozon_taxonomy_attributes', 'ozon_taxonomy_nodes', 'ozon_products', 'automation_runs', 'ozon_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }

    private function snapshot(): array
    {
        $tables = collect(DB::select('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [DB::getDatabaseName()]))
            ->pluck('TABLE_NAME')->all();
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        return ['tables' => $tables, 'counts' => $counts, 'schemas' => collect($tables)->mapWithKeys(fn (string $table) => [$table => $this->schemaFingerprint($table)])->all()];
    }

    private function schemaFingerprint(string $table): array
    {
        $indexes = collect(DB::select('SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [DB::getDatabaseName(), $table]))
            ->groupBy('INDEX_NAME')
            ->map(fn ($parts): array => [
                'columns' => $parts->pluck('COLUMN_NAME')->all(),
                'unique' => ! (bool) $parts->first()->NON_UNIQUE,
                'primary' => $parts->first()->INDEX_NAME === 'PRIMARY',
                'type' => $parts->first()->INDEX_TYPE,
            ])->sortBy(fn (array $index): string => json_encode($index))->values()->all();
        $fingerprint = [
            'columns' => DB::select('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [DB::getDatabaseName(), $table]),
            'indexes' => $indexes,
            'foreign_keys' => $this->foreignKeys($table),
            'table' => DB::selectOne('SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [DB::getDatabaseName(), $table]),
        ];

        return json_decode((string) json_encode($fingerprint), true, flags: JSON_THROW_ON_ERROR);
    }

    private function foreignKeys(string $table): array
    {
        return DB::select('SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r
              ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
            WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY k.COLUMN_NAME', [DB::getDatabaseName(), $table]);
    }

    private function autoIncrement(string $table): int
    {
        return (int) DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->value('AUTO_INCREMENT');
    }

    private function benchmarkDdl(): array
    {
        DB::statement('CREATE TABLE `rehearsal_attr_bench` LIKE `ozon_taxonomy_attributes`');
        DB::statement('INSERT INTO `rehearsal_attr_bench` SELECT * FROM `ozon_taxonomy_attributes`');
        $truncateMs = $this->timeStatement('TRUNCATE TABLE `rehearsal_attr_bench`');
        Schema::drop('rehearsal_attr_bench');

        DB::statement('CREATE TABLE `rehearsal_ops_source` LIKE `ozon_operations`');
        DB::statement('INSERT INTO `rehearsal_ops_source` SELECT * FROM `ozon_operations`');
        $createLikeMs = $this->timeStatement('CREATE TABLE `rehearsal_ops_compact` LIKE `rehearsal_ops_source`');
        $copyMs = $this->timeStatement("INSERT INTO `rehearsal_ops_compact` SELECT * FROM `rehearsal_ops_source` WHERE NOT (`operation_type` = 'taxonomy_sync' AND `status` = 'completed')");
        $renameMs = $this->timeStatement('RENAME TABLE `rehearsal_ops_source` TO `rehearsal_ops_old`, `rehearsal_ops_compact` TO `rehearsal_ops_source`');
        $dropMs = $this->timeStatement('DROP TABLE `rehearsal_ops_old`');
        Schema::drop('rehearsal_ops_source');

        return compact('truncateMs', 'createLikeMs', 'copyMs', 'renameMs', 'dropMs');
    }

    private function timeStatement(string $sql): float
    {
        $started = hrtime(true);
        DB::statement($sql);
        return round((hrtime(true) - $started) / 1_000_000, 3);
    }

    private function tableSizes(): array
    {
        return collect(DB::select('SELECT TABLE_NAME, DATA_LENGTH, INDEX_LENGTH, DATA_FREE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (?, ?, ?, ?) ORDER BY TABLE_NAME', [
            DB::getDatabaseName(), Cleanup::LIVE, Cleanup::OLD, 'ozon_taxonomy_attributes', 'ozon_taxonomy_nodes',
        ]))->mapWithKeys(fn (object $row): array => [$row->TABLE_NAME => [
            'data_bytes' => (int) $row->DATA_LENGTH,
            'index_bytes' => (int) $row->INDEX_LENGTH,
            'data_free_bytes' => (int) $row->DATA_FREE,
        ]])->all();
    }
}
