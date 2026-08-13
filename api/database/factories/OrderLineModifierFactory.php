<?php

namespace Database\Factories;

use App\Models\OrderLine;
use App\Models\OrderLineModifier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderLineModifier> */
class OrderLineModifierFactory extends Factory
{
    protected $model = OrderLineModifier::class;

    public function definition(): array
    {
        return [
            'order_line_id' => OrderLine::factory(),
            'modifier_id' => null,
            'name_snapshot' => 'Extra shot',
            'price_delta_snapshot' => 200,
        ];
    }
}
