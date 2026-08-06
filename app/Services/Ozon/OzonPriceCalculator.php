<?php

namespace App\Services\Ozon;

use App\Models\OzonAccount;
use App\Models\OzonProduct;
use App\Models\Product;
use InvalidArgumentException;

class OzonPriceCalculator
{
    public function calculate(Product $product, OzonAccount $account, ?OzonProduct $ozonProduct = null, ?string $multiplier = null, ?string $roundingRule = null): string
    {
        $source = $ozonProduct?->manual_ozon_price ?? $product->price;
        if (! is_numeric($source) || (float) $source <= 0) throw new InvalidArgumentException('Цена должна быть больше нуля.');
        $factor = $ozonProduct?->price_multiplier ?? $multiplier ?? $account->default_price_multiplier ?? '1';
        if (! is_numeric($factor) || (float) $factor <= 0) throw new InvalidArgumentException('Коэффициент цены должен быть больше нуля.');
        $sourceCents = $this->scaled((string) $source, 2);
        $factorUnits = $this->scaled((string) $factor, 4);
        $cents = intdiv(($sourceCents * $factorUnits) + 5000, 10000);
        $rule = $ozonProduct?->rounding_rule ?? $roundingRule ?? $account->rounding_rule ?? 'none';
        $value = $cents / 100;
        $value = match ($rule) {
            'integer' => round($value), 'nearest_10' => round($value / 10) * 10, 'nearest_100' => round($value / 100) * 100,
            'up_to_10' => ceil($value / 10) * 10, 'up_to_100' => ceil($value / 100) * 100, default => $value,
        };
        if ($value <= 0) throw new InvalidArgumentException('Рассчитанная цена должна быть больше нуля.');
        return number_format($value, 2, '.', '');
    }

    private function scaled(string $value, int $scale): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($value), 2), 2, '');
        $fraction = substr(str_pad(preg_replace('/\D/', '', $fraction), $scale, '0'), 0, $scale);
        return ((int) $whole * (10 ** $scale)) + (int) $fraction;
    }
}
