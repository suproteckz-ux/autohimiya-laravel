<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->index('sync_logs', ['source'], 'sync_logs_source_perf_idx');
        $this->index('sync_logs', ['status'], 'sync_logs_status_perf_idx');
        $this->index('sync_logs', ['started_at'], 'sync_logs_started_at_perf_idx');
        $this->index('sync_logs', ['command'], 'sync_logs_command_perf_idx');

        $this->index('products', ['sku'], 'products_sku_perf_idx');
        $this->index('products', ['category_id'], 'products_category_id_perf_idx');
        $this->index('products', ['kaspi_product_url'], 'products_kaspi_product_url_perf_idx');
        $this->index('products', ['updated_at'], 'products_updated_at_perf_idx');
        $this->index('products', ['content_verified_at'], 'products_content_verified_at_perf_idx');
        $this->index('products', ['auto_content_locked'], 'products_auto_content_locked_perf_idx');
    }

    public function down(): void
    {
        foreach ([
            ['sync_logs', 'sync_logs_source_perf_idx'],
            ['sync_logs', 'sync_logs_status_perf_idx'],
            ['sync_logs', 'sync_logs_started_at_perf_idx'],
            ['sync_logs', 'sync_logs_command_perf_idx'],
            ['products', 'products_sku_perf_idx'],
            ['products', 'products_category_id_perf_idx'],
            ['products', 'products_kaspi_product_url_perf_idx'],
            ['products', 'products_updated_at_perf_idx'],
            ['products', 'products_content_verified_at_perf_idx'],
            ['products', 'products_auto_content_locked_perf_idx'],
        ] as [$table, $index]) {
            if ($this->indexExists($table, $index)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index));
            }
        }
    }

    /**
     * @param array<int, string> $columns
     */
    private function index(string $table, array $columns, string $name): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $name) || $this->indexForColumnsExists($table, $columns)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
    }

    private function indexExists(string $table, string $name): bool
    {
                if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

$database = DB::getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $name)
            ->exists();
    }

    /**
     * @param array<int, string> $columns
     */
    private function indexForColumnsExists(string $table, array $columns): bool
    {
                if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

$database = DB::getDatabaseName();
        $indexes = DB::table('information_schema.statistics')
            ->select(['index_name', 'column_name', 'seq_in_index'])
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->orderBy('index_name')
            ->orderBy('seq_in_index')
            ->get()
            ->groupBy('index_name');

        foreach ($indexes as $indexColumns) {
            $existing = $indexColumns->pluck('column_name')->values()->all();

            if ($existing === array_values($columns)) {
                return true;
            }
        }

        return false;
    }
};
