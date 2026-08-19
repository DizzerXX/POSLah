<?php

namespace App\Http\Controllers\Api\V1\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Account and store settings.
 *
 * Owner-only, because these are decisions about the business rather than about a shift.
 *
 * Two of them have consequences a merchant will not expect, so the API reports them rather
 * than leaving the UI to guess:
 *
 *  - **Currency is retroactive.** Money is stored as minor units with no per-row currency, so
 *    changing it relabels every historical figure — including sales actually taken in the old
 *    one. It is a display setting pretending to be an accounting one, and the honest fix is a
 *    per-row currency, which v1 explicitly does not have.
 *  - **Timezone moves day boundaries.** Yesterday's takings are whatever fell inside
 *    yesterday, and shifting the zone reshapes that.
 */
class SettingsController extends Controller
{
    /** Everything an owner may change, plus what changing it would affect. */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $account = Account::query()->findOrFail($user->account_id);
        $store = Store::query()->where('account_id', $account->id)->orderBy('name')->firstOrFail();

        return response()->json([
            'data' => [
                'account' => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'timezone' => $account->timezone,
                    'currency' => $account->currency,
                ],
                'store' => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'address' => $store->address,
                    'email' => $store->email,
                    'phone' => $store->phone,
                    // Fraction, not percent: 0.0600 is 6%.
                    'tax_rate' => $store->tax_rate,
                ],
                // Surfaced so the confirmation can say what is actually at stake, rather
                // than asking "are you sure?" about everything equally.
                'impact' => [
                    'currency' => $this->currencyImpact($account->id),
                    'timezone' => $this->timezoneImpact($account->id),
                ],
                'can_edit' => $user->role->value === 'owner',
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->role->value === 'owner', 403, 'Only an owner may change settings.');

        $validated = $request->validate([
            'timezone' => ['sometimes', 'string', Rule::in(timezone_identifiers_list())],
            // ISO 4217, three letters. Validated rather than free text, because it ends up
            // on receipts.
            'currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'store_name' => ['sometimes', 'string', 'max:120'],
            'store_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'store_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'store_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            // A percentage would be the friendlier input, but the column is the fraction the
            // orders snapshot, and converting in two places is how they drift apart.
            'tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
        ]);

        $account = Account::query()->findOrFail($user->account_id);
        $store = Store::query()->where('account_id', $account->id)->orderBy('name')->firstOrFail();

        DB::transaction(function () use ($validated, $account, $store) {
            $accountChanges = array_filter([
                'timezone' => $validated['timezone'] ?? null,
                'currency' => $validated['currency'] ?? null,
            ], fn ($value) => $value !== null);

            if ($accountChanges !== []) {
                $account->update($accountChanges);
            }

            $storeChanges = [];

            foreach (['name', 'email', 'phone', 'address'] as $field) {
                if (array_key_exists("store_{$field}", $validated)) {
                    $storeChanges[$field] = $validated["store_{$field}"];
                }
            }

            if (array_key_exists('tax_rate', $validated)) {
                $storeChanges['tax_rate'] = $validated['tax_rate'];
            }

            if ($storeChanges !== []) {
                $store->update($storeChanges);
            }
        });

        // Nothing is pushed to the tills. They re-read config on their next pull, which is
        // the same mechanism entitlements use.
        return $this->show($request);
    }

    /** How much history a currency change would silently relabel. */
    private function currencyImpact(string $accountId): array
    {
        $orders = DB::table('orders')->where('account_id', $accountId)->count();
        $takings = (int) DB::table('ledger_entries')->where('account_id', $accountId)->sum('amount');

        return [
            'orders' => $orders,
            'takings' => $takings,
            'note' => $orders > 0
                ? 'Amounts already recorded will be shown in the new currency without being converted.'
                : null,
        ];
    }

    private function timezoneImpact(string $accountId): array
    {
        return [
            'orders' => DB::table('orders')->where('account_id', $accountId)->count(),
            'note' => 'Day boundaries in reports move, so daily totals may regroup.',
        ];
    }
}
