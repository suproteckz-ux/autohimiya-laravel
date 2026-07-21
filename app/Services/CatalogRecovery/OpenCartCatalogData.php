<?php

namespace App\Services\CatalogRecovery;

use App\Services\OpenCart\OpenCartDumpReader;
use Illuminate\Support\Collection;

class OpenCartCatalogData
{
    private ?array $data = null;

    public function __construct(private readonly ?OpenCartDumpReader $reader = null)
    {
    }

    public function all(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $reader = $this->reader ?: new OpenCartDumpReader(
            path: config('services.opencart.sql_dump'),
            prefix: config('services.opencart.db_prefix', 'oc_'),
        );

        $aliases = array_replace(
            $this->aliasesByQuery($reader->tableRows('url_alias')),
            $this->aliasesByQuery($reader->tableRows('seo_url')),
        );

        return $this->data = [
            'products' => collect($reader->tableRows('product'))->keyBy(fn (array $row): int => (int) $row['product_id'])->all(),
            'descriptions' => collect($reader->tableRows('product_description'))->keyBy(fn (array $row): int => (int) $row['product_id'])->all(),
            'categories' => collect($reader->tableRows('category'))->keyBy(fn (array $row): int => (int) $row['category_id'])->all(),
            'category_descriptions' => collect($reader->tableRows('category_description'))->keyBy(fn (array $row): int => (int) $row['category_id'])->all(),
            'category_paths' => $reader->tableRows('category_path'),
            'product_to_category' => $reader->tableRows('product_to_category'),
            'manufacturers' => collect($reader->tableRows('manufacturer'))->keyBy(fn (array $row): int => (int) $row['manufacturer_id'])->all(),
            'product_images' => collect($reader->tableRows('product_image'))->groupBy(fn (array $row): int => (int) $row['product_id']),
            'aliases' => $aliases,
        ];
    }

    public function diagnostics(): array
    {
        $reader = $this->reader ?: new OpenCartDumpReader(
            path: config('services.opencart.sql_dump'),
            prefix: config('services.opencart.db_prefix', 'oc_'),
        );

        return $reader->diagnostics();
    }

    private function aliasesByQuery(array $rows): array
    {
        $aliases = [];

        foreach ($rows as $row) {
            if (filled($row['query'] ?? null) && filled($row['keyword'] ?? null)) {
                $aliases[$row['query']] = $row['keyword'];
            }
        }

        return $aliases;
    }
}
