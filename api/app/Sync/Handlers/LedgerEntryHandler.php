<?php

namespace App\Sync\Handlers;

use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Models\Device;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The financial fact. Insert-only in every sense: the table refuses UPDATE and DELETE at
 * the database, so there is no code path here that could correct one even by accident.
 *
 * Not enforced here, deliberately: that a refund or void carries an authorized_by_user_id
 * belonging to a manager or owner. That check belongs to step 3, which introduces voids and
 * refunds; adding it now would be guessing at a payload that does not exist yet.
 */
class LedgerEntryHandler extends EntityHandler
{
    protected function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'account_id' => ['required', 'uuid'],
            'store_id' => ['required', 'uuid'],
            'order_id' => ['nullable', 'uuid'],
            'entry_type' => ['required', Rule::enum(LedgerEntryType::class)],
            // Signed minor units: reversals are negative, so takings are a plain SUM.
            'amount' => ['required', 'integer'],
            'reverses_entry_id' => ['nullable', 'uuid'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'authorized_by_user_id' => ['nullable', 'uuid'],
            'metadata' => ['nullable', 'array'],
            'occurred_at' => ['required', 'date'],
        ];
    }

    protected function persist(array $payload, Device $device, Carbon $recordedAt): bool
    {
        return DB::table('ledger_entries')->insertOrIgnore([[
            'id' => $payload['id'],
            'account_id' => $payload['account_id'],
            'store_id' => $payload['store_id'],
            'order_id' => $payload['order_id'] ?? null,
            'entry_type' => $payload['entry_type'],
            'amount' => $payload['amount'],
            'reverses_entry_id' => $payload['reverses_entry_id'] ?? null,
            'payment_method' => $payload['payment_method'],
            'authorized_by_user_id' => $payload['authorized_by_user_id'] ?? null,
            'metadata' => isset($payload['metadata']) ? json_encode($payload['metadata']) : null,
            'occurred_at' => $payload['occurred_at'],
            // The server clock. Everything financial orders by this column, because the
            // till's clock is untrusted and not monotonic across devices.
            'recorded_at' => $recordedAt,
            'created_at' => $recordedAt,
        ]]) > 0;
    }
}
