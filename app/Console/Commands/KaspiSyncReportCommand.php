<?php

namespace App\Console\Commands;

use App\Models\KaspiEnrichmentTask;
use App\Models\Product;
use Illuminate\Console\Command;

class KaspiSyncReportCommand extends Command
{
    protected $signature = 'kaspi:sync-report';

    protected $description = 'Show Kaspi content enrichment summary.';

    public function handle(): int
    {
        $this->table(['Metric', 'Value'], [
            ['Products total', Product::query()->count()],
            ['With Product SKU / Kaspi SKU', Product::query()->whereNotNull('sku')->where('sku', '<>', '')->count()],
            ['Kaspi button ready automatically', Product::query()->whereNotNull('sku')->where('sku', '<>', '')->count()],
            ['Content tasks total', KaspiEnrichmentTask::query()->count()],
            ['Content tasks pending', KaspiEnrichmentTask::query()->where('status', 'pending')->count()],
            ['Content tasks draft', KaspiEnrichmentTask::query()->where('status', 'draft')->count()],
            ['Content tasks approved', KaspiEnrichmentTask::query()->where('status', 'approved')->count()],
            ['Resolved from widget', KaspiEnrichmentTask::query()->where('status', 'resolved_from_widget')->count()],
            ['Needs manual URL', KaspiEnrichmentTask::query()->where('status', 'needs_manual_url')->count()],
            ['Widget resolver diagnostics', KaspiEnrichmentTask::query()->whereIn('status', ['widget_not_found', 'widget_timeout', 'kaspi_button_not_found', 'kaspi_url_not_opened', 'invalid_kaspi_url', 'error'])->count()],
            ['Content tasks failed', KaspiEnrichmentTask::query()->where('status', 'failed')->count()],
            ['Tasks with public URL', KaspiEnrichmentTask::query()->whereNotNull('kaspi_product_url')->where('kaspi_product_url', '<>', '')->count()],
            ['Tasks with parsed images', KaspiEnrichmentTask::query()->whereNotNull('parsed_images')->where('parsed_images', '<>', '[]')->count()],
            ['Tasks with parsed description', KaspiEnrichmentTask::query()->whereNotNull('parsed_description')->where('parsed_description', '<>', '')->count()],
            ['Dry run', config('services.kaspi.dry_run') ? 'yes' : 'no'],
            ['Public parsing enabled', config('services.kaspi.enrichment_enabled') ? 'yes' : 'no'],
        ]);

        return self::SUCCESS;
    }
}
