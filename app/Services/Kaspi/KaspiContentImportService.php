<?php

namespace App\Services\Kaspi;

use App\Models\KaspiEnrichmentTask;
use App\Models\Product;
use App\Models\SyncLog;
use App\Services\Automation\AutomationProgressReporterInterface;
use App\Services\Automation\NullProgressReporter;
use App\Services\Catalog\CatalogDescriptionFallbackService;
use App\Support\Utf8Sanitizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

class KaspiContentImportService
{
    public function __construct(private readonly KaspiEnrichmentParser $parser, private readonly KaspiDraftPublisher $publisher, private readonly CatalogDescriptionFallbackService $fallbackService) {}

    public function import(array $options = [], ?AutomationProgressReporterInterface $progress = null): array
    {
        $progress ??= new NullProgressReporter();
        $startedAt = Carbon::now();
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $delayMs = max(0, (int) ($options['delay_ms'] ?? 3000));
        $limit = max(0, (int) ($options['limit'] ?? 100));
        $applyPhotos = $this->truthy($options['photos'] ?? true);
        $applyDescription = $this->truthy($options['description'] ?? true);
        $applyAttributes = $this->truthy($options['attributes'] ?? true);
        $force = $this->truthy($options['force'] ?? false);
        $forcePhotos = $force || (bool) ($options['force_photos'] ?? false);
        $forceDescription = $force || (bool) ($options['force_description'] ?? false);
        $forceAttributes = $force || (bool) ($options['force_attributes'] ?? false);
        $onlyMissing = $this->truthy($options['only_missing'] ?? false);

        if (! $dryRun && ! config('services.kaspi.enrichment_enabled')) { $this->writeGuardWarningLog($startedAt, $options); throw new \RuntimeException('KASPI_ENRICHMENT_ENABLED is not set. Enable it in .env before running mass import.'); }

        $query = Product::query()->with(['images', 'attributes', 'kaspiEnrichmentTasks' => fn ($q) => $q->orderByDesc('updated_at')])->eligibleForKaspiEnrichment()->whereNotNull('kaspi_product_url')->where('kaspi_product_url', '<>', '')->orderBy('id');
        if (filled($options['product_id'] ?? null)) { $query->where('id', (int) $options['product_id']); }
        if (filled($options['ids'] ?? null)) { $ids = array_filter(array_map('intval', explode(',', (string) $options['ids']))); if ($ids !== []) { $query->whereIn('id', $ids); } }
        if (filled($options['sku'] ?? null)) { $query->where('sku', (string) $options['sku']); }
        if ($onlyMissing) { $query->whereDoesntHave('images', fn ($q) => $q->where('source', 'kaspi')); }
        if ($limit > 0) { $query->limit($limit); }

        $products = $query->get();
        $total = $products->count();
        $imported = $partial = $noData = $blocked = $errors = 0;
        $progress->start($total, 'Kaspi: импорт контента.');

        foreach ($products as $index => $product) {
            if ($index > 0 && $delayMs > 0 && ! $dryRun) { usleep($delayMs * 1000); }
            $kaspiUrl = (string) $product->kaspi_product_url;
            try {
                $response = Http::timeout(30)->withHeaders(['User-Agent' => 'AutohimiyaKzBot/1.0 (+https://autohimiki.kz)'])->get($kaspiUrl);
                if (! $response->successful()) { if (! $dryRun) { $this->updateOrCreateTask($product, 'kaspi_blocked', null, 'HTTP '.$response->status()); } $blocked++; $progress->incrementFailed(); $progress->advance(1, 'Kaspi: HTTP '.$response->status()); continue; }
                $payload = $this->parser->parse($response->body(), $kaspiUrl);
                $images = (array) data_get($payload, 'cleaned.images', []);
                $description = data_get($payload, 'cleaned.description') ?: $this->fallbackService->generate($product, data_get($payload, 'cleaned.attributes', []));
                $attributes = (array) data_get($payload, 'cleaned.attributes', []);
                $available = (int) ($applyPhotos && count($images) > 0) + (int) ($applyDescription && filled($description)) + (int) ($applyAttributes && count($attributes) > 0);
                if ($dryRun) { $available === 0 ? $noData++ : $imported++; $progress->advance(1, 'Kaspi dry-run: '.$product->id); continue; }
                $task = $this->updateOrCreateTask($product, 'running', $payload);
                $result = $this->publisher->publish($task, ['dry_run' => false, 'apply_photo' => $applyPhotos, 'apply_description' => $applyDescription, 'apply_attributes' => $applyAttributes, 'force_photo' => $forcePhotos, 'force_description' => $forceDescription, 'force_attributes' => $forceAttributes, 'replace_kaspi_attributes' => $forceAttributes]);
                $photosAdded = (int) ($result['photo']['added'] ?? 0); $descAdded = (int) ($result['description']['added'] ?? 0); $attrsAdded = (int) ($result['attributes']['added'] ?? 0); $applied = (int) ($photosAdded > 0) + (int) ($descAdded > 0) + (int) ($attrsAdded > 0);
                $importStatus = match (true) { $available === 0 => 'kaspi_no_data', $applied === $available => 'kaspi_imported', $applied > 0 => 'kaspi_partial', default => 'kaspi_no_data' };
                $task->update(['status' => $importStatus, 'finished_at' => now()]);
                if ($importStatus === 'kaspi_no_data') { $task->update(['error' => $this->noDataDiagnostic($product, $images, $photosAdded, $attrsAdded)]); }
                match ($importStatus) { 'kaspi_imported' => $imported++, 'kaspi_partial' => $partial++, default => $noData++ };
                $progress->incrementUpdated(); $progress->advance(1, 'Kaspi: импортирован товар '.$product->id);
            } catch (Throwable $exception) {
                $safeError = Utf8Sanitizer::errorForDb($exception, 1000);
                if (! $dryRun) { try { $this->updateOrCreateTask($product, 'error', null, $safeError); } catch (Throwable) {} }
                $errors++; $progress->incrementFailed(); $progress->advance(1, 'Kaspi: ошибка товара '.$product->id);
            }
        }

        return ['successful' => $errors === 0, 'warnings' => ($errors + $blocked) > 0, 'message' => 'Kaspi import complete. Products checked: '.$total, 'total_items' => $total, 'processed_items' => $total, 'updated_count' => $imported + $partial, 'skipped_count' => $noData, 'failed_count' => $errors + $blocked, 'metrics' => compact('imported', 'partial', 'noData', 'blocked', 'errors')];
    }

    private function updateOrCreateTask(Product $product, string $status, ?array $payload, ?string $error = null): KaspiEnrichmentTask
    {
        $task = KaspiEnrichmentTask::query()->where('product_id', $product->id)->orderByDesc('updated_at')->first();
        $safeError = $error !== null ? Utf8Sanitizer::forDb($error, 1000) : null;
        $fill = ['kaspi_product_url' => $product->kaspi_product_url, 'status' => $status, 'error' => $safeError, 'started_at' => now()];
        if ($payload !== null) { $fill = array_merge($fill, ['parsed_title' => ['value' => $payload['name'] ?? null], 'parsed_images' => $payload['images'] ?? [], 'parsed_description' => $payload['description'] ?? null, 'parsed_attributes' => $payload['attributes'] ?? [], 'parsed_brand' => $payload['brand'] ?? null, 'parsed_category' => $payload['category'] ?? null, 'raw_payload' => $payload, 'finished_at' => now(), 'error' => null]); }
        if ($task) { $task->update($fill); return $task->refresh(); }
        return KaspiEnrichmentTask::query()->create(array_merge($fill, ['product_id' => $product->id, 'kaspi_merchant_sku' => $product->sku, 'source' => 'kaspi_import_content']));
    }

    private function truthy(mixed $value): bool { if (is_bool($value)) { return $value; } return in_array(mb_strtolower((string) $value), ['true', '1', 'yes', 'on'], true); }
    private function noDataDiagnostic(Product $product, array $images, int $photosAdded, int $attributesAdded): string { if ($images !== [] && $photosAdded === 0) { return $product->images()->where('source', 'kaspi')->exists() ? 'photos_found_but_duplicate_image' : 'photos_found_but_download_failed_or_duplicate_source'; } if ($attributesAdded === 0 && (bool) $product->attributes_are_manual) { return 'attributes_found_but_protected_manual_attributes'; } return 'no_usable_kaspi_content_found'; }
    private function writeGuardWarningLog(Carbon $startedAt, array $options): void { SyncLog::query()->create(['source' => 'kaspi', 'mode' => 'import-content', 'command' => 'kaspi:import-content', 'status' => 'warning', 'started_at' => $startedAt, 'finished_at' => now(), 'duration_ms' => (int) $startedAt->diffInMilliseconds(now()), 'processed_count' => 0, 'created_count' => 0, 'updated_count' => 0, 'skipped_count' => 0, 'error_count' => 1, 'payload_summary' => ['reason' => 'KASPI_ENRICHMENT_ENABLED is disabled.', 'dry_run' => false], 'diagnostics' => ['warning' => 'Set KASPI_ENRICHMENT_ENABLED=true in .env, then run php artisan optimize:clear.', 'command' => 'kaspi:import-content'], 'raw_payload' => ['options' => $options], 'error_message' => 'KASPI_ENRICHMENT_ENABLED is disabled; scheduled Kaspi import did not run.']); }
}