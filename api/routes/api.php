<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Backoffice\AccountModuleController;
use App\Http\Controllers\Api\V1\Backoffice\CatalogController;
use App\Http\Controllers\Api\V1\Backoffice\CustomerController;
use App\Http\Controllers\Api\V1\Backoffice\DeviceController;
use App\Http\Controllers\Api\V1\Backoffice\RecipeController;
use App\Http\Controllers\Api\V1\Backoffice\SettingsController;
use App\Http\Controllers\Api\V1\Backoffice\StockExceptionController;
use App\Http\Controllers\Api\V1\KitchenController;
use App\Http\Controllers\Api\V1\Pos\PosController;
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

        // Shared by both verticals, and deliberately ungated. Switching the backoffice to
        // F&B filters inventory and reporting; it does not load a different customer list.
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');

        Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
        Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
        Route::patch('devices/{device}', [DeviceController::class, 'update'])->name('devices.update');

        Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
        Route::patch('settings', [SettingsController::class, 'update'])->name('settings.update');

        Route::get('account/modules', [AccountModuleController::class, 'show'])->name('account.modules.show');
        Route::patch('account/modules', [AccountModuleController::class, 'update'])->name('account.modules.update');

        // Vertical-specific. The middleware reads enabled_modules from the accounts row on
        // every request, so revoking a module takes effect immediately rather than at token
        // expiry.
        Route::middleware('module:fnb')->group(function () {
            Route::get('ingredients', [CatalogController::class, 'ingredients'])->name('ingredients.index');
            Route::get('recipes', [RecipeController::class, 'index'])->name('recipes.index');
            Route::put('recipes/{product}', [RecipeController::class, 'update'])->name('recipes.update');
        });

        Route::middleware('module:retail')->group(function () {
            Route::get('product-variants', [CatalogController::class, 'variants'])->name('variants.index');
        });
    });
});

/*
 * Kitchen display. F&B-gated: a retail-only account has no kitchen, and the middleware reads
 * that from the accounts row on every request.
 *
 * activeOrders is the KDS's primary source of truth, polled on a timer. The socket is an
 * optimisation layered on top, never a dependency — the same stance the till takes toward
 * the network, arrived at by a different mechanism.
 */
Route::prefix('v1')->middleware(['auth:sanctum', 'module:fnb'])->group(function () {
    Route::get('kitchen/active-orders', [KitchenController::class, 'activeOrders'])
        ->name('kitchen.active-orders');

    Route::patch('kitchen/lines/{orderLine}', [KitchenController::class, 'updateLine'])
        ->name('kitchen.lines.update');
});

/*
 * The web till.
 *
 * Online-only by design, and F&B-gated like the kitchen. It writes through rather than
 * queuing — a browser cannot honestly claim the mobile till's local-first guarantee without
 * a second outbox implementation, so it does not claim it.
 */
Route::prefix('v1/pos')->middleware(['auth:sanctum', 'backoffice', 'module:fnb'])->group(function () {
    Route::get('menu', [PosController::class, 'menu'])->name('pos.menu');
    Route::get('tables', [PosController::class, 'tables'])->name('pos.tables');
    Route::get('orders/active', [PosController::class, 'activeOrders'])->name('pos.orders.active');
    Route::post('orders', [PosController::class, 'placeOrder'])->name('pos.orders.store');
    Route::get('history', [PosController::class, 'history'])->name('pos.history');
    Route::get('supply', [PosController::class, 'supply'])->name('pos.supply');
});
