<?php

namespace App\Services\Paloma;

final readonly class PalomaOfferData
{
    /**
     * @param array<int, PalomaAvailabilityData> $availability_entries
     */
    public function __construct(
        public ?string $sku,
        public ?string $model,
        public ?float $price,
        public int $stock,
        public bool $available,
        public string $payload_hash,
        public int $raw_offers_count = 1,
        public bool $has_model_conflict = false,
        public bool $has_price_conflict = false,
        public array $availability_entries = [],
        public int $duplicate_availability_count = 0,
        public int $invalid_availability_count = 0,
        public bool $has_stock_conflict = false,
    ) {
    }
}
