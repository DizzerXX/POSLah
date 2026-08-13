<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\RecipeLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecipeLine> */
class RecipeLineFactory extends Factory
{
    protected $model = RecipeLine::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory()->menuItem(),
            'ingredient_id' => Ingredient::factory(),
            // In the ingredient's own unit; no cross-unit conversion in v1.
            'quantity' => fake()->randomElement(['1.000', '2.000', '18.000', '150.000']),
        ];
    }
}
