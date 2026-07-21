<?php

namespace App\Console\Commands;

use App\Models\KaspiEnrichmentTask;
use Illuminate\Console\Command;

class KaspiEnrichmentReportCommand extends Command
{
    protected $signature = 'kaspi:enrichment-report';

    protected $description = 'Show Kaspi enrichment task summary.';

    public function handle(): int
    {
        $this->table(['Metric', 'Value'], [
            ['Tasks total', KaspiEnrichmentTask::query()->count()],
            ['Pending', KaspiEnrichmentTask::query()->where('status', 'pending')->count()],
            ['Draft', KaspiEnrichmentTask::query()->where('status', 'draft')->count()],
            ['Approved', KaspiEnrichmentTask::query()->where('status', 'approved')->count()],
            ['Published', KaspiEnrichmentTask::query()->where('status', 'published')->count()],
            ['Rejected', KaspiEnrichmentTask::query()->where('status', 'rejected')->count()],
            ['Failed', KaspiEnrichmentTask::query()->where('status', 'failed')->count()],
            ['With URL', KaspiEnrichmentTask::query()->whereNotNull('kaspi_product_url')->count()],
            ['With images', KaspiEnrichmentTask::query()->whereNotNull('parsed_images')->count()],
            ['With description', KaspiEnrichmentTask::query()->whereNotNull('parsed_description')->where('parsed_description', '<>', '')->count()],
            ['With attributes', KaspiEnrichmentTask::query()->whereNotNull('parsed_attributes')->count()],
        ]);

        return self::SUCCESS;
    }
}
