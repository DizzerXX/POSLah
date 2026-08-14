<?php

namespace App\Http\Controllers\Api\V1\Backoffice;

use App\Enums\Module;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Registering a till.
 *
 * The receipt prefix is assigned **here**, and is the reason this screen has to exist: it is
 * what lets two offline tills number receipts independently without colliding, and until now
 * it could only be set by editing a row by hand.
 *
 * The token is shown exactly once. There is no recovery path and there should not be one —
 * a credential a server can re-read is a credential a server is storing.
 */
class DeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => Device::query()
                ->where('account_id', $user->account_id)
                ->orderBy('device_name')
                ->get()
                ->map(fn (Device $device) => [
                    'id' => $device->id,
                    'device_name' => $device->device_name,
                    'number_prefix' => $device->number_prefix,
                    'assigned_module' => $device->assigned_module?->value,
                    'is_active' => $device->is_active,
                    'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                    'last_sync_at' => $device->last_sync_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->role->value === 'owner', 403, 'Only an owner may register a till.');

        $storeIds = Store::query()->where('account_id', $user->account_id)->pluck('id');

        $validated = $request->validate([
            'device_name' => ['required', 'string', 'max:60'],
            'store_id' => ['required', Rule::in($storeIds)],
            // Unique per store: the prefix is what makes a locally generated "T1-0042"
            // collision-free while two tills are both offline.
            'number_prefix' => [
                'required', 'string', 'max:8', 'regex:/^[A-Z0-9]+$/',
                Rule::unique('devices', 'number_prefix')->where(
                    fn ($query) => $query->where('store_id', $request->input('store_id')),
                ),
            ],
            // Null means unpinned: the switcher appears if the account has both verticals.
            'assigned_module' => ['nullable', Rule::enum(Module::class)],
        ]);

        $device = Device::query()->create([
            'account_id' => $user->account_id,
            'store_id' => $validated['store_id'],
            'registered_by_user_id' => $user->id,
            'device_name' => $validated['device_name'],
            'number_prefix' => $validated['number_prefix'],
            'assigned_module' => $validated['assigned_module'] ?? null,
        ]);

        $token = $device->createToken(
            "till:{$device->device_name}",
            [Device::ABILITY_PULL, Device::ABILITY_PUSH],
        );

        return response()->json([
            'data' => [
                'id' => $device->id,
                'device_name' => $device->device_name,
                'number_prefix' => $device->number_prefix,
                // Shown once. The till provisions itself from this and learns everything
                // else — store, account, prefix — from its first pull.
                'token' => $token->plainTextToken,
            ],
        ], 201);
    }

    /** Deactivating a till stops it syncing on its very next request. */
    public function update(Request $request, Device $device): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($device->account_id === $user->account_id, 403, 'That device belongs to another account.');
        abort_unless($user->role->value === 'owner', 403, 'Only an owner may change a till.');

        $validated = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'assigned_module' => ['sometimes', 'nullable', Rule::enum(Module::class)],
        ]);

        $device->update($validated);

        // Revoking access means revoking the credential, not just flipping a flag.
        if (($validated['is_active'] ?? true) === false) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', 'device')
                ->where('tokenable_id', $device->id)
                ->delete();
        }

        return $this->index($request);
    }
}
