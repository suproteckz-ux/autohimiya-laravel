<?php

namespace App\Console\Commands;

use App\Services\Automation\NullProgressReporter;
use App\Services\Kaspi\KaspiContentImportService;
use Illuminate\Console\Command;
use Throwable;

class KaspiImportContentCommand extends Command
{
    protected $signature = 'kaspi:import-content
        {--limit=100           : Max products to process (0 = no limit)}
        {--product-id=         : Process only this product_id}
        {--ids=                : Comma-separated product IDs (overrides --limit)}
        {--sku=                : Process only this SKU}
        {--dry-run             : Parse and plan without saving anything}
        {--delay-ms=3000       : Delay in ms between Kaspi HTTP requests}
        {--only-missing=false  : Only products that have no Kaspi photos yet}
        {--photos=true         : Import photos}
        {--description=true    : Import description}
        {--attributes=true     : Import attributes/characteristics}
        {--force=false         : Replace existing photos / description / attributes}
        {--force-photos        : Force photo import even when protected}
        {--force-description   : Force description import even when protected}
        {--force-attributes    : Force attributes import even when protected}';

    protected $description = 'Import photos, descriptions, and attributes from Kaspi for products with kaspi_product_url.';

    public function handle(KaspiContentImportService $service): int
    {
        try {
            $result = $service->import([
                'limit' => max(0, (int) $this->option('limit')),
                'product_id' => $this->option('product-id'),
                'ids' => $this->option('ids'),
                'sku' => $this->option('sku'),
                'dry_run' => (bool) $this->option('dry-run'),
                'delay_ms' => max(0, (int) $this->option('delay-ms')),
                'only_missing' => $this->option('only-missing'),
                'photos' => $this->option('photos'),
                'description' => $this->option('description'),
                'attributes' => $this->option('attributes'),
                'force' => $this->option('force'),
                'force_photos' => (bool) $this->option('force-photos'),
                'force_description' => (bool) $this->option('force-description'),
                'force_attributes' => (bool) $this->option('force-attributes'),
            ], new NullProgressReporter());
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Metric', 'Count'], collect($result['metrics'] ?? [])->map(fn (int $count, string $metric): array => [$metric, $count])->values()->all());
        $this->info((string) ($result['message'] ?? 'Import complete.'));

        return (int) ($result['failed_count'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}