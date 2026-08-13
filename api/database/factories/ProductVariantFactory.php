<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductVariant> */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => fake()->randomElement(['Small', 'Medium', 'Large']),
            'sku' => strtoupper(fake()->unique()->bothify('??-####-?')),
            'barcode' => fake()->unique()->ean13(),
            // Signed: a smaller size may be a negative delta on the base price.
            'price_delta' => fake()->randomElement([-200, 0, 200, 500]),
            'is_active' => true,
        ];
    }
}
