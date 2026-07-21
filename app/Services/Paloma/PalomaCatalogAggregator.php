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
            ])
            ->values()
            ->all();

        return new PalomaOfferData(
            sku: $first->sku,
            model: $first->model,
            price: $prices->isEmpty() ? null : (float) $prices->min(),
            stock: (int) collect($group)->sum('stock'),
            available: collect($group)->contains(fn (PalomaOfferData $offer): bool => $offer->available && $offer->stock > 0),
            payload_hash: hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
            raw_offers_count: count($group),
            has_model_conflict: $models->count() > 1,
            has_price_conflict: $prices->unique()->count() > 1,
        );
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
