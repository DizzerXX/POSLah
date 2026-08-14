<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The backoffice is for people. Sanctum resolves a token for any tokenable model, so a till's
 * device token must not reach these endpoints just because it is a valid credential.
 */
class EnsureBackofficeUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403, 'This endpoint requires a user token.');
        }

        // Read from the row, not the token: deactivating someone must take effect on their
        // next request rather than whenever their token happens to expire.
        if (! $user->is_active) {
            abort(403, 'That account is no longer active.');
        }

        return $next($request);
    }
}
