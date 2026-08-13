<?php

namespace Tests\Feature\Sync;

use App\Enums\StockableType;
use App\Enums\StockMovementReason;
use App\Models\Account;
use App\Models\Device;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockException;
use App\Models\Store;
use App\Sync\EntryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\TillSimulator;
use Tests\TestCase;

/**
 * The scenarios named in the sync plan's testing table.
 *
 * These are the deliverable as much as the implementation is. "It worked when I tried it"
 * is not evidence for a sync engine — every one of these failures is invisible in manual
 * testing on a good network, and every one of them is fatal in a shop.
 */
class SyncScenariosTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Store $store;

    private Device $device;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->bothModules()->create();
        $this->store = Store::factory()->for($this->account)->create();
        $this->device = Device::factory()
            ->for($this->account)
            ->for($this->store)
            ->create(['number_prefix' => 'T1']);

        $product = Product::factory()->for($this->account)->retail()->create();
        $this->variant = ProductVariant::factory()->for($product)->create();
    }

    /**
     * Scenario 1 — three sales made offline, then the network comes back.
     */
    public function test_three_offline_sales_all_sync_reconcile_stock_and_do_not_duplicate(): void
    {
        $this->openingStock('10.000');
        $till = $this->till();

        $entries = [];
        foreach (range(1, 3) as $ignored) {
            $entries = [...$entries, ...$till->ringUpSale($this->variant->id)['entries']];
        }

        $response = $this->push($till->batch($entries));

        $response->assertOk();
        $this->assertResultsAre(EntryStatus::Accepted, $response, expectedCount: 9);

        $this->assertSame(3, DB::table('orders')->count());
        $this->assertSame(3, DB::table('order_lines')->count());
        $this->assertSame(3, DB::table('ledger_entries')->count());
        $this->assertSame(3, DB::table('stock_movements')->where('reason', StockMovementReason::Sale)->count());

        // Ten on the shelf, three sold: the projection matches the movements.
        $this->assertSame('7.000', $this->levelFor($this->variant->id));
        $this->assertSame(0, StockException::query()->count(), 'Nothing went negative, so nothing is flagged.');
    }

    /**
     * Scenario 2 — the same batch arrives twice. A crash mid-request, a flaky network, a
     * user force-quitting the app: the till resends, because that is cheaper than knowing
     * whether the first attempt landed.
     */
    public function test_resending_the_same_batch_is_a_no_op(): void
    {
        $this->openingStock('10.000');
        $till = $this->till();

        $batch = $till->batch($till->ringUpSale($this->variant->id, quantity: 2)['entries']);

        $first = $this->push($batch);
        $this->assertResultsAre(EntryStatus::Accepted, $first, expectedCount: 3);
        $this->assertSame('8.000', $this->levelFor($this->variant->id));

        // Byte-identical resend, including the batch_id.
        $second = $this->push($batch);

        $this->assertResultsAre(EntryStatus::Duplicate, $second, expectedCount: 3);

        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(1, DB::table('order_lines')->count());
        $this->assertSame(1, DB::table('ledger_entries')->count());
        $this->assertSame(1, DB::table('stock_movements')->where('reason', StockMovementReason::Sale)->count());

        // The decisive assertion: no double decrement. The level is recomputed from the
        // movement log rather than adjusted by a delta, so a resend cannot move it.
        $this->assertSame('8.000', $this->levelFor($this->variant->id));
    }

    /**
     * Scenario 4 — two tills each sell the last unit while offline.
     *
     * Both sales are facts. Neither is rejected. Stock lands at -1 and a human is told.
     */
    public function test_two_tills_selling_the_last_unit_offline_both_land_and_raise_one_exception(): void
    {
        $this->openingStock('1.000');

        $secondDevice = Device::factory()
            ->for($this->account)
            ->for($this->store)
            ->create(['number_prefix' => 'T2']);

        $tillOne = $this->till();
        $tillTwo = new TillSimulator($secondDevice, 'T2');

        $saleOne = $tillOne->ringUpSale($this->variant->id);
        $saleTwo = $tillTwo->ringUpSale($this->variant->id);

        // Neither device knew about the other's sale at the time it was made.
        $this->assertResultsAre(EntryStatus::Accepted, $this->push($tillOne->batch($saleOne['entries'])), 3);

        Sanctum::actingAs($secondDevice, [Device::ABILITY_PUSH, Device::ABILITY_PULL]);
        $this->assertResultsAre(EntryStatus::Accepted, $this->push($tillTwo->batch($saleTwo['entries'])), 3);

        $this->assertSame('-1.000', $this->levelFor($this->variant->id), 'Negative is the correct answer here.');
        $this->assertSame(2, DB::table('orders')->count(), 'Both sales stand.');

        // One exception, not two: repeated batches append to the open row rather than
        // producing a wall of duplicates.
        $this->assertSame(1, StockException::query()->count());

        $exception = StockException::query()->firstOrFail();
        $this->assertSame('-1.000', $exception->resulting_quantity);

        // Only the second sale is listed, and that is correct rather than a gap: the first
        // took the level to exactly 0, which is not an exception. contributing_order_ids is
        // "what landed while this was negative" — a lead for the investigation, not an
        // exhaustive causal set. The exhaustive answer is the movement log, which is what
        // the stockable index on stock_movements exists to serve.
        $this->assertSame([$saleTwo['order_id']], $exception->contributing_order_ids);
    }

    /**
     * A third sale arriving while the stockable is already negative appends to the open
     * exception rather than opening a second one. Without this, a till that was offline for
     * a day would bury the merchant in duplicate rows for a single real problem.
     */
    public function test_later_batches_append_to_the_open_exception_rather_than_opening_another(): void
    {
        $this->openingStock('0.000');
        $till = $this->till();

        $first = $till->ringUpSale($this->variant->id);
        $this->push($till->batch($first['entries']))->assertOk();

        $second = $till->ringUpSale($this->variant->id);
        $this->push($till->batch($second['entries']))->assertOk();

        $this->assertSame(1, StockException::query()->count());

        $exception = StockException::query()->firstOrFail();
        $this->assertSame('-2.000', $exception->resulting_quantity);
        $this->assertEqualsCanonicalizing(
            [$first['order_id'], $second['order_id']],
            $exception->contributing_order_ids,
        );
    }

    /**
     * Scenario 5 — a poison entry in a batch of fifty.
     *
     * The single most common way a naive sync engine dies: one bad row fails the batch, the
     * till resends, it fails again, and nothing behind it ever syncs.
     */
    public function test_a_poison_entry_does_not_block_the_other_forty_nine(): void
    {
        $this->openingStock('100.000');
        $till = $this->till();

        $entries = [];
        // 16 sales x 3 entries = 48 good entries.
        foreach (range(1, 16) as $ignored) {
            $entries = [...$entries, ...$till->ringUpSale($this->variant->id)['entries']];
        }

        // The poison: an order whose money arrived as a float. Exactly the kind of thing a
        // client bug produces, and exactly the kind of thing that must not be retried.
        $poison = $till->ringUpSale($this->variant->id)['entries'][0];
        $poison['payload']['grand_total'] = 12.34;

        // A second poison of a different shape: an entity type the server does not know.
        $unknown = $till->entry('loyalty_point', (string) Str::uuid7(), ['id' => 'x']);

        $entries[] = $poison;
        $entries[] = $unknown;

        $response = $this->push($till->batch($entries));
        $response->assertOk();

        $results = collect($response->json('results'));

        $this->assertCount(50, $results);
        $this->assertSame(48, $results->where('status', EntryStatus::Accepted->value)->count());
        $this->assertSame(2, $results->where('status', EntryStatus::Rejected->value)->count());

        $this->assertSame('validation', $results->firstWhere('entry_id', $poison['id'])['reason']);
        $this->assertSame('unknown_entity_type', $results->firstWhere('entry_id', $unknown['id'])['reason']);

        // The 16 good sales landed and reconciled, untouched by the two failures beside them.
        $this->assertSame(16, DB::table('orders')->count());
        $this->assertSame('84.000', $this->levelFor($this->variant->id));
    }

    /**
     * A rejected entry must not be retried, so the response has to distinguish it from a
     * transient failure. Rejections are 200-with-a-status, never a 5xx.
     */
    public function test_a_rejected_entry_still_returns_a_successful_batch_response(): void
    {
        $till = $this->till();
        $broken = $till->ringUpSale($this->variant->id)['entries'][0];
        unset($broken['payload']['lines']);

        $this->push($till->batch([$broken]))
            ->assertOk()
            ->assertJsonPath('results.0.status', EntryStatus::Rejected->value)
            ->assertJsonPath('results.0.reason', 'validation');
    }

    /**
     * The ledger entry and the movement both point at the order. The till sorts its outbox
     * by created_at, but a batch may still arrive with dependents ahead of their parent —
     * the server sorts by dependency depth so it lands anyway.
     */
    public function test_a_batch_sent_in_the_wrong_order_still_lands(): void
    {
        $this->openingStock('5.000');
        $till = $this->till();

        $entries = $till->ringUpSale($this->variant->id)['entries'];

        // Reversed: movement, then ledger entry, then the order they both reference.
        $this->assertResultsAre(EntryStatus::Accepted, $this->push($till->batch(array_reverse($entries))), 3);

        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame('4.000', $this->levelFor($this->variant->id));
    }

    public function test_results_come_back_in_the_order_the_entries_were_sent(): void
    {
        $till = $this->till();
        $entries = $till->ringUpSale($this->variant->id)['entries'];

        $response = $this->push($till->batch(array_reverse($entries)));

        $this->assertSame(
            array_column(array_reverse($entries), 'id'),
            array_column($response->json('results'), 'entry_id'),
            'A till must be able to zip results against its own outbox positionally.',
        );
    }

    /**
     * An entry referencing a row that never arrived is rejected on its own terms, and says
     * why, rather than surfacing as an opaque 500.
     */
    public function test_an_entry_whose_dependency_is_missing_is_rejected_alone(): void
    {
        $till = $this->till();

        $orphan = $till->ringUpSale($this->variant->id)['entries'][1]; // the ledger entry
        $orphan['payload']['order_id'] = (string) Str::uuid7();

        $response = $this->push($till->batch([$orphan]));

        $response->assertOk()->assertJsonPath('results.0.reason', 'missing_dependency');
    }

    private function till(): TillSimulator
    {
        Sanctum::actingAs($this->device, [Device::ABILITY_PUSH, Device::ABILITY_PULL]);

        return new TillSimulator($this->device, 'T1');
    }

    private function push(array $batch)
    {
        return $this->postJson(route('sync.push'), $batch);
    }

    private function openingStock(string $quantity): void
    {
        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid7(),
            'store_id' => $this->store->id,
            'stockable_type' => StockableType::ProductVariant->value,
            'stockable_id' => $this->variant->id,
            'delta' => $quantity,
            'reason' => StockMovementReason::ManualAdjustment->value,
            'occurred_at' => Carbon::now(),
            'recorded_at' => Carbon::now(),
            'created_at' => Carbon::now(),
        ]);
    }

    private function levelFor(string $stockableId): string
    {
        return DB::table('stock_levels')
            ->where('store_id', $this->store->id)
            ->where('stockable_id', $stockableId)
            ->value('quantity_on_hand');
    }

    private function assertResultsAre(EntryStatus $expected, $response, int $expectedCount): void
    {
        $results = collect($response->json('results'));

        $this->assertCount($expectedCount, $results);

        $statuses = $results->pluck('status')->unique()->all();

        $this->assertSame(
            [$expected->value],
            array_values($statuses),
            'Unexpected statuses: '.json_encode($results->all()),
        );
    }
}
