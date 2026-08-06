<?php

namespace Database\Factories;

use App\Enums\OzonOperationStatus;
use App\Enums\OzonOperationType;
use App\Models\OzonAccount;
use App\Models\OzonOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OzonOperation> */
class OzonOperationFactory extends Factory
{
    protected $model = OzonOperation::class;

    public function definition(): array
    {
        return [
            'ozon_account_id' => OzonAccount::factory(),
            'operation_key' => fake()->uuid(),
            'operation_type' => OzonOperationType::ProductPrepare,
            'status' => OzonOperationStatus::Pending,
            'attempt' => 1,
        ];
    }
}
