<?php

namespace Tests\Feature;

use App\Models\AutomationRun;
use App\Models\OzonAccount;
use App\Models\OzonOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseStorageAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_runs_formats_sections_and_does_not_change_database(): void
    {
        $account = OzonAccount::query()->create([
            'name' => 'Audit fixture',
            'client_id' => 'client',
            'api_key' => 'secret',
            'is_active' => true,
        ]);
        OzonOperation::query()->create([
            'ozon_account_id' => $account->id,
            'operation_key' => 'audit-fixture',
            'operation_type' => 'connection_check',
            'status' => 'completed',
            'request_payload' => ['safe' => true],
            'response_payload' => ['result' => 'ok'],
        ]);

        $before = $this->tableCounts();
        $exitCode = Artisan::call('db:storage-audit');
        $output = Artisan::output();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('READ-ONLY DATABASE STORAGE AUDIT', $output);
        self::assertStringContainsString('A. Table sizes', $output);
        self::assertStringContainsString('ozon_operations', $output);
        self::assertStringContainsString('Audit completed. No database data or schema was changed.', $output);
        self::assertSame($before, $this->tableCounts());
    }

    public function test_optional_information_schema_metadata_is_graceful_on_sqlite(): void
    {
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(0, Artisan::call('db:storage-audit'));
        self::assertStringContainsString('tablespace metadata unavailable for this database driver', Artisan::output());
    }

    public function test_default_mode_never_runs_deep_payload_scans(): void
    {
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = strtolower($event->sql);
        });

        self::assertSame(0, Artisan::call('db:storage-audit'));

        $sql = implode("\n", $queries);
        self::assertStringNotContainsString('sum(length(', $sql);
        self::assertStringNotContainsString('avg(length(', $sql);
        self::assertStringNotContainsString('max(length(', $sql);
        self::assertStringNotContainsString('md5(', $sql);
        self::assertStringContainsString('skipped in lightweight mode', Artisan::output());
    }

    private function tableCounts(): array
    {
        return collect([
            'ozon_operations', 'ozon_taxonomy_attributes', 'ozon_taxonomy_nodes',
            'kaspi_enrichment_tasks', 'kaspi_import_receipts', 'kaspi_production_pushes',
            'kaspi_publish_logs', 'kaspi_sync_logs', 'sync_logs', 'automation_runs',
            'content_change_logs', 'jobs', 'failed_jobs',
        ])->filter(fn (string $table): bool => Schema::hasTable($table))
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
            ->all();
    }
}
