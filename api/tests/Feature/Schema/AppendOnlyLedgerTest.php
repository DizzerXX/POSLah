<?php

namespace Tests\Feature\Schema;

use App\Enums\LedgerEntryType;
use App\Enums\StockMovementReason;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The append-only guarantee, tested at the layer that actually provides it.
 *
 * Every raw case here goes through the query builder rather than Eloquent on purpose: a
 * model observer proves only that the model refuses, which is worth nothing against a
 * tinker session, a maintenance script, or a future developer who reaches for DB::table().
 * These assertions fail if the trigger is dropped, regardless of what the PHP layer does.
 *
 * The offending statement is wrapped in DB::transaction() so it runs inside a savepoint:
 * a failed statement aborts the surrounding PostgreSQL transaction, and without the
 * savepoint the test could not query anything afterwards to prove the row survived.
 */
class AppendOnlyLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_database_rejects_an_update_to_a_ledger_entry(): void
    {
        $entry = $this->ledgerEntry(['amount' => 12_500]);

        $this->expectDatabaseRefusal(
            fn () => DB::table('ledger_entries')->where('id', $entry->id)->update(['amount' => 1]),
            'ledger_entries is append-only',
        );

        $this->assertSame(
            12_500,
            (int) DB::table('ledger_entries')->where('id', $entry->id)->value('amount'),
            'The original amount must survive the rejected update byte for byte.',
        );
    }

    public function test_the_database_rejects_a_delete_of_a_ledger_entry(): void
    {
        $entry = $this->ledgerEntry();

        $this->expectDatabaseRefusal(
            fn () => DB::table('ledger_entries')->where('id', $entry->id)->delete(),
            'ledger_entries is append-only',
        );

        $this->assertDatabaseHas('ledger_entries', ['id' => $entry->id]);
    }

    public function test_the_database_rejects_an_update_to_a_stock_movement(): void
    {
        $movement = $this->stockMovement();

        $this->expectDatabaseRefusal(
            fn () => DB::table('stock_movements')->where('id', $movement->id)->update(['delta' => '99.000']),
            'stock_movements is append-only',
        );

        $this->assertSame(
            '-1.000',
            DB::table('stock_movements')->where('id', $movement->id)->value('delta'),
        );
    }

    public function test_the_database_rejects_a_delete_of_a_stock_movement(): void
    {
        $movement = $this->stockMovement();

        $this->expectDatabaseRefusal(
            fn () => DB::table('stock_movements')->where('id', $movement->id)->delete(),
            'stock_movements is append-only',
        );

        $this->assertDatabaseHas('stock_movements', ['id' => $movement->id]);
    }

    public function test_eloquent_refuses_before_the_query_is_ever_sent(): void
    {
        $entry = $this->ledgerEntry();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ledger_entries is append-only');

        $entry->amount = 1;
        $entry->save();
    }

    /**
     * The shape a correction is supposed to take: a new row pointing at the old one, with
     * the original left exactly as it was.
     */
    public function test_a_refund_is_recorded_as_a_reversing_entry_and_leaves_the_sale_intact(): void
    {
        $account = Account::factory()->create();
        $store = Store::factory()->for($account)->create();
        $order = Order::factory()->for($account)->for($store)->create();

        $sale = LedgerEntry::factory()->for($account)->for($store)->for($order)->create(['amount' => 4_500]);

        $refund = LedgerEntry::factory()->reversing($sale)->create();

        $this->assertSame(-4_500, $refund->amount, 'A reversal carries the negative amount.');
        $this->assertSame($sale->id, $refund->reverses_entry_id);

        // The whole point: net position is a plain SUM, with no case analysis and no edit
        // of history to arrive at it.
        $this->assertSame(
            0,
            (int) DB::table('ledger_entries')->where('order_id', $order->id)->sum('amount'),
        );
        $this->assertSame(2, DB::table('ledger_entries')->where('order_id', $order->id)->count());

        $this->assertDatabaseHas('ledger_entries', [
            'id' => $sale->id,
            'amount' => 4_500,
            'entry_type' => LedgerEntryType::Sale->value,
        ]);
    }

    /**
     * Runs $attempt inside a savepoint and asserts PostgreSQL refused it.
     */
    private function expectDatabaseRefusal(callable $attempt, string $expectedMessage): void
    {
        try {
            DB::transaction($attempt);
        } catch (QueryException $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage());

            return;
        }

        $this->fail("Expected PostgreSQL to refuse the statement with: {$expectedMessage}");
    }

    private function ledgerEntry(array $attributes = []): LedgerEntry
    {
        $account = Account::factory()->create();
        $store = Store::factory()->for($account)->create();

        return LedgerEntry::factory()->for($account)->for($store)->create($attributes);
    }

    private function stockMovement(): StockMovement
    {
        $store = Store::factory()->create();

        return StockMovement::factory()->for($store)->create([
            'delta' => '-1.000',
            'reason' => StockMovementReason::Sale,
        ]);
    }
}
