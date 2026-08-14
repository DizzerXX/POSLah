<?php

namespace Tests\Feature\Backoffice;

use App\Models\Account;
use App\Models\Device;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Registering a till.
 *
 * The receipt prefix is the reason this exists: it is assigned here, and it is what lets two
 * offline tills number receipts independently without colliding.
 */
class DeviceRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->bothModules()->create();
        $this->store = Store::factory()->for($this->account)->create();
        $this->owner = User::factory()->for($this->account)->owner()->create();
    }

    public function test_registering_a_till_returns_a_token_once(): void
    {
        Sanctum::actingAs($this->owner, ['backoffice']);

        $response = $this->postJson(route('devices.store'), [
            'device_name' => 'Cafe Counter',
            'store_id' => $this->store->id,
            'number_prefix' => 'T2',
            'assigned_module' => 'fnb',
        ]);

        $response->assertCreated()->assertJsonPath('data.number_prefix', 'T2');

        $token = $response->json('data.token');
        $this->assertNotNull($token);

        // actingAs pins a user resolver for the rest of the test, so the bearer header would
        // otherwise be ignored and every request would still be the owner's.
        $this->app['auth']->forgetGuards();

        // The token works, and the till learns everything else from its first pull.
        $this->withToken($token)->getJson(route('sync.pull'))
            ->assertOk()
            ->assertJsonPath('device.number_prefix', 'T2')
            ->assertJsonPath('device.assigned_module', 'fnb');

        // Listing devices never re-issues it.
        Sanctum::actingAs($this->owner, ['backoffice']);
        $this->assertArrayNotHasKey('token', $this->getJson(route('devices.index'))->json('data.0'));
    }

    /**
     * Two tills in one store cannot share a prefix, or "T1-0042" stops being unique the
     * moment both are offline.
     */
    public function test_a_prefix_cannot_be_reused_within_a_store(): void
    {
        Device::factory()->for($this->account)->for($this->store)->create(['number_prefix' => 'T1']);

        Sanctum::actingAs($this->owner, ['backoffice']);

        $this->postJson(route('devices.store'), [
            'device_name' => 'Second Counter',
            'store_id' => $this->store->id,
            'number_prefix' => 'T1',
        ])->assertStatus(422)->assertJsonValidationErrorFor('number_prefix');
    }

    public function test_a_manager_may_not_register_a_till(): void
    {
        Sanctum::actingAs(User::factory()->for($this->account)->manager()->create(), ['backoffice']);

        $this->postJson(route('devices.store'), [
            'device_name' => 'Counter',
            'store_id' => $this->store->id,
            'number_prefix' => 'T9',
        ])->assertForbidden();
    }

    public function test_a_till_cannot_be_registered_into_another_accounts_store(): void
    {
        $stranger = Store::factory()->for(Account::factory()->retailOnly())->create();

        Sanctum::actingAs($this->owner, ['backoffice']);

        $this->postJson(route('devices.store'), [
            'device_name' => 'Counter',
            'store_id' => $stranger->id,
            'number_prefix' => 'T9',
        ])->assertStatus(422);
    }

    /**
     * Revoking access means revoking the credential, not flipping a flag and hoping.
     */
    public function test_deactivating_a_till_deletes_its_tokens(): void
    {
        $device = Device::factory()->for($this->account)->for($this->store)->create();
        $token = $device->createToken('till', [Device::ABILITY_PULL])->plainTextToken;

        $this->withToken($token)->getJson(route('sync.pull'))->assertOk();

        Sanctum::actingAs($this->owner, ['backoffice']);


        $this->patchJson(route('devices.update', $device), ['is_active' => false])->assertOk();

        $this->assertSame(0, DB::table('personal_access_tokens')
            ->where('tokenable_id', $device->id)->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson(route('sync.pull'))->assertUnauthorized();
    }
}
