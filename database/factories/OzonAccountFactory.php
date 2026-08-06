<?php

namespace Database\Factories;

use App\Models\OzonAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OzonAccount> */
class OzonAccountFactory extends Factory
{
    protected $model = OzonAccount::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'client_id' => fake()->unique()->numerify('##########'),
            'api_key' => fake()->uuid(),
            'is_active' => true,
            'is_test_mode' => true,
            'default_price_multiplier' => 1,
            'batch_size' => 20,
            'sync_prices_enabled' => true,
            'sync_stocks_enabled' => true,
        ];
    }
}
