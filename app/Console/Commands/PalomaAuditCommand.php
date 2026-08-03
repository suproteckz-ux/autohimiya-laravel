<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Paloma\PalomaCatalogAggregator;
use App\Services\Paloma\PalomaClient;
use App\Services\Paloma\PalomaOfferData;
use Illuminate\Console\Command;

class PalomaAuditCommand extends Command
{
    protected $signature = 'paloma:audit
        {--sku=* : Limit diagnostics to one or more Paloma SKUs}
        {--limit=25 : Maximum mismatch/detail rows to display}';

    protected $description = 'Audit Paloma catalog XML without writing to the database.';

    public function handle(PalomaClient $client, PalomaCatalogAggregator $aggregator): int
    {
        $rawOffers = $client->offers();
        $offers = $aggregator->aggregate($rawOffers);
        $stats = PalomaImportCommand::buildStats($rawOffers, $offers);
        $skuFilter = collect((array) $this->option('sku'))
            ->map(fn (string $sku): string => trim($sku))
            ->filter()
            ->values();

        if ($skuFilter->isNotEmpty()) {
            $offers = collect($offers)
                ->filter(fn (PalomaOfferData $offer): bool => $skuFilter->contains($offer->sku))
                ->values()
                ->all();
        }

        $comparison = $this->compareDatabase($rawOffers, $offers, max(1, (int) $this->option('limit')));

        $this->info('Paloma catalog audit');
        $this->table(['Metric', 'Value'], PalomaImportCommand::statsRows($stats));
        $this->table(['DB metric', 'Value'], [
            ['matched products', $comparison['matched']],
            ['missing in DB', $comparison['missing_in_db']],
            ['exact stock matches', $comparison['exact']],
            ['stock mismatches', $comparison['mismatches']],
            ['DB greater than feed', $comparison['greater']],
            ['DB lower than feed', $comparison['lower']],
            ['DB equals feed x4', $comparison['equals_feed_times_4']],
        ]);

        if ($comparison['rows'] !== []) {
            $this->table(['sku', 'raw offers', 'stores', 'feed stock', 'db quantity', 'status', 'duplicates', 'invalid'], $comparison['rows']);
        }

        return self::SUCCESS;
    }

    /**
     * @param array<int, PalomaOfferData> $rawOffers
     * @param array<int, PalomaOfferData> $offers
     * @return array{matched: int, missing_in_db: int, exact: int, mismatches: int, greater: int, lower: int, equals_feed_times_4: int, rows: array<int, array<int, mixed>>}
     */
    private function compareDatabase(array $rawOffers, array $offers, int $limit): array
    {
        $skus = collect($offers)->pluck('sku')->filter()->values();
        $rawOfferCounts = collect($rawOffers)->pluck('sku')->filter()->countBy();
        $products = Product::query()
            ->whereIn('paloma_sku', $skus)
            ->orWhereIn('sku', $skus)
            ->get();
        $productsBySku = [];

        foreach ($products as $product) {
            foreach ([(string) $product->paloma_sku, (string) $product->sku] as $sku) {
                if ($sku !== '') {
                    $productsBySku[$sku] = $product;
                }
            }
        }

        $summary = [
            'matched' => 0,
            'missing_in_db' => 0,
            'exact' => 0,
            'mismatches' => 0,
            'greater' => 0,
            'lower' => 0,
            'equals_feed_times_4' => 0,
            'rows' => [],
        ];

        foreach ($offers as $offer) {
            if (blank($offer->sku)) {
                continue;
            }

            $product = $productsBySku[$offer->sku] ?? null;
            $status = 'missing_in_db';
            $dbQuantity = null;

            if (! $product) {
                $summary['missing_in_db']++;
            } else {
                $summary['matched']++;
                $dbQuantity = (int) $product->quantity;

                if ($dbQuantity === $offer->stock) {
                    $summary['exact']++;
                    $status = 'exact';
                } else {
                    $summary['mismatches']++;
                    $status = 'mismatch';

                    if ($dbQuantity > $offer->stock) {
                        $summary['greater']++;
                        $status = 'db_greater';
                    }

                    if ($dbQuantity < $offer->stock) {
                        $summary['lower']++;
                        $status = 'db_lower';
                    }

                    if ($offer->stock > 0 && $dbQuantity === $offer->stock * 4) {
                        $summary['equals_feed_times_4']++;
                        $status = 'db_equals_feed_x4';
                    }
                }
            }

            if ($status !== 'exact' && count($summary['rows']) < $limit) {
                $summary['rows'][] = [
                    $offer->sku,
                    (int) ($rawOfferCounts[$offer->sku] ?? $offer->raw_offers_count),
                    collect($offer->availability_entries)->map(fn ($entry) => $entry->storeId ?: 'single-source')->unique()->implode(', '),
                    $offer->stock,
                    $dbQuantity ?? 'n/a',
                    $status,
                    $offer->duplicate_availability_count,
                    $offer->invalid_availability_count,
                ];
            }
        }

        return $summary;
    }
}
