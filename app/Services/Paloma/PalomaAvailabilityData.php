<?php

namespace App\Services\Paloma;

final readonly class PalomaAvailabilityData
{
    public function __construct(
        public ?string $storeId,
        public int $stockCount,
        public bool $available,
        public string $payload_hash,
    ) {
    }

    public function effectiveStock(): int
    {
        return $this->available ? max(0, $this->stockCount) : 0;
    }

    public function storeKey(): string
    {
        $storeId = trim((string) $this->storeId);

        return $storeId === '' ? 'single-source' : 'store:'.mb_strtolower($storeId);
    }

    public function duplicateKey(): string
    {
        return implode('|', [
            $this->storeKey(),
            (string) $this->effectiveStock(),
            $this->available ? '1' : '0',
        ]);
    }
}
