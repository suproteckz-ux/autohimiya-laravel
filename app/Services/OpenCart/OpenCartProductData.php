<?php

namespace App\Services\OpenCart;

final class OpenCartProductData
{
    public function __construct(
        public int $product_id,
        public ?string $sku,
        public ?string $model,
        public ?string $name = null,
    ) {
    }
}
