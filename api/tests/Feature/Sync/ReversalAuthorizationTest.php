<?php

namespace Tests\Feature\Sync;

use App\Enums\LedgerEntryType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
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
 * Voids and refunds, from the server's side.
 *
 * The till prompts for a manager PIN, and that prompt is theatre on its own — a rebuilt APK
 * skips it, and the device sits on a counter in a shop rather than in a vault. These tests
 * cover the check that actually decides, which is why it reads the users table on the server
 * instead of believing the payload.
 */
class ReversalAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Store $store;

    private Device $device;

    private ProductVariant $variant;

    private TillSimulator $till;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->bothModules()->create();
        $this->store = Store::factory()->for($this->account)->create();
        $this->device = Device::factory()->for($this->account)->for($this->store)->create();

        $product = Product::factory()->for($this->account)->retail()->create();
        $this->variant = ProductVariant::factory()->for($product)->create();

        Sanctum::actingAs($this->device, [Device::ABILITY_PUSH, Device::ABILITY_PULL]);
        $this->till = new TillSimulator($this->device);
    }

    public function test_a_void_authorised_by_a_manager_is_accepted(): void
    {
        $manager = User::factory()->for($this->account)->manager()->create();
        $sale = $this->completedSale();

        $response = $this->pushVoid($sale, $manager);

        $response->assertOk()->assertJsonPath('results.0.status', 'accepted');
        $this->assertSame(2, DB::table('ledger_entries')->count());
    }

    public function test_an_owner_may_also_authorise(): void
    {
        $owner = User::factory()->for($this->account)->owner()->create();
        $sale = $this->completedSale();

        $this->pushVoid($sale, $owner)->assertOk()->assertJsonPath('results.0.status', 'accepted');
    }

    /**
     * The definition-of-done case: refused at the API, not merely hidden in the UI.
     */
    public function test_a_void_authorised_by_a_cashier_is_rejected_at_the_api(): void
    {
        $cashier = User::factory()->for($this->account)->cashier()->create();
        $sale = $this->completedSale();

        $this->pushVoid($sale, $cashier)
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.reason', 'unauthorized_reversal');

        $this->assertSame(1, DB::table('ledger_entries')->count(), 'The sale stands; nothing reverses it.');
        $this->assertSame(
            OrderStatus::Created->value,
            DB::table('orders')->where('id', $sale['order_id'])->value('status'),
        );
    }

    public function test_a_void_with_no_authoriser_at_all_is_rejected(): void
    {
        $sale = $this->completedSale();

        $this->pushVoid($sale, null)
            ->assertOk()
            ->assertJsonPath('results.0.reason', 'unauthorized_reversal');
    }

    /**
     * A manager who has been deactivated cannot authorise anything. The role is read from
     * the row on every reversal rather than trusted from whatever the till last pulled.
     */
    public function test_a_deactivated_manager_cannot_authorise(): void
    {
        $manager = User::factory()->for($this->account)->manager()->inactive()->create();
        $sale = $this->completedSale();

        $this->pushVoid($sale, $manager)
            ->assertOk()
            ->assertJsonPath('results.0.reason', 'unauthorized_reversal');
    }

    /**
     * A manager belonging to somebody else's account is not a manager here.
     */
    public function test_a_manager_from_another_account_cannot_authorise(): void
    {
        $stranger = Account::factory()->retailOnly()->create();
        $manager = User::factory()->for($stranger)->manager()->create();
        $sale = $this->completedSale();

        $this->pushVoid($sale, $manager)
            ->assertOk()
            ->assertJsonPath('results.0.reason', 'unauthorized_reversal');
    }

    /**
     * A sale needs no authoriser, and requiring one would break every ordinary checkout.
     */
    public function test_an_ordinary_sale_needs_no_authoriser(): void
    {
        $this->completedSale();

        $this->assertSame(1, DB::table('ledger_entries')->count());
    }

    /**
     * The order's voided status is derived from the ledger entry landing, not set by the
     * till: every sync write is an insert, so a status change could not have reached the
     * server as an update even if we wanted to trust the client with one.
     */
    public function test_the_order_is_marked_voided_by_the_arrival_of_the_void_entry(): void
    {
        $manager = User::factory()->for($this->account)->manager()->create();
        $sale = $this->completedSale();

        $this->assertSame(
            OrderStatus::Created->value,
            DB::table('orders')->where('id', $sale['order_id'])->value('status'),
        );

        $this->pushVoid($sale, $manager)->assertOk();

        $order = DB::table('orders')->where('id', $sale['order_id'])->first();

        $this->assertSame(OrderStatus::Voided->value, $order->status);
        $this->assertSame(PaymentStatus::Refunded->value, $order->payment_status);
    }

    /**
     * The original entry is untouched. The reversal is a new row pointing at it, and the
     * net position is a plain SUM over both.
     */
    public function test_the_original_sale_entry_is_byte_identical_afterwards(): void
    {
        $manager = User::factory()->for($this->account)->manager()->create();
        $sale = $this->completedSale();

        $before = DB::table('ledger_entries')->where('order_id', $sale['order_id'])->first();

        $this->pushVoid($sale, $manager)->assertOk();

        $after = DB::table('ledger_entries')->where('id', $before->id)->first();

        $this->assertEquals($before, $after);
        $this->assertSame(
            0,
            (int) DB::table('ledger_entries')->where('order_id', $sale['order_id'])->sum('amount'),
        );
    }

    /**
     * The PIN hash reaches the till for managers and owners, so an offline void can be
     * gated at the counter — and never for a cashier, whose PIN the till has no reason to
     * check.
     */
    public function test_the_pull_carries_pin_hashes_only_for_users_who_may_authorise(): void
    {
        User::factory()->for($this->account)->manager()->create(['name' => 'Manager']);
        User::factory()->for($this->account)->cashier()->create(['name' => 'Cashier']);

        $users = collect($this->getJson(route('sync.pull'))->json('changes.users'));

        $this->assertNotNull($users->firstWhere('role', UserRole::Manager->value)['pin_hash']);
        $this->assertNull($users->firstWhere('role', UserRole::Cashier->value)['pin_hash']);
    }

    public function test_the_login_password_hash_never_leaves_the_server(): void
    {
        User::factory()->for($this->account)->manager()->create();

        $users = $this->getJson(route('sync.pull'))->json('changes.users');

        $this->assertArrayNotHasKey('password', $users[0]);
    }

    /** @return array{order_id: string, ledger_id: string, amount: int} */
    private function completedSale(): array
    {
        $sale = $this->till->ringUpSale($this->variant->id);

        $this->postJson(route('sync.push'), $this->till->batch($sale['entries']))->assertOk();

        $ledger = DB::table('ledger_entries')->where('order_id', $sale['order_id'])->first();

        return [
            'order_id' => $sale['order_id'],
            'ledger_id' => $ledger->id,
            'amount' => (int) $ledger->amount,
        ];
    }

    private function pushVoid(array $sale, ?User $authorizer)
    {
        $entry = $this->till->entry('ledger_entry', $id = (string) Str::uuid7(), [
            'id' => $id,
            'account_id' => $this->account->id,
            'store_id' => $this->store->id,
            'order_id' => $sale['order_id'],
            'entry_type' => LedgerEntryType::Void->value,
            // Negative, so the net position is a SUM with no case analysis.
            'amount' => -$sale['amount'],
            'reverses_entry_id' => $sale['ledger_id'],
            'payment_method' => PaymentMethod::Cash->value,
            'authorized_by_user_id' => $authorizer?->id,
            'metadata' => null,
            'occurred_at' => now()->toIso8601String(),
        ]);

        return $this->postJson(route('sync.push'), $this->till->batch([$entry]));
    }
}
