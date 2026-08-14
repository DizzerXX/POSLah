<?php

namespace App\Http\Middleware;

use App\Enums\Module;
use App\Models\Account;
use App\Models\Device;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The actual security boundary for module entitlement. Everything on the client is UX.
 *
 * `enabled_modules` is read from the **accounts row on every request**, never from the token
 * claim. A token issued this morning carries this morning's entitlements: if an admin
 * disables F&B at noon, a claim-based check keeps granting access until that token expires.
 * Reading the row costs one query and closes the window entirely.
 *
 * The claim in the login response is for *rendering*. The database is for *authorising*.
 * Those two uses must not blur.
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $required = Module::tryFrom($module);

        abort_if($required === null, 500, "Unknown module '{$module}' on a route.");

        $accountId = match (true) {
            ($actor = $request->user()) instanceof User => $actor->account_id,
            $actor instanceof Device => $actor->account_id,
            default => null,
        };

        abort_if($accountId === null, 403, 'This endpoint requires an authenticated account.');

        // Deliberately a fresh read, not $actor->account: a relation cached on the
        // authenticated model is a snapshot of whenever it happened to load.
        $account = Account::query()->find($accountId);

        abort_if(
            $account === null || ! $account->hasModule($required),
            403,
            "This account does not have the {$module} module enabled.",
        );

        return $next($request);
    }
}
