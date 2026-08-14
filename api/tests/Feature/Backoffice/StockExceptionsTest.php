<?php

namespace Tests\Feature\Backoffice;

use App\Enums\StockableType;
use App\Enums\StockExceptionStatus;
use App\Enums\StockMovementReason;
use App\Models\Account;
use App\Models\Device;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockException;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\TillSimulator;
use Tests\TestCase;

/**
 * The other half of "accept both, allow negative, flag".
 *
 * Refusing to reject a sale that already happened only works if somebody is told, and being
 * told is worth nothing without somewhere to act. Resolution is a human stocktake recorded as
 * one more append — there is no algorithm that can know what is actually on the shelf.
 */
class StockExceptionsTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Store $store;

    private Device $device;

    private ProductVariant $variant;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->bothModules()->create();
        $this->store = Store::factory()->for($this->account)->create();
        $this->device = Device::factory()->for($this->account)->for($this->store)
            ->create(['device_name' => 'Front Counter']);
        $this->manager = User::factory()->for($this->account)->manager()->create();

        $product = Product::factory()->for($this->account)->retail()
            ->create(['name' => 'Ethiopia Yirgacheffe']);
        $this->variant = ProductVariant::factory()->for($product)->create(['name' => '250g Bag']);
    }

    /**
     * The real path: an offline sale drives stock negative on sync, and it turns up here.
     */
    public function test_an_exception_raised_by_sync_appears_in_the_backoffice(): void
    {
        $sale = $this->sellPastZero();

        $response = $this->actingAsManager()->getJson(route('stock-exceptions.index'));

        $response->assertOk()->assertJsonCount(1, 'data');

        $exception = $response->json('data.0');

        $this->assertSame('Ethiopia Yirgacheffe — 250g Bag', $exception['stockable_name']);
        $this->assertSame('-1.000', $exception['resulting_quantity']);
        $this->assertSame(StockExceptionStatus::Open->value, $exception['status']);

        // Which sale, and which till. The first two questions a merchant asks.
        $this->assertSame([$sale['order_id']], array_column($exception['contributing_orders'], 'id'));
        $this->assertSame(['Front Counter'], $exception['devices']);
    }

    /**
     * Resolution is an append, not an edit. The sales that drove the level negative are
     * untouched; the correction sits alongside them with its own provenance.
     */
    public function test_a_stocktake_resolves_the_exception_by_appending_a_movement(): void
    {
        $this->sellPastZero();

        $exception = StockException::query()->sole();
        $movementsBefore = DB::table('stock_movements')->count();

        $response = $this->actingAsManager()->postJson(
            route('stock-exceptions.resolve', $exception),
            ['counted_quantity' => 4, 'note' => 'Found a sealed box out back.'],
        );

        $response->assertOk()->assertJsonPath('data.status', StockExceptionStatus::Resolved->value);

        // One new movement, not an edit of any existing one.
        $this->assertSame($movementsBefore + 1, DB::table('stock_movements')->count());

        $adjustment = DB::table('stock_movements')
            ->where('reason', StockMovementReason::ManualAdjustment->value)
            ->latest('recorded_at')
            ->first();

        // Counted 4 against a level of -1, so the shelf gained 5.
        $this->assertSame('5.000', $adjustment->delta);
        $this->assertSame($this->manager->id, $adjustment->created_by_user_id);

        // And the level is recomputed from the log, landing on what was counted.
        $this->assertSame('4.000', $this->currentLevel());

        $exception->refresh();
        $this->assertSame(StockExceptionStatus::Resolved, $exception->status);
        $this->assertSame($this->manager->id, $exception->resolved_by_user_id);
        $this->assertNotNull($exception->resolved_at);
    }

    /**
     * The sale that caused all this stands. Resolving stock never rewrites the ledger.
     */
    public function test_resolving_does_not_touch_the_sale_or_its_ledger_entry(): void
    {
        $sale = $this->sellPastZero();

        $ledgerBefore = DB::table('ledger_entries')->where('order_id', $sale['order_id'])->first();

        $this->actingAsManager()->postJson(
            route('stock-exceptions.resolve', StockException::query()->sole()),
            ['counted_quantity' => 0],
        )->assertOk();

        $this->assertEquals(
            $ledgerBefore,
            DB::table('ledger_entries')->where('id', $ledgerBefore->id)->first(),
        );
    }

    /**
     * A stocktake that still finds a shortfall opens a fresh exception rather than being
     * treated as a special case. The level is recomputed either way.
     */
    public function test_counting_a_still_negative_quantity_raises_a_new_exception(): void
    {
        $this->sellPastZero();

        $this->actingAsManager()->postJson(
            route('stock-exceptions.resolve', StockException::query()->sole()),
            ['counted_quantity' => -3, 'note' => 'Owed to a customer.'],
        )->assertOk();

        $this->assertSame('-3.000', $this->currentLevel());
        $this->assertSame(1, StockException::query()->open()->count());
        $this->assertSame(2, StockException::query()->count());
    }

    public function test_an_exception_cannot_be_resolved_twice(): void
    {
        $this->sellPastZero();

        $exception = StockException::query()->sole();

        $this->actingAsManager()
            ->postJson(route('stock-exceptions.resolve', $exception), ['counted_quantity' => 5])
            ->assertOk();

        $this->actingAsManager()
            ->postJson(route('stock-exceptions.resolve', $exception), ['counted_quantity' => 9])
            ->assertStatus(422);
    }

    public function test_the_list_can_be_filtered_by_status(): void
    {
        $this->sellPastZero();

        $this->actingAsManager()
            ->getJson(route('stock-exceptions.index', ['status' => 'resolved']))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAsManager()
            ->getJson(route('stock-exceptions.index', ['status' => 'open']))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ---- boundaries -------------------------------------------------------

    public function test_the_endpoint_requires_authentication(): void
    {
        $this->getJson(route('stock-exceptions.index'))->assertUnauthorized();
    }

    /**
     * A till's credential is not a backoffice credential, even though both are valid Sanctum
     * tokens. The endpoints say what they actually require.
     */
    public function test_a_device_token_cannot_read_the_backoffice(): void
    {
        Sanctum::actingAs($this->device, [Device::ABILITY_PULL]);

        $this->getJson(route('stock-exceptions.index'))->assertForbidden();
    }

    public function test_another_accounts_exceptions_are_never_listed(): void
    {
        $this->sellPastZero();

        $stranger = User::factory()->for(Account::factory()->retailOnly())->owner()->create();

        Sanctum::actingAs($stranger, ['backoffice']);

        $this->getJson(route('stock-exceptions.index'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_another_accounts_exception_cannot_be_resolved(): void
    {
        $this->sellPastZero();

        $stranger = User::factory()->for(Account::factory()->retailOnly())->owner()->create();

        Sanctum::actingAs($stranger, ['backoffice']);

        $this->postJson(
            route('stock-exceptions.resolve', StockException::query()->sole()),
            ['counted_quantity' => 5],
        )->assertForbidden();
    }

    public function test_a_deactivated_user_is_refused(): void
    {
        $this->manager->update(['is_active' => false]);

        $this->actingAsManager()->getJson(route('stock-exceptions.index'))->assertForbidden();
    }

    // ---- helpers ----------------------------------------------------------

    /** Sells one unit with nothing on the shelf, through the real sync endpoint. */
    private function sellPastZero(): array
    {
        Sanctum::actingAs($this->device, [Device::ABILITY_PUSH]);

        $till = new TillSimulator($this->device);
        $sale = $till->ringUpSale($this->variant->id, stockableType: StockableType::ProductVariant);

        $this->postJson(route('sync.push'), $till->batch($sale['entries']))->assertOk();

        return $sale;
    }

    private function actingAsManager(): static
    {
        Sanctum::actingAs($this->manager, ['backoffice']);

        return $this;
    }

    private function currentLevel(): string
    {
        return DB::table('stock_levels')
            ->where('store_id', $this->store->id)
            ->where('stockable_id', $this->variant->id)
            ->value('quantity_on_hand');
    }
}
