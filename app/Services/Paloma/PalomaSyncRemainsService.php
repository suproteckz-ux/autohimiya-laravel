<?php

namespace App\Services\Paloma;

use App\Models\Product;
use App\Models\SyncLog;
use App\Services\Automation\AutomationProgressReporterInterface;
use App\Services\Automation\NullProgressReporter;
use App\Services\Catalog\DefaultCategoryResolver;
use App\Support\ProductSlugger;
use App\Support\ProductStatus;
use Illuminate\Support\Carbon;
use Throwable;

class PalomaSyncRemainsService
{
    public function __construct(private readonly PalomaClient $client, private readonly PalomaCatalogAggregator $aggregator) {}

    public function sync(array $options = [], ?AutomationProgressReporterInterface $progress = null): array
    {
        $progress ??= new NullProgressReporter();
        $startedAt = Carbon::now();
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $skuFilter = trim((string) ($options['sku'] ?? ''));
        $processed = $updated = $created = $skipped = $notMatched = $errors = 0;
        $rows = [];

        try {
            $offers = collect($this->aggregator->aggregate($this->client->offers()))
                ->when($skuFilter !== '', fn ($items) => $items->filter(fn (PalomaOfferData $offer): bool => $offer->sku === $skuFilter))
                ->when($limit > 0, fn ($items) => $items->take($limit))
                ->values();
            $progress->start($offers->count(), 'Paloma: получение остатков и цен.');

            foreach ($offers as $offer) {
                $processed++;
                if (blank($offer->sku)) {
                    $skipped++;
                    $progress->incrementSkipped();
                    $progress->advance(1, 'Paloma: пропущен товар без SKU.');
                    continue;
                }

                try {
                    $product = Product::query()->where('paloma_sku', $offer->sku)->orWhere('sku', $offer->sku)->first();
                    $available = (int) $offer->stock > 0;
                    $needsReview = $this->needsReview($offer);
                    $data = [
                        'paloma_sku' => $offer->sku,
                        'price' => $offer->price,
                        'quantity' => $offer->stock,
                        'stock_quantity' => $offer->stock,
                        'availability' => $available,
                        'availability_status' => $available ? 'in_stock' : 'out_of_stock',
                        'sync_status' => $needsReview ? 'conflict' : 'matched',
                        'price_source' => 'paloma',
                        'stock_source' => 'paloma',
                        'paloma_payload_hash' => $offer->payload_hash,
                        'last_synced_at' => now(),
                        'sync_error' => $this->syncErrorFor($offer),
                    ];

                    if (! $product) {
                        $product = new Product([
                            'sku' => $offer->sku,
                            'model' => $offer->model,
                            'name' => $this->initialName($offer),
                            'slug' => ProductSlugger::uniqueFromName($this->initialName($offer), $offer->sku),
                            'product_status' => $needsReview ? ProductStatus::NEEDS_REVIEW : ProductStatus::ACTIVE_SYNCED,
                            'category_id' => $dryRun ? null : app(DefaultCategoryResolver::class)->getOrCreateNewProductsCategoryId(),
                            'category_is_manual' => false,
                        ]);
                        $product->fill($data);
                        if (! $dryRun) { $product->save(); }
                        $created++;
                        $progress->incrementCreated();
                        $rows[] = [$offer->sku, 'created', $offer->price, $offer->stock, $offer->available ? 'yes' : 'no'];
                        $progress->advance(1, 'Paloma: создан товар '.$offer->sku);
                        continue;
                    }

                    if ((string) $product->product_status === ProductStatus::NEEDS_REVIEW && ! $needsReview) { $data['product_status'] = ProductStatus::ACTIVE_SYNCED; }
                    if (ProductSlugger::isBad($product->slug, $product)) { $data['slug'] = ProductSlugger::uniqueFromName((string) $product->name, $offer->sku, $product->id); }
                    if (! $dryRun) { $product->fill($data)->save(); }
                    $updated++;
                    $progress->incrementUpdated();
                    $rows[] = [$offer->sku, $dryRun ? 'would_update' : 'updated', $offer->price, $offer->stock, $offer->available ? 'yes' : 'no'];
                    $progress->advance(1, 'Paloma: обновлен товар '.$offer->sku);
                } catch (Throwable $exception) {
                    $errors++;
                    $progress->incrementFailed();
                    $rows[] = [$offer->sku, 'error', $offer->price, $offer->stock, mb_substr($exception->getMessage(), 0, 80)];
                    $progress->advance(1, 'Paloma: ошибка по товару '.$offer->sku);
                }
            }

            $stats = compact('processed', 'updated', 'created', 'skipped', 'notMatched', 'errors');
            if (! $dryRun) { $this->writeLog($startedAt, $errors > 0 ? 'warning' : 'success', $stats, $rows, null, $options); }
            $progress->finish('Paloma: синхронизация завершена.');

            return ['successful' => $errors === 0, 'warnings' => $errors > 0, 'message' => 'Paloma sync complete. Products checked: '.$processed, 'total_items' => $processed, 'processed_items' => $processed, 'created_count' => $created, 'updated_count' => $updated, 'skipped_count' => $skipped, 'failed_count' => $errors, 'rows' => $rows];
        } catch (Throwable $exception) {
            $errors++;
            $stats = compact('processed', 'updated', 'created', 'skipped', 'notMatched', 'errors');
            $this->writeLog($startedAt, 'failed', $stats, $rows, $exception->getMessage(), $options);
            throw $exception;
        }
    }

    private function writeLog(Carbon $startedAt, string $status, array $stats, array $rows, ?string $error = null, array $options = []): void
    {
        SyncLog::query()->create(['source' => 'paloma', 'mode' => 'sync-remains', 'command' => 'paloma:sync-remains', 'status' => $status, 'started_at' => $startedAt, 'finished_at' => now(), 'duration_ms' => (int) $startedAt->diffInMilliseconds(now()), 'processed_count' => $stats['processed'] ?? 0, 'created_count' => $stats['created'] ?? 0, 'updated_count' => $stats['updated'] ?? 0, 'skipped_count' => $stats['skipped'] ?? 0, 'not_found_count' => $stats['notMatched'] ?? 0, 'error_count' => $stats['errors'] ?? 0, 'payload_summary' => $stats, 'diagnostics' => ['rules' => 'Paloma sync updated only price, quantity, availability and sync metadata.', 'force' => (bool) ($options['force'] ?? false), 'timeout' => (int) ($options['timeout'] ?? 60)], 'raw_payload' => ['rows' => array_slice($rows, 0, 100)], 'error_message' => $error]);
    }

    private function initialName(PalomaOfferData $offer): string { return $offer->model ?: $offer->sku ?: 'Paloma product'; }
    private function syncErrorFor(PalomaOfferData $offer): ?string { $errors = []; if ($offer->has_model_conflict) { $errors[] = 'Aggregated Paloma SKU has different model/name values.'; } if ($offer->has_price_conflict) { $errors[] = 'Aggregated Paloma SKU has different prices; minimum price was selected.'; } return $errors === [] ? null : implode(' ', $errors); }
    private function needsReview(PalomaOfferData $offer): bool { return $offer->has_model_conflict || $offer->has_price_conflict; }
}