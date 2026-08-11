<?php

namespace App\Console\Commands;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationType;
use App\Enums\OzonOperationStatus;
use App\Enums\OzonOperationType;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OzonEmergencyStorageCleanupCommand extends Command
{
    public const CONFIRMATION = 'REMOVE_OZON_TAXONOMY_CACHE';
    public const LIVE = 'ozon_operations';
    public const COMPACT = 'ozon_operations_compact';
    public const OLD = 'ozon_operations_old';
    public const ROLLBACK_COPY = 'ozon_operations_compact_rollback';
    public const STALE_MINUTES = 120;

    protected $signature = 'ozon:emergency-storage-cleanup
        {--execute : Execute the selected destructive stage}
        {--confirm= : Required confirmation token}
        {--maintenance-confirmed : Confirm Ozon workers and relevant maintenance traffic are stopped}
        {--allow-stale-taxonomy-running : Preserve and allow stale running taxonomy records}
        {--drop-old : Stage 2: validate and drop the old operations rollback table}
        {--rollback : Atomically restore the old operations table before Stage 2}
        {--simulate-failure= : Testing only: attributes, copy-count, or fingerprint}';

    protected $description = 'Guarded two-stage cleanup of emergency Ozon taxonomy storage growth';

    public function handle(): int
    {
        if ($this->option('drop-old') && $this->option('rollback')) {
            return $this->abort('--drop-old and --rollback are mutually exclusive.');
        }

        $state = $this->state();
        $summary = $this->summary($state);
        $this->renderDryRun($state, $summary);

        if (! $this->option('execute')) {
            $this->components->info('NO CHANGES WERE MADE.');

            return self::SUCCESS;
        }

        if ($this->option('confirm') !== self::CONFIRMATION) {
            return $this->abort('Confirmation token is missing or invalid. NO CHANGES WERE MADE.', self::INVALID);
        }

        if (! $this->option('maintenance-confirmed')) {
            return $this->abort('--maintenance-confirmed is required. NO CHANGES WERE MADE.', self::INVALID);
        }

        if (app()->environment('production')) {
            $this->newLine();
            $this->error('!!! PRODUCTION DESTRUCTIVE OPERATION REQUESTED !!!');
            $this->error('Verified backup and maintenance ownership are mandatory.');
            $this->newLine();
        }

        if ($this->option('simulate-failure') && ! app()->environment('testing')) {
            return $this->abort('--simulate-failure is permitted only in the testing environment.');
        }

        $preflight = $this->preflight($state);
        if (! $preflight['ok']) {
            return self::FAILURE;
        }

        if ($this->option('rollback')) {
            return $this->rollback($state);
        }

        if ($this->option('drop-old')) {
            return $this->stageTwo($state);
        }

        return $this->stageOne($state, $summary);
    }

    public static function shouldPreserveOperation(string $type, string $status): bool
    {
        return ! ($type === OzonOperationType::TaxonomySync->value
            && $status === OzonOperationStatus::Completed->value);
    }

    private function preflight(array $state): array
    {
        $required = [self::LIVE, 'ozon_taxonomy_attributes', 'ozon_taxonomy_nodes', 'automation_runs', 'ozon_products'];
        $missing = collect($required)->reject(fn (string $table): bool => Schema::hasTable($table))->values();
        if ($missing->isNotEmpty()) {
            $this->components->error('PRE-FLIGHT ABORT: missing tables: '.$missing->implode(', '));
            return ['ok' => false];
        }

        if (DB::table('ozon_taxonomy_nodes')->count() === 0) {
            $this->components->error('PRE-FLIGHT ABORT: ozon_taxonomy_nodes is empty.');
            return ['ok' => false];
        }

        if ($this->hasIncomingForeignKeys('ozon_taxonomy_attributes')) {
            $this->components->error('PRE-FLIGHT ABORT: unexpected incoming FK to ozon_taxonomy_attributes.');
            return ['ok' => false];
        }

        if ($state['unexpected']) {
            $this->components->error('PRE-FLIGHT ABORT: ambiguous replacement-table state.');
            return ['ok' => false];
        }

        $runs = DB::table('automation_runs')
            ->where('type', AutomationType::OzonTaxonomySync->value)
            ->whereIn('status', AutomationRunStatus::activeValues())
            ->select('id', 'status', 'started_at', 'heartbeat_at', 'updated_at')
            ->orderBy('id')->get();
        $operations = DB::table(self::LIVE)
            ->where('operation_type', OzonOperationType::TaxonomySync->value)
            ->whereIn('status', [OzonOperationStatus::Pending->value, OzonOperationStatus::Running->value])
            ->select('id', 'status', 'created_at', 'updated_at', 'automation_run_id')
            ->orderBy('id')->get();

        $this->line('Active taxonomy operation metadata:');
        $this->table(['id', 'status', 'created_at', 'updated_at', 'automation_run_id'], $operations->map(fn (object $row): array => (array) $row)->all());
        $this->line('Active taxonomy AutomationRun metadata:');
        $this->table(['id', 'status', 'started_at', 'heartbeat_at', 'updated_at'], $runs->map(fn (object $row): array => (array) $row)->all());

        $freshRuns = $runs->filter(fn (object $run): bool => $run->status === AutomationRunStatus::Pending->value
            || ($run->heartbeat_at !== null && now()->parse($run->heartbeat_at)->gte(now()->subMinutes(self::STALE_MINUTES))));
        if ($freshRuns->isNotEmpty()) {
            $this->components->error('PRE-FLIGHT ABORT: a taxonomy automation run is pending or has a fresh heartbeat.');
            return ['ok' => false];
        }

        if ($operations->contains(fn (object $operation): bool => $operation->status === OzonOperationStatus::Pending->value)) {
            $this->components->error('PRE-FLIGHT ABORT: a taxonomy operation is pending.');
            return ['ok' => false];
        }

        $hasStale = $runs->isNotEmpty() || $operations->isNotEmpty();
        if ($hasStale && ! $this->option('allow-stale-taxonomy-running')) {
            $this->components->warn('PRE-FLIGHT ABORT: stale/uncorrelated taxonomy running records require --allow-stale-taxonomy-running.');
            return ['ok' => false];
        }

        $lock = Cache::lock('automation:'.AutomationType::OzonTaxonomySync->value, 5);
        if (! $lock->get()) {
            $this->components->error('PRE-FLIGHT ABORT: existing Ozon taxonomy automation lock is held.');
            return ['ok' => false];
        }
        $lock->release();

        $this->components->info('PRE-FLIGHT VERIFIED.');
        return ['ok' => true];
    }

    private function stageOne(array $state, array $summary): int
    {
        if ($state['stage_one_complete']) {
            $this->components->info('STAGE 1 is already complete. Old operations table remains for rollback.');
            $this->spaceReport();
            return self::SUCCESS;
        }

        if ($state['rollback_copy']) {
            return $this->abort('A rollback compact copy exists. Resolve it manually before another Stage 1.');
        }

        $nodesBefore = DB::table('ozon_taxonomy_nodes')->count();
        $productsBefore = DB::table('ozon_products')->count();
        $attributeSchema = $this->schemaFingerprint('ozon_taxonomy_attributes');

        if (DB::table('ozon_taxonomy_attributes')->count() > 0) {
            $this->truncateAttributes();
        }

        $attributesVerified = DB::table('ozon_taxonomy_attributes')->count() === 0
            && DB::table('ozon_taxonomy_nodes')->count() === $nodesBefore
            && DB::table('ozon_products')->count() === $productsBefore
            && $this->schemaFingerprint('ozon_taxonomy_attributes') === $attributeSchema
            && $this->option('simulate-failure') !== 'attributes';
        if (! $attributesVerified) {
            return $this->abort('ATTRIBUTES VERIFICATION FAILED. Operations stage was not started.');
        }
        $this->components->info('ATTRIBUTES CLEANUP VERIFIED.');

        if (! $state['compact']) {
            $this->createCompactTable();
            $this->copyPreservedOperations();
        }

        $expected = (int) $summary['preserve'];
        if ($this->option('simulate-failure') === 'copy-count'
            || DB::table(self::COMPACT)->count() !== $expected
            || ! $this->breakdownsMatch(self::LIVE, self::COMPACT)) {
            return $this->abort('COMPACT COPY VERIFICATION FAILED. Atomic rename was not executed.');
        }

        if ($this->option('simulate-failure') === 'fingerprint'
            || ! $this->schemaFingerprintsMatch(self::LIVE, self::COMPACT)) {
            return $this->abort('SCHEMA FINGERPRINT VERIFICATION FAILED. Atomic rename was not executed.');
        }

        if (! $this->autoIncrementIsSane(self::COMPACT)) {
            return $this->abort('COMPACT AUTO_INCREMENT VERIFICATION FAILED. Atomic rename was not executed.');
        }

        $this->renameTables([
            self::LIVE => self::OLD,
            self::COMPACT => self::LIVE,
        ]);

        if (DB::table(self::LIVE)->count() !== $expected
            || $this->discardedOperations(self::LIVE)->exists()
            || DB::table('ozon_taxonomy_nodes')->count() !== $nodesBefore
            || DB::table('ozon_products')->count() !== $productsBefore
            || ! $this->schemaFingerprintsMatch(self::OLD, self::LIVE)) {
            return $this->abort('POST-RENAME VERIFICATION FAILED. Do not drop old table; execute guarded rollback.');
        }

        $this->components->info('STAGE 1 VERIFIED. ozon_operations_old retained for rollback.');
        $this->spaceReport();
        return self::SUCCESS;
    }

    private function stageTwo(array $state): int
    {
        if (! $state['old']) {
            if (Schema::hasTable(self::LIVE) && ! $this->discardedOperations(self::LIVE)->exists()) {
                $this->components->info('STAGE 2 is already complete; old table is absent and active table is compact.');
                return self::SUCCESS;
            }
            return $this->abort('STAGE 2 ABORT: ozon_operations_old is absent and active table is not verified compact.');
        }

        if ($state['compact'] || $state['rollback_copy']) {
            return $this->abort('STAGE 2 ABORT: ambiguous temporary-table state.');
        }

        $expected = $this->preservedOperations(self::OLD)->count();
        if ($this->discardedOperations(self::LIVE)->exists()
            || DB::table(self::LIVE)->count() !== $expected
            || ! $this->breakdownsMatch(self::OLD, self::LIVE)
            || ! $this->schemaFingerprintsMatch(self::OLD, self::LIVE)
            || ! $this->autoIncrementIsSane(self::LIVE)) {
            return $this->abort('STAGE 2 VALIDATION FAILED. Old table was not dropped.');
        }

        Schema::drop(self::OLD);
        if (Schema::hasTable(self::OLD)) {
            return $this->abort('STAGE 2 DROP VERIFICATION FAILED.');
        }

        $this->components->info('STAGE 2 VERIFIED. Old operations table was dropped.');
        $this->spaceReport();
        return self::SUCCESS;
    }

    private function rollback(array $state): int
    {
        if (! $state['stage_one_complete'] || $state['compact'] || $state['rollback_copy']) {
            return $this->abort('ROLLBACK ABORT: required active + old table state is unavailable.');
        }

        if (! $this->schemaFingerprintsMatch(self::OLD, self::LIVE)) {
            return $this->abort('ROLLBACK ABORT: schema fingerprints differ.');
        }

        $this->renameTables([
            self::LIVE => self::ROLLBACK_COPY,
            self::OLD => self::LIVE,
        ]);

        if (! Schema::hasTable(self::LIVE) || ! Schema::hasTable(self::ROLLBACK_COPY)
            || ! $this->discardedOperations(self::LIVE)->exists()) {
            return $this->abort('ROLLBACK VERIFICATION FAILED. Inspect table names immediately.');
        }

        $this->components->info('ROLLBACK VERIFIED. Original operations table restored; compact copy retained for analysis.');
        $this->components->warn('Attributes are not restored; they remain a reproducible on-demand cache.');
        return self::SUCCESS;
    }

    private function state(): array
    {
        $live = Schema::hasTable(self::LIVE);
        $compact = Schema::hasTable(self::COMPACT);
        $old = Schema::hasTable(self::OLD);
        $rollback = Schema::hasTable(self::ROLLBACK_COPY);

        return [
            'live' => $live,
            'compact' => $compact,
            'old' => $old,
            'rollback_copy' => $rollback,
            'stage_one_complete' => $live && $old && ! $compact && ! $rollback,
            'interrupted_before_rename' => $live && $compact && ! $old && ! $rollback,
            'unexpected' => ! $live || ($old && $compact) || ($rollback && ($old || $compact)),
        ];
    }

    private function summary(array $state): array
    {
        $source = $state['old'] ? self::OLD : self::LIVE;
        $available = Schema::hasTable($source);
        return [
            'source' => $source,
            'attributes' => Schema::hasTable('ozon_taxonomy_attributes') ? DB::table('ozon_taxonomy_attributes')->count() : 0,
            'nodes' => Schema::hasTable('ozon_taxonomy_nodes') ? DB::table('ozon_taxonomy_nodes')->count() : 0,
            'discard' => $available ? $this->discardedOperations($source)->count() : 0,
            'preserve' => $available ? $this->preservedOperations($source)->count() : 0,
            'running' => $available ? DB::table($source)->where('operation_type', OzonOperationType::TaxonomySync->value)->where('status', OzonOperationStatus::Running->value)->count() : 0,
            'attributes_mb' => $this->tableSizeMb('ozon_taxonomy_attributes'),
            'operations_mb' => $this->tableSizeMb($source),
            'nodes_mb' => $this->tableSizeMb('ozon_taxonomy_nodes'),
            'preserve_groups' => $available ? $this->breakdown($source, true) : collect(),
        ];
    }

    private function renderDryRun(array $state, array $summary): void
    {
        $this->components->info('OZON EMERGENCY STORAGE CLEANUP PLAN');
        $this->table(['table/item', 'rows', 'logical_mb', 'treatment'], [
            ['ozon_taxonomy_attributes', $summary['attributes'], $summary['attributes_mb'], 'TRUNCATE cache in Stage 1'],
            ['completed taxonomy operations', $summary['discard'], $summary['operations_mb'], 'exclude from compact copy'],
            ['operations to preserve', $summary['preserve'], 'included above', 'copy unchanged with IDs'],
            ['running taxonomy operations', $summary['running'], 'included above', 'preserve unchanged'],
            ['ozon_taxonomy_nodes', $summary['nodes'], $summary['nodes_mb'], 'preserve'],
        ]);
        $this->line('Preservation breakdown:');
        $this->table(['operation_type', 'status', 'rows'], $summary['preserve_groups']->map(fn (object $row): array => [$row->operation_type, $row->status, $row->rows_count])->all());
        $this->line('State: '.json_encode($state));
        $this->line('Strategy: Stage 1 TRUNCATE attributes + compact copy + atomic rename; Stage 2 guarded old-table drop.');
        if (is_numeric($summary['attributes_mb']) && is_numeric($summary['operations_mb'])) {
            $this->line('Estimated maximum reclaim after Stage 2: '.number_format(((float) $summary['attributes_mb'] + (float) $summary['operations_mb']) / 1024, 2, '.', '').' GB.');
        }
    }

    private function createCompactTable(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::create(self::COMPACT, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('ozon_account_id')->constrained('ozon_accounts')->restrictOnDelete();
                $table->foreignId('ozon_product_id')->nullable()->constrained('ozon_products')->cascadeOnDelete();
                $table->foreignId('automation_run_id')->nullable()->constrained('automation_runs')->nullOnDelete();
                $table->string('operation_key')->unique();
                $table->string('operation_type')->index();
                $table->string('status')->default(OzonOperationStatus::Pending->value)->index();
                $table->string('endpoint')->nullable();
                $table->json('request_payload')->nullable();
                $table->json('response_payload')->nullable();
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->string('request_id')->nullable()->index();
                $table->unsignedInteger('attempt')->default(1);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
                $table->string('http_method', 8)->nullable();
                $table->string('error_code', 64)->nullable()->index();
                $table->index(['operation_type', 'status', 'created_at']);
                $table->index(['ozon_account_id', 'created_at']);
            });
            return;
        }

        DB::statement('CREATE TABLE `'.self::COMPACT.'` LIKE `'.self::LIVE.'`');
        if ($this->foreignKeyFingerprint(self::COMPACT) === []) {
            DB::statement('ALTER TABLE `'.self::COMPACT.'`
                ADD CONSTRAINT `oz_ops_compact_account_fk` FOREIGN KEY (`ozon_account_id`) REFERENCES `ozon_accounts` (`id`) ON DELETE RESTRICT,
                ADD CONSTRAINT `oz_ops_compact_product_fk` FOREIGN KEY (`ozon_product_id`) REFERENCES `ozon_products` (`id`) ON DELETE CASCADE,
                ADD CONSTRAINT `oz_ops_compact_run_fk` FOREIGN KEY (`automation_run_id`) REFERENCES `automation_runs` (`id`) ON DELETE SET NULL');
        }
    }

    private function copyPreservedOperations(): void
    {
        $grammar = DB::connection()->getQueryGrammar();
        $columns = collect(Schema::getColumnListing(self::LIVE))->map(fn (string $column): string => $grammar->wrap($column))->implode(', ');
        DB::insert('INSERT INTO `'.self::COMPACT.'` ('.$columns.') SELECT '.$columns.' FROM `'.self::LIVE.'` WHERE NOT (`operation_type` = ? AND `status` = ?)', [
            OzonOperationType::TaxonomySync->value,
            OzonOperationStatus::Completed->value,
        ]);
    }

    private function truncateAttributes(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::table('ozon_taxonomy_attributes')->delete();
            return;
        }
        DB::statement('TRUNCATE TABLE `ozon_taxonomy_attributes`');
    }

    private function renameTables(array $renames): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach ($renames as $from => $to) {
                Schema::rename($from, $to);
            }
            return;
        }
        $parts = collect($renames)->map(fn (string $to, string $from): string => '`'.$from.'` TO `'.$to.'`')->implode(', ');
        DB::statement('RENAME TABLE '.$parts);
    }

    private function discardedOperations(string $table): Builder
    {
        return DB::table($table)->where('operation_type', OzonOperationType::TaxonomySync->value)->where('status', OzonOperationStatus::Completed->value);
    }

    private function preservedOperations(string $table): Builder
    {
        return DB::table($table)->where(function (Builder $query): void {
            $query->where('operation_type', '!=', OzonOperationType::TaxonomySync->value)->orWhere('status', '!=', OzonOperationStatus::Completed->value);
        });
    }

    private function breakdown(string $table, bool $preserved = false): Collection
    {
        $query = $preserved ? $this->preservedOperations($table) : DB::table($table);
        return $query->select('operation_type', 'status')->selectRaw('COUNT(*) AS rows_count')->groupBy('operation_type', 'status')->orderBy('operation_type')->orderBy('status')->get();
    }

    private function breakdownsMatch(string $source, string $target): bool
    {
        return $this->breakdown($source, true)->map(fn (object $row): array => (array) $row)->values()->all()
            === $this->breakdown($target)->map(fn (object $row): array => (array) $row)->values()->all();
    }

    private function schemaFingerprintsMatch(string $left, string $right): bool
    {
        return $this->schemaFingerprint($left) === $this->schemaFingerprint($right);
    }

    private function schemaFingerprint(string $table): array
    {
        $columns = collect(Schema::getColumns($table))->map(fn (array $column): array => collect($column)->only(['name', 'type_name', 'type', 'nullable', 'default', 'auto_increment', 'collation'])->all())->values()->all();
        $indexes = collect(Schema::getIndexes($table))->map(fn (array $index): array => [
            'columns' => $index['columns'] ?? [],
            'type' => $index['type'] ?? null,
            'unique' => (bool) ($index['unique'] ?? false),
            'primary' => (bool) ($index['primary'] ?? false),
        ])->sortBy(fn (array $index): string => json_encode($index))->values()->all();

        return [
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign_keys' => $this->foreignKeyFingerprint($table),
            'engine' => $this->tableMetadata($table)['engine'],
            'collation' => $this->tableMetadata($table)['collation'],
        ];
    }

    private function foreignKeyFingerprint(string $table): array
    {
        return collect(Schema::getForeignKeys($table))->map(fn (array $foreign): array => [
            'columns' => $foreign['columns'] ?? [],
            'foreign_schema' => $foreign['foreign_schema'] ?? null,
            'foreign_table' => $foreign['foreign_table'] ?? null,
            'foreign_columns' => $foreign['foreign_columns'] ?? [],
            'on_update' => strtolower((string) ($foreign['on_update'] ?? 'no action')),
            'on_delete' => strtolower((string) ($foreign['on_delete'] ?? 'no action')),
        ])->sortBy(fn (array $foreign): string => json_encode($foreign))->values()->all();
    }

    private function autoIncrementIsSane(string $table): bool
    {
        $max = (int) (DB::table($table)->max('id') ?? 0);
        if (DB::getDriverName() === 'sqlite') {
            $sequence = DB::table('sqlite_sequence')->where('name', $table)->value('seq');
            return $sequence === null || (int) $sequence >= $max;
        }
        $next = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->value('AUTO_INCREMENT');
        return $next !== null && (int) $next > $max;
    }

    private function hasIncomingForeignKeys(string $table): bool
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return false;
        }
        return DB::table('information_schema.KEY_COLUMN_USAGE')->where('REFERENCED_TABLE_SCHEMA', DB::getDatabaseName())->where('REFERENCED_TABLE_NAME', $table)->exists();
    }

    private function tableMetadata(string $table): array
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return ['engine' => DB::getDriverName(), 'collation' => null];
        }
        $row = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->first();
        return ['engine' => $row?->ENGINE, 'collation' => $row?->TABLE_COLLATION];
    }

    private function tableSizeMb(string $table): string
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || ! Schema::hasTable($table)) {
            return 'unavailable';
        }
        $row = DB::table('information_schema.TABLES')->selectRaw('ROUND((DATA_LENGTH + INDEX_LENGTH) / 1048576, 2) AS total_mb')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->first();
        return (string) ($row?->total_mb ?? '0');
    }

    private function spaceReport(): void
    {
        $tables = [self::LIVE, self::OLD, 'ozon_taxonomy_attributes', 'ozon_taxonomy_nodes'];
        $rows = collect($tables)->filter(fn (string $table): bool => Schema::hasTable($table))->map(function (string $table): array {
            if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                return [$table, 'unavailable', 'unavailable', 'unavailable', 'unavailable'];
            }
            $row = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->first();
            return [$table, round((float) $row->DATA_LENGTH / 1048576, 2), round((float) $row->INDEX_LENGTH / 1048576, 2), round(((float) $row->DATA_LENGTH + (float) $row->INDEX_LENGTH) / 1048576, 2), round((float) $row->DATA_FREE / 1048576, 2)];
        })->all();
        $this->table(['table', 'data_mb', 'index_mb', 'total_mb', 'data_free_mb'], $rows);
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $total = DB::table('information_schema.TABLES')->selectRaw('ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1048576, 2) AS total_mb')->where('TABLE_SCHEMA', DB::getDatabaseName())->value('total_mb');
            $this->line('Total logical database MB: '.$total);
        }
    }

    private function abort(string $message, int $code = self::FAILURE): int
    {
        $this->components->error($message);
        return $code;
    }
}
