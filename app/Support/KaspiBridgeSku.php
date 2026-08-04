<?php

namespace App\Support;

class KaspiBridgeSku
{
    public static function normalize(?string $sku): string
    {
        $sku = mb_strtolower(trim((string) $sku));
        $sku = preg_replace('/\s+/u', '', $sku) ?: $sku;

        return $sku;
    }
}
