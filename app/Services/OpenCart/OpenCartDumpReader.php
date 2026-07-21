<?php

namespace App\Services\OpenCart;

use RuntimeException;
use ZipArchive;

class OpenCartDumpReader
{
    private const PRODUCT_COLUMNS = [
        'product_id',
        'model',
        'sku',
        'upc',
        'ean',
        'jan',
        'isbn',
        'mpn',
        'location',
        'quantity',
        'stock_status_id',
        'image',
        'manufacturer_id',
        'shipping',
        'price',
        'points',
        'tax_class_id',
        'date_available',
        'weight',
        'weight_class_id',
        'length',
        'width',
        'height',
        'length_class_id',
        'subtract',
        'minimum',
        'sort_order',
        'status',
        'viewed',
        'date_added',
        'date_modified',
    ];

    private const PRODUCT_DESCRIPTION_COLUMNS = [
        'product_id',
        'language_id',
        'name',
        'description',
        'tag',
        'meta_title',
        'meta_description',
        'meta_keyword',
    ];

    private const FALLBACK_COLUMNS = [
        'product' => self::PRODUCT_COLUMNS,
        'product_description' => self::PRODUCT_DESCRIPTION_COLUMNS,
        'category' => ['category_id', 'image', 'parent_id', 'top', 'column', 'sort_order', 'status', 'date_added', 'date_modified'],
        'category_description' => ['category_id', 'language_id', 'name', 'description', 'meta_title', 'meta_description', 'meta_keyword', 'meta_h1'],
        'category_path' => ['category_id', 'path_id', 'level'],
        'manufacturer' => ['manufacturer_id', 'name', 'image', 'sort_order'],
        'product_to_category' => ['product_id', 'category_id'],
        'url_alias' => ['url_alias_id', 'query', 'keyword'],
        'seo_url' => ['seo_url_id', 'store_id', 'language_id', 'query', 'keyword'],
        'product_image' => ['product_image_id', 'product_id', 'image', 'sort_order'],
        'product_attribute' => ['product_id', 'attribute_id', 'language_id', 'text'],
        'attribute_description' => ['attribute_id', 'language_id', 'name'],
    ];

    public function __construct(
        private readonly ?string $path = null,
        private readonly string $prefix = 'oc_',
    ) {
    }

    public function configuredPath(): ?string
    {
        return $this->path ?: config('services.opencart.sql_dump');
    }

    public function resolvedPath(): ?string
    {
        $path = $this->configuredPath();

        if (blank($path)) {
            return null;
        }

        $path = trim((string) $path, "\"'");

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        $candidates = [
            base_path($path),
            base_path('../'.$path),
            storage_path('app/'.$path),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return base_path($path);
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $path = $this->resolvedPath();

        return [
            'configured_path' => $this->configuredPath() ?: 'not configured',
            'resolved_path' => $path ?: 'not configured',
            'file_exists' => $path !== null && is_file($path),
            'file_size' => $path !== null && is_file($path) ? filesize($path) : null,
            'db_prefix' => $this->prefix,
            'supported_formats' => '.sql, .sql.zip',
        ];
    }

    /**
     * @return array<int, OpenCartProductData>
     */
    public function products(): array
    {
        $path = $this->resolvedPath();

        if (blank($path)) {
            throw new RuntimeException('OPENCART_SQL_DUMP is not configured.');
        }

        if (! is_file($path)) {
            throw new RuntimeException('OpenCart SQL dump was not found: '.$path);
        }

        $products = [];
        $descriptions = [];

        $this->readStatements($path, function (string $statement) use (&$products, &$descriptions): void {
            $table = $this->tableName($statement);

            if ($table === $this->prefix.'product') {
                foreach ($this->rows($statement, self::PRODUCT_COLUMNS) as $row) {
                    $productId = (int) ($row['product_id'] ?? 0);

                    if ($productId <= 0) {
                        continue;
                    }

                    $products[$productId] = new OpenCartProductData(
                        product_id: $productId,
                        sku: $this->nullable($row['sku'] ?? null),
                        model: $this->nullable($row['model'] ?? null),
                    );
                }
            }

            if ($table === $this->prefix.'product_description') {
                foreach ($this->rows($statement, self::PRODUCT_DESCRIPTION_COLUMNS) as $row) {
                    $productId = (int) ($row['product_id'] ?? 0);

                    if ($productId <= 0) {
                        continue;
                    }

                    $languageId = (int) ($row['language_id'] ?? 0);
                    $name = $this->nullable($row['name'] ?? null);

                    if ($name !== null && (! isset($descriptions[$productId]) || $languageId === 1)) {
                        $descriptions[$productId] = $name;
                    }
                }
            }
        });

        foreach ($descriptions as $productId => $name) {
            if (isset($products[$productId])) {
                $products[$productId]->name = $name;
            }
        }

        return $products;
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    public function tableRows(string $table): array
    {
        $table = str_starts_with($table, $this->prefix) ? $table : $this->prefix.$table;
        $fallbackKey = substr($table, strlen($this->prefix));
        $fallbackColumns = $this->tableColumns($table) ?: (self::FALLBACK_COLUMNS[$fallbackKey] ?? []);
        $rows = [];
        $path = $this->resolvedPath();

        if (blank($path)) {
            throw new RuntimeException('OPENCART_SQL_DUMP is not configured.');
        }

        if (! is_file($path)) {
            throw new RuntimeException('OpenCart SQL dump was not found: '.$path);
        }

        $this->readStatements($path, function (string $statement) use ($table, $fallbackColumns, &$rows): void {
            if ($this->tableName($statement) !== $table) {
                return;
            }

            $rows = array_merge($rows, $this->rows($statement, $fallbackColumns));
        }, [$table]);

        return $rows;
    }

    private function readStatements(string $path, callable $callback, ?array $tables = null): void
    {
        $stream = $this->openStream($path);
        $statement = '';

        while (($line = fgets($stream)) !== false) {
            if (stripos(ltrim($line), 'INSERT INTO') !== 0) {
                continue;
            }

            $statement = $line;

            while (! str_ends_with(rtrim($statement), ';') && ($next = fgets($stream)) !== false) {
                $statement .= $next;
            }

            $table = $this->tableName($statement);

            $wantedTables = $tables ?? [$this->prefix.'product', $this->prefix.'product_description'];

            if (in_array($table, $wantedTables, true)) {
                $callback($statement);
            }

            $statement = '';
        }

        fclose($stream);
    }

    /**
     * @return array<int, string>
     */
    private function tableColumns(string $table): array
    {
        $path = $this->resolvedPath();

        if (blank($path) || ! is_file($path)) {
            return [];
        }

        $stream = $this->openStream($path);
        $statement = '';
        $columns = [];

        while (($line = fgets($stream)) !== false) {
            if ($statement === '' && stripos(ltrim($line), 'CREATE TABLE') !== 0) {
                continue;
            }

            $statement .= $line;

            if (! str_ends_with(rtrim($statement), ';')) {
                continue;
            }

            if ($this->tableNameFromCreate($statement) === $table) {
                foreach (preg_split('/\r\n|\r|\n/', $statement) ?: [] as $definitionLine) {
                    $definitionLine = trim($definitionLine);

                    if (preg_match('/^`([^`]+)`\s+/u', $definitionLine, $matches) === 1) {
                        $columns[] = $matches[1];
                    }
                }

                break;
            }

            $statement = '';
        }

        fclose($stream);

        return $columns;
    }

    private function tableNameFromCreate(string $statement): ?string
    {
        if (preg_match('/CREATE\s+TABLE\s+`?([^`\s(]+)`?/i', $statement, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function openStream(string $path): mixed
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'zip') {
            $stream = fopen($path, 'rb');

            if ($stream === false) {
                throw new RuntimeException('Unable to open OpenCart SQL dump.');
            }

            return $stream;
        }

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is required to read zipped OpenCart dumps.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open zipped OpenCart SQL dump.');
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);

            if ($name !== false && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'sql') {
                $stream = $zip->getStream($name);

                if ($stream === false) {
                    break;
                }

                $temp = fopen('php://temp', 'w+b');

                if ($temp === false) {
                    throw new RuntimeException('Unable to allocate temporary stream for zipped OpenCart dump.');
                }

                stream_copy_to_stream($stream, $temp);
                fclose($stream);
                $zip->close();
                rewind($temp);

                return $temp;
            }
        }

        $zip->close();

        throw new RuntimeException('No .sql file was found inside OpenCart dump zip.');
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[a-zA-Z]:[\\\\\\/]/', $path) === 1
            || str_starts_with($path, '\\\\')
            || str_starts_with($path, '/');
    }

    private function tableName(string $statement): ?string
    {
        if (preg_match('/INSERT\s+INTO\s+`?([^`\s(]+)`?/i', $statement, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function rows(string $statement, array $fallbackColumns): array
    {
        if (preg_match('/INSERT\s+INTO\s+`?[^`\s(]+`?\s*(\((.*?)\))?\s*VALUES\s*(.*);$/is', $statement, $matches) !== 1) {
            return [];
        }

        $columns = $fallbackColumns;

        if (! empty($matches[2])) {
            $columns = array_map(
                fn (string $column): string => trim($column, " `\t\n\r\0\x0B"),
                explode(',', $matches[2]),
            );
        }

        $rows = [];

        foreach ($this->splitRows($matches[3]) as $values) {
            $row = [];

            foreach ($columns as $index => $column) {
                $row[$column] = $values[$index] ?? null;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array<int, array<int, string|null>>
     */
    private function splitRows(string $values): array
    {
        $rows = [];
        $current = '';
        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = 0, $length = strlen($values); $i < $length; $i++) {
            $char = $values[$i];

            if ($inString) {
                $current .= $char;

                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === "'") {
                    $inString = false;
                }

                continue;
            }

            if ($char === "'") {
                $inString = true;
                $current .= $char;

                continue;
            }

            if ($char === '(') {
                $depth++;

                if ($depth === 1) {
                    $current = '';
                    continue;
                }
            }

            if ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    $rows[] = $this->splitValues($current);
                    $current = '';
                    continue;
                }
            }

            if ($depth > 0) {
                $current .= $char;
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string|null>
     */
    private function splitValues(string $row): array
    {
        $values = [];
        $current = '';
        $inString = false;
        $escaped = false;

        for ($i = 0, $length = strlen($row); $i < $length; $i++) {
            $char = $row[$i];

            if ($inString) {
                $current .= $char;

                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === "'") {
                    $inString = false;
                }

                continue;
            }

            if ($char === "'") {
                $inString = true;
                $current .= $char;

                continue;
            }

            if ($char === ',') {
                $values[] = $this->decodeValue($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $values[] = $this->decodeValue($current);

        return $values;
    }

    private function decodeValue(string $value): ?string
    {
        $value = trim($value);

        if (strcasecmp($value, 'NULL') === 0) {
            return null;
        }

        if (str_starts_with($value, "'") && str_ends_with($value, "'")) {
            return stripcslashes(substr($value, 1, -1));
        }

        return $value;
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
