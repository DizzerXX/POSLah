<?php

namespace App\Broadcasting;

use App\Enums\Module;
use App\Models\Account;
use App\Models\Device;
use App\Models\Store;
use App\Models\User;

/**
 * Who may listen to what.
 *
 * A class rather than helpers in the route file, because that file is loaded more than once
 * across a test run and redeclared functions are a fatal error — but mainly because these are
 * authorisation decisions and deserve somewhere testable to live.
 */
class ChannelAccess
{
    public static function belongsToStore(User|Device $actor, string $storeId): bool
    {
        if ($actor instanceof Device) {
            // A till is bound to exactly one store, so this is an equality, not a lookup.
            return $actor->is_active && $actor->store_id === $storeId;
        }

        return $actor->is_active
            && Store::query()->whereKey($storeId)->where('account_id', $actor->account_id)->exists();
    }

    /**
     * Entitlement, read from the accounts row rather than a token claim — the same rule the
     * HTTP middleware follows. Authentication alone is not enough: a retail-only account
     * holds perfectly valid credentials and must still be refused the kitchen channel.
     */
    public static function hasModule(User|Device $actor, Module $module): bool
    {
        $account = Account::query()->find($actor->account_id);

        return $account !== null && $account->hasModule($module);
    }
}
