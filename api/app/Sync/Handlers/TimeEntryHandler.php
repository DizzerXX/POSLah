<?php

namespace App\Sync\Handlers;

use App\Models\Device;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Clock in and out. Identical for both verticals. No fork.
 */
class TimeEntryHandler extends EntityHandler
{
    protected function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'account_id' => ['required', 'uuid'],
            'store_id' => ['required', 'uuid'],
            'user_id' => ['required', 'uuid'],
            'clocked_in_at' => ['required', 'date'],
            'clocked_out_at' => ['nullable', 'date', 'after_or_equal:clocked_in_at'],
        ];
    }

    protected function persist(array $payload, Device $device, Carbon $recordedAt): bool
    {
        return DB::table('time_entries')->insertOrIgnore([[
            'id' => $payload['id'],
            'account_id' => $payload['account_id'],
            'store_id' => $payload['store_id'],
            'user_id' => $payload['user_id'],
            'clocked_in_at' => $payload['clocked_in_at'],
            'clocked_out_at' => $payload['clocked_out_at'] ?? null,
            'source_device_id' => $device->id,
            'recorded_at' => $recordedAt,
            'created_at' => $recordedAt,
            'updated_at' => $recordedAt,
        ]]) > 0;
    }
}
