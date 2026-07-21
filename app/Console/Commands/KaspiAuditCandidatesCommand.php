<?php

namespace App\Console\Commands;

use App\Models\KaspiEnrichmentTask;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class KaspiAuditCandidatesCommand extends Command
{
    protected $signature = 'kaspi:audit-candidates
        {--reset-not-found : Reset widget_not_found tasks to pending for products with active Kaspi button}';

    protected $description = 'Show resolver candidate counts grouped by exclusion reason, and optionally reset stale widget_not_found tasks.';

    public function handle(): int
    {
        $mc = config('services.kaspi.merchant_code');
        $cc = config('services.kaspi.city_code');
        $buttonReady = filled($mc) && filled($cc);

        $this->info('=== Kaspi Configuration ===');
        $this->table(['Key', 'Value'], [
            ['merchant_code', $mc ?: '(not set)'],
            ['city_code', $cc ?: '(not set)'],
            ['enrichment_enabled', config('services.kaspi.enrichment_enabled') ? 'true' : 'false'],
            ['dry_run', config('services.kaspi.dry_run') ? 'true' : 'false'],
            ['Kaspi button ready', $buttonReady ? 'YES' : 'NO'],
        ]);

        $this->newLine();
        $this->info('=== Candidate Audit ===');

        $total = Product::count();
        $eligible = Product::eligibleForKaspiEnrichment()->count();
        $withButton = $buttonReady ? Product::eligibleForKaspiEnrichment()->withKaspiButton()->count() : 0;

        $withUrl = Product::eligibleForKaspiEnrichment()
            ->whereNotNull('kaspi_product_url')->where('kaspi_product_url', '<>', '')
            ->count();

        $missingUrl = Product::eligibleForKaspiEnrichment()->withKaspiButton()
            ->where(fn (Builder $q) => $q->whereNull('kaspi_product_url')->orWhere('kaspi_product_url', ''))
            ->count();

        $excludedNotFound = Product::eligibleForKaspiEnrichment()->withKaspiButton()
            ->where(fn (Builder $q) => $q->whereNull('kaspi_product_url')->orWhere('kaspi_product_url', ''))
            ->whereHas('kaspiEnrichmentTasks', fn (Builder $q) => $q->where('status', 'widget_not_found'))
            ->count();

        $finalCandidates = Product::eligibleForKaspiEnrichment()->withKaspiButton()
            ->where(fn (Builder $q) => $q->whereNull('kaspi_product_url')->orWhere('kaspi_product_url', ''))
            ->whereDoesntHave('kaspiEnrichmentTasks', fn (Builder $q) => $q->where('status', 'widget_not_found'))
            ->count();

        $retryableCandidates = $missingUrl; // with --retry-not-found

        $this->table(['Category', 'Count'], [
            ['Total products', $total],
            ['Eligible for Kaspi enrichment', $eligible],
            ['With Kaspi button (has SKU + merchant configured)', $withButton],
            ['Already have kaspi_product_url', $withUrl],
            ['Missing URL (need resolution)', $missingUrl],
            ['  → excluded: have widget_not_found task', $excludedNotFound],
            ['  → remaining candidates (default run)', $finalCandidates],
            ['  → retryable candidates (--retry-not-found)', $retryableCandidates],
        ]);

        $this->newLine();
        $this->info('=== Task Status Distribution ===');

        $taskStatuses = KaspiEnrichmentTask::query()
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->orderByDesc('cnt')
            ->get();

        $this->table(['Status', 'Count'], $taskStatuses->map(fn ($r) => [$r->status, $r->cnt])->toArray());

        if ($finalCandidates === 0 && $excludedNotFound > 0) {
            $this->newLine();
            $this->warn("All {$excludedNotFound} unresolved products are blocked by stale widget_not_found tasks.");
            $this->warn('Run with --reset-not-found to clear stale tasks, or pass --retry-not-found to the resolver.');
        }

        if ($this->option('reset-not-found')) {
            $this->newLine();
            $this->info('Resetting widget_not_found tasks to pending for products with active Kaspi button...');

            $resetIds = Product::eligibleForKaspiEnrichment()->withKaspiButton()
                ->where(fn (Builder $q) => $q->whereNull('kaspi_product_url')->orWhere('kaspi_product_url', ''))
                ->pluck('id');

            $resetCount = KaspiEnrichmentTask::query()
                ->whereIn('product_id', $resetIds)
                ->where('status', 'widget_not_found')
                ->update(['status' => 'pending', 'error' => 'Reset by kaspi:audit-candidates --reset-not-found']);

            $this->info("Reset {$resetCount} tasks to pending.");
        }

        $this->newLine();
        $this->info('=== Sample Products Blocked by widget_not_found ===');
        $samples = Product::eligibleForKaspiEnrichment()->withKaspiButton()
            ->where(fn (Builder $q) => $q->whereNull('kaspi_product_url')->orWhere('kaspi_product_url', ''))
            ->whereHas('kaspiEnrichmentTasks', fn (Builder $q) => $q->where('status', 'widget_not_found'))
            ->limit(10)
            ->get(['id', 'sku', 'name', 'product_status']);

        $this->table(
            ['ID', 'SKU', 'Name (truncated)', 'Status'],
            $samples->map(fn ($p) => [$p->id, $p->sku, mb_substr($p->name, 0, 50), $p->product_status])->toArray()
        );

        return self::SUCCESS;
    }
}
