<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Models\Account;
use App\Models\Ingredient;
use App\Models\Modifier;
use App\Models\Product;
use App\Models\RecipeLine;
use App\Models\RestaurantTable;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The web till.
 *
 * Online-only, unlike the mobile one — but everything it writes has to be indistinguishable
 * from what the mobile till writes, because the ledger, the kitchen and the reports do not
 * care which client a sale came from and must not have to.
 */
class WebTillTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Store $store;

    private User $cashier;

    private Product $burger;

    private Ingredient $patty;

    private Ingredient $cheese;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->bothModules()->create();
        $this->store = Store::factory()->for($this->account)->create(['tax_rate' => '0.0600']);
        $this->cashier = User::factory()->for($this->account)->manager()->create();

        $this->burger = Product::factory()->for($this->account)->menuItem()
            ->create(['name' => 'Cheeseburger', 'price' => 2200]);

        $this->patty = Ingredient::factory()->for($this->account)
            ->create(['name' => 'Beef Patty', 'unit' => 'g']);
        $this->cheese = Ingredient::factory()->for($this->account)
            ->create(['name' => 'Cheese Slice', 'unit' => 'unit', 'low_stock_threshold' => '10.000']);

        RecipeLine::factory()->for($this->burger)->for($this->patty)->create(['quantity' => '150.000']);
        RecipeLine::factory()->for($this->burger)->for($this->cheese)->create(['quantity' => '2.000']);

        Sanctum::actingAs($this->cashier, ['backoffice']);
    }

    public function test_the_menu_carries_products_and_their_modifiers(): void
    {
        Modifier::factory()->for($this->burger)->create([
            'name' => 'Extra Cheese',
            'price_delta' => 300,
            'consumes_ingredient_id' => $this->cheese->id,
            'consumes_quantity' => '1.000',
        ]);

        $this->getJson(route('pos.menu'))
            ->assertOk()
            ->assertJsonPath('products.0.name', 'Cheeseburger')
            ->assertJsonPath('products.0.modifiers.0.name', 'Extra Cheese');
    }

    /** A sale from the browser expands its recipe exactly as the mobile till does. */
    public function test_placing_an_order_writes_the_sale_and_expands_the_recipe(): void
    {
        $response = $this->postJson(route('pos.orders.store'), [
            'store_id' => $this->store->id,
            'service_type' => 'takeaway',
            'payment_method' => 'cash',
            'lines' => [['product_id' => $this->burger->id, 'quantity' => 2]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', OrderStatus::Sent->value)
            ->assertJsonPath('data.service_type', 'takeaway');

        // 2200 x 2 = 4400, plus 6% = 264.
        $order = DB::table('orders')->sole();
        $this->assertSame(4400, (int) $order->subtotal);
        $this->assertSame(264, (int) $order->tax_total);
        $this->assertSame(4664, (int) $order->grand_total);
        $this->assertSame($this->cashier->id, $order->cashier_user_id);

        // One movement per ingredient, doubled for the quantity.
        $movements = DB::table('stock_movements')->get()->keyBy('stockable_id');
        $this->assertSame('-300.000', $movements[$this->patty->id]->delta);
        $this->assertSame('-4.000', $movements[$this->cheese->id]->delta);

        // And the ledger records it, so a day's takings include browser sales.
        $this->assertSame(4664, (int) DB::table('ledger_entries')->sum('amount'));
    }

    public function test_a_modifier_prices_the_line_and_consumes_its_ingredient(): void
    {
        $modifier = Modifier::factory()->for($this->burger)->create([
            'name' => 'Extra Cheese',
            'price_delta' => 300,
            'consumes_ingredient_id' => $this->cheese->id,
            'consumes_quantity' => '1.000',
        ]);

        $this->postJson(route('pos.orders.store'), [
            'store_id' => $this->store->id,
            'payment_method' => 'card',
            'lines' => [[
                'product_id' => $this->burger->id,
                'quantity' => 1,
                'modifier_ids' => [$modifier->id],
            ]],
        ])->assertCreated();

        $this->assertSame(2500, (int) DB::table('orders')->value('subtotal'));

        // Base recipe plus the extra slice, kept as separate rows.
        $cheese = DB::table('stock_movements')->where('stockable_id', $this->cheese->id)->get();
        $this->assertCount(2, $cheese);
        $this->assertEqualsCanonicalizing(['-2.000', '-1.000'], $cheese->pluck('delta')->all());
    }

    /**
     * The locked decision holds on this client too: the food was handed over, so the sale
     * stands and the shortfall becomes a human's problem.
     */
    public function test_selling_past_zero_raises_an_exception_rather_than_refusing(): void
    {
        $this->postJson(route('pos.orders.store'), [
            'store_id' => $this->store->id,
            'payment_method' => 'cash',
            'lines' => [['product_id' => $this->burger->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(2, DB::table('stock_exceptions')->count());
        $this->assertSame('-150.000', DB::table('stock_levels')
            ->where('stockable_id', $this->patty->id)->value('quantity_on_hand'));
    }

    /** Availability is derived from unfinished orders, never stored. */
    public function test_a_table_reads_as_occupied_while_its_order_is_unfinished(): void
    {
        $table = RestaurantTable::factory()->for($this->account)->for($this->store)
            ->create(['label' => 'T01']);

        $this->getJson(route('pos.tables'))->assertOk()->assertJsonPath('data.0.is_available', true);

        $this->postJson(route('pos.orders.store'), [
            'store_id' => $this->store->id,
            'restaurant_table_id' => $table->id,
            'service_type' => 'dine_in',
            'payment_method' => 'cash',
            'lines' => [['product_id' => $this->burger->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->getJson(route('pos.tables'))->assertOk()->assertJsonPath('data.0.is_available', false);

        // Finishing the order frees the table, with nothing to update by hand.
        DB::table('orders')->update(['status' => OrderStatus::Voided->value]);

        $this->getJson(route('pos.tables'))->assertOk()->assertJsonPath('data.0.is_available', true);
    }

    public function test_supply_reports_stock_state_per_ingredient(): void
    {
        $response = $this->getJson(route('pos.supply'));

        $response->assertOk();

        $states = collect($response->json('data'))->pluck('state', 'name');

        // Nothing has been received yet, so both read as out of stock rather than "safe".
        $this->assertSame('out_of_stock', $states['Cheese Slice']);
        $this->assertSame('out_of_stock', $states['Beef Patty']);
    }

    public function test_history_lists_past_orders(): void
    {
        $this->postJson(route('pos.orders.store'), [
            'store_id' => $this->store->id,
            'payment_method' => 'qr',
            'lines' => [['product_id' => $this->burger->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->getJson(route('pos.history'))->assertOk()->assertJsonCount(1, 'data');
    }

    /** Web order numbers are prefixed so they can never collide with a mobile till's. */
    public function test_web_order_numbers_are_prefixed_and_sequential(): void
    {
        foreach ([1, 2] as $ignored) {
            $this->postJson(route('pos.orders.store'), [
                'store_id' => $this->store->id,
                'payment_method' => 'cash',
                'lines' => [['product_id' => $this->burger->id, 'quantity' => 1]],
            ])->assertCreated();
        }

        $this->assertEqualsCanonicalizing(
            ['W-0001', 'W-0002'],
            DB::table('orders')->pluck('order_number')->all(),
        );
    }

    /** The same entitlement boundary as everything else F&B. */
    public function test_a_retail_only_account_cannot_reach_the_web_till(): void
    {
        $owner = User::factory()->for(Account::factory()->retailOnly())->owner()->create();

        Sanctum::actingAs($owner, ['backoffice']);

        $this->getJson(route('pos.menu'))->assertForbidden();
        $this->getJson(route('pos.tables'))->assertForbidden();
    }
}
