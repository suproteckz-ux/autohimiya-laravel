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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Throwable;

class PalomaSyncRemainsService
{
    public function __construct(private readonly PalomaClient $client, private readonly PalomaCatalogAggregator $aggregator) {}

    public function sync(array $options = [], ?AutomationProgressReporterInterface $progress = null): array
    {
        $timeout = max(60, (int) ($options['timeout'] ?? 60));
        $lock = Cache::lock('paloma:sync-remains', $timeout);

        if (! $lock->get()) {
            return [
                'successful' => true,
                'warnings' => true,
                'message' => 'Paloma sync skipped because another run is already active.',
                'total_items' => 0,
                'processed_items' => 0,
                'created_count' => 0,
                'updated_count' => 0,
                'skipped_count' => 1,
                'failed_count' => 0,
                'rows' => [['paloma', 'skipped_overlap', null, null, 'already running']],
            ];
        }

        try {
            return $this->syncUnlocked($options, $progress);
        } finally {
            optional($lock)->release();
        }
    }

    private function syncUnlocked(array $options = [], ?AutomationProgressReporterInterface $progress = null): array
    {
        $progress ??= new NullProgressReporter();
        $startedAt = Carbon::now();
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $skuFilter = trim((string) ($options['sku'] ?? ''));
        $processed = $updated = $created = $skipped = $notMatched = $errors = 0;
        $duplicateAvailability = $invalidAvailability = $stockConflicts = 0;
        $rows = [];

        try {
            $rawOffers = $this->client->offers();
            $aggregatedOffers = collect($this->aggregator->aggregate($rawOffers));
            $feedSkus = $this->validFeedSkus($aggregatedOffers->all());

            if ($rawOffers === [] || $aggregatedOffers->isEmpty() || $feedSkus === []) {
                throw new RuntimeException('Paloma feed is empty or contains no valid SKU offers.');
            }

            $isPartialRun = $skuFilter !== '' || $limit > 0;
            $offers = $aggregatedOffers
                ->when($skuFilter !== '', fn ($items) => $items->filter(fn (PalomaOfferData $offer): bool => $offer->sku === $skuFilter))
                ->when($limit > 0, fn ($items) => $items->take($limit))
                ->values();

            $progress->start($offers->count(), 'Paloma: reading stock and prices.');

            $processOffers = function () use ($offers, $feedSkus, $isPartialRun, $dryRun, $progress, &$processed, &$updated, &$created, &$skipped, &$notMatched, &$errors, &$duplicateAvailability, &$invalidAvailability, &$stockConflicts, &$rows): void {
                foreach ($offers as $offer) {
                    $processed++;
                    $duplicateAvailability += $offer->duplicate_availability_count;
                    $invalidAvailability += $offer->invalid_availability_count;
                    $stockConflicts += $offer->has_stock_conflict ? 1 : 0;

                    if (blank($offer->sku)) {
                        $skipped++;
                        $progress->incrementSkipped();
                        $progress->advance(1, 'Paloma: skipped offer without SKU.');

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
                            if (! $dryRun) {
                                $product->save();
                            }
                            $created++;
                            $progress->incrementCreated();
                            $rows[] = [$offer->sku, 'created', $offer->price, $offer->stock, $offer->available ? 'yes' : 'no'];
                            $progress->advance(1, 'Paloma: created product '.$offer->sku);

                            continue;
                        }

                        if ((string) $product->product_status === ProductStatus::NEEDS_REVIEW && ! $needsReview) {
                            $data['product_status'] = ProductStatus::ACTIVE_SYNCED;
                        }

                        if (ProductSlugger::isBad($product->slug, $product)) {
                            $data['slug'] = ProductSlugger::uniqueFromName((string) $product->name, $offer->sku, $product->id);
                        }

                        if (! $dryRun) {
                            $product->fill($data)->save();
                        }
                        $updated++;
                        $progress->incrementUpdated();
                        $rows[] = [$offer->sku, $dryRun ? 'would_update' : 'updated', $offer->price, $offer->stock, $offer->available ? 'yes' : 'no'];
                        $progress->advance(1, 'Paloma: updated product '.$offer->sku);
                    } catch (Throwable $exception) {
                        $errors++;
                        $progress->incrementFailed();
                        $rows[] = [$offer->sku, 'error', $offer->price, $offer->stock, mb_substr($exception->getMessage(), 0, 80)];
                        $progress->advance(1, 'Paloma: product error '.$offer->sku);
                    }
                }

                if (! $dryRun && ! $isPartialRun) {
                    $notMatched = $this->zeroProductsMissingFromFeed($feedSkus, $rows);
                }
            };

            $dryRun ? $processOffers() : DB::transaction($processOffers);

            $stats = compact('processed', 'updated', 'created', 'skipped', 'notMatched', 'errors', 'duplicateAvailability', 'invalidAvailability', 'stockConflicts');
            $hasWarnings = $errors > 0 || $stockConflicts > 0 || $invalidAvailability > 0;
            if (! $dryRun) {
                $this->writeLog($startedAt, $hasWarnings ? 'warning' : 'success', $stats, $rows, null, $options);
            }
            $progress->finish('Paloma: sync finished.');

            return [
                'successful' => $errors === 0,
                'warnings' => $hasWarnings,
                'message' => 'Paloma sync complete. Products checked: '.$processed,
                'total_items' => $processed,
                'processed_items' => $processed,
                'created_count' => $created,
                'updated_count' => $updated,
                'skipped_count' => $skipped,
                'not_found_count' => $notMatched,
                'absent_zeroed_count' => $notMatched,
                'failed_count' => $errors,
                'duplicate_availability_count' => $duplicateAvailability,
                'invalid_availability_count' => $invalidAvailability,
                'stock_conflict_count' => $stockConflicts,
                'rows' => $rows,
            ];
        } catch (Throwable $exception) {
            $errors++;
            $stats = compact('processed', 'updated', 'created', 'skipped', 'notMatched', 'errors', 'duplicateAvailability', 'invalidAvailability', 'stockConflicts');
            $this->writeLog($startedAt, 'failed', $stats, $rows, $exception->getMessage(), $options);

            throw $exception;
        }
    }

    private function writeLog(Carbon $startedAt, string $status, array $stats, array $rows, ?string $error = null, array $options = []): void
    {
        SyncLog::query()->create([
            'source' => 'paloma',
            'mode' => 'sync-remains',
            'command' => 'paloma:sync-remains',
            'status' => $status,
            'started_at' => $startedAt,
            'finished_at' => now(),
            'duration_ms' => (int) $startedAt->diffInMilliseconds(now()),
            'processed_count' => $stats['processed'] ?? 0,
            'created_count' => $stats['created'] ?? 0,
            'updated_count' => $stats['updated'] ?? 0,
            'skipped_count' => $stats['skipped'] ?? 0,
            'not_found_count' => $stats['notMatched'] ?? 0,
            'error_count' => $stats['errors'] ?? 0,
            'payload_summary' => $stats,
            'diagnostics' => [
                'rules' => 'Paloma sync replaces quantity/stock_quantity with the authoritative feed stock. Repeated SKU/availability snapshots are deduplicated by store before writing.',
                'force' => (bool) ($options['force'] ?? false),
                'timeout' => (int) ($options['timeout'] ?? 60),
            ],
            'raw_payload' => ['rows' => array_slice($rows, 0, 100)],
            'error_message' => $error,
        ]);
    }

    /**
     * @param array<int, PalomaOfferData> $offers
     * @return array<int, string>
     */
    private function validFeedSkus(array $offers): array
    {
        return collect($offers)
            ->pluck('sku')
            ->map(fn (?string $sku): string => trim((string) $sku))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int, string> $feedSkus
     * @param array<int, array<int, mixed>> $rows
     */
    private function zeroProductsMissingFromFeed(array $feedSkus, array &$rows): int
    {
        $zeroed = 0;

        $this->missingFromFeedQuery($feedSkus)
            ->orderBy('id')
            ->chunkById(200, function ($products) use (&$zeroed, &$rows): void {
                foreach ($products as $product) {
                    $product->forceFill([
                        'quantity' => 0,
                        'stock_quantity' => 0,
                        'availability' => false,
                        'availability_status' => 'out_of_stock',
                        'stock_source' => 'paloma',
                        'last_synced_at' => now(),
                    ])->save();

                    $zeroed++;

                    if (count($rows) < 100) {
                        $rows[] = [$product->paloma_sku ?: $product->sku ?: 'product:'.$product->id, 'zeroed_missing_from_feed', $product->price, 0, 'no'];
                    }
                }
            });

        return $zeroed;
    }

    /**
     * @param array<int, string> $feedSkus
     */
    private function missingFromFeedQuery(array $feedSkus): Builder
    {
        return Product::query()
            ->where(function (Builder $query) use ($feedSkus): void {
                $query->whereNull('paloma_sku')->orWhereNotIn('paloma_sku', $feedSkus);
            })
            ->where(function (Builder $query) use ($feedSkus): void {
                $query->whereNull('sku')->orWhereNotIn('sku', $feedSkus);
            });
    }

    private function initialName(PalomaOfferData $offer): string
    {
        return $offer->model ?: $offer->sku ?: 'Paloma product';
    }

    private function syncErrorFor(PalomaOfferData $offer): ?string
    {
        $errors = [];

        if ($offer->has_model_conflict) {
            $errors[] = 'Aggregated Paloma SKU has different model/name values.';
        }

        if ($offer->has_price_conflict) {
            $errors[] = 'Aggregated Paloma SKU has different prices; minimum price was selected.';
        }

        if ($offer->has_stock_conflict) {
            $errors[] = 'Aggregated Paloma SKU has different stock values for the same store; maximum stock was selected for that store.';
        }

        if ($offer->invalid_availability_count > 0) {
            $errors[] = 'Paloma availability contained invalid stockCount values that were ignored.';
        }

        return $errors === [] ? null : implode(' ', $errors);
    }

    private function needsReview(PalomaOfferData $offer): bool
    {
        return $offer->has_model_conflict || $offer->has_price_conflict || $offer->has_stock_conflict || $offer->invalid_availability_count > 0;
    }
}
