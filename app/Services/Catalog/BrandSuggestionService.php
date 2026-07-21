<?php

namespace App\Services\Catalog;

use App\Models\Brand;
use App\Models\Product;
use Illuminate\Support\Str;

class BrandSuggestionService
{
    public function suggest(Product $product): array
    {
        if ($product->brand_id && $product->brand) {
            return [
                'brand_id' => $product->brand_id,
                'brand_name' => $product->brand->display_name,
                'confidence' => 100,
                'reason' => 'Product already has brand.',
                'source' => 'current_product',
            ];
        }

        $name = Str::lower($product->display_name.' '.$product->model.' '.$product->sku.' '.$product->paloma_sku);

        $brand = Brand::query()
            ->whereNotNull('name')
            ->orderByRaw('LENGTH(name) DESC')
            ->get()
            ->first(fn (Brand $brand): bool => filled($brand->name) && str_contains($name, Str::lower($brand->display_name)));

        if ($brand) {
            return [
                'brand_id' => $brand->id,
                'brand_name' => $brand->display_name,
                'confidence' => 85,
                'reason' => 'Brand name found in product name or identifiers.',
                'source' => 'brand_name_rule',
            ];
        }

        return [
            'brand_id' => null,
            'brand_name' => '',
            'confidence' => 0,
            'reason' => 'No brand candidate found.',
            'source' => 'brand_name_rule',
        ];
    }
}
