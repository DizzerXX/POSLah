<?php

namespace Tests\Feature\Schema;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Structural invariants that no single feature owns, and that are therefore easy to erode
 * one convenient migration at a time.
 *
 * Each of these would be caught eventually by a wrong number on a receipt or a misordered
 * day report. Catching them here costs a schema query.
 */
class SchemaShapeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Money is integer minor units everywhere. A float column is wrong by a cent under
     * specific inputs and looks fine in every test that does not use those inputs.
     */
    public function test_every_money_column_is_a_bigint(): void
    {
        $moneyColumns = [
            'products' => ['price'],
            'product_variants' => ['price_delta'],
            'modifiers' => ['price_delta'],
            'ingredients' => ['cost_per_unit'],
            'orders' => ['subtotal', 'tax_total', 'discount_total', 'grand_total'],
            'order_lines' => ['price_snapshot', 'line_total'],
            'order_line_modifiers' => ['price_delta_snapshot'],
            'ledger_entries' => ['amount'],
        ];

        foreach ($moneyColumns as $table => $columns) {
            foreach ($columns as $column) {
                $type = DB::table('information_schema.columns')
                    ->where('table_schema', 'public')
                    ->where('table_name', $table)
                    ->where('column_name', $column)
                    ->value('data_type');

                $this->assertSame('bigint', $type, "{$table}.{$column} must hold minor units as bigint.");
            }
        }
    }

    public function test_no_column_anywhere_uses_a_floating_point_type(): void
    {
        $floats = DB::table('information_schema.columns')
            ->select('table_name', 'column_name', 'data_type')
            ->where('table_schema', 'public')
            ->whereIn('data_type', ['double precision', 'real', 'money'])
            ->get();

        $this->assertCount(0, $floats, 'Money is integer minor units; stock is numeric. Neither is a float.');
    }

    /**
     * Stock is the one family of quantities that is not an integer — 150 g of patty is not
     * a count — and it must be signed, because a level of -1 is a legal outcome.
     */
    public function test_stock_quantities_are_signed_numerics_with_no_non_negative_check(): void
    {
        $type = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'stock_levels')
            ->where('column_name', 'quantity_on_hand')
            ->value('data_type');

        $this->assertSame('numeric', $type);

        $checks = DB::table('information_schema.check_constraints as cc')
            ->join('information_schema.constraint_column_usage as ccu', 'ccu.constraint_name', '=', 'cc.constraint_name')
            ->where('ccu.table_name', 'stock_levels')
            ->where('ccu.column_name', 'quantity_on_hand')
            ->where('cc.check_clause', 'not like', '%IS NOT NULL%')
            ->get();

        $this->assertCount(
            0,
            $checks,
            'A non-negative check on stock would make an offline sale unrecordable and quietly reverse a locked decision.',
        );
    }

    /**
     * Both clocks on every row that can be created offline. occurred_at is when the human
     * did the thing; recorded_at is when the server learned of it, and is the only one that
     * anything financial may order by.
     */
    public function test_syncable_tables_carry_both_clocks(): void
    {
        foreach (['orders', 'ledger_entries', 'stock_movements', 'customers'] as $table) {
            foreach (['occurred_at', 'recorded_at'] as $column) {
                $type = DB::table('information_schema.columns')
                    ->where('table_schema', 'public')
                    ->where('table_name', $table)
                    ->where('column_name', $column)
                    ->value('data_type');

                $this->assertSame(
                    'timestamp with time zone',
                    $type,
                    "{$table}.{$column} is missing, or is not timezone-aware.",
                );
            }
        }
    }

    /**
     * A naive timestamp column in a system whose accounts carry their own timezone is a
     * silently wrong day boundary in a report.
     */
    public function test_no_timestamp_column_is_timezone_naive(): void
    {
        $naive = DB::table('information_schema.columns')
            ->select('table_name', 'column_name')
            ->where('table_schema', 'public')
            ->where('data_type', 'timestamp without time zone')
            // Laravel's own supporting tables.
            ->whereNotIn('table_name', ['password_reset_tokens', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks'])
            ->get();

        $this->assertCount(0, $naive, 'Timestamps are timestamptz.');
    }

    /**
     * The snapshot columns exist and are not nullable: a receipt that could render a null
     * name is a receipt that will, once a product is deactivated.
     */
    public function test_order_line_snapshots_are_required(): void
    {
        foreach (['name_snapshot', 'price_snapshot', 'fulfillment_type'] as $column) {
            $nullable = DB::table('information_schema.columns')
                ->where('table_schema', 'public')
                ->where('table_name', 'order_lines')
                ->where('column_name', $column)
                ->value('is_nullable');

            $this->assertSame('NO', $nullable, "order_lines.{$column} is load-bearing and must be required.");
        }
    }
}
