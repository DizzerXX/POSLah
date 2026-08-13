<?php

namespace Tests\Feature\Sync;

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
 * A till pushes financial rows. Everything here is the boundary that stops a token from
 * writing sales it has no business writing.
 *
 * These checks are server-side by necessity: the till is an app on a device in a shop, and
 * anything it enforces about itself can be removed by rebuilding it.
 */
class SyncAuthorizationTest extends TestCase
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
        $this->device = Device::factory()->for($this->account)->for($this->store)->create();

        $product = Product::factory()->for($this->account)->retail()->create();
        $this->variant = ProductVariant::factory()->for($product)->create();
    }

    public function test_pushing_without_a_token_is_unauthenticated(): void
    {
        $till = new TillSimulator($this->device);

        $this->postJson(route('sync.push'), $till->batch($till->ringUpSale($this->variant->id)['entries']))
            ->assertUnauthorized();
    }

    public function test_pulling_without_a_token_is_unauthenticated(): void
    {
        $this->getJson(route('sync.pull'))->assertUnauthorized();
    }

    /**
     * A real token, end to end, rather than Sanctum::actingAs — this is the only test that
     * proves the issuing path and the guard agree with each other.
     */
    public function test_a_real_device_token_authenticates(): void
    {
        $token = $this->device->createToken('till', [Device::ABILITY_PULL, Device::ABILITY_PUSH]);

        $this->withToken($token->plainTextToken)
            ->getJson(route('sync.pull'))
            ->assertOk();
    }

    /**
     * The abilities split exists so a read-only credential cannot invent sales.
     */
    public function test_a_pull_only_token_cannot_push(): void
    {
        $token = $this->device->createToken('till', [Device::ABILITY_PULL]);
        $till = new TillSimulator($this->device);

        $this->withToken($token->plainTextToken)
            ->postJson(route('sync.push'), $till->batch($till->ringUpSale($this->variant->id)['entries']))
            ->assertForbidden();

        $this->assertSame(0, DB::table('orders')->count());
    }

    /**
     * Deactivating a till in the backoffice must stop it syncing on its next request, not
     * whenever its token happens to expire.
     */
    public function test_a_deactivated_device_is_refused(): void
    {
        $token = $this->device->createToken('till', [Device::ABILITY_PULL, Device::ABILITY_PUSH]);

        $this->device->update(['is_active' => false]);

        $this->withToken($token->plainTextToken)
            ->getJson(route('sync.pull'))
            ->assertForbidden();
    }

    /**
     * A user token is not a device token. Sanctum will resolve a token for any tokenable
     * model, so the endpoint has to say what it actually requires.
     */
    public function test_a_user_token_cannot_drive_the_sync_endpoints(): void
    {
        $user = User::factory()->for($this->account)->owner()->create();
        $token = $user->createToken('backoffice', [Device::ABILITY_PUSH, Device::ABILITY_PULL]);

        $this->withToken($token->plainTextToken)
            ->getJson(route('sync.pull'))
            ->assertForbidden();
    }

    public function test_a_device_cannot_push_a_batch_claiming_to_be_another_device(): void
    {
        $other = Device::factory()->for($this->account)->for($this->store)->create();

        Sanctum::actingAs($this->device, [Device::ABILITY_PUSH]);

        $till = new TillSimulator($other);

        $this->postJson(route('sync.push'), $till->batch($till->ringUpSale($this->variant->id)['entries']))
            ->assertForbidden();
    }

    /**
     * The tenancy boundary. A stolen or misconfigured token must not be able to write rows
     * into somebody else's shop — and it fails per entry, with a reason that names the
     * problem, rather than as an opaque 500.
     */
    public function test_entries_belonging_to_another_account_are_rejected(): void
    {
        $stranger = Account::factory()->retailOnly()->create();
        $strangerStore = Store::factory()->for($stranger)->create();

        Sanctum::actingAs($this->device, [Device::ABILITY_PUSH]);

        $till = new TillSimulator($this->device);
        $sale = $till->ringUpSale($this->variant->id);

        $entries = $sale['entries'];
        $entries[0]['payload']['account_id'] = $stranger->id;
        $entries[0]['payload']['store_id'] = $strangerStore->id;

        $response = $this->postJson(route('sync.push'), $till->batch($entries));

        $response->assertOk()->assertJsonPath('results.0.reason', 'foreign_tenant');
        $this->assertSame(0, DB::table('orders')->count());
    }

    /**
     * Provenance must not be self-reported: whatever the payload claims, the row records the
     * device the token belongs to. Without this, "which till drove stock negative?" has an
     * answer the till itself chose.
     */
    public function test_the_recorded_device_comes_from_the_token_not_the_payload(): void
    {
        Sanctum::actingAs($this->device, [Device::ABILITY_PUSH]);

        $till = new TillSimulator($this->device);
        $sale = $till->ringUpSale($this->variant->id);

        $entries = $sale['entries'];
        $entries[0]['payload']['device_id'] = (string) Str::uuid7();

        $this->postJson(route('sync.push'), $till->batch($entries))->assertOk();

        $this->assertSame(
            $this->device->id,
            DB::table('orders')->where('id', $sale['order_id'])->value('device_id'),
        );
    }

    /**
     * Envelope failures are the one place a whole batch may fail, so the boundary has to be
     * somewhere a well-behaved till never lands.
     */
    public function test_an_oversized_batch_is_refused_as_a_client_bug(): void
    {
        Sanctum::actingAs($this->device, [Device::ABILITY_PUSH]);

        $till = new TillSimulator($this->device);

        $entries = [];
        while (count($entries) < 51) {
            $entries[] = $till->entry('customer', (string) Str::uuid7(), []);
        }

        $this->postJson(route('sync.push'), $till->batch($entries))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('entries');
    }

    public function test_an_empty_batch_is_accepted_and_changes_nothing(): void
    {
        Sanctum::actingAs($this->device, [Device::ABILITY_PUSH]);

        $till = new TillSimulator($this->device);

        $this->postJson(route('sync.push'), $till->batch([]))
            ->assertOk()
            ->assertJsonPath('results', []);
    }
}
