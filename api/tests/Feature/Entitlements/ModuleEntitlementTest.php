<?php

namespace Tests\Feature\Entitlements;

use App\Enums\FulfillmentType;
use App\Enums\StockableType;
use App\Models\Account;
use App\Models\Device;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\TillSimulator;
use Tests\TestCase;

/**
 * The security boundary for module entitlement.
 *
 * Everything the till and the backoffice do about modules is UX — a rebuilt client shows
 * whatever it likes. These tests are hand-crafted requests rather than UI assertions,
 * because "the button isn't there" is not an access control.
 */
class ModuleEntitlementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The definition-of-done case, verified by request rather than by UI absence.
     */
    public function test_an_fnb_endpoint_returns_403_for_a_retail_only_account(): void
    {
        $user = $this->userFor(Account::factory()->retailOnly());

        Sanctum::actingAs($user, ['backoffice']);

        $this->getJson(route('ingredients.index'))->assertForbidden();
        $this->getJson(route('variants.index'))->assertOk();
    }

    public function test_a_retail_endpoint_returns_403_for_an_fnb_only_account(): void
    {
        $user = $this->userFor(Account::factory()->fnbOnly());

        Sanctum::actingAs($user, ['backoffice']);

        $this->getJson(route('variants.index'))->assertForbidden();
        $this->getJson(route('ingredients.index'))->assertOk();
    }

    public function test_an_account_with_both_modules_reaches_both(): void
    {
        $user = $this->userFor(Account::factory()->bothModules());

        Sanctum::actingAs($user, ['backoffice']);

        $this->getJson(route('ingredients.index'))->assertOk();
        $this->getJson(route('variants.index'))->assertOk();
    }

    /**
     * The reason entitlement is read from the row rather than the token.
     *
     * A token issued this morning carries this morning's entitlements. If disabling a module
     * only took effect at expiry, an admin revoking access would have no idea when — or
     * whether — it had actually happened.
     */
    public function test_revoking_a_module_takes_effect_on_the_very_next_request(): void
    {
        $account = Account::factory()->bothModules()->create();
        $user = User::factory()->for($account)->owner()->create();

        $token = $user->createToken('backoffice', ['backoffice'])->plainTextToken;

        $this->withToken($token)->getJson(route('ingredients.index'))->assertOk();

        // Same token, no re-login.
        $account->update(['enabled_modules' => ['retail']]);

        $this->withToken($token)->getJson(route('ingredients.index'))->assertForbidden();
    }

    public function test_customers_are_shared_and_never_gated_by_module(): void
    {
        foreach ([Account::factory()->retailOnly(), Account::factory()->fnbOnly()] as $factory) {
            $user = $this->userFor($factory);

            Sanctum::actingAs($user, ['backoffice']);

            $this->getJson(route('customers.index'))->assertOk();
        }
    }

    // ---- the module toggle ------------------------------------------------

    public function test_an_owner_may_change_the_accounts_modules(): void
    {
        $account = Account::factory()->retailOnly()->create();
        $owner = User::factory()->for($account)->owner()->create();

        Sanctum::actingAs($owner, ['backoffice']);

        $this->patchJson(route('account.modules.update'), ['enabled_modules' => ['retail', 'fnb']])
            ->assertOk()
            ->assertJsonPath('data.enabled_modules', ['retail', 'fnb']);

        $this->getJson(route('ingredients.index'))->assertOk();
    }

    public function test_a_manager_may_not_change_the_accounts_modules(): void
    {
        $account = Account::factory()->bothModules()->create();
        $manager = User::factory()->for($account)->manager()->create();

        Sanctum::actingAs($manager, ['backoffice']);

        $this->patchJson(route('account.modules.update'), ['enabled_modules' => ['retail']])
            ->assertForbidden();
    }

    /**
     * An account with no modules is prevented, not handled. The API refuses it and so does a
     * database CHECK behind it — so even a direct write cannot produce the state.
     */
    public function test_an_account_cannot_be_stripped_of_every_module(): void
    {
        $account = Account::factory()->bothModules()->create();
        $owner = User::factory()->for($account)->owner()->create();

        Sanctum::actingAs($owner, ['backoffice']);

        $this->patchJson(route('account.modules.update'), ['enabled_modules' => []])
            ->assertStatus(422);

        $this->assertSame(['retail', 'fnb'], $account->fresh()->enabled_modules);
    }

    // ---- sync push --------------------------------------------------------

    /**
     * The definition-of-done case for sync: the offending entry is rejected on its own and
     * the rest of the batch still lands. A batch-level refusal here would wedge the till's
     * queue over a single mis-configured product.
     */
    public function test_an_fnb_entry_is_rejected_while_the_rest_of_the_batch_lands(): void
    {
        $account = Account::factory()->retailOnly()->create();
        $store = Store::factory()->for($account)->create();
        $device = Device::factory()->for($account)->for($store)->create();

        $product = Product::factory()->for($account)->retail()->create();
        $variant = ProductVariant::factory()->for($product)->create();

        Sanctum::actingAs($device, [Device::ABILITY_PUSH]);

        $till = new TillSimulator($device);

        // A perfectly ordinary retail sale.
        $retail = $till->ringUpSale($variant->id);

        // And a kitchen order this account has no business making.
        $fnb = $till->ringUpSale(
            $variant->id,
            fulfillmentType: FulfillmentType::Routed,
            productName: 'Cheeseburger',
        );

        $response = $this->postJson(
            route('sync.push'),
            $till->batch([...$retail['entries'], ...$fnb['entries']]),
        );

        $response->assertOk();

        $results = collect($response->json('results'));

        $reasons = $results->where('status', 'rejected')->pluck('reason')->countBy();

        // The order itself is refused for the module.
        $this->assertSame(1, $reasons['module_not_enabled']);

        // Its ledger entry and stock movement then fail on the missing order, which is the
        // correct cascade rather than a second bug: an aggregate that was refused must not
        // leave its parts scattered on the server.
        $this->assertSame(2, $reasons['missing_dependency']);

        // Meanwhile the retail sale — order, ledger entry and movement — landed untouched.
        $this->assertSame(3, $results->where('status', 'accepted')->count());
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame($retail['order_id'], DB::table('orders')->value('id'));
        $this->assertSame(1, DB::table('ledger_entries')->count());
    }

    /**
     * An ingredient movement is F&B business by virtue of what it moves, regardless of what
     * the order looked like.
     */
    public function test_an_ingredient_movement_is_rejected_for_a_retail_only_account(): void
    {
        $account = Account::factory()->retailOnly()->create();
        $store = Store::factory()->for($account)->create();
        $device = Device::factory()->for($account)->for($store)->create();

        Sanctum::actingAs($device, [Device::ABILITY_PUSH]);

        $till = new TillSimulator($device);

        $sale = $till->ringUpSale(
            (string) Str::uuid7(),
            stockableType: StockableType::Ingredient,
        );

        $movement = collect($sale['entries'])->firstWhere('entity_type', 'stock_movement');

        $this->postJson(route('sync.push'), $till->batch([$movement]))
            ->assertOk()
            ->assertJsonPath('results.0.reason', 'module_not_enabled');
    }

    /**
     * A retail sale from a dual-vertical shop is still a retail sale. The branch is per item,
     * never per account, and the schema has supported mixed carts since day one.
     */
    public function test_a_routed_order_is_accepted_when_the_account_has_fnb(): void
    {
        $account = Account::factory()->bothModules()->create();
        $store = Store::factory()->for($account)->create();
        $device = Device::factory()->for($account)->for($store)->create();

        $product = Product::factory()->for($account)->menuItem()->create();
        $variant = ProductVariant::factory()->for($product)->create();

        Sanctum::actingAs($device, [Device::ABILITY_PUSH]);

        $till = new TillSimulator($device);
        $sale = $till->ringUpSale($variant->id, fulfillmentType: FulfillmentType::Routed);

        $response = $this->postJson(route('sync.push'), $till->batch($sale['entries']));

        $response->assertOk();
        $this->assertSame(3, collect($response->json('results'))->where('status', 'accepted')->count());
    }

    private function userFor($accountFactory): User
    {
        $account = $accountFactory->create();

        return User::factory()->for($account)->owner()->create();
    }
}
