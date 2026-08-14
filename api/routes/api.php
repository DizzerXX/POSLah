<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Backoffice\StockExceptionController;
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

/*
 * Backoffice. A person signs in here and holds a user token; a till's device token is
 * refused, and vice versa. Module entitlement middleware layers on top of this in step 4.
 */
Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');

    Route::middleware(['auth:sanctum', 'backoffice'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::get('stock-exceptions', [StockExceptionController::class, 'index'])
            ->name('stock-exceptions.index');

        Route::post('stock-exceptions/{stockException}/resolve', [StockExceptionController::class, 'resolve'])
            ->name('stock-exceptions.resolve');
    });
});
