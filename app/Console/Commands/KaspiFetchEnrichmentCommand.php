<?php

namespace App\Console\Commands;

use App\Models\KaspiEnrichmentTask;
use App\Services\Kaspi\KaspiEnrichmentParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class KaspiFetchEnrichmentCommand extends Command
{
    protected $signature = 'kaspi:fetch-enrichment {--limit=5} {--dry-run}';

    protected $description = 'Fetch Kaspi enrichment data into draft task records.';

    public function handle(KaspiEnrichmentParser $parser): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $fetched = 0;
        $failed = 0;
        $rows = [];
        $tasks = KaspiEnrichmentTask::query()
            ->with('product')
            ->whereIn('status', ['pending', 'failed'])
            ->whereNotNull('kaspi_product_url')
            ->limit($limit)
            ->get();

        if (! $dryRun && ! config('services.kaspi.enrichment_enabled')) {
            $this->warn('Kaspi public content parsing is disabled. Set KASPI_ENRICHMENT_ENABLED=true only when public parsing is allowed.');

            return self::SUCCESS;
        }

        foreach ($tasks as $task) {
            if ($dryRun) {
                $rows[] = [$task->id, $task->product?->sku, $task->kaspi_product_url, 'planned', 'Dry-run: request was not sent.'];
                continue;
            }

            try {
                $task->update(['status' => 'running', 'started_at' => now(), 'error' => null]);

                sleep(max(1, (int) config('services.kaspi.rate_limit_seconds', 10)));

                $response = Http::timeout(20)
                    ->withHeaders(['User-Agent' => 'AutohimiyaKzBot/1.0 (+https://autohimiki.kz)'])
                    ->get($task->kaspi_product_url);

                $payload = $parser->parse($response->body(), $task->kaspi_product_url);

                $task->update([
                    'status' => 'draft',
                    'parsed_title' => ['value' => $payload['name'] ?? null],
                    'parsed_images' => $payload['images'] ?? [],
                    'parsed_description' => $payload['description'] ?? null,
                    'parsed_attributes' => $payload['attributes'] ?? [],
                    'parsed_brand' => $payload['brand'] ?? null,
                    'parsed_category' => $payload['category'] ?? null,
                    'raw_payload' => $payload,
                    'finished_at' => now(),
                    'error' => null,
                ]);

                $fetched++;
                $rows[] = [$task->id, $task->product?->sku, $task->kaspi_product_url, 'draft', null];
            } catch (Throwable $exception) {
                $task->update(['status' => 'failed', 'finished_at' => now(), 'error' => $exception->getMessage()]);
                $failed++;
                $rows[] = [$task->id, $task->product?->sku, $task->kaspi_product_url, 'failed', $exception->getMessage()];
            }
        }

        $this->table(['Metric', 'Value'], [
            ['Mode', $dryRun ? 'dry-run' : 'apply'],
            ['Pending tasks found', $tasks->count()],
            ['Fetched', $fetched],
            ['Failed', $failed],
            ['Products changed', 0],
        ]);

        if ($rows !== []) {
            $this->table(['Task ID', 'SKU', 'URL', 'Status', 'Error'], $rows);
        }

        return self::SUCCESS;
    }
}
