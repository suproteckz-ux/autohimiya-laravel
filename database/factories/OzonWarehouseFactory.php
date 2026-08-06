<?php

namespace Database\Factories;

use App\Models\OzonAccount;
use App\Models\OzonWarehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OzonWarehouse> */
class OzonWarehouseFactory extends Factory
{
    protected $model = OzonWarehouse::class;

    public function definition(): array
    {
        return [
            'ozon_account_id' => OzonAccount::factory(),
            'ozon_warehouse_id' => fake()->unique()->numerify('####################'),
            'name' => 'Склад '.fake()->unique()->word(),
            'status' => 'active',
            'is_active' => true,
            'is_default' => false,
            'raw_payload' => ['source' => 'factory'],
            'synced_at' => now(),
        ];
    }
}
