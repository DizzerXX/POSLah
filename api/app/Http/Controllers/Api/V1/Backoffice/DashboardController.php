<?php

namespace App\Http\Controllers\Api\V1\Backoffice;

use App\Enums\LedgerEntryType;
use App\Enums\StockExceptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's numbers.
 *
 * Everything here is **ordered and grouped by `recorded_at`, the server clock**, not by
 * `occurred_at`. A till's clock is untrusted and not monotonic across devices, so a day's
 * takings computed from it would move depending on which device was fast — and an offline
 * sale synced on Wednesday would land in Monday's total. Display uses the client clock;
 * anything financial uses this one.
 *
 * The day boundary comes from the account's timezone, which is why changing that setting
 * regroups these figures.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $account = Account::query()->findOrFail($user->account_id);
        $zone = $account->timezone;

        $startOfDay = Carbon::now($zone)->startOfDay()->utc();

        $storeIds = DB::table('stores')->where('account_id', $account->id)->pluck('id');

        return response()->json([
            'data' => [
                'today' => [
                    'sales' => DB::table('orders')
                        ->whereIn('store_id', $storeIds)
                        ->where('recorded_at', '>=', $startOfDay)
                        ->count(),
                    // A plain SUM: reversals are negative rows, so takings need no case
                    // analysis and no edit of history to arrive at.
                    'income' => (int) DB::table('ledger_entries')
                        ->whereIn('store_id', $storeIds)
                        ->where('recorded_at', '>=', $startOfDay)
                        ->sum('amount'),
                ],
                'open_stock_exceptions' => DB::table('stock_exceptions')
                    ->whereIn('store_id', $storeIds)
                    ->where('status', StockExceptionStatus::Open->value)
                    ->count(),
                'staff' => DB::table('users')
                    ->where('account_id', $account->id)
                    ->where('is_active', true)
                    ->count(),
                'inventory' => [
                    'stockables_tracked' => DB::table('stock_levels')
                        ->whereIn('store_id', $storeIds)->count(),
                    'below_zero' => DB::table('stock_levels')
                        ->whereIn('store_id', $storeIds)
                        ->where('quantity_on_hand', '<', 0)
                        ->count(),
                    'products' => DB::table('products')
                        ->where('account_id', $account->id)->where('is_active', true)->count(),
                ],
                'monthly_income' => $this->monthlyIncome($storeIds, $zone),
                'payment_mix' => $this->paymentMix($storeIds),
                'currency' => $account->currency,
            ],
        ]);
    }

    /** Twelve months of takings, for the trend line. */
    private function monthlyIncome($storeIds, string $zone): array
    {
        $since = Carbon::now($zone)->startOfMonth()->subMonths(11)->utc();

        $rows = DB::table('ledger_entries')
            ->whereIn('store_id', $storeIds)
            ->where('recorded_at', '>=', $since)
            ->selectRaw("to_char(recorded_at AT TIME ZONE ?, 'YYYY-MM') as month, sum(amount) as total", [$zone])
            ->groupBy('month')
            ->pluck('total', 'month');

        $series = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now($zone)->startOfMonth()->subMonths($i);

            $series[] = [
                'month' => $month->format('M'),
                'key' => $month->format('Y-m'),
                'total' => (int) ($rows[$month->format('Y-m')] ?? 0),
            ];
        }

        return $series;
    }

    /** How customers actually paid. v1 records the method; it does not process it. */
    private function paymentMix($storeIds): array
    {
        return DB::table('ledger_entries')
            ->whereIn('store_id', $storeIds)
            ->where('entry_type', LedgerEntryType::Sale->value)
            ->selectRaw('payment_method, count(*) as orders, sum(amount) as total')
            ->groupBy('payment_method')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'method' => $row->payment_method,
                'orders' => (int) $row->orders,
                'total' => (int) $row->total,
            ])
            ->all();
    }
}
