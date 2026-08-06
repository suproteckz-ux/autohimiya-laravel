<?php

namespace Database\Factories;

use App\Enums\OzonProductStatus;
use App\Models\OzonAccount;
use App\Models\OzonProduct;
use App\Models\Product;
use App\Support\ProductStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OzonProduct> */
class OzonProductFactory extends Factory
{
    protected $model = OzonProduct::class;

    public function definition(): array
    {
        $sku = 'OZ-'.fake()->unique()->numerify('########');

        return [
            'ozon_account_id' => OzonAccount::factory(),
            'product_id' => fn (): int => Product::query()->create([
                'name' => 'Тестовый товар '.$sku,
                'slug' => strtolower($sku),
                'sku' => $sku,
                'price' => 1000,
                'quantity' => 1,
                'stock_quantity' => 1,
                'product_status' => ProductStatus::ACTIVE_SYNCED,
            ])->id,
            'offer_id' => $sku,
            'status' => OzonProductStatus::Draft,
            'price_sync_enabled' => true,
            'stock_sync_enabled' => true,
            'content_sync_enabled' => false,
        ];
    }
}
