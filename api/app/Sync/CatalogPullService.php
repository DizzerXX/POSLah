<?php

namespace App\Sync;

use App\Models\Account;
use App\Models\Device;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Catalog, staff and config flow downward only. The till never edits a product, so there is
 * no merge, no conflict resolution, and last write in the backoffice wins.
 *
 * Two properties matter more than the query list:
 *
 *  1. **Deactivated rows must appear in the delta.** Filtering on is_active would mean a
 *     discontinued product simply stops being mentioned — and a row that is never mentioned
 *     again lives forever on the till. This is the classic incremental-sync bug, and it is
 *     invisible in testing unless you deactivate something and look at a device.
 *
 *  2. **The cursor is the server's clock, never the till's.** The window is closed at a
 *     timestamp taken before the queries run, so a row updated mid-request falls into the
 *     next pull rather than into the gap between them.
 */
class CatalogPullService
{
    /**
     * Tables that flow down, and how each is scoped to the account. Stock levels are
     * absent on purpose: the till's stock figure is an advisory hint for greying a tile,
     * and pulling authoritative-looking numbers down would create exactly the illusion of
     * authority the design refuses it.
     */
    public function pull(Device $device, ?Carbon $since): array
    {
        // Taken before any query. Rows written after this instant belong to the next pull.
        $serverTime = Carbon::now();

        // Read from the accounts row, deliberately not from $device->account: a cached
        // relation is a snapshot of whenever it happened to be loaded, and entitlements are
        // authorised from the database per request. An admin disabling F&B at noon must
        // reach the till on its next pull, not whenever some in-memory model expires.
        $account = Account::query()->findOrFail($device->account_id);

        return [
            'server_time' => $serverTime->toIso8601String(),
            'since' => $since?->toIso8601String(),
            // Re-read on every pull, not just at login: a till that stays logged in for
            // three weeks must not run on three-week-old entitlements.
            'enabled_modules' => $account->enabled_modules,
            'changes' => [
                'stores' => $this->delta('stores', $since, $serverTime,
                    fn ($q) => $q->where('account_id', $device->account_id)
                        ->select('id', 'account_id', 'name', 'address', 'tax_rate', 'updated_at'),
                ),
                'users' => $this->delta('users', $since, $serverTime,
                    // Never the password or PIN hash. The till needs to know who may ring
                    // up a sale, not how to verify them offline.
                    fn ($q) => $q->where('account_id', $device->account_id)
                        ->select('id', 'account_id', 'name', 'email', 'role', 'is_active', 'updated_at'),
                ),
                'categories' => $this->delta('categories', $since, $serverTime,
                    fn ($q) => $q->where('account_id', $device->account_id)
                        ->select('id', 'account_id', 'name', 'sort_order', 'is_active', 'updated_at'),
                ),
                'products' => $this->delta('products', $since, $serverTime,
                    fn ($q) => $q->where('account_id', $device->account_id)
                        ->select('id', 'account_id', 'category_id', 'name', 'sku', 'barcode',
                            'price', 'fulfillment_type', 'tracks_stock', 'is_active', 'updated_at'),
                ),
                'product_variants' => $this->delta('product_variants', $since, $serverTime,
                    fn ($q) => $q->whereIn('product_id', $this->productIds($device))
                        ->select('id', 'product_id', 'name', 'sku', 'barcode', 'price_delta',
                            'is_active', 'updated_at'),
                ),
                'modifiers' => $this->delta('modifiers', $since, $serverTime,
                    fn ($q) => $q->whereIn('product_id', $this->productIds($device))
                        ->select('id', 'product_id', 'name', 'price_delta', 'consumes_ingredient_id',
                            'consumes_quantity', 'is_active', 'updated_at'),
                ),
                'ingredients' => $this->delta('ingredients', $since, $serverTime,
                    fn ($q) => $q->where('account_id', $device->account_id)
                        ->select('id', 'account_id', 'name', 'unit', 'low_stock_threshold',
                            'cost_per_unit', 'is_active', 'updated_at'),
                ),
                // Recipes must be resident locally: expansion happens on the till, against
                // the recipe as it stood when the burger was actually made.
                'recipe_lines' => $this->delta('recipe_lines', $since, $serverTime,
                    fn ($q) => $q->whereIn('product_id', $this->productIds($device))
                        ->select('id', 'product_id', 'ingredient_id', 'quantity', 'updated_at'),
                ),
            ],
        ];
    }

    private function delta(string $table, ?Carbon $since, Carbon $serverTime, callable $scope): array
    {
        $query = DB::table($table);

        $scope($query);

        if ($since !== null) {
            $query->where('updated_at', '>', $since);
        }

        // Closes the window at the instant the request began, so nothing can slip between
        // this pull and the next.
        $query->where('updated_at', '<=', $serverTime);

        // NOT filtered by is_active, on purpose. See the class docblock.
        return $query->orderBy('updated_at')->get()->map(
            static fn ($row) => (array) $row,
        )->all();
    }

    private function productIds(Device $device): Builder
    {
        return DB::table('products')->select('id')->where('account_id', $device->account_id);
    }
}
