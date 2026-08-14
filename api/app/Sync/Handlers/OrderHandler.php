<?php

namespace App\Sync\Handlers;

use App\Enums\FulfillmentType;
use App\Enums\LineFulfillmentStatus;
use App\Enums\Module;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SyncStatus;
use App\Events\OrderSentToKitchen;
use App\Kitchen\OrderStatusDeriver;
use App\Models\Device;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * An order arrives as one entry carrying its lines and their modifiers.
 *
 * Keeping the aggregate in a single outbox entry is deliberate: a sale is one fact, and
 * splitting it across three entries would let a receipt exist server-side with two of its
 * three lines while the third sat rejected.
 */
class OrderHandler extends EntityHandler
{
    protected function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'account_id' => ['required', 'uuid'],
            'store_id' => ['required', 'uuid'],
            'cashier_user_id' => ['nullable', 'uuid'],
            'customer_id' => ['nullable', 'uuid'],
            // Composed on the till from its own prefix, so a customer never walks away
            // with a receipt numbered "pending".
            'order_number' => ['nullable', 'string', 'max:32'],
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'payment_status' => ['required', Rule::enum(PaymentStatus::class)],
            // Minor units. `integer` and not `numeric`: a decimal here means someone put
            // floating point money on the wire.
            'subtotal' => ['required', 'integer'],
            'tax_total' => ['required', 'integer'],
            'discount_total' => ['required', 'integer'],
            'grand_total' => ['required', 'integer'],
            'tax_rate_snapshot' => ['required', 'numeric'],
            'occurred_at' => ['required', 'date'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'uuid'],
            'lines.*.product_id' => ['nullable', 'uuid'],
            'lines.*.product_variant_id' => ['nullable', 'uuid'],
            'lines.*.name_snapshot' => ['required', 'string', 'max:255'],
            'lines.*.price_snapshot' => ['required', 'integer'],
            'lines.*.fulfillment_type' => ['required', Rule::enum(FulfillmentType::class)],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.line_total' => ['required', 'integer'],
            'lines.*.fulfillment_status' => ['required', Rule::enum(LineFulfillmentStatus::class)],

            'lines.*.modifiers' => ['sometimes', 'array'],
            'lines.*.modifiers.*.id' => ['required', 'uuid'],
            'lines.*.modifiers.*.modifier_id' => ['nullable', 'uuid'],
            'lines.*.modifiers.*.name_snapshot' => ['required', 'string', 'max:255'],
            'lines.*.modifiers.*.price_delta_snapshot' => ['required', 'integer'],
        ];
    }

    /**
     * An order is F&B business when any line is routed to a kitchen. Not when the account
     * happens to have F&B on — a retail sale from a dual-vertical shop is still a retail
     * sale, and the schema has supported mixed carts since day one.
     */
    protected function requiredModule(array $payload): ?Module
    {
        foreach ($payload['lines'] ?? [] as $line) {
            if (($line['fulfillment_type'] ?? null) === FulfillmentType::Routed->value) {
                return Module::Fnb;
            }
        }

        return null;
    }

    protected function persist(array $payload, Device $device, Carbon $recordedAt): bool
    {
        $inserted = DB::table('orders')->insertOrIgnore([[
            'id' => $payload['id'],
            'account_id' => $payload['account_id'],
            'store_id' => $payload['store_id'],
            // Taken from the authenticated device, never from the payload: provenance must
            // not be self-reported.
            'device_id' => $device->id,
            'cashier_user_id' => $payload['cashier_user_id'] ?? null,
            'customer_id' => $payload['customer_id'] ?? null,
            'order_number' => $payload['order_number'] ?? null,
            'status' => $payload['status'],
            'payment_status' => $payload['payment_status'],
            'subtotal' => $payload['subtotal'],
            'tax_total' => $payload['tax_total'],
            'discount_total' => $payload['discount_total'],
            'grand_total' => $payload['grand_total'],
            'tax_rate_snapshot' => $payload['tax_rate_snapshot'],
            'occurred_at' => $payload['occurred_at'],
            'recorded_at' => $recordedAt,
            'sync_status' => SyncStatus::Synced->value,
            'created_at' => $recordedAt,
            'updated_at' => $recordedAt,
        ]]) > 0;

        if (! $inserted) {
            // Already here. Its lines came with it the first time, so there is nothing left
            // to do and nothing to reconcile.
            return false;
        }

        $this->insertLines($payload, $recordedAt);

        // A routed order becomes a kitchen ticket the moment it lands. Dispatched after the
        // rows are written so a listener cannot observe a half-built order — and outside any
        // assumption of delivery, because the KDS polls regardless.
        if ($this->requiredModule($payload) === Module::Fnb) {
            $order = Order::query()->with('lines.modifiers')->find($payload['id']);

            if ($order !== null) {
                try {
                    // Derived from the lines rather than trusted from the payload. The till
                    // sends what it believed; the kitchen state is a consequence of what was
                    // actually routed, and letting a client set it directly is what the state
                    // machine forbids.
                    app(OrderStatusDeriver::class)->apply($order);

                    OrderSentToKitchen::dispatch($order->fresh(['lines.modifiers']));
                } catch (Throwable $exception) {
                    // A broadcast failure must never reject a sale. The order is already
                    // written; the kitchen screen polls every 30 seconds and will pick it up
                    // regardless, which is precisely why nothing here is allowed to depend on
                    // a message arriving.
                    Log::error('Kitchen notification failed', [
                        'order_id' => $order->id,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return true;
    }

    private function insertLines(array $payload, Carbon $recordedAt): void
    {
        $lines = [];
        $modifiers = [];

        foreach ($payload['lines'] as $line) {
            $lines[] = [
                'id' => $line['id'],
                'order_id' => $payload['id'],
                'product_id' => $line['product_id'] ?? null,
                'product_variant_id' => $line['product_variant_id'] ?? null,
                'name_snapshot' => $line['name_snapshot'],
                'price_snapshot' => $line['price_snapshot'],
                'fulfillment_type' => $line['fulfillment_type'],
                'quantity' => $line['quantity'],
                'line_total' => $line['line_total'],
                'fulfillment_status' => $line['fulfillment_status'],
                'created_at' => $recordedAt,
                'updated_at' => $recordedAt,
            ];

            foreach ($line['modifiers'] ?? [] as $modifier) {
                $modifiers[] = [
                    'id' => $modifier['id'],
                    'order_line_id' => $line['id'],
                    'modifier_id' => $modifier['modifier_id'] ?? null,
                    'name_snapshot' => $modifier['name_snapshot'],
                    'price_delta_snapshot' => $modifier['price_delta_snapshot'],
                    'created_at' => $recordedAt,
                    'updated_at' => $recordedAt,
                ];
            }
        }

        DB::table('order_lines')->insertOrIgnore($lines);

        if ($modifiers !== []) {
            DB::table('order_line_modifiers')->insertOrIgnore($modifiers);
        }
    }
}
