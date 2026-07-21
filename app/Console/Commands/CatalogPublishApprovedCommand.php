<?php

namespace App\Console\Commands;

use App\Models\CatalogEnrichmentTask;
use App\Services\Catalog\EnrichmentPublisher;
use Illuminate\Console\Command;

class CatalogPublishApprovedCommand extends Command
{
    protected $signature = 'catalog:publish-approved {--type=all : image|description|seo|brand|category|all} {--limit=50} {--dry-run}';

    protected $description = 'Publish approved enrichment tasks to products.';

    public function handle(EnrichmentPublisher $publisher): int
    {
        $type = (string) $this->option('type');
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $query = CatalogEnrichmentTask::query()
            ->with('product')
            ->where('status', 'approved')
            ->orderBy('id');

        if ($type !== 'all') {
            $query->whereIn('task_type', $type === 'seo' ? ['seo_title', 'seo_description', 'seo'] : [$type]);
        }

        $tasks = $query->limit($limit)->get();
        $published = 0;
        $changedProducts = collect();

        foreach ($tasks as $task) {
            if ($dryRun) {
                $published++;
                $changedProducts->push($task->product_id);
                continue;
            }

            if ($publisher->publish($task)) {
                $published++;
                $changedProducts->push($task->product_id);
            }
        }

        $this->table(['Metric', 'Value'], [
            ['Mode', $dryRun ? 'dry-run' : 'apply'],
            ['Type', $type],
            ['Approved tasks found', $tasks->count()],
            ['Tasks '.($dryRun ? 'publishable' : 'published'), $published],
            ['Products changed', $changedProducts->unique()->count()],
        ]);

        return self::SUCCESS;
    }
}
