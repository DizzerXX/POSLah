<?php

use App\Http\Controllers\Api\V1\SyncPullController;
use App\Http\Controllers\Api\V1\SyncPushController;
use App\Models\Device;
use Illuminate\Support\Facades\Route;

/*
 * Sync endpoints. A till authenticates as a device, not as a user: it drains its outbox in
 * the background across shift changes, and the abilities split read from write so a pull-only
 * token cannot inject sales.
 */
Route::prefix('v1')->middleware(['auth:sanctum', 'device'])->group(function () {
    Route::post('sync/push', SyncPushController::class)
        ->middleware('ability:'.Device::ABILITY_PUSH)
        ->name('sync.push');

    Route::get('sync/pull', SyncPullController::class)
        ->middleware('ability:'.Device::ABILITY_PULL)
        ->name('sync.pull');
});
