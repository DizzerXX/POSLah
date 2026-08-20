<?php

namespace Tests\Feature\Backoffice;

use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\StockExceptionStatus;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\StockException;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The dashboard's figures.
 *
 * The one thing worth testing hard here is *which clock* they use. A till's clock is
 * untrusted and not monotonic across devices, so a day's takings computed from `occurred_at`
 * would move depending on which device ran fast — and an offline sale synced on Wednesday
 * would land in Monday's total.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->bothModules()->create(['timezone' => 'Asia/Kuala_Lumpur']);
        $this->store = Store::factory()->for($this->account)->create();
        $this->owner = User::factory()->for($this->account)->owner()->create();

        Sanctum::actingAs($this->owner, ['backoffice']);
    }

    public function test_it_reports_todays_sales_and_income(): void
    {
        $this->sale(4_500);
        $this->sale(2_000);

        $response = $this->getJson(route('dashboard'));

        $response->assertOk()
            ->assertJsonPath('data.today.sales', 2)
            ->assertJsonPath('data.today.income', 6_500)
            ->assertJsonPath('data.currency', 'MYR');
    }

    /**
     * Takings are a plain SUM because reversals are negative rows. A refund reduces the day
     * without anything being edited or special-cased.
     */
    public function test_a_refund_reduces_takings_without_editing_history(): void
    {
        $sale = $this->sale(5_000);

        LedgerEntry::factory()->reversing($sale)->create(['recorded_at' => Carbon::now()]);

        $this->getJson(route('dashboard'))->assertOk()->assertJsonPath('data.today.income', 0);
    }

    /**
     * The load-bearing case: an offline sale that happened days ago but only reached the
     * server today counts as today's income, because that is when the money was recorded.
     */
    public function test_income_follows_the_server_clock_not_the_till_clock(): void
    {
        $this->sale(3_000, occurredAt: Carbon::now()->subDays(3), recordedAt: Carbon::now());

        $this->getJson(route('dashboard'))->assertOk()->assertJsonPath('data.today.income', 3_000);
    }

    public function test_yesterdays_takings_are_not_counted_as_todays(): void
    {
        $this->sale(9_000, recordedAt: Carbon::now('Asia/Kuala_Lumpur')->startOfDay()->subHour()->utc());

        $this->getJson(route('dashboard'))
            ->assertOk()
            ->assertJsonPath('data.today.income', 0)
            ->assertJsonPath('data.today.sales', 0);
    }

    public function test_it_counts_open_stock_exceptions_and_active_staff(): void
    {
        StockException::factory()->for($this->store)->create();
        StockException::factory()->for($this->store)->create([
            'status' => StockExceptionStatus::Resolved,
            'resolved_at' => now(),
        ]);

        User::factory()->for($this->account)->cashier()->create();
        User::factory()->for($this->account)->cashier()->inactive()->create();

        $response = $this->getJson(route('dashboard'));

        $response->assertOk()->assertJsonPath('data.open_stock_exceptions', 1);
        // The owner plus one active cashier; the deactivated one is not staff on shift.
        $this->assertSame(2, $response->json('data.staff'));
    }

    public function test_the_trend_covers_twelve_months_ending_this_one(): void
    {
        $series = $this->getJson(route('dashboard'))->json('data.monthly_income');

        $this->assertCount(12, $series);
        $this->assertSame(
            Carbon::now('Asia/Kuala_Lumpur')->format('Y-m'),
            $series[11]['key'],
            'The series ends on the current month.',
        );
    }

    public function test_the_payment_mix_groups_by_method(): void
    {
        $this->sale(1_000, method: PaymentMethod::Cash);
        $this->sale(2_000, method: PaymentMethod::Cash);
        $this->sale(5_000, method: PaymentMethod::Card);

        $mix = collect($this->getJson(route('dashboard'))->json('data.payment_mix'))
            ->keyBy('method');

        $this->assertSame(2, $mix['cash']['orders']);
        $this->assertSame(3_000, $mix['cash']['total']);
        $this->assertSame(5_000, $mix['card']['total']);
    }

    public function test_another_accounts_figures_are_never_included(): void
    {
        $stranger = Account::factory()->retailOnly()->create();
        $strangerStore = Store::factory()->for($stranger)->create();

        LedgerEntry::factory()->for($stranger)->for($strangerStore)->create([
            'amount' => 99_999,
            'recorded_at' => Carbon::now(),
        ]);

        $this->getJson(route('dashboard'))->assertOk()->assertJsonPath('data.today.income', 0);
    }

    public function test_it_requires_a_user_token(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson(route('dashboard'))->assertUnauthorized();
    }

    private function sale(
        int $amount,
        ?Carbon $occurredAt = null,
        ?Carbon $recordedAt = null,
        PaymentMethod $method = PaymentMethod::Cash,
    ): LedgerEntry {
        $recordedAt ??= Carbon::now();

        $order = Order::factory()->for($this->account)->for($this->store)->create([
            'occurred_at' => $occurredAt ?? $recordedAt,
            'recorded_at' => $recordedAt,
        ]);

        return LedgerEntry::factory()->for($this->account)->for($this->store)->for($order)->create([
            'entry_type' => LedgerEntryType::Sale,
            'amount' => $amount,
            'payment_method' => $method,
            'occurred_at' => $occurredAt ?? $recordedAt,
            'recorded_at' => $recordedAt,
        ]);
    }
}
