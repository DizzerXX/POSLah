<?php

namespace App\Sync\Handlers;

use App\Models\Device;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customers are the one shared entity a till creates: quick-create at checkout. Identical
 * for both verticals, so there is one handler and no fork.
 */
class CustomerHandler extends EntityHandler
{
    protected function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'account_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
            'occurred_at' => ['required', 'date'],
        ];
    }

    protected function tenantKeys(): array
    {
        return ['account_id' => 'account_id'];
    }

    protected function persist(array $payload, Device $device, Carbon $recordedAt): bool
    {
        return DB::table('customers')->insertOrIgnore([[
            'id' => $payload['id'],
            'account_id' => $payload['account_id'],
            'name' => $payload['name'],
            'phone' => $payload['phone'] ?? null,
            'email' => $payload['email'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'occurred_at' => $payload['occurred_at'],
            'recorded_at' => $recordedAt,
            'source_device_id' => $device->id,
            'created_at' => $recordedAt,
            'updated_at' => $recordedAt,
        ]]) > 0;
    }
}
