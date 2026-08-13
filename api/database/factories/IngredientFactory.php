<?php

namespace Database\Factories;

use App\Enums\IngredientUnit;
use App\Models\Account;
use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Ingredient> */
class IngredientFactory extends Factory
{
    protected $model = Ingredient::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->word(),
            'unit' => fake()->randomElement(IngredientUnit::cases()),
            'low_stock_threshold' => '500.000',
            'cost_per_unit' => fake()->numberBetween(1, 50),
            'is_active' => true,
        ];
    }
}
