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
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class PalomaSyncRemainsCommand extends Command
{
    protected $signature = 'paloma:sync-remains
        {--dry-run}
        {--limit=0}
        {--sku=}
        {--force}
        {--timeout=60}';

    protected $description = 'Sync only Paloma price, stock, availability and sync metadata.';

    public function handle(PalomaClient $client, PalomaCatalogAggregator $aggregator): int
    {
        $startedAt = Carbon::now();
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $skuFilter = trim((string) $this->option('sku'));
        $processed = $updated = $created = $skipped = $notMatched = $errors = 0;
        $rows = [];

        try {
            $offers = collect($aggregator->aggregate($client->offers()))
                ->when($skuFilter !== '', fn ($items) => $items->filter(fn (PalomaOfferData $offer): bool => $offer->sku === $skuFilter))
                ->when($limit > 0, fn ($items) => $items->take($limit))
                ->values();

            foreach ($offers as $offer) {
                $processed++;

                if (blank($offer->sku)) {
                    $skipped++;
                    continue;
                }

                try {
                    $product = Product::query()
                        ->where('paloma_sku', $offer->sku)
                        ->orWhere('sku', $offer->sku)
                        ->first();

                    $available = (int) $offer->stock > 0;
                    $needsReview = $this->needsReview($offer);
                    $data = [
                        'paloma_sku' => $offer->sku,
                        'price' => $offer->price,
                        'quantity' => $offer->stock,
                        'stock_quantity' => $offer->stock,
                        // TODO: stock_quantity is a deprecated duplicate kept mirrored to quantity.
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
                        $rows[] = [$offer->sku, 'created', $offer->price, $offer->stock, $offer->available ? 'yes' : 'no'];
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
                    $rows[] = [$offer->sku, $dryRun ? 'would_update' : 'updated', $offer->price, $offer->stock, $offer->available ? 'yes' : 'no'];
                } catch (Throwable $exception) {
                    $errors++;
                    $rows[] = [$offer->sku, 'error', $offer->price, $offer->stock, mb_substr($exception->getMessage(), 0, 80)];
                }
            }

            if (! $dryRun) {
                $this->writeLog($startedAt, 'success', compact('processed', 'updated', 'created', 'skipped', 'notMatched', 'errors'), $rows);
            }
        } catch (Throwable $exception) {
            $errors++;
            $this->writeLog($startedAt, 'failed', compact('processed', 'updated', 'created', 'skipped', 'notMatched', 'errors'), $rows, $exception->getMessage());
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['sku', 'status', 'price', 'quantity', 'availability'], $rows);
        $this->table(['Metric', 'Count'], [
            ['processed', $processed],
            ['updated', $updated],
            ['created', $created],
            ['skipped', $skipped],
            ['not_matched', $notMatched],
            ['errors', $errors],
        ]);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function writeLog(Carbon $startedAt, string $status, array $stats, array $rows, ?string $error = null): void
    {
        $payload = [
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
                'rules' => 'Paloma sync updated only price, quantity, availability and sync metadata.',
                'force' => (bool) $this->option('force'),
                'timeout' => (int) $this->option('timeout'),
            ],
            'raw_payload' => ['rows' => array_slice($rows, 0, 100)],
            'error_message' => $error,
        ];

        SyncLog::query()->create($payload);
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

        return $errors === [] ? null : implode(' ', $errors);
    }

    private function needsReview(PalomaOfferData $offer): bool
    {
        return $offer->has_model_conflict || $offer->has_price_conflict;
    }
}
