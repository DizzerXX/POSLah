<?php

namespace Database\Factories;

use App\Enums\StockExceptionStatus;
use App\Models\ProductVariant;
use App\Models\StockException;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockException> */
class StockExceptionFactory extends Factory
{
    protected $model = StockException::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'stockable_type' => 'product_variant',
            'stockable_id' => ProductVariant::factory(),
            'resulting_quantity' => '-1.000',
            'contributing_order_ids' => [],
            'status' => StockExceptionStatus::Open,
            'resolved_by_user_id' => null,
            'resolution_note' => null,
            'resolved_at' => null,
        ];
    }
}
