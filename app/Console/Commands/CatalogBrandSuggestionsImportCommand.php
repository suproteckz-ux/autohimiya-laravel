<?php

namespace App\Console\Commands;

use App\Models\CatalogEnrichmentTask;
use App\Models\Product;
use Illuminate\Console\Command;

class CatalogBrandSuggestionsImportCommand extends Command
{
    protected $signature = 'catalog:brand-suggestions:import {--path=BRAND_SUGGESTIONS.csv}';

    protected $description = 'Import BRAND_SUGGESTIONS.csv into draft enrichment tasks.';

    public function handle(): int
    {
        $path = dirname(base_path()).DIRECTORY_SEPARATOR.$this->option('path');

        if (! is_file($path)) {
            $this->warn('Brand suggestions CSV not found: '.$path);

            return self::SUCCESS;
        }

        $file = fopen($path, 'rb');
        $header = fgetcsv($file) ?: [];
        $created = 0;
        $updated = 0;
        $skipped = 0;

        while (($row = fgetcsv($file)) !== false) {
            $values = $this->cleanValues(array_combine($header, $row) ?: []);
            $productId = (int) ($values['product_id'] ?? 0);
            $product = $productId > 0 ? Product::query()->find($productId) : null;
            $suggestedBrand = (string) ($values['suggested_brand'] ?? '');

            if (! $product || $product->brand_id || $suggestedBrand === '') {
                $skipped++;
                continue;
            }

            $task = CatalogEnrichmentTask::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'task_type' => 'brand',
                    'source' => 'rule',
                    'status' => 'draft',
                ],
                [
                    'priority' => 60,
                    'current_value' => null,
                    'suggested_value' => $suggestedBrand,
                    'confidence' => (int) ($values['confidence'] ?? 70),
                    'reason' => 'Imported from BRAND_SUGGESTIONS.csv for manual review.',
                    'payload_json' => $values,
                ],
            );

            $task->wasRecentlyCreated ? $created++ : $updated++;
        }

        fclose($file);
        $this->table(['Metric', 'Count'], [['created', $created], ['updated', $updated], ['skipped', $skipped]]);

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function cleanValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            if (! mb_check_encoding($value, 'UTF-8')) {
                $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1251,ISO-8859-1,UTF-8');
            }

            $values[$key] = trim($value);
        }

        return $values;
    }
}
