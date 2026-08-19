<?php

namespace Tests\Feature\Backoffice;

use App\Models\Account;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Account and store settings.
 *
 * Owner-only, and two of them carry consequences a merchant will not expect — so the API
 * reports what is at stake rather than leaving a confirmation dialog to invent it.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->bothModules()->create([
            'timezone' => 'Asia/Kuala_Lumpur',
            'currency' => 'MYR',
        ]);

        $this->store = Store::factory()->for($this->account)->create(['name' => 'Bangsar Outlet']);
        $this->owner = User::factory()->for($this->account)->owner()->create();

        Sanctum::actingAs($this->owner, ['backoffice']);
    }

    public function test_an_owner_reads_the_current_settings(): void
    {
        $this->getJson(route('settings.show'))
            ->assertOk()
            ->assertJsonPath('data.account.timezone', 'Asia/Kuala_Lumpur')
            ->assertJsonPath('data.account.currency', 'MYR')
            ->assertJsonPath('data.store.name', 'Bangsar Outlet')
            ->assertJsonPath('data.can_edit', true);
    }

    public function test_an_owner_changes_timezone_currency_and_store_contact_details(): void
    {
        $this->patchJson(route('settings.update'), [
            'timezone' => 'Asia/Singapore',
            'currency' => 'SGD',
            'store_email' => 'hello@kedaikopi.test',
            'store_phone' => '03-1234 5678',
            'store_name' => 'Bangsar Flagship',
        ])->assertOk();

        $this->assertSame('Asia/Singapore', $this->account->fresh()->timezone);
        $this->assertSame('SGD', $this->account->fresh()->currency);

        $store = $this->store->fresh();
        $this->assertSame('hello@kedaikopi.test', $store->email);
        $this->assertSame('03-1234 5678', $store->phone);
        $this->assertSame('Bangsar Flagship', $store->name);
    }

    /**
     * Currency is a display setting pretending to be an accounting one: money is stored as
     * minor units with no per-row currency, so switching it relabels history rather than
     * converting it. The API says how much history that is, so the confirmation can be
     * specific instead of asking "are you sure?" about everything equally.
     */
    public function test_the_currency_impact_reports_how_much_history_would_be_relabelled(): void
    {
        $this->assertNull($this->getJson(route('settings.show'))->json('data.impact.currency.note'));

        Order::factory()->count(3)->for($this->account)->for($this->store)->create();

        $impact = $this->getJson(route('settings.show'))->json('data.impact.currency');

        $this->assertSame(3, $impact['orders']);
        $this->assertStringContainsString('without being converted', $impact['note']);
    }

    public function test_the_timezone_impact_warns_that_day_boundaries_move(): void
    {
        $this->assertStringContainsString(
            'Day boundaries',
            $this->getJson(route('settings.show'))->json('data.impact.timezone.note'),
        );
    }

    public function test_a_manager_may_read_but_not_change_settings(): void
    {
        $manager = User::factory()->for($this->account)->manager()->create();

        Sanctum::actingAs($manager, ['backoffice']);

        $this->getJson(route('settings.show'))->assertOk()->assertJsonPath('data.can_edit', false);

        $this->patchJson(route('settings.update'), ['currency' => 'USD'])->assertForbidden();

        $this->assertSame('MYR', $this->account->fresh()->currency);
    }

    public function test_an_unknown_timezone_is_refused(): void
    {
        $this->patchJson(route('settings.update'), ['timezone' => 'Mars/Olympus_Mons'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('timezone');
    }

    /** It ends up on receipts, so it is validated rather than accepted as free text. */
    public function test_a_malformed_currency_is_refused(): void
    {
        foreach (['ringgit', 'my', 'myr', 'MYRR'] as $bad) {
            $this->patchJson(route('settings.update'), ['currency' => $bad])
                ->assertStatus(422)
                ->assertJsonValidationErrorFor('currency');
        }

        $this->assertSame('MYR', $this->account->fresh()->currency);
    }

    /** The column is the fraction orders snapshot, so it is bounded rather than free. */
    public function test_a_tax_rate_outside_zero_to_one_is_refused(): void
    {
        $this->patchJson(route('settings.update'), ['tax_rate' => 6])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('tax_rate');
    }

    public function test_a_partial_update_leaves_everything_else_alone(): void
    {
        $this->patchJson(route('settings.update'), ['store_email' => 'only@this.test'])->assertOk();

        $this->assertSame('MYR', $this->account->fresh()->currency);
        $this->assertSame('Asia/Kuala_Lumpur', $this->account->fresh()->timezone);
        $this->assertSame('Bangsar Outlet', $this->store->fresh()->name);
    }

    public function test_another_accounts_settings_are_never_returned(): void
    {
        $stranger = Account::factory()->retailOnly()->create(['currency' => 'USD']);
        Store::factory()->for($stranger)->create(['name' => 'Not Yours']);

        $this->getJson(route('settings.show'))
            ->assertOk()
            ->assertJsonPath('data.store.name', 'Bangsar Outlet')
            ->assertJsonPath('data.account.currency', 'MYR');
    }
}
