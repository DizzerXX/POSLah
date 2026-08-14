<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\RestaurantTable;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RestaurantTable> */
class RestaurantTableFactory extends Factory
{
    protected $model = RestaurantTable::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'store_id' => Store::factory(),
            'label' => strtoupper(fake()->unique()->bothify('T##')),
            'seats' => fake()->randomElement([2, 4, 6]),
            'is_active' => true,
        ];
    }
}
