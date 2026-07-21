<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CatalogUtf8Scanner
{
    public static function targets(): array
    {
        return array_replace_recursive(self::siteTextTargets(), [
            'categories' => [
                'name' => ['max' => 255],
                'slug' => ['max' => 255],
                'h1' => ['max' => 255],
                'description' => ['max' => 65000],
                'meta_title' => ['max' => 255],
                'meta_description' => ['max' => 500],
            ],
            'brands' => [
                'name' => ['max' => 255],
                'slug' => ['max' => 255],
                'description' => ['max' => 65000],
            ],
            'products' => [
                'name' => ['max' => 255],
                'slug' => ['max' => 255],
                'model' => ['max' => 255],
                'sku' => ['max' => 255],
                'h1' => ['max' => 255],
                'short_description' => ['max' => 1000],
                'description' => ['max' => 65000],
                'meta_title' => ['max' => 255],
                'meta_description' => ['max' => 500],
            ],
            'product_attributes' => [
                'group_name' => ['max' => 255],
                'name' => ['max' => 255],
                'value' => ['max' => 65000],
                'unit' => ['max' => 255],
            ],
            'sync_logs' => [
                'payload_summary' => ['json' => true],
                'diagnostics' => ['json' => true],
                'raw_payload' => ['json' => true],
                'error_message' => ['max' => 2000],
            ],
        ]);
    }

    public static function scan(int $limit = 0): array
    {
        $issues = [];

        foreach (self::targets() as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id')) {
                continue;
            }

            foreach ($columns as $column => $meta) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                DB::table($table)
                    ->select(['id', $column])
                    ->whereNotNull($column)
                    ->orderBy('id')
                    ->chunkById(200, function ($rows) use (&$issues, $table, $column, $meta, $limit): bool {
                        foreach ($rows as $row) {
                            $value = $row->{$column};

                            if (! is_string($value) || $value === '') {
                                continue;
                            }

                            $issue = self::detectIssue($value, (bool) ($meta['json'] ?? false));

                            if ($issue === null) {
                                continue;
                            }

                            $issues[] = [
                                'table' => $table,
                                'column' => $column,
                                'id' => (int) $row->id,
                                'preview' => TextEncoding::preview($value),
                                'raw_preview' => TextEncoding::rawPreview($value),
                                'issue' => $issue,
                                'value' => $value,
                                'meta' => $meta,
                            ];

                            if ($limit > 0 && count($issues) >= $limit) {
                                return false;
                            }
                        }

                        return true;
                    });

                if ($limit > 0 && count($issues) >= $limit) {
                    break 2;
                }
            }
        }

        return $issues;
    }

    public static function cleanForColumn(string $value, array $meta): ?string
    {
        if ((bool) ($meta['json'] ?? false)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $encoded = json_encode(TextEncoding::cleanRecursive($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return is_string($encoded) ? $encoded : null;
            }
        }

        $clean = TextEncoding::clean($value);

        if ($clean === null) {
            return null;
        }

        $max = (int) ($meta['max'] ?? 0);

        if ($max > 0 && mb_strlen($clean) > $max) {
            $clean = mb_substr($clean, 0, $max);
        }

        return $clean;
    }

    private static function detectIssue(string $value, bool $json): ?string
    {
        $issue = TextEncoding::issue($value);

        if ($issue !== null) {
            return $issue;
        }

        if (! $json) {
            return null;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return 'invalid_json_or_utf8';
        }

        return self::findNestedIssue($decoded);
    }

    private static function findNestedIssue(mixed $value): ?string
    {
        if (is_string($value)) {
            return TextEncoding::issue($value);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $keyIssue = is_string($key) ? TextEncoding::issue($key) : null;
                if ($keyIssue !== null) {
                    return 'json_key_'.$keyIssue;
                }

                $itemIssue = self::findNestedIssue($item);
                if ($itemIssue !== null) {
                    return 'json_'.$itemIssue;
                }
            }
        }

        return null;
    }

    private static function siteTextTargets(): array
    {
        $targets = [];

        foreach (self::tableNames() as $table) {
            if (! Schema::hasColumn($table, 'id') || self::shouldSkipTable($table)) {
                continue;
            }

            foreach (Schema::getColumnListing($table) as $column) {
                if ($column === 'id' || str_ends_with($column, '_id') || in_array($column, ['created_at', 'updated_at', 'deleted_at'], true)) {
                    continue;
                }

                $type = self::columnType($table, $column);

                if (! self::isTextColumn($type)) {
                    continue;
                }

                $targets[$table][$column] = [
                    'json' => str_contains($type, 'json') || str_ends_with($column, '_json') || in_array($column, ['payload', 'payload_summary', 'diagnostics', 'raw_payload'], true),
                    'max' => self::maxLengthForType($type),
                ];
            }
        }

        return $targets;
    }

    private static function tableNames(): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"))
                ->pluck('name')
                ->map(fn (string $name): string => $name)
                ->all();
        }

        return collect(DB::select('SHOW TABLES'))
            ->map(fn (object $row): string => (string) array_values((array) $row)[0])
            ->all();
    }

    private static function columnType(string $table, string $column): string
    {
        try {
            return strtolower((string) Schema::getColumnType($table, $column, true));
        } catch (\Throwable) {
            try {
                return strtolower((string) Schema::getColumnType($table, $column));
            } catch (\Throwable) {
                return '';
            }
        }
    }

    private static function isTextColumn(string $type): bool
    {
        return str_contains($type, 'char')
            || str_contains($type, 'text')
            || str_contains($type, 'json')
            || str_contains($type, 'enum')
            || str_contains($type, 'varchar')
            || $type === 'string';
    }

    private static function maxLengthForType(string $type): int
    {
        if (preg_match('/(?:varchar|char)\((\d+)\)/', $type, $matches) === 1) {
            return (int) $matches[1];
        }

        if (str_contains($type, 'tinytext')) {
            return 255;
        }

        if (str_contains($type, 'mediumtext')) {
            return 16000000;
        }

        if (str_contains($type, 'longtext')) {
            return 65000;
        }

        if (str_contains($type, 'text')) {
            return 65000;
        }

        return 0;
    }

    private static function shouldSkipTable(string $table): bool
    {
        return in_array($table, [
            'cache',
            'cache_locks',
            'failed_jobs',
            'job_batches',
            'jobs',
            'migrations',
            'password_reset_tokens',
            'sessions',
        ], true);
    }
}
