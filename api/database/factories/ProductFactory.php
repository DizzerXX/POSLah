<?php

namespace Database\Factories;

use App\Enums\FulfillmentType;
use App\Models\Account;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'category_id' => null,
            'name' => fake()->words(2, true),
            'sku' => strtoupper(fake()->unique()->bothify('??-####')),
            'barcode' => fake()->unique()->ean13(),
            // Minor units: RM 12.50 is 1250.
            'price' => fake()->numberBetween(200, 8000),
            'fulfillment_type' => FulfillmentType::Direct,
            'tracks_stock' => true,
            'is_active' => true,
        ];
    }

    /** Retail: a SKU that decrements its own variant. */
    public function retail(): static
    {
        return $this->state([
            'fulfillment_type' => FulfillmentType::Direct,
            'tracks_stock' => true,
        ]);
    }

    /** F&B: routed to the kitchen, holds no stock of its own, decrements its recipe. */
    public function menuItem(): static
    {
        return $this->state([
            'fulfillment_type' => FulfillmentType::Routed,
            'tracks_stock' => false,
            'sku' => null,
            'barcode' => null,
        ]);
    }

    /**
     * The third case: a corkage fee or delivery charge. Sellable, touches no inventory,
     * and expands to zero stock movements — which is a legitimate outcome, not an error.
     */
    public function untracked(): static
    {
        return $this->state([
            'fulfillment_type' => FulfillmentType::Direct,
            'tracks_stock' => false,
            'barcode' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
