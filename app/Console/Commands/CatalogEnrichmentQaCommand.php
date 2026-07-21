<?php

namespace App\Console\Commands;

use App\Models\CatalogEnrichmentTask;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;

class CatalogEnrichmentQaCommand extends Command
{
    protected $signature = 'catalog:enrichment-qa';

    protected $description = 'Report catalog enrichment task coverage.';

    public function handle(): int
    {
        $stats = [
            'total tasks' => CatalogEnrichmentTask::query()->count(),
            'draft tasks' => CatalogEnrichmentTask::query()->where('status', 'draft')->count(),
            'approved tasks' => CatalogEnrichmentTask::query()->where('status', 'approved')->count(),
            'rejected tasks' => CatalogEnrichmentTask::query()->where('status', 'rejected')->count(),
            'products with no tasks but missing content' => $this->productsWithNoTasksButMissingContent(),
        ];

        $byType = CatalogEnrichmentTask::query()
            ->selectRaw('task_type, COUNT(*) as rows_count')
            ->groupBy('task_type')
            ->orderBy('task_type')
            ->pluck('rows_count', 'task_type')
            ->all();

        $bySource = CatalogEnrichmentTask::query()
            ->selectRaw('source, COUNT(*) as rows_count')
            ->groupBy('source')
            ->orderBy('source')
            ->pluck('rows_count', 'source')
            ->all();

        $this->writeReport($stats, $byType, $bySource);
        $this->table(['Metric', 'Count'], collect($stats)->map(fn (int $count, string $metric): array => [$metric, $count])->values());
        $this->table(['Task type', 'Count'], collect($byType)->map(fn (int $count, string $type): array => [$type, $count])->values());
        $this->table(['Source', 'Count'], collect($bySource)->map(fn (int $count, string $source): array => [$source, $count])->values());

        return self::SUCCESS;
    }

    private function productsWithNoTasksButMissingContent(): int
    {
        return Product::query()
            ->whereDoesntHave('enrichmentTasks')
            ->where(function (Builder $query): void {
                $query->whereNull('brand_id')
                    ->orWhereNull('primary_image')
                    ->orWhereNull('description')->orWhere('description', '')
                    ->orWhereNull('meta_title')->orWhere('meta_title', '')
                    ->orWhereNull('meta_description')->orWhere('meta_description', '');
            })
            ->count();
    }

    private function writeReport(array $stats, array $byType, array $bySource): void
    {
        $lines = ['# CATALOG_ENRICHMENT_QA_REPORT', '', 'Дата проверки: '.now()->toDateString(), '', '## Summary', '', '| Метрика | Количество |', '| --- | ---: |'];

        foreach ($stats as $metric => $count) {
            $lines[] = '| '.$metric.' | '.$count.' |';
        }

        $lines[] = '';
        $lines[] = '## By Task Type';
        $lines[] = '';
        $lines[] = '| Task type | Count |';
        $lines[] = '| --- | ---: |';

        foreach ($byType as $type => $count) {
            $lines[] = '| '.$type.' | '.$count.' |';
        }

        $lines[] = '';
        $lines[] = '## By Source';
        $lines[] = '';
        $lines[] = '| Source | Count |';
        $lines[] = '| --- | ---: |';

        foreach ($bySource as $source => $count) {
            $lines[] = '| '.$source.' | '.$count.' |';
        }

        File::put(dirname(base_path()).DIRECTORY_SEPARATOR.'CATALOG_ENRICHMENT_QA_REPORT.md', implode(PHP_EOL, $lines).PHP_EOL);
    }
}
