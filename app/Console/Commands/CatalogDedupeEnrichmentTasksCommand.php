<?php

namespace App\Console\Commands;

use App\Models\CatalogEnrichmentTask;
use App\Services\Catalog\EnrichmentTaskBuilder;
use Illuminate\Console\Command;

class CatalogDedupeEnrichmentTasksCommand extends Command
{
    protected $signature = 'catalog:dedupe-enrichment-tasks {--dry-run}';

    protected $description = 'Close duplicate active enrichment tasks.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $duplicates = 0;

        CatalogEnrichmentTask::query()
            ->whereIn('status', EnrichmentTaskBuilder::ACTIVE_STATUSES)
            ->orderBy('id')
            ->get()
            ->groupBy(fn (CatalogEnrichmentTask $task): string => $task->product_id.'|'.$task->task_type)
            ->each(function ($group) use (&$duplicates, $dryRun): void {
                $extra = $group->skip(1);
                $duplicates += $extra->count();

                if (! $dryRun) {
                    $extra->each->update([
                        'status' => 'rejected',
                        'reason' => 'Closed by duplicate protection command.',
                    ]);
                }
            });

        $this->table(['Metric', 'Value'], [
            ['Mode', $dryRun ? 'dry-run' : 'apply'],
            ['Duplicate active tasks', $duplicates],
            ['Action', $dryRun ? 'none' : 'extra tasks marked rejected'],
        ]);

        return self::SUCCESS;
    }
}
