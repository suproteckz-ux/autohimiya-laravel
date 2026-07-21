<?php

namespace App\Console\Commands;

use App\Models\KaspiEnrichmentTask;
use App\Models\Product;
use App\Models\SyncLog;
use App\Services\Catalog\CatalogDescriptionFallbackService;
use App\Services\Kaspi\KaspiDraftPublisher;
use App\Services\Kaspi\KaspiEnrichmentParser;
use App\Support\Utf8Sanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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

    public function handle(KaspiEnrichmentParser $parser, KaspiDraftPublisher $publisher, CatalogDescriptionFallbackService $fallbackService): int
    {
        $startedAt = Carbon::now();
        $dryRun = (bool) $this->option('dry-run');
        $delayMs = max(0, (int) $this->option('delay-ms'));
        $limit = max(0, (int) $this->option('limit'));
        $applyPhotos = $this->truthy($this->option('photos'));
        $applyDescription = $this->truthy($this->option('description'));
        $applyAttributes = $this->truthy($this->option('attributes'));
        $force = $this->truthy($this->option('force'));
        $forcePhotos = $force || (bool) $this->option('force-photos');
        $forceDescription = $force || (bool) $this->option('force-description');
        $forceAttributes = $force || (bool) $this->option('force-attributes');
        $onlyMissing = $this->truthy($this->option('only-missing'));

        if (! $dryRun && ! config('services.kaspi.enrichment_enabled')) {
            $this->writeGuardWarningLog($startedAt);
            $this->error('KASPI_ENRICHMENT_ENABLED is not set. Enable it in .env before running mass import.');
            $this->line('Set KASPI_ENRICHMENT_ENABLED=true in .env, then run php artisan optimize:clear');

            return self::FAILURE;
        }

        $query = Product::query()
            ->with(['images', 'attributes', 'kaspiEnrichmentTasks' => fn ($q) => $q->orderByDesc('updated_at')])
            ->eligibleForKaspiEnrichment()
            ->whereNotNull('kaspi_product_url')
            ->where('kaspi_product_url', '<>', '')
            ->orderBy('id');

        if ($this->option('product-id')) {
            $query->where('id', (int) $this->option('product-id'));
        }

        if (filled($this->option('ids'))) {
            $ids = array_filter(array_map('intval', explode(',', (string) $this->option('ids'))));
            if ($ids !== []) {
                $query->whereIn('id', $ids);
            }
        }

        if ($this->option('sku')) {
            $query->where('sku', $this->option('sku'));
        }

        if ($onlyMissing) {
            $query->whereDoesntHave('images', fn ($q) => $q->where('source', 'kaspi'));
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $products = $query->get();

        if ($products->isEmpty()) {
            $this->warn('No products matched the query.');

            return self::SUCCESS;
        }

        $this->line('Mode: '.($dryRun ? '<fg=yellow>DRY RUN</>' : '<fg=green>REAL</>'));
        $this->line(sprintf('Products to process: %d  |  force: %s  |  delay: %d ms', $products->count(), $force ? 'yes' : 'no', $delayMs));
        $this->newLine();

        $rows = [];
        $total = $products->count();
        $imported = 0;
        $partial = 0;
        $noData = 0;
        $blocked = 0;
        $errors = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($products as $index => $product) {
            if ($index > 0 && $delayMs > 0 && ! $dryRun) {
                usleep($delayMs * 1000);
            }

            $kaspiUrl = $product->kaspi_product_url;
            $row = [
                'product_id' => $product->id,
                'sku' => $product->sku ?? '—',
                'kaspi_url' => mb_substr($kaspiUrl, 0, 55),
                'photos_found' => 0,
                'photos_to_save' => 0,
                'description_found' => 'no',
                'attributes_found' => 0,
                'status' => '—',
                'error' => '',
            ];

            try {
                if ($dryRun) {
                    // Fetch and parse to plan, but don't save
                    $response = Http::timeout(30)
                        ->withHeaders(['User-Agent' => 'AutohimiyaKzBot/1.0 (+https://autohimiki.kz)'])
                        ->get($kaspiUrl);

                    if (! $response->successful()) {
                        $row['status'] = 'kaspi_blocked';
                        $row['error'] = 'HTTP '.$response->status();
                        $blocked++;
                        $rows[] = $row;
                        $bar->advance();
                        continue;
                    }

                    $payload = $parser->parse($response->body(), $kaspiUrl);
                    $images = (array) data_get($payload, 'cleaned.images', []);
                    $description = data_get($payload, 'cleaned.description') ?: $fallbackService->generate($product, data_get($payload, 'cleaned.attributes', []));
                    $attributes = (array) data_get($payload, 'cleaned.attributes', []);

                    $row['photos_found'] = count($images);
                    $row['photos_to_save'] = ($applyPhotos && count($images) > 0) ? count($images) : 0;
                    $row['description_found'] = filled($description) ? 'yes' : 'no';
                    $row['attributes_found'] = count($attributes);

                    $available = (int) ($applyPhotos && count($images) > 0)
                        + (int) ($applyDescription && filled($description))
                        + (int) ($applyAttributes && count($attributes) > 0);

                    $row['status'] = match (true) {
                        $available === 0 => 'kaspi_no_data',
                        default => 'kaspi_imported (planned)',
                    };
                    $row['error'] = $available === 0 ? $this->noDataDiagnostic($product, $images, 0, 0) : '';

                    if ($available === 0) {
                        $noData++;
                    } else {
                        $imported++;
                    }
                } else {
                    // Fetch
                    $response = Http::timeout(30)
                        ->withHeaders(['User-Agent' => 'AutohimiyaKzBot/1.0 (+https://autohimiki.kz)'])
                        ->get($kaspiUrl);

                    if (! $response->successful()) {
                        $row['status'] = 'kaspi_blocked';
                        $row['error'] = 'HTTP '.$response->status();
                        $this->updateOrCreateTask($product, 'kaspi_blocked', null, 'HTTP '.$response->status());
                        $blocked++;
                        $rows[] = $row;
                        $bar->advance();
                        continue;
                    }

                    // Parse
                    $payload = $parser->parse($response->body(), $kaspiUrl);
                    $images = (array) data_get($payload, 'cleaned.images', []);
                    $description = data_get($payload, 'cleaned.description') ?: $fallbackService->generate($product, data_get($payload, 'cleaned.attributes', []));
                    $attributes = (array) data_get($payload, 'cleaned.attributes', []);

                    $row['photos_found'] = count($images);
                    $row['description_found'] = filled($description) ? 'yes' : 'no';
                    $row['attributes_found'] = count($attributes);

                    // Create / update task
                    $task = $this->updateOrCreateTask($product, 'running', $payload);

                    // Publish
                    $result = $publisher->publish($task, [
                        'dry_run' => false,
                        'apply_photo' => $applyPhotos,
                        'apply_description' => $applyDescription,
                        'apply_attributes' => $applyAttributes,
                        'force_photo' => $forcePhotos,
                        'force_description' => $forceDescription,
                        'force_attributes' => $forceAttributes,
                        'replace_kaspi_attributes' => $forceAttributes,
                    ]);

                    $photosAdded = (int) ($result['photo']['added'] ?? 0);
                    $descAdded = (int) ($result['description']['added'] ?? 0);
                    $attrsAdded = (int) ($result['attributes']['added'] ?? 0);

                    $row['photos_to_save'] = $photosAdded;

                    $available = (int) ($applyPhotos && count($images) > 0)
                        + (int) ($applyDescription && filled($description))
                        + (int) ($applyAttributes && count($attributes) > 0);
                    $applied = (int) ($photosAdded > 0) + (int) ($descAdded > 0) + (int) ($attrsAdded > 0);

                    $importStatus = match (true) {
                        $available === 0 => 'kaspi_no_data',
                        $applied === $available => 'kaspi_imported',
                        $applied > 0 => 'kaspi_partial',
                        default => 'kaspi_no_data',
                    };

                    $task->update(['status' => $importStatus, 'finished_at' => now()]);
                    if ($importStatus === 'kaspi_no_data') {
                        $task->update(['error' => $this->noDataDiagnostic($product, $images, $photosAdded, $attrsAdded)]);
                        $row['error'] = $task->error;
                    }
                    $row['status'] = $importStatus;

                    match ($importStatus) {
                        'kaspi_imported' => $imported++,
                        'kaspi_partial' => $partial++,
                        default => $noData++,
                    };
                }
            } catch (Throwable $exception) {
                $safeError = Utf8Sanitizer::errorForDb($exception, 1000);
                $row['status'] = 'error';
                $row['error'] = mb_substr($safeError, 0, 80);
                try {
                    $this->updateOrCreateTask($product, 'error', null, $safeError);
                } catch (Throwable) {
                    // If saving the error itself fails, continue — the product is still counted as error
                }
                $errors++;
            }

            $rows[] = $row;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['product_id', 'sku', 'kaspi_url', 'photos_found', 'photos_to_save', 'description_found', 'attributes_found', 'status', 'error'],
            array_map(fn (array $r): array => array_values($r), $rows),
        );

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Processed', $total],
            [$dryRun ? 'Planned (kaspi_imported)' : 'kaspi_imported', $imported],
            ['kaspi_partial', $partial],
            ['kaspi_no_data', $noData],
            ['kaspi_blocked', $blocked],
            ['error', $errors],
        ]);

        if ($dryRun) {
            $this->newLine();
            $this->warn('Dry-run complete — nothing was saved. Remove --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    private function updateOrCreateTask(Product $product, string $status, ?array $payload, ?string $error = null): KaspiEnrichmentTask
    {
        $task = KaspiEnrichmentTask::query()
            ->where('product_id', $product->id)
            ->orderByDesc('updated_at')
            ->first();

        $safeError = $error !== null ? Utf8Sanitizer::forDb($error, 1000) : null;

        $fill = [
            'kaspi_product_url' => $product->kaspi_product_url,
            'status' => $status,
            'error' => $safeError,
            'started_at' => now(),
        ];

        if ($payload !== null) {
            $fill = array_merge($fill, [
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
        }

        if ($task) {
            $task->update($fill);

            return $task->refresh();
        }

        return KaspiEnrichmentTask::query()->create(array_merge($fill, [
            'product_id' => $product->id,
            'kaspi_merchant_sku' => $product->sku,
            'source' => 'kaspi_import_content',
        ]));
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(mb_strtolower((string) $value), ['true', '1', 'yes', 'on'], true);
    }

    private function noDataDiagnostic(Product $product, array $images, int $photosAdded, int $attributesAdded): string
    {
        if ($images !== [] && $photosAdded === 0) {
            if ($product->images()->where('source', 'kaspi')->exists()) {
                return 'photos_found_but_duplicate_image';
            }

            return 'photos_found_but_download_failed_or_duplicate_source';
        }

        if ($attributesAdded === 0 && (bool) $product->attributes_are_manual) {
            return 'attributes_found_but_protected_manual_attributes';
        }

        return 'no_usable_kaspi_content_found';
    }

    private function writeGuardWarningLog(Carbon $startedAt): void
    {
        SyncLog::query()->create([
            'source' => 'kaspi',
            'mode' => 'import-content',
            'command' => 'kaspi:import-content',
            'status' => 'warning',
            'started_at' => $startedAt,
            'finished_at' => now(),
            'duration_ms' => (int) $startedAt->diffInMilliseconds(now()),
            'processed_count' => 0,
            'created_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'error_count' => 1,
            'payload_summary' => [
                'reason' => 'KASPI_ENRICHMENT_ENABLED is disabled.',
                'dry_run' => false,
            ],
            'diagnostics' => [
                'warning' => 'Set KASPI_ENRICHMENT_ENABLED=true in .env, then run php artisan optimize:clear.',
                'command' => 'kaspi:import-content',
            ],
            'raw_payload' => [
                'options' => [
                    'limit' => $this->option('limit'),
                    'only_missing' => $this->option('only-missing'),
                    'force' => $this->option('force'),
                ],
            ],
            'error_message' => 'KASPI_ENRICHMENT_ENABLED is disabled; scheduled Kaspi import did not run.',
        ]);
    }
}
