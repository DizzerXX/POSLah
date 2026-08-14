<?php

use App\Broadcasting\ChannelAccess;
use App\Enums\Module;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Channel authorisation.
 *
 * Broadcast auth is a real endpoint with a real answer, so the entitlement check belongs
 * here as much as on the API. Enforcing modules on HTTP while leaving the socket open would
 * be a listening hole beside a locked door.
 */

/** Kitchen tickets. F&B only. */
Broadcast::channel('store.{storeId}.kitchen', function (User|Device $actor, string $storeId) {
    return ChannelAccess::belongsToStore($actor, $storeId)
        && ChannelAccess::hasModule($actor, Module::Fnb);
});

/** Line and order status, for every till in the store. */
Broadcast::channel('store.{storeId}.tills', function (User|Device $actor, string $storeId) {
    return ChannelAccess::belongsToStore($actor, $storeId);
});

/** Backoffice. Users only — a till has no business listening here. */
Broadcast::channel('account.{accountId}.backoffice', function (User|Device $actor, string $accountId) {
    return $actor instanceof User && $actor->account_id === $accountId && $actor->is_active;
});
