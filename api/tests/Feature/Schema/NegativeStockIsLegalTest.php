<?php

namespace Tests\Feature\Schema;

use App\Enums\StockExceptionStatus;
use App\Models\ProductVariant;
use App\Models\StockException;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Accept both, allow negative, flag" is a schema property before it is a sync behaviour.
 *
 * The reconciliation logic that produces these rows arrives in step 2. What is being
 * asserted here is that the schema *permits* the outcome — if a CHECK (quantity >= 0) ever
 * appears on stock_levels, an offline sale becomes unrecordable and the locked decision is
 * quietly reversed.
 */
class NegativeStockIsLegalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stock_level_may_go_negative(): void
    {
        $level = StockLevel::factory()->negative()->create();

        $this->assertSame('-1.000', $level->quantity_on_hand);
        $this->assertTrue($level->isNegative());
        $this->assertDatabaseHas('stock_levels', [
            'id' => $level->id,
            'quantity_on_hand' => '-1.000',
        ]);
    }

    /**
     * The scenario the decision exists for: two tills each sell the last unit while offline.
     * Both movements are facts, both are appended, and the level lands at -1.
     */
    public function test_two_offline_sales_of_the_last_unit_both_land(): void
    {
        $store = Store::factory()->create();
        $variant = ProductVariant::factory()->create();

        StockMovement::factory()->for($store)->adjustment('1.000')->create([
            'stockable_type' => $variant->getMorphClass(),
            'stockable_id' => $variant->id,
            'note' => 'Opening stock',
        ]);

        // Two devices, neither aware of the other, both offline at the time of sale.
        foreach (range(1, 2) as $ignored) {
            StockMovement::factory()->for($store)->create([
                'stockable_type' => $variant->getMorphClass(),
                'stockable_id' => $variant->id,
                'delta' => '-1.000',
            ]);
        }

        $sum = DB::table('stock_movements')
            ->where('store_id', $store->id)
            ->where('stockable_id', $variant->id)
            ->sum('delta');

        $this->assertSame(-1.0, (float) $sum, 'Both sales stand; the level is the sum of the facts.');
        $this->assertSame(3, StockMovement::query()->where('stockable_id', $variant->id)->count());
    }

    public function test_an_exception_records_the_negative_level_without_undoing_the_sale(): void
    {
        $exception = StockException::factory()->create([
            'resulting_quantity' => '-1.000',
            'contributing_order_ids' => ['019ff984-248c-7321-9c72-820a6b9142c2'],
        ]);

        $this->assertSame(StockExceptionStatus::Open, $exception->status);
        $this->assertCount(1, $exception->contributing_order_ids);
        $this->assertSame(1, StockException::query()->open()->count());
    }

    /**
     * Repeated offline batches landing on an already-negative stockable append to the
     * existing exception rather than producing a wall of duplicates. That is what makes the
     * reconciliation upsert in step 2 well-defined.
     */
    public function test_only_one_exception_may_be_open_per_stockable_per_store(): void
    {
        $store = Store::factory()->create();
        $variant = ProductVariant::factory()->create();

        $attributes = [
            'store_id' => $store->id,
            'stockable_type' => $variant->getMorphClass(),
            'stockable_id' => $variant->id,
        ];

        StockException::factory()->create($attributes);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('stock_exceptions_open_unique');

        StockException::factory()->create($attributes);
    }

    public function test_a_resolved_exception_frees_the_stockable_for_a_new_one(): void
    {
        $store = Store::factory()->create();
        $variant = ProductVariant::factory()->create();

        $attributes = [
            'store_id' => $store->id,
            'stockable_type' => $variant->getMorphClass(),
            'stockable_id' => $variant->id,
        ];

        // Resolution is a human stocktake, recorded as one more append.
        StockException::factory()->create($attributes + [
            'status' => StockExceptionStatus::Resolved,
            'resolved_at' => now(),
            'resolution_note' => 'Counted 4 on the shelf.',
        ]);

        StockException::factory()->create($attributes);

        $this->assertSame(2, StockException::query()->count());
        $this->assertSame(1, StockException::query()->open()->count());
    }
}
