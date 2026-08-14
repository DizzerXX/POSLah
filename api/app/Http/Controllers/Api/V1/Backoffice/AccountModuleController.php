<?php

namespace App\Http\Controllers\Api\V1\Backoffice;

use App\Enums\Module;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Turning a vertical on or off.
 *
 * The empty case is refused by a database CHECK rather than only here, so "an account with
 * no modules" is a state that cannot exist rather than one every screen has to handle.
 */
class AccountModuleController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $account = Account::query()->findOrFail($user->account_id);

        return response()->json([
            'data' => [
                'enabled_modules' => $account->enabled_modules,
                'available_modules' => array_map(fn (Module $m) => $m->value, Module::cases()),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled_modules' => ['required', 'array', 'min:1'],
            'enabled_modules.*' => [Rule::enum(Module::class)],
        ]);

        /** @var User $user */
        $user = $request->user();

        // Changing what a business sells is an owner's decision, not a manager's.
        abort_unless($user->role->value === 'owner', 403, 'Only an owner may change modules.');

        $modules = array_values(array_unique($validated['enabled_modules']));

        if ($modules === []) {
            throw ValidationException::withMessages([
                'enabled_modules' => 'An account must keep at least one module.',
            ]);
        }

        $account = Account::query()->findOrFail($user->account_id);
        $account->update(['enabled_modules' => $modules]);

        // Nothing is pushed to the tills. They re-read entitlements on their next pull, which
        // is the same mechanism that protects against a till left signed in for three weeks.
        return response()->json(['data' => ['enabled_modules' => $account->fresh()->enabled_modules]]);
    }
}
