<?php

namespace App\Sync\Handlers;

use App\Enums\StockableType;
use App\Enums\StockMovementReason;
use App\Models\Device;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Movements are appended unconditionally. There is no branch in this class that inspects
 * the current stock level, and there must never be one: the sale already happened on a till
 * that was offline, and refusing to record it would not un-hand the goods to the customer.
 *
 * Whether the resulting level is negative is discovered afterwards, by the reconciler, and
 * reported as an exception for a human rather than as an error to the till.
 */
class StockMovementHandler extends EntityHandler
{
    protected function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'store_id' => ['required', 'uuid'],
            'stockable_type' => ['required', Rule::enum(StockableType::class)],
            'stockable_id' => ['required', 'uuid'],
            // Signed, and numeric rather than integer: 150 g of patty is not a count.
            'delta' => ['required', 'numeric'],
            'reason' => ['required', Rule::enum(StockMovementReason::class)],
            'source_order_id' => ['nullable', 'uuid'],
            'source_order_line_id' => ['nullable', 'uuid'],
            'created_by_user_id' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string'],
            'occurred_at' => ['required', 'date'],
        ];
    }

    protected function tenantKeys(): array
    {
        return ['store_id' => 'store_id'];
    }

    protected function persist(array $payload, Device $device, Carbon $recordedAt): bool
    {
        return DB::table('stock_movements')->insertOrIgnore([[
            'id' => $payload['id'],
            'store_id' => $payload['store_id'],
            'stockable_type' => $payload['stockable_type'],
            'stockable_id' => $payload['stockable_id'],
            'delta' => $payload['delta'],
            'reason' => $payload['reason'],
            'source_order_id' => $payload['source_order_id'] ?? null,
            'source_order_line_id' => $payload['source_order_line_id'] ?? null,
            'source_device_id' => $device->id,
            'created_by_user_id' => $payload['created_by_user_id'] ?? null,
            'note' => $payload['note'] ?? null,
            'occurred_at' => $payload['occurred_at'],
            'recorded_at' => $recordedAt,
            'created_at' => $recordedAt,
        ]]) > 0;
    }
}
