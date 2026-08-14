<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Backoffice sign-in.
 *
 * A user token, distinct from the device tokens the tills hold: a person signs in here, and
 * the abilities are different in kind — nobody in the backoffice pushes sales.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        // One message for "no such user" and "wrong password" alike: distinguishing them
        // turns the login form into a directory of who works here.
        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Those credentials do not match.']);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'That account is no longer active.']);
        }

        $account = $user->account()->sole();

        return response()->json([
            'token' => $user->createToken("backoffice:{$user->email}", ['backoffice'])->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
            ],
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'currency' => $account->currency,
                // For *rendering* only. Authorising against a module reads the database per
                // request; this copy is stale the moment an admin changes it.
                'enabled_modules' => $account->enabled_modules,
            ],
            // v1 is single-store per account, but this is a list rather than a field so that
            // multi-store stays additive — the same reason the schema carries store_id
            // everywhere it does.
            'stores' => Store::query()
                ->where('account_id', $account->id)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($store) => ['id' => $store->id, 'name' => $store->name])
                ->all(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }
}
