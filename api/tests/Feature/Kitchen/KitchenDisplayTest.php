<?php

namespace Tests\Feature\Kitchen;

use App\Enums\FulfillmentType;
use App\Enums\LineFulfillmentStatus;
use App\Enums\OrderStatus;
use App\Events\OrderLineStatusChanged;
use App\Events\OrderSentToKitchen;
use App\Events\OrderStatusChanged;
use App\Kitchen\OrderStatusDeriver;
use App\Models\Account;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Support\TillSimulator;
use Tests\TestCase;

/**
 * The kitchen display.
 *
 * The load-bearing property is not the socket — it is that **nothing depends on the socket**.
 * WebSocket delivery is not guaranteed, and a missed ticket is a customer waiting for food
 * nobody is cooking. That failure is invisible in development, because a local connection
 * never drops.
 */
class KitchenDisplayTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Store $store;

    private Device $device;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->bothModules()->create();
        $this->store = Store::factory()->for($this->account)->create();
        $this->device = Device::factory()->for($this->account)->for($this->store)->create();
        $this->manager = User::factory()->for($this->account)->manager()->create();
    }

    // ---- tickets ----------------------------------------------------------

    public function test_a_routed_order_becomes_a_kitchen_ticket_when_it_syncs(): void
    {
        Event::fake([OrderSentToKitchen::class, OrderStatusChanged::class]);

        $sale = $this->sellRouted();

        Event::assertDispatched(
            OrderSentToKitchen::class,
            fn (OrderSentToKitchen $event) => $event->order->id === $sale['order_id'],
        );

        Sanctum::actingAs($this->manager, ['backoffice']);

        $this->getJson(route('kitchen.active-orders'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sale['order_id']);
    }

    /**
     * A kitchen screen has no business displaying a bag of coffee beans someone bought
     * alongside their latte.
     */
    public function test_a_retail_order_never_reaches_the_kitchen(): void
    {
        Event::fake([OrderSentToKitchen::class]);

        $this->sellDirect();

        Event::assertNotDispatched(OrderSentToKitchen::class);

        Sanctum::actingAs($this->manager, ['backoffice']);

        $this->getJson(route('kitchen.active-orders'))->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * The mixed case, which v1's till cannot yet create but the payload already handles —
     * because the filter lives in the payload rather than on the screen.
     */
    public function test_a_mixed_order_shows_only_its_routed_lines(): void
    {
        $order = $this->buildOrder([
            ['type' => FulfillmentType::Routed, 'name' => 'Latte'],
            ['type' => FulfillmentType::Direct, 'name' => 'Bag of beans'],
        ]);

        Sanctum::actingAs($this->manager, ['backoffice']);

        $response = $this->getJson(route('kitchen.active-orders'));

        $response->assertOk()->assertJsonCount(1, 'data.0.lines');
        $this->assertSame('Latte', $response->json('data.0.lines.0.name'));
    }

    // ---- derived status ---------------------------------------------------

    /**
     * The definition-of-done case: order status is never set directly. It follows from the
     * lines, because two independently mutable sources of truth for one fact eventually
     * disagree.
     */
    public function test_marking_every_routed_line_ready_derives_the_order_to_ready(): void
    {
        Event::fake([OrderLineStatusChanged::class, OrderStatusChanged::class]);

        $order = $this->buildOrder([
            ['type' => FulfillmentType::Routed, 'name' => 'Latte'],
            ['type' => FulfillmentType::Routed, 'name' => 'Flat White'],
        ]);

        Sanctum::actingAs($this->manager, ['backoffice']);

        $lines = $order->lines()->get();

        // First line bumped: some work started, none finished.
        $this->patchJson(route('kitchen.lines.update', $lines[0]), ['status' => 'ready'])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Preparing->value);

        // Second: everything routed is ready, so the order is.
        $this->patchJson(route('kitchen.lines.update', $lines[1]), ['status' => 'ready'])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Ready->value);

        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);

        // The tills hear about both, which is what lets them update without a refresh.
        Event::assertDispatchedTimes(OrderLineStatusChanged::class, 2);
        Event::assertDispatched(OrderStatusChanged::class);
    }

    public function test_a_direct_line_cannot_be_bumped(): void
    {
        $order = $this->buildOrder([['type' => FulfillmentType::Direct, 'name' => 'Bag of beans']]);

        Sanctum::actingAs($this->manager, ['backoffice']);

        $this->patchJson(route('kitchen.lines.update', $order->lines()->sole()), ['status' => 'ready'])
            ->assertStatus(422);
    }

    /** A voided order stays voided whatever its lines say. */
    public function test_a_void_is_not_undone_by_line_status(): void
    {
        $order = $this->buildOrder([['type' => FulfillmentType::Routed, 'name' => 'Latte']]);
        $order->forceFill(['status' => OrderStatus::Voided])->save();

        $order->lines()->update(['fulfillment_status' => LineFulfillmentStatus::Ready]);

        $this->assertSame(
            OrderStatus::Voided,
            app(OrderStatusDeriver::class)->derive($order->fresh(['lines'])),
        );
    }

    /**
     * The collapse, stated at the derivation itself: with nothing routed there is no kitchen
     * state to be in.
     */
    public function test_an_order_with_no_routed_lines_collapses(): void
    {
        $order = $this->buildOrder([['type' => FulfillmentType::Direct, 'name' => 'Bag of beans']]);

        $this->assertSame(
            OrderStatus::Created,
            app(OrderStatusDeriver::class)->derive($order->fresh(['lines'])),
        );
    }

    // ---- the polling safety net -------------------------------------------

    /**
     * **The failure this step exists to handle.**
     *
     * A ticket is created while nothing is listening — the socket is down, the tab was
     * suspended, Reverb restarted. No event reaches the screen. The poll must still return
     * it, because the poll is the source of truth and the socket is only an optimisation.
     *
     * The client half of this — reconciling a poll against local state — is tested in the
     * Angular suite.
     */
    public function test_a_ticket_created_with_no_listener_is_still_returned_by_the_poll(): void
    {
        // Nothing is listening. Faked narrowly on purpose: a bare Event::fake() also fakes
        // Eloquent's model events, and this codebase mints UUIDv7 keys in a `creating`
        // hook — so a blanket fake silently produces rows with null primary keys.
        Event::fake([OrderSentToKitchen::class, OrderStatusChanged::class]);

        $sale = $this->sellRouted();

        Sanctum::actingAs($this->manager, ['backoffice']);

        $response = $this->getJson(route('kitchen.active-orders'));

        $response->assertOk()->assertJsonPath('data.0.id', $sale['order_id']);
        $this->assertNotNull($response->json('server_time'));
    }

    /**
     * An order made offline arrives late and its elapsed timer is large — which is correct
     * and informative rather than a bug, because a ticket's age is measured from when the
     * customer ordered.
     */
    public function test_a_late_synced_ticket_carries_its_original_placed_at(): void
    {
        $placedAt = now()->subMinutes(37);

        $order = $this->buildOrder(
            [['type' => FulfillmentType::Routed, 'name' => 'Latte']],
            occurredAt: $placedAt,
        );

        Sanctum::actingAs($this->manager, ['backoffice']);

        $response = $this->getJson(route('kitchen.active-orders'));

        $this->assertSame(
            $placedAt->toIso8601String(),
            $response->json('data.0.placed_at'),
            'Elapsed time runs from when the customer ordered, not from when we heard about it.',
        );
        $this->assertSame($order->id, $response->json('data.0.id'));
    }

    // ---- the boundary -----------------------------------------------------

    /**
     * The definition-of-done case: a retail-only account is refused the kitchen, verified at
     * the endpoint rather than by the button being absent.
     */
    public function test_a_retail_only_account_cannot_reach_the_kitchen_endpoints(): void
    {
        $retail = Account::factory()->retailOnly()->create();
        $owner = User::factory()->for($retail)->owner()->create();

        Sanctum::actingAs($owner, ['backoffice']);

        $this->getJson(route('kitchen.active-orders'))->assertForbidden();
    }

    /**
     * And the same refusal on the socket. Authentication is not enough: a retail-only account
     * holds perfectly valid credentials, so the broadcast auth callback checks entitlement
     * too — otherwise the kitchen would be readable by listening even while the API refused.
     */
    public function test_a_retail_only_account_cannot_subscribe_to_the_kitchen_channel(): void
    {
        $retail = Account::factory()->retailOnly()->create();
        $store = Store::factory()->for($retail)->create();
        $owner = User::factory()->for($retail)->owner()->create();

        Sanctum::actingAs($owner, ['backoffice']);

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-store.{$store->id}.kitchen",
        ])->assertForbidden();

        // The tills channel is not module-gated, so the same credential is accepted there —
        // proving the refusal above is about entitlement rather than about authentication.
        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-store.{$store->id}.tills",
        ])->assertOk();
    }

    public function test_an_fnb_account_may_subscribe_to_its_own_kitchen(): void
    {
        Sanctum::actingAs($this->manager, ['backoffice']);

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-store.{$this->store->id}.kitchen",
        ])->assertOk();
    }

    public function test_another_stores_kitchen_is_refused(): void
    {
        $stranger = Store::factory()->for(Account::factory()->fnbOnly())->create();

        Sanctum::actingAs($this->manager, ['backoffice']);

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-store.{$stranger->id}.kitchen",
        ])->assertForbidden();
    }

    /** A till has no business on the backoffice channel. */
    public function test_a_device_cannot_subscribe_to_the_backoffice_channel(): void
    {
        Sanctum::actingAs($this->device, [Device::ABILITY_PULL]);

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-account.{$this->account->id}.backoffice",
        ])->assertForbidden();
    }

    // ---- helpers ----------------------------------------------------------

    private function sellRouted(): array
    {
        Sanctum::actingAs($this->device, [Device::ABILITY_PUSH]);

        $product = Product::factory()->for($this->account)->menuItem()->create();
        $variant = ProductVariant::factory()->for($product)->create();

        $till = new TillSimulator($this->device);
        $sale = $till->ringUpSale($variant->id, fulfillmentType: FulfillmentType::Routed);

        $this->postJson(route('sync.push'), $till->batch($sale['entries']))->assertOk();

        return $sale;
    }

    private function sellDirect(): array
    {
        Sanctum::actingAs($this->device, [Device::ABILITY_PUSH]);

        $product = Product::factory()->for($this->account)->retail()->create();
        $variant = ProductVariant::factory()->for($product)->create();

        $till = new TillSimulator($this->device);
        $sale = $till->ringUpSale($variant->id);

        $this->postJson(route('sync.push'), $till->batch($sale['entries']))->assertOk();

        return $sale;
    }

    /** @param  list<array{type: FulfillmentType, name: string}>  $lines */
    private function buildOrder(array $lines, ?Carbon $occurredAt = null): Order
    {
        $order = Order::factory()->for($this->account)->for($this->store)->create([
            'status' => OrderStatus::Sent,
            'occurred_at' => $occurredAt ?? now(),
        ]);

        foreach ($lines as $line) {
            OrderLine::factory()->for($order)->create([
                'name_snapshot' => $line['name'],
                'fulfillment_type' => $line['type'],
                'fulfillment_status' => $line['type'] === FulfillmentType::Routed
                    ? LineFulfillmentStatus::Pending
                    : LineFulfillmentStatus::Fulfilled,
            ]);
        }

        return $order->fresh(['lines']);
    }
}
