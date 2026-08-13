<?php

namespace Tests\Support;

use App\Enums\FulfillmentType;
use App\Enums\LedgerEntryType;
use App\Enums\LineFulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\StockableType;
use App\Enums\StockMovementReason;
use App\Models\Device;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Stands in for the Flutter till when exercising the server.
 *
 * It produces the same shape the real outbox produces: client-minted UUIDv7 ids, a payload
 * snapshotted at enqueue time, an occurred_at from the device's own clock, and one entry per
 * aggregate. Building batches by hand in each test would drift from that shape, and the
 * tests would start proving things about the tests.
 */
final class TillSimulator
{
    private int $sequence = 1;

    public function __construct(
        private readonly Device $device,
        private readonly string $numberPrefix = 'T1',
    ) {}

    /**
     * One completed sale: the order with its lines, the ledger entry, and the stock
     * movement it caused — the exact set the checkout transaction enqueues.
     *
     * @return array{order_id: string, entries: list<array>}
     */
    public function ringUpSale(
        string $stockableId,
        int $unitPrice = 4500,
        int $quantity = 1,
        StockableType $stockableType = StockableType::ProductVariant,
        ?Carbon $occurredAt = null,
        string $productName = 'Ethiopia Yirgacheffe 250g',
        ?string $productId = null,
        ?string $variantId = null,
        FulfillmentType $fulfillmentType = FulfillmentType::Direct,
    ): array {
        $occurredAt ??= Carbon::now();

        $orderId = (string) Str::uuid7();
        $lineId = (string) Str::uuid7();

        $lineTotal = $unitPrice * $quantity;
        // Integer arithmetic, cents in and cents out — the same rule the till itself obeys.
        $tax = (int) round($lineTotal * 0.06);

        $order = [
            'id' => $orderId,
            'account_id' => $this->device->account_id,
            'store_id' => $this->device->store_id,
            'cashier_user_id' => null,
            'customer_id' => null,
            // Composed locally, before any network call.
            'order_number' => sprintf('%s-%04d', $this->numberPrefix, $this->sequence++),
            'status' => OrderStatus::Created->value,
            'payment_status' => PaymentStatus::Paid->value,
            'subtotal' => $lineTotal,
            'tax_total' => $tax,
            'discount_total' => 0,
            'grand_total' => $lineTotal + $tax,
            'tax_rate_snapshot' => '0.0600',
            'occurred_at' => $occurredAt->toIso8601String(),
            'lines' => [[
                'id' => $lineId,
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                // Snapshots: what the cashier quoted, not what the catalog says later.
                'name_snapshot' => $productName,
                'price_snapshot' => $unitPrice,
                'fulfillment_type' => $fulfillmentType->value,
                'quantity' => $quantity,
                'line_total' => $lineTotal,
                'fulfillment_status' => $fulfillmentType === FulfillmentType::Routed
                    ? LineFulfillmentStatus::Pending->value
                    : LineFulfillmentStatus::Fulfilled->value,
                'modifiers' => [],
            ]],
        ];

        $ledger = [
            'id' => (string) Str::uuid7(),
            'account_id' => $this->device->account_id,
            'store_id' => $this->device->store_id,
            'order_id' => $orderId,
            'entry_type' => LedgerEntryType::Sale->value,
            'amount' => $lineTotal + $tax,
            'reverses_entry_id' => null,
            'payment_method' => PaymentMethod::Cash->value,
            'authorized_by_user_id' => null,
            'metadata' => null,
            'occurred_at' => $occurredAt->toIso8601String(),
        ];

        $movement = [
            'id' => (string) Str::uuid7(),
            'store_id' => $this->device->store_id,
            'stockable_type' => $stockableType->value,
            'stockable_id' => $stockableId,
            'delta' => number_format(-$quantity, 3, '.', ''),
            'reason' => StockMovementReason::Sale->value,
            'source_order_id' => $orderId,
            'source_order_line_id' => $lineId,
            'created_by_user_id' => null,
            'note' => null,
            'occurred_at' => $occurredAt->toIso8601String(),
        ];

        return [
            'order_id' => $orderId,
            'entries' => [
                $this->entry('order', $orderId, $order),
                $this->entry('ledger_entry', $ledger['id'], $ledger),
                $this->entry('stock_movement', $movement['id'], $movement),
            ],
        ];
    }

    /** An outbox row: a self-contained, replayable fact. */
    public function entry(string $entityType, string $entityId, array $payload, string $operation = 'create'): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'operation' => $operation,
            'payload' => $payload,
        ];
    }

    /**
     * The push envelope. batch_id is minted per attempt; the entry ids are what make a
     * resend idempotent, not this.
     */
    public function batch(array $entries, ?string $batchId = null): array
    {
        return [
            'batch_id' => $batchId ?? (string) Str::uuid7(),
            'device_id' => $this->device->id,
            'entries' => array_values($entries),
        ];
    }
}
