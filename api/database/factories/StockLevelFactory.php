<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockLevel> */
class StockLevelFactory extends Factory
{
    protected $model = StockLevel::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'stockable_type' => 'product_variant',
            'stockable_id' => ProductVariant::factory(),
            'quantity_on_hand' => (string) fake()->numberBetween(0, 200).'.000',
        ];
    }

    /**
     * Two tills sold the last unit while offline. Both sales are facts; -1 is the correct
     * record of what happened, and an exception for the merchant to resolve.
     */
    public function negative(): static
    {
        return $this->state(['quantity_on_hand' => '-1.000']);
    }
}
