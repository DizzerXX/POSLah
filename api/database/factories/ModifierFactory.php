<?php

namespace Database\Factories;

use App\Models\Modifier;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Modifier> */
class ModifierFactory extends Factory
{
    protected $model = Modifier::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => fake()->randomElement(['Extra shot', 'Less ice', 'Oat milk']),
            'price_delta' => fake()->randomElement([0, 100, 200]),
            'consumes_ingredient_id' => null,
            'consumes_quantity' => null,
            'is_active' => true,
        ];
    }
}
