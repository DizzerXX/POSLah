<?php

namespace App\Sync\Handlers;

use App\Enums\LedgerEntryType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Device;
use App\Models\User;
use App\Sync\EntryResult;
use App\Sync\PushEntry;
use App\Sync\RejectionReason;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The financial fact. Insert-only in every sense: the table refuses UPDATE and DELETE at
 * the database, so there is no code path here that could correct one even by accident.
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

    /**
     * A void or refund must name a manager or owner, and that role is read from the users
     * table here rather than trusted from the payload.
     *
     * The till also prompts for a PIN, and that prompt is theatre on its own: a modified
     * build skips it, and the device is in a shop rather than a vault. This check is what
     * makes the requirement real, which is why it lives on the server and reads the row.
     */
    public function handle(PushEntry $entry, Device $device): EntryResult
    {
        $type = LedgerEntryType::tryFrom($entry->payload['entry_type'] ?? '');

        if ($type !== null && $type->isReversal()) {
            $authorizerId = $entry->payload['authorized_by_user_id'] ?? null;

            $authorizer = $authorizerId === null
                ? null
                : User::query()
                    ->where('id', $authorizerId)
                    ->where('account_id', $device->account_id)
                    ->first();

            if ($authorizer === null || ! $authorizer->canAuthorizeReversal()) {
                return EntryResult::rejected(
                    $entry->id,
                    RejectionReason::UnauthorizedReversal,
                    'A void or refund must be authorised by an active manager or owner.',
                );
            }
        }

        return parent::handle($entry, $device);
    }

    protected function persist(array $payload, Device $device, Carbon $recordedAt): bool
    {
        $inserted = DB::table('ledger_entries')->insertOrIgnore([[
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

        if ($inserted) {
            $this->applyReversalToOrder($payload, $recordedAt);
        }

        return $inserted;
    }

    /**
     * A voided order's status is *derived from the ledger entry that voided it*, not set by
     * the till.
     *
     * The till marks the order voided in its own store, but that change can never reach the
     * server as an update: every sync write is an insert, and the ledger tables refuse
     * mutation by design. Rather than carving out an update path for one column — which
     * would mean trusting a client to set an order's status directly, the thing the state
     * machine explicitly forbids — the void entry landing *is* the trigger. The fact
     * produces the projection, which is the same relationship stock_movements has with
     * stock_levels.
     */
    private function applyReversalToOrder(array $payload, Carbon $recordedAt): void
    {
        $orderId = $payload['order_id'] ?? null;

        if ($orderId === null) {
            return;
        }

        $type = LedgerEntryType::from($payload['entry_type']);

        $attributes = match ($type) {
            LedgerEntryType::Void => [
                'status' => OrderStatus::Voided->value,
                'payment_status' => PaymentStatus::Refunded->value,
            ],
            // A refund may be partial, so it says nothing about fulfilment. Only the money
            // moved, and the ledger already records that.
            LedgerEntryType::Refund => ['payment_status' => PaymentStatus::Refunded->value],
            LedgerEntryType::Sale => null,
        };

        if ($attributes === null) {
            return;
        }

        DB::table('orders')
            ->where('id', $orderId)
            ->update($attributes + ['updated_at' => $recordedAt]);
    }
}
