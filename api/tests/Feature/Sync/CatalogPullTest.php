<?php

namespace Tests\Feature\Sync;

use App\Models\Account;
use App\Models\Device;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeLine;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Catalog flows downward only. There is no merge and no conflict resolution here, which is
 * why the interesting failures are all about what the delta *omits*.
 */
class CatalogPullTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Store $store;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->bothModules()->create();
        $this->store = Store::factory()->for($this->account)->create();
        $this->device = Device::factory()->for($this->account)->for($this->store)->create();

        Sanctum::actingAs($this->device, [Device::ABILITY_PULL]);
    }

    public function test_a_first_pull_with_no_cursor_returns_the_whole_catalog(): void
    {
        $product = Product::factory()->for($this->account)->retail()->create();
        ProductVariant::factory()->for($product)->create();

        $response = $this->pull();

        $response->assertOk()
            ->assertJsonCount(1, 'changes.products')
            ->assertJsonCount(1, 'changes.product_variants')
            ->assertJsonCount(1, 'changes.stores');

        $this->assertNull($response->json('since'));
        $this->assertNotNull($response->json('server_time'));
    }

    /**
     * Scenario 6 — a product is discontinued in the backoffice.
     *
     * The row must appear in the delta carrying is_active:false. Filtering deactivated rows
     * out means the till is never told, and the product sits on the catalog screen forever.
     * This is the classic incremental-sync bug and it is invisible unless tested exactly
     * this way: deactivate, pull, and look for the row rather than for its absence.
     */
    public function test_a_deactivated_product_arrives_in_the_delta_rather_than_vanishing(): void
    {
        $product = Product::factory()->for($this->account)->retail()->create();

        $cursor = $this->pull()->json('server_time');

        // Nothing has changed since that cursor.
        $this->assertSame([], $this->pull($cursor)->json('changes.products'));

        $this->travelTo(Carbon::now()->addSecond());
        $product->update(['is_active' => false]);

        $delta = $this->pull($cursor)->json('changes.products');

        $this->assertCount(1, $delta, 'A discontinued product must be mentioned, not silently dropped.');
        $this->assertSame($product->id, $delta[0]['id']);
        $this->assertFalse((bool) $delta[0]['is_active']);
    }

    public function test_a_deactivated_variant_also_rides_along(): void
    {
        $product = Product::factory()->for($this->account)->retail()->create();
        $variant = ProductVariant::factory()->for($product)->create();

        $cursor = $this->pull()->json('server_time');

        $this->travelTo(Carbon::now()->addSecond());
        $variant->update(['is_active' => false]);

        $delta = $this->pull($cursor)->json('changes.product_variants');

        $this->assertCount(1, $delta);
        $this->assertFalse((bool) $delta[0]['is_active']);
    }

    public function test_the_delta_only_contains_rows_changed_since_the_cursor(): void
    {
        Product::factory()->for($this->account)->retail()->create();

        $cursor = $this->pull()->json('server_time');

        $this->travelTo(Carbon::now()->addSecond());
        $second = Product::factory()->for($this->account)->retail()->create();

        $delta = $this->pull($cursor)->json('changes.products');

        $this->assertCount(1, $delta);
        $this->assertSame($second->id, $delta[0]['id']);
    }

    /**
     * The till stores the server's own timestamp as its next cursor rather than reading its
     * own clock. A device running three hours slow would otherwise re-request the same rows
     * forever; one running fast would skip rows silently, which is worse.
     */
    public function test_the_cursor_comes_from_the_server_clock(): void
    {
        $this->travelTo(Carbon::parse('2026-08-13T09:00:00+00:00'));

        $serverTime = $this->pull()->json('server_time');

        // The server's clock, whatever the caller's is.
        $this->assertSame('2026-08-13T09:00:00+00:00', $serverTime);

        // A cursor from the future is what a badly skewed till would send if it used its own
        // clock. The server still answers coherently — with an empty delta — rather than
        // erroring.
        $future = Carbon::now()->addHours(3)->toIso8601String();
        $this->pull($future)->assertOk()->assertJsonPath('changes.products', []);
    }

    public function test_recipes_and_ingredients_come_down_so_expansion_can_happen_on_the_till(): void
    {
        $menuItem = Product::factory()->for($this->account)->menuItem()->create();
        $ingredient = Ingredient::factory()->for($this->account)->create();
        RecipeLine::factory()->for($menuItem)->for($ingredient)->create(['quantity' => '18.000']);

        $response = $this->pull();

        $response->assertOk()
            ->assertJsonCount(1, 'changes.ingredients')
            ->assertJsonCount(1, 'changes.recipe_lines');
    }

    /**
     * Stock is deliberately absent. The till's stock figure is an advisory hint for greying
     * a tile; shipping authoritative-looking numbers down would create exactly the illusion
     * of authority the design refuses it, and the first thing built on that illusion would
     * be a blocked sale.
     */
    public function test_stock_levels_are_not_pulled(): void
    {
        $this->pull()->assertOk()->assertJsonMissingPath('changes.stock_levels');
    }

    public function test_password_and_pin_hashes_never_leave_the_server(): void
    {
        User::factory()->for($this->account)->cashier()->create();

        $users = $this->pull()->json('changes.users');

        $this->assertCount(1, $users);
        $this->assertArrayNotHasKey('password', $users[0]);
        $this->assertArrayNotHasKey('pin_hash', $users[0]);
        $this->assertArrayHasKey('role', $users[0]);
    }

    /**
     * Entitlements are re-read on every pull, not just at login: a till that stays signed in
     * for three weeks must not still be running on three-week-old modules.
     */
    public function test_the_pull_carries_the_accounts_current_modules(): void
    {
        $this->pull()->assertOk()->assertJsonPath('enabled_modules', ['retail', 'fnb']);

        $this->account->update(['enabled_modules' => ['retail']]);

        $this->pull()->assertOk()->assertJsonPath('enabled_modules', ['retail']);
    }

    public function test_another_accounts_catalog_is_never_returned(): void
    {
        $stranger = Account::factory()->retailOnly()->create();
        Product::factory()->for($stranger)->retail()->create();
        User::factory()->for($stranger)->owner()->create();

        $response = $this->pull();

        $response->assertOk()
            ->assertJsonCount(0, 'changes.products')
            ->assertJsonCount(0, 'changes.users');
    }

    private function pull(?string $since = null)
    {
        return $this->getJson(route('sync.pull', array_filter(['since' => $since])));
    }
}
