<?php

namespace App\Services\Paloma;

class PalomaCatalogAggregator
{
    /**
     * @param array<int, PalomaOfferData> $offers
     * @return array<int, PalomaOfferData>
     */
    public function aggregate(array $offers): array
    {
        $groups = [];

        foreach ($offers as $offer) {
            if (blank($offer->sku)) {
                continue;
            }

            $groups[$offer->sku][] = $offer;
        }

        return array_values(array_map(
            fn (array $group): PalomaOfferData => $this->aggregateGroup($group),
            $groups,
        ));
    }

    /**
     * @param array<int, PalomaOfferData> $group
     */
    private function aggregateGroup(array $group): PalomaOfferData
    {
        $first = $group[0];
        $prices = collect($group)
            ->pluck('price')
            ->filter(fn (?float $price): bool => $price !== null)
            ->values();

        $models = collect($group)
            ->pluck('model')
            ->filter()
            ->map(fn (string $model): string => $this->normalize($model))
            ->unique()
            ->values();

        $payload = collect($group)
            ->map(fn (PalomaOfferData $offer): array => [
                'sku' => $offer->sku,
                'model' => $offer->model,
                'price' => $offer->price,
                'stock' => $offer->stock,
                'available' => $offer->available,
                'payload_hash' => $offer->payload_hash,
                'availability_entries' => collect($offer->availability_entries)
                    ->map(fn (PalomaAvailabilityData $entry): array => [
                        'store_id' => $entry->storeId,
                        'stock_count' => $entry->stockCount,
                        'available' => $entry->available,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
        $stockSummary = $this->aggregateStock($group);

        return new PalomaOfferData(
            sku: $first->sku,
            model: $first->model,
            price: $prices->isEmpty() ? null : (float) $prices->min(),
            stock: $stockSummary['stock'],
            available: $stockSummary['stock'] > 0,
            payload_hash: hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
            raw_offers_count: count($group),
            has_model_conflict: $models->count() > 1,
            has_price_conflict: $prices->unique()->count() > 1,
            availability_entries: $stockSummary['entries'],
            duplicate_availability_count: $stockSummary['duplicates'],
            invalid_availability_count: collect($group)->sum('invalid_availability_count'),
            has_stock_conflict: $stockSummary['conflicts'] > 0 || collect($group)->contains(fn (PalomaOfferData $offer): bool => $offer->has_stock_conflict),
        );
    }

    /**
     * @param array<int, PalomaOfferData> $group
     * @return array{stock: int, entries: array<int, PalomaAvailabilityData>, duplicates: int, conflicts: int}
     */
    private function aggregateStock(array $group): array
    {
        $entries = [];

        foreach ($group as $offer) {
            if ($offer->availability_entries !== []) {
                array_push($entries, ...$offer->availability_entries);

                continue;
            }

            $entries[] = new PalomaAvailabilityData(
                storeId: null,
                stockCount: $offer->stock,
                available: $offer->available,
                payload_hash: $offer->payload_hash,
            );
        }

        $seen = [];
        $stores = [];
        $uniqueEntries = [];
        $duplicates = 0;
        $conflicts = 0;

        foreach ($entries as $entry) {
            $duplicateKey = $entry->duplicateKey();

            if (isset($seen[$duplicateKey])) {
                $duplicates++;

                continue;
            }

            $seen[$duplicateKey] = true;
            $storeKey = $entry->storeKey();
            $stock = $entry->effectiveStock();

            if (array_key_exists($storeKey, $stores) && $stores[$storeKey]['stock'] !== $stock) {
                $conflicts++;
                $stores[$storeKey]['stock'] = max($stores[$storeKey]['stock'], $stock);
                $stores[$storeKey]['entry'] = $stores[$storeKey]['stock'] === $stock ? $entry : $stores[$storeKey]['entry'];

                continue;
            }

            $stores[$storeKey] = ['stock' => $stock, 'entry' => $entry];
        }

        foreach ($stores as $store) {
            $uniqueEntries[] = $store['entry'];
        }

        return [
            'stock' => array_sum(array_column($stores, 'stock')),
            'entries' => $uniqueEntries,
            'duplicates' => $duplicates,
            'conflicts' => $conflicts,
        ];
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
