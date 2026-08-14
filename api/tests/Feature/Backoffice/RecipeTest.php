<?php

namespace Tests\Feature\Backoffice;

use App\Enums\StockableType;
use App\Models\Account;
use App\Models\Device;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\RecipeLine;
use App\Models\StockException;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\TillSimulator;
use Tests\TestCase;

/**
 * F&B inventory, server side.
 *
 * The reconciler is deliberately stockable-agnostic — it sums movements for whatever the
 * stockable happens to be — so what these tests really check is that nothing along the F&B
 * path special-cases ingredients into a different set of rules.
 */
class RecipeTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Store $store;

    private Device $device;

    private Ingredient $beans;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->bothModules()->create();
        $this->store = Store::factory()->for($this->account)->create();
        $this->device = Device::factory()->for($this->account)->for($this->store)
            ->create(['device_name' => 'Cafe Counter']);
        $this->owner = User::factory()->for($this->account)->owner()->create();

        $this->beans = Ingredient::factory()->for($this->account)
            ->create(['name' => 'House Espresso Blend', 'unit' => 'g']);
    }

    /**
     * The locked decision, on the F&B side: a latte was made and handed over, so the
     * movement is a fact. Ingredients go negative exactly as variants do.
     */
    public function test_an_ingredient_driven_negative_raises_an_exception_and_the_sale_stands(): void
    {
        Sanctum::actingAs($this->device, [Device::ABILITY_PUSH]);

        $till = new TillSimulator($this->device);

        // Nothing in the hopper, and a latte gets made anyway.
        $sale = $till->ringUpSale($this->beans->id, stockableType: StockableType::Ingredient);

        $this->postJson(route('sync.push'), $till->batch($sale['entries']))->assertOk();

        $this->assertSame(
            '-1.000',
            DB::table('stock_levels')->where('stockable_id', $this->beans->id)->value('quantity_on_hand'),
        );

        $exception = StockException::query()->sole();

        $this->assertSame(StockableType::Ingredient->value, $exception->stockable_type);
        $this->assertSame([$sale['order_id']], $exception->contributing_order_ids);

        // The sale is untouched.
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(1, DB::table('ledger_entries')->count());
    }

    /**
     * The backoffice presents an ingredient exception by ingredient name, not by a variant
     * join that would come back empty.
     */
    public function test_an_ingredient_exception_reads_correctly_in_the_backoffice(): void
    {
        Sanctum::actingAs($this->device, [Device::ABILITY_PUSH]);

        $till = new TillSimulator($this->device);
        $till->ringUpSale($this->beans->id, stockableType: StockableType::Ingredient);

        $this->postJson(
            route('sync.push'),
            $till->batch($till->ringUpSale($this->beans->id, stockableType: StockableType::Ingredient)['entries']),
        )->assertOk();

        Sanctum::actingAs($this->owner, ['backoffice']);

        $response = $this->getJson(route('stock-exceptions.index'));

        $response->assertOk()
            ->assertJsonPath('data.0.stockable_name', 'House Espresso Blend')
            ->assertJsonPath('data.0.stockable_type', 'ingredient')
            ->assertJsonPath('data.0.unit', 'g');
    }

    /**
     * A stocktake resolves an ingredient shortfall the same way it resolves a variant one:
     * by appending an adjustment, never by editing what came before.
     */
    public function test_a_stocktake_resolves_an_ingredient_exception(): void
    {
        Sanctum::actingAs($this->device, [Device::ABILITY_PUSH]);

        $till = new TillSimulator($this->device);

        $this->postJson(
            route('sync.push'),
            $till->batch($till->ringUpSale($this->beans->id, stockableType: StockableType::Ingredient)['entries']),
        )->assertOk();

        Sanctum::actingAs($this->owner, ['backoffice']);

        $this->postJson(
            route('stock-exceptions.resolve', StockException::query()->sole()),
            ['counted_quantity' => 2400, 'note' => 'Weighed the hopper.'],
        )->assertOk();

        $this->assertSame(
            '2400.000',
            DB::table('stock_levels')->where('stockable_id', $this->beans->id)->value('quantity_on_hand'),
        );

        $this->assertSame(0, StockException::query()->open()->count());
    }

    /**
     * Recipes reach the till because expansion happens there, against the recipe as it stood
     * when the burger was actually made.
     */
    public function test_recipes_and_modifier_consumption_reach_the_till_in_the_pull(): void
    {
        $burger = Product::factory()->for($this->account)->menuItem()->create(['name' => 'Cheeseburger']);
        RecipeLine::factory()->for($burger)->for($this->beans)->create(['quantity' => '150.000']);

        DB::table('modifiers')->insert([
            'id' => (string) Str::uuid7(),
            'product_id' => $burger->id,
            'name' => 'Extra Cheese',
            'price_delta' => 300,
            'consumes_ingredient_id' => $this->beans->id,
            'consumes_quantity' => '1.000',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->device, [Device::ABILITY_PULL]);

        $response = $this->getJson(route('sync.pull'));

        $response->assertOk()
            ->assertJsonPath('changes.recipe_lines.0.quantity', '150.000')
            ->assertJsonPath('changes.modifiers.0.consumes_quantity', '1.000');

        $this->assertNotNull($response->json('changes.modifiers.0.consumes_ingredient_id'));
    }
}
