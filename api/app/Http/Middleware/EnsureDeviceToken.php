<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The sync endpoints are for tills, and a till authenticates as a Device rather than as a
 * user. Sanctum will happily resolve a token belonging to any tokenable model, so this
 * asserts the thing on the other end really is a registered, active device.
 */
class EnsureDeviceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user();

        if (! $device instanceof Device) {
            abort(403, 'This endpoint requires a device token.');
        }

        if (! $device->is_active) {
            // Deactivating a device in the backoffice must stop it syncing immediately.
            // Read from the row, not from the token — the same principle the entitlement
            // work in step 4 applies to modules.
            abort(403, 'This device has been deactivated.');
        }

        return $next($request);
    }
}
