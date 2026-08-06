<?php

namespace App\Services\Ozon\DTO;

use App\Models\Product;

final readonly class OzonPreparedProduct
{
    public function __construct(public Product $product, public array $snapshot, public OzonValidationResult $validation) {}
    public function toArray(): array { return $this->snapshot + $this->validation->toArray(); }
}
