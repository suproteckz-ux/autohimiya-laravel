<?php

namespace App\Services\Catalog;

use App\Models\Product;
use Illuminate\Support\Facades\Storage;

class ProductImageSuggestionService
{
    public function suggest(Product $product): array
    {
        if (filled($product->primary_image)) {
            return [
                'source' => 'local_storage',
                'confidence' => 95,
                'reason' => 'Product already has primary_image.',
                'images' => [[
                    'url' => Storage::disk('public')->url($product->primary_image),
                    'path' => $product->primary_image,
                    'source' => 'local_storage',
                    'confidence' => 95,
                    'reason' => 'Existing primary image.',
                ]],
            ];
        }

        $directories = array_filter([
            $product->opencart_product_id ? 'products/opencart/'.$product->opencart_product_id : null,
            $product->paloma_sku ? 'products/paloma/'.$product->paloma_sku : null,
            $product->sku ? 'products/sku/'.$product->sku : null,
        ]);

        foreach ($directories as $directory) {
            $files = collect(Storage::disk('public')->files($directory))
                ->filter(fn (string $path): bool => preg_match('/\.(jpe?g|png|webp)$/i', $path) === 1)
                ->values();

            if ($files->isNotEmpty()) {
                return [
                    'source' => 'local_storage',
                    'confidence' => 80,
                    'reason' => 'Found image in local storage.',
                    'images' => $files->take(5)->map(fn (string $path): array => [
                        'url' => Storage::disk('public')->url($path),
                        'path' => $path,
                        'source' => 'local_storage',
                        'confidence' => 80,
                        'reason' => 'Local storage candidate.',
                    ])->all(),
                ];
            }
        }

        return [
            'source' => 'external_search_stub',
            'confidence' => 0,
            'reason' => 'No local image candidate found. External search is intentionally disabled.',
            'images' => [],
        ];
    }
}
