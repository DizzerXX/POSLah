<?php

namespace Database\Factories;

use App\Enums\StockMovementReason;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockMovement> */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'stockable_type' => 'product_variant',
            'stockable_id' => ProductVariant::factory(),
            'delta' => '-1.000',
            'reason' => StockMovementReason::Sale,
            'source_order_id' => null,
            'source_order_line_id' => null,
            'source_device_id' => null,
            'created_by_user_id' => null,
            'note' => null,
            'occurred_at' => now(),  // client clock
            'recorded_at' => now(),  // server clock
        ];
    }

    /** Opening stock, or the stocktake that resolves a negative level. */
    public function adjustment(string $delta): static
    {
        return $this->state([
            'delta' => $delta,
            'reason' => StockMovementReason::ManualAdjustment,
        ]);
    }
}
