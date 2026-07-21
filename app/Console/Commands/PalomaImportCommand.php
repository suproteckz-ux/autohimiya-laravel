<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\SyncLog;
use App\Services\Catalog\DefaultCategoryResolver;
use App\Services\Paloma\PalomaCatalogAggregator;
use App\Services\Paloma\PalomaClient;
use App\Services\Paloma\PalomaOfferData;
use App\Support\ProductStatus;
use App\Support\ProductSlugger;
use App\Support\Utf8Sanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PalomaImportCommand extends Command
{
    protected $signature = 'paloma:import {--dry-run : Parse and audit Paloma XML without database writes} {--apply : Apply Paloma catalog import}';

    protected $description = 'Safely import Paloma catalog offers into products.';

    public function handle(PalomaClient $client, PalomaCatalogAggregator $aggregator): int
    {
        if ($this->option('dry-run') === $this->option('apply')) {
            $this->error('Run exactly one mode: --dry-run or --apply.');

            return self::FAILURE;
        }

        $rawOffers = $client->offers();
        $offers = $aggregator->aggregate($rawOffers);
        $stats = self::buildStats($rawOffers, $offers);

        if ($this->option('dry-run')) {
            $this->info('Paloma import dry-run. No database writes were made.');
            $this->table(['Metric', 'Value'], self::statsRows($stats));

            return self::SUCCESS;
        }

        return $this->apply($offers, $stats);
    }

    /**
     * @param array<int, PalomaOfferData> $offers
     * @param array<string, mixed> $stats
     */
    private function apply(array $offers, array $stats): int
    {
        $startedAt = Carbon::now();
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;
        $defaultCategoryId = null;

        try {
            DB::transaction(function () use ($offers, &$defaultCategoryId, &$created, &$updated, &$skipped, &$errors): void {
                foreach ($offers as $offer) {
                    if (blank($offer->sku)) {
                        $skipped++;

                        continue;
                    }

                    try {
                        $product = Product::query()->firstOrNew(['paloma_sku' => $offer->sku]);
                        $exists = $product->exists;
                        $needsReview = $offer->has_model_conflict || $offer->has_price_conflict;
                        $available = (int) $offer->stock > 0;

                        $data = [
                            'paloma_sku' => $offer->sku,
                            'price' => $offer->price,
                            'quantity' => $offer->stock,
                            'stock_quantity' => $offer->stock,
                            // TODO: stock_quantity is a deprecated duplicate kept mirrored to quantity.
                            'availability' => $available,
                            'availability_status' => $available ? 'in_stock' : 'out_of_stock',
                            'product_status' => $needsReview ? ProductStatus::NEEDS_REVIEW : ProductStatus::ACTIVE_SYNCED,
                            'sync_status' => $needsReview ? 'conflict' : 'matched',
                            'price_source' => 'paloma',
                            'stock_source' => 'paloma',
                            'paloma_payload_hash' => $offer->payload_hash,
                            'last_synced_at' => now(),
                            'sync_error' => $this->syncErrorFor($offer),
                        ];

                        if (! $exists) {
                            $data['sku'] = $offer->sku;
                            $data['model'] = Utf8Sanitizer::forDb($offer->model, 255);
                            $data['name'] = $this->nameFor($offer);
                            $data['slug'] = ProductSlugger::uniqueFromName($data['name'], $offer->sku);
                            $defaultCategoryId ??= app(DefaultCategoryResolver::class)->getOrCreateNewProductsCategoryId();
                            $data['category_id'] = $defaultCategoryId;
                            $data['category_is_manual'] = false;
                        } elseif (blank($product->name)) {
                            $data['name'] = $this->nameFor($offer);
                        }

                        $candidate = clone $product;
                        $candidate->forceFill(array_merge($product->getAttributes(), $data));
                        if ($exists && ProductSlugger::isBad($product->slug, $candidate)) {
                            $data['slug'] = ProductSlugger::uniqueFromName((string) ($data['name'] ?? $product->name), $offer->sku, $product->id);
                        }

                        $product->fill($data);

                        $product->save();

                        $exists ? $updated++ : $created++;
                    } catch (\Throwable) {
                        $errors++;
                    }
                }
            });

            $this->writeSyncLog($startedAt, 'success', $stats, $created, $updated, $skipped, $errors);
        } catch (\Throwable $exception) {
            $this->writeSyncLog($startedAt, 'failed', $stats, $created, $updated, $skipped, $errors + 1, $exception->getMessage());
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Paloma import applied.');
        $this->table(['Metric', 'Value'], [
            ['offers', $stats['offers_count']],
            ['unique products by SKU', $stats['unique_products_by_sku']],
            ['created', $created],
            ['updated', $updated],
            ['skipped', $skipped],
            ['duplicate offer groups', $stats['duplicate_offer_groups']],
            ['errors', $errors],
        ]);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<int, PalomaOfferData> $rawOffers
     * @param array<int, PalomaOfferData> $aggregatedOffers
     * @return array<string, mixed>
     */
    public static function buildStats(array $rawOffers, array $aggregatedOffers): array
    {
        $skuCounts = collect($rawOffers)->pluck('sku')->filter()->countBy();
        $prices = collect($aggregatedOffers)->pluck('price')->filter(fn (?float $price): bool => $price !== null);

        return [
            'offers_count' => count($rawOffers),
            'raw_offers_count' => count($rawOffers),
            'unique_products_by_sku' => count($aggregatedOffers),
            'sku_count' => count($aggregatedOffers),
            'duplicate_offer_groups' => $skuCounts->filter(fn (int $count): bool => $count > 1)->count(),
            'duplicate_sku_count' => $skuCounts->filter(fn (int $count): bool => $count > 1)->count(),
            'total_aggregated_stock' => collect($aggregatedOffers)->sum('stock'),
            'min_price' => $prices->isEmpty() ? null : $prices->min(),
            'max_price' => $prices->isEmpty() ? null : $prices->max(),
            'without_sku_count' => collect($rawOffers)->filter(fn (PalomaOfferData $offer): bool => blank($offer->sku))->count(),
            'without_price_count' => collect($rawOffers)->filter(fn (PalomaOfferData $offer): bool => $offer->price === null)->count(),
            'model_conflict_groups' => collect($aggregatedOffers)->filter(fn (PalomaOfferData $offer): bool => $offer->has_model_conflict)->count(),
            'price_conflict_groups' => collect($aggregatedOffers)->filter(fn (PalomaOfferData $offer): bool => $offer->has_price_conflict)->count(),
        ];
    }

    /**
     * @param array<string, mixed> $stats
     * @return array<int, array<int, mixed>>
     */
    public static function statsRows(array $stats): array
    {
        return [
            ['raw offers count', $stats['raw_offers_count']],
            ['unique products by SKU', $stats['unique_products_by_sku']],
            ['duplicate offer groups', $stats['duplicate_offer_groups']],
            ['total aggregated stock', $stats['total_aggregated_stock']],
            ['min price', $stats['min_price'] ?? 'n/a'],
            ['max price', $stats['max_price'] ?? 'n/a'],
            ['without SKU', $stats['without_sku_count']],
            ['without price', $stats['without_price_count']],
            ['model conflict groups', $stats['model_conflict_groups']],
            ['price conflict groups', $stats['price_conflict_groups']],
        ];
    }

    private function writeSyncLog(
        Carbon $startedAt,
        string $status,
        array $stats,
        int $created,
        int $updated,
        int $skipped,
        int $errors,
        ?string $errorMessage = null,
    ): void {
        SyncLog::query()->create([
            'source' => 'paloma',
            'mode' => 'import',
            'status' => $status,
            'started_at' => $startedAt,
            'finished_at' => now(),
            'offers_count' => $stats['raw_offers_count'],
            'created_count' => $created,
            'updated_count' => $updated,
            'skipped_count' => $skipped,
            'duplicate_count' => $stats['duplicate_offer_groups'],
            'error_count' => $errors,
            'payload_summary' => $stats,
            'error_message' => $errorMessage,
        ]);
    }

    private function nameFor(PalomaOfferData $offer): string
    {
        return Utf8Sanitizer::forDb($offer->model ?: $offer->sku ?: 'Paloma offer', 255);
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

        return $errors === [] ? null : implode(' ', $errors);
    }

}
