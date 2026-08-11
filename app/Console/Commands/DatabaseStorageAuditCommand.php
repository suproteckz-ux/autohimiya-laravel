<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DatabaseStorageAuditCommand extends Command
{
    protected $signature = 'db:storage-audit';

    protected $description = 'Read-only database storage, growth, payload, duplicate, and tablespace audit';

    private const AUDIT_TABLES = [
        'ozon_operations', 'ozon_taxonomy_attributes', 'ozon_taxonomy_nodes',
        'kaspi_enrichment_tasks', 'kaspi_import_receipts', 'kaspi_production_pushes',
        'kaspi_publish_logs', 'kaspi_sync_logs', 'sync_logs', 'automation_runs',
        'content_change_logs', 'jobs', 'failed_jobs',
    ];

    public function handle(): int
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();

        $this->components->info('READ-ONLY DATABASE STORAGE AUDIT');
        $this->line('Connection: '.$connection->getName());
        $this->line('Database: '.$database);

        $sizes = $this->tableSizes($connection, $database);
        $this->section('A. Table sizes');
        $this->table(['table', 'rows', 'data_mb', 'index_mb', 'total_mb', 'data_free_mb'], $sizes);
        $this->totals($sizes);

        $this->section('B. Row growth');
        $this->table(
            ['table', 'count', 'min_created_at', 'max_created_at', 'last_hour', '24_hours', '7_days', '30_days'],
            $this->growthRows($connection),
        );

        $this->section('C. JSON/TEXT/LONGTEXT usage');
        $this->table(['table', 'column', 'avg_bytes', 'max_bytes', 'total_mb'], $this->largeColumnStats($connection, $database));

        $this->section('D. Ozon taxonomy duplicates');
        $this->taxonomyDuplicates($connection);

        $this->section('E. AUTO_INCREMENT versus MAX(id)');
        $this->table(['table', 'auto_increment', 'max_id'], $this->autoIncrementRows($connection, $database));

        $this->section('F. Tablespace metadata');
        $this->tablespaceMetadata($connection, $database);

        $this->section('G. ozon_operations distribution');
        $this->ozonOperationStats($connection);

        $this->newLine();
        $this->components->info('Audit completed. No database data or schema was changed.');

        return self::SUCCESS;
    }

    private function tableSizes(ConnectionInterface $connection, string $database): array
    {
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return collect(self::AUDIT_TABLES)->filter(fn (string $table) => Schema::hasTable($table))
                ->map(fn (string $table): array => [$table, DB::table($table)->count(), 'n/a', 'n/a', 'n/a', 'n/a'])->all();
        }

        return collect($connection->select(
            'SELECT TABLE_NAME AS table_name, TABLE_ROWS AS table_rows, DATA_LENGTH, INDEX_LENGTH, DATA_FREE
             FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC',
            [$database],
        ))->map(fn (object $row): array => [
            $row->table_name,
            (int) $row->table_rows,
            $this->mb($row->DATA_LENGTH),
            $this->mb($row->INDEX_LENGTH),
            $this->mb($row->DATA_LENGTH + $row->INDEX_LENGTH),
            $this->mb($row->DATA_FREE),
        ])->all();
    }

    private function totals(array $sizes): void
    {
        $numeric = collect($sizes)->filter(fn (array $row): bool => is_numeric($row[4]));
        if ($numeric->isEmpty()) {
            $this->line('Logical and DATA_FREE totals are unavailable for this database driver.');
            return;
        }

        $this->line('Total logical MB: '.number_format((float) $numeric->sum(fn (array $row) => $row[4]), 2, '.', ''));
        $this->line('Total DATA_FREE MB: '.number_format((float) $numeric->sum(fn (array $row) => $row[5]), 2, '.', ''));
        $this->line('Largest DATA_FREE tables:');
        $this->table(['table', 'data_free_mb'], $numeric->sortByDesc(fn (array $row) => $row[5])->take(10)->map(fn (array $row) => [$row[0], $row[5]])->values()->all());
    }

    private function growthRows(ConnectionInterface $connection): array
    {
        return collect(self::AUDIT_TABLES)->filter(fn (string $table) => Schema::hasTable($table))->map(function (string $table) use ($connection): array {
            $count = (int) DB::table($table)->count();
            if (! Schema::hasColumn($table, 'created_at')) {
                return [$table, $count, 'n/a', 'n/a', 'n/a', 'n/a', 'n/a', 'n/a'];
            }

            $quoted = $connection->getQueryGrammar()->wrap('created_at');
            $aggregate = DB::table($table)->selectRaw("MIN({$quoted}) AS min_created, MAX({$quoted}) AS max_created")->first();

            return [
                $table, $count, $aggregate?->min_created ?? '—', $aggregate?->max_created ?? '—',
                DB::table($table)->where('created_at', '>=', now()->subHour())->count(),
                DB::table($table)->where('created_at', '>=', now()->subDay())->count(),
                DB::table($table)->where('created_at', '>=', now()->subDays(7))->count(),
                DB::table($table)->where('created_at', '>=', now()->subDays(30))->count(),
            ];
        })->all();
    }

    private function largeColumnStats(ConnectionInterface $connection, string $database): array
    {
        $columns = [];
        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $columns = $connection->select(
                "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND DATA_TYPE IN ('json','text','mediumtext','longtext','blob','mediumblob','longblob')",
                [$database],
            );
        } else {
            foreach (self::AUDIT_TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                foreach (Schema::getColumns($table) as $column) {
                    if (preg_match('/text|json|blob/i', (string) ($column['type_name'] ?? $column['type'] ?? ''))) {
                        $columns[] = (object) ['TABLE_NAME' => $table, 'COLUMN_NAME' => $column['name']];
                    }
                }
            }
        }

        return collect($columns)->filter(fn (object $column) => in_array($column->TABLE_NAME, self::AUDIT_TABLES, true))
            ->map(function (object $column) use ($connection): array {
                $table = $connection->getQueryGrammar()->wrap($column->TABLE_NAME);
                $name = $connection->getQueryGrammar()->wrap($column->COLUMN_NAME);
                $length = $connection->getDriverName() === 'sqlite' ? "LENGTH(CAST({$name} AS BLOB))" : "LENGTH({$name})";
                $stats = $connection->selectOne("SELECT AVG({$length}) avg_bytes, MAX({$length}) max_bytes, SUM({$length}) total_bytes FROM {$table}");

                return [$column->TABLE_NAME, $column->COLUMN_NAME, round((float) ($stats->avg_bytes ?? 0), 2), (int) ($stats->max_bytes ?? 0), $this->mb($stats->total_bytes ?? 0)];
            })->sortByDesc(fn (array $row) => $row[4])->values()->all();
    }

    private function taxonomyDuplicates(ConnectionInterface $connection): void
    {
        $definitions = [
            'ozon_taxonomy_nodes' => ['ozon_account_id', 'description_category_id', 'type_id'],
            'ozon_taxonomy_attributes' => ['ozon_taxonomy_node_id', 'attribute_id'],
        ];

        foreach ($definitions as $table => $keys) {
            if (! Schema::hasTable($table)) {
                $this->line("{$table}: table missing");
                continue;
            }
            $query = DB::table($table);
            $total = (int) (clone $query)->count();
            $distinct = (int) DB::query()->fromSub((clone $query)->select($keys)->distinct(), 'business_keys')->count();
            $duplicates = max(0, $total - $distinct);
            $this->line("{$table}: rows={$total}; distinct business keys={$distinct}; duplicate rows={$duplicates}");
            $this->table([...$keys, 'occurrences'], DB::table($table)->select($keys)->selectRaw('COUNT(*) AS occurrences')
                ->groupBy($keys)->havingRaw('COUNT(*) > 1')->orderByDesc('occurrences')->limit(20)->get()
                ->map(fn (object $row) => collect($keys)->map(fn (string $key) => $row->{$key})->push($row->occurrences)->all())->all());
        }
    }

    private function autoIncrementRows(ConnectionInterface $connection, string $database): array
    {
        $auto = collect();
        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $auto = collect($connection->select('SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$database]))
                ->keyBy('TABLE_NAME');
        }

        return collect(self::AUDIT_TABLES)->filter(fn (string $table) => Schema::hasTable($table))->map(function (string $table) use ($auto): array {
            $max = Schema::hasColumn($table, 'id') ? DB::table($table)->max('id') : null;
            return [$table, $auto->get($table)?->AUTO_INCREMENT ?? 'unavailable', $max ?? 'n/a'];
        })->all();
    }

    private function tablespaceMetadata(ConnectionInterface $connection, string $database): void
    {
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->line('tablespace metadata unavailable for this database driver');
            return;
        }

        foreach (['INNODB_SYS_TABLESPACES', 'INNODB_TABLESPACES'] as $view) {
            try {
                $columns = collect($connection->select("SHOW COLUMNS FROM information_schema.{$view}"))->pluck('Field');
                $name = $columns->first(fn (string $column) => in_array(strtoupper($column), ['NAME', 'TABLESPACE_NAME'], true));
                if (! $name) {
                    continue;
                }
                $rows = $connection->select("SELECT * FROM information_schema.{$view} WHERE {$name} LIKE ? LIMIT 100", [$database.'/%']);
                $this->line("Source: information_schema.{$view}");
                $this->table($columns->all(), collect($rows)->map(fn (object $row) => (array) $row)->all());
                return;
            } catch (Throwable) {
                // Try the next MariaDB/MySQL-compatible metadata view.
            }
        }

        $this->line('tablespace metadata unavailable due to database permissions');
    }

    private function ozonOperationStats(ConnectionInterface $connection): void
    {
        if (! Schema::hasTable('ozon_operations')) {
            $this->line('ozon_operations: table missing');
            return;
        }

        $this->table(['operation_type', 'status', 'count'], DB::table('ozon_operations')->select('operation_type', 'status')->selectRaw('COUNT(*) count')
            ->groupBy('operation_type', 'status')->orderByDesc('count')->get()->map(fn (object $row) => (array) $row)->all());

        foreach (['request_payload', 'response_payload', 'error_message'] as $column) {
            if (! Schema::hasColumn('ozon_operations', $column)) {
                continue;
            }
            $table = $connection->getQueryGrammar()->wrap('ozon_operations');
            $name = $connection->getQueryGrammar()->wrap($column);
            $stats = $connection->selectOne("SELECT AVG(LENGTH({$name})) avg_bytes, MAX(LENGTH({$name})) max_bytes, SUM(LENGTH({$name})) total_bytes FROM {$table}");
            $this->line("{$column}: avg=".round((float) ($stats->avg_bytes ?? 0), 2).'; max='.(int) ($stats->max_bytes ?? 0).'; total_mb='.$this->mb($stats->total_bytes ?? 0));
        }

        foreach (['request_payload', 'response_payload'] as $column) {
            if (! Schema::hasColumn('ozon_operations', $column)) {
                continue;
            }
            $hash = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) ? "MD5(COALESCE({$column}, ''))" : "COALESCE({$column}, '')";
            $duplicates = DB::table('ozon_operations')->selectRaw("{$hash} payload_hash, COUNT(*) occurrences")
                ->whereNotNull($column)->groupByRaw($hash)->havingRaw('COUNT(*) > 1')->orderByDesc('occurrences')->limit(20)->get();
            $this->line("Repeated {$column} values (top 20):");
            $this->table(['payload_hash', 'occurrences'], $duplicates->map(fn (object $row) => (array) $row)->all());
        }
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->components->info($title);
    }

    private function mb(int|float|string|null $bytes): string
    {
        return number_format(((float) $bytes) / 1048576, 2, '.', '');
    }
}
