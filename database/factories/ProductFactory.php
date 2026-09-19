<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->words(3, true);

        return [
            'name' => $name,
            'slug' => $this->faker->unique()->slug(3),
            'sku' => $this->faker->unique()->bothify('SKU-####??'),
            'quantity' => 0,
            'availability' => false,
            'product_status' => 'needs_review',
            'kaspi_available' => false,
            'kaspi_price' => null,
        ];
    }
}
