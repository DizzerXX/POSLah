<?php

namespace Database\Factories;

use App\Enums\FulfillmentType;
use App\Enums\LineFulfillmentStatus;
use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderLine> */
class OrderLineFactory extends Factory
{
    protected $model = OrderLine::class;

    public function definition(): array
    {
        $price = fake()->numberBetween(200, 5000);
        $quantity = fake()->numberBetween(1, 3);

        return [
            'order_id' => Order::factory(),
            'product_id' => null,
            'product_variant_id' => null,
            // Snapshots, not joins: a receipt from March must not change in June.
            'name_snapshot' => fake()->words(2, true),
            'price_snapshot' => $price,
            'fulfillment_type' => FulfillmentType::Direct,
            'quantity' => $quantity,
            'line_total' => $price * $quantity,
            // direct lines are handed over at ring-up, so they are fulfilled immediately.
            'fulfillment_status' => LineFulfillmentStatus::Fulfilled,
        ];
    }

    /** A kitchen line: it is what makes the order state machine leave `created`. */
    public function routed(): static
    {
        return $this->state([
            'fulfillment_type' => FulfillmentType::Routed,
            'fulfillment_status' => LineFulfillmentStatus::Pending,
        ]);
    }

    public function ready(): static
    {
        return $this->state(['fulfillment_status' => LineFulfillmentStatus::Ready]);
    }
}
