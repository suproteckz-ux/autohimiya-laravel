<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\ProductStatus;
use App\Services\OpenCart\OpenCartDumpReader;
use App\Services\OpenCart\OpenCartMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OpenCartMatchCommand extends Command
{
    protected $signature = 'opencart:match {--dry-run : Analyze matches without database writes} {--apply : Update matching metadata only}';

    protected $description = 'Match imported Paloma products with local OpenCart dump products.';

    public function handle(OpenCartMatcher $matcher): int
    {
        if ($this->option('dry-run') === $this->option('apply')) {
            $this->error('Run exactly one mode: --dry-run or --apply.');

            return self::FAILURE;
        }

        $reader = new OpenCartDumpReader(
            path: config('services.opencart.sql_dump'),
            prefix: config('services.opencart.db_prefix', 'oc_'),
        );

        $diagnostics = $reader->diagnostics();
        $this->showPreflight($diagnostics);

        if (! $diagnostics['file_exists']) {
            $this->error('OpenCart SQL dump is not available. Set OPENCART_SQL_DUMP in .env and run php artisan config:clear.');
            $this->line('Expected format: absolute Windows path or path relative to laravel/, project root, or storage/app.');
            $this->line('Example: OPENCART_SQL_DUMP=C:\\Users\\anton\\Downloads\\v_11706_us1344_2026-06-16_10-12-58.sql.zip');

            return self::FAILURE;
        }

        $palomaProducts = Product::query()
            ->whereNotNull('paloma_sku')
            ->get([
                'id',
                'paloma_sku',
                'name',
                'opencart_product_id',
                'match_method',
                'match_confidence',
                'sync_status',
                'sync_error',
                'product_status',
            ]);

        $openCartProducts = $reader->products();
        $analysis = $matcher->match($palomaProducts, $openCartProducts);

        $this->writeCsvReport($analysis['results']);
        $this->table(['Metric', 'Value'], $this->statsRows($analysis['stats']));

        if ($this->option('dry-run')) {
            $this->info('OpenCart matching dry-run complete. No database writes were made.');

            return self::SUCCESS;
        }

        $updated = $this->apply($analysis['results']);
        $this->info('OpenCart matching metadata updated: '.$updated.' products.');

        return self::SUCCESS;
    }

    /**
     * @param array<int, array<string, mixed>> $results
     */
    private function apply(array $results): int
    {
        $updated = 0;

        DB::transaction(function () use ($results, &$updated): void {
            foreach ($results as $result) {
                $data = [
                    'opencart_product_id' => $result['opencart_product_id'],
                    'match_method' => $result['match_method'],
                    'match_confidence' => $result['confidence'],
                    'sync_status' => $this->syncStatusFor($result['match_status']),
                    'sync_error' => $this->syncErrorFor($result),
                ];

                if (in_array($result['match_status'], ['duplicate_sku', 'duplicate_model', 'conflict'], true)) {
                    $data['product_status'] = ProductStatus::NEEDS_REVIEW;
                }

                $affected = Product::query()
                    ->whereKey($result['product_id'])
                    ->update($data);

                $updated += $affected;
            }
        });

        return $updated;
    }

    /**
     * @param array<int, array<string, mixed>> $results
     */
    private function writeCsvReport(array $results): void
    {
        $path = $this->reportPath();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new \RuntimeException('Unable to write OpenCart matching report: '.$path);
        }

        fputcsv($handle, [
            'paloma_sku',
            'paloma_name',
            'opencart_product_id',
            'opencart_sku',
            'opencart_model',
            'match_method',
            'match_status',
            'confidence',
            'recommended_action',
        ]);

        foreach ($results as $result) {
            fputcsv($handle, [
                $result['paloma_sku'],
                $result['paloma_name'],
                $result['report_opencart_product_id'],
                $result['opencart_sku'],
                $result['opencart_model'],
                $result['match_method'],
                $result['match_status'],
                $result['confidence'],
                $result['recommended_action'],
            ]);
        }

        fclose($handle);

        $this->info('CSV report written: '.$path);
    }

    /**
     * @param array<string, int> $stats
     * @return array<int, array<int, int|string>>
     */
    private function statsRows(array $stats): array
    {
        return [
            ['Paloma products count', $stats['paloma_products_count']],
            ['OpenCart products count', $stats['opencart_products_count']],
            ['Matched by Model', $stats['matched_by_model']],
            ['Matched by SKU fallback', $stats['matched_by_sku']],
            ['Duplicate SKU', $stats['duplicate_sku']],
            ['Duplicate Model', $stats['duplicate_model']],
            ['Conflicts', $stats['conflicts']],
            ['Not Found', $stats['not_found']],
            ['OpenCart Only Skipped', $stats['opencart_only_skipped']],
        ];
    }

    /**
     * @param array<string, mixed> $diagnostics
     */
    private function showPreflight(array $diagnostics): void
    {
        $this->info('OpenCart matching preflight');
        $this->table(['Check', 'Value'], [
            ['Configured SQL dump path', $diagnostics['configured_path']],
            ['Resolved SQL dump path', $diagnostics['resolved_path']],
            ['SQL dump found', $diagnostics['file_exists'] ? 'yes' : 'no'],
            ['SQL dump size', $diagnostics['file_size'] === null ? 'n/a' : $this->formatBytes((int) $diagnostics['file_size'])],
            ['OpenCart DB prefix', $diagnostics['db_prefix']],
            ['Supported formats', $diagnostics['supported_formats']],
            ['Matching report path', $this->reportPath()],
        ]);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 2).' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 2).' KB';
        }

        return $bytes.' B';
    }

    private function syncStatusFor(string $matchStatus): string
    {
        return match ($matchStatus) {
            'matched' => 'matched',
            'duplicate_sku', 'duplicate_model', 'conflict' => 'conflict',
            default => 'not_found',
        };
    }

    /**
     * @param array<string, mixed> $result
     */
    private function syncErrorFor(array $result): ?string
    {
        return match ($result['match_status']) {
            'duplicate_sku' => 'Multiple OpenCart products have the same SKU.',
            'duplicate_model' => 'Multiple OpenCart products have the same model.',
            'conflict' => 'Ambiguous OpenCart match.',
            'not_found' => 'No OpenCart match found by SKU or model.',
            default => null,
        };
    }

    private function reportPath(): string
    {
        $path = config('services.opencart.matching_report_path');

        if (filled($path)) {
            $path = trim((string) $path, "\"'");

            if ($this->isAbsolutePath($path)) {
                return $path;
            }

            return base_path($path);
        }

        return storage_path('app/reports/opencart-matching-report.csv');
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[a-zA-Z]:[\\\\\\/]/', $path) === 1
            || str_starts_with($path, '\\\\')
            || str_starts_with($path, '/');
    }
}
