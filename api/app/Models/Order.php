<?php

namespace App\Models;

use App\Enums\FulfillmentType;
use App\Enums\LineFulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SyncStatus;
use App\Models\Concerns\HasUuidV7Key;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sale. The id and order_number are both minted on the till, before any network call.
 *
 * `status` is fulfilment only and is *derived* from the lines — it is stored for query
 * performance and recomputed on every transition, never set directly from a UI action.
 * Storing it as an independently mutable field alongside line statuses would create two
 * sources of truth that eventually disagree.
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use HasUuidV7Key;

    protected $fillable = [
        'id', 'account_id', 'store_id', 'device_id', 'cashier_user_id', 'customer_id',
        'restaurant_table_id', 'service_type', 'customer_note',
        'order_number', 'status', 'payment_status', 'subtotal', 'tax_total',
        'discount_total', 'grand_total', 'tax_rate_snapshot', 'occurred_at', 'recorded_at',
        'sync_status',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'sync_status' => SyncStatus::class,
            'subtotal' => 'integer',
            'tax_total' => 'integer',
            'discount_total' => 'integer',
            'grand_total' => 'integer',
            'tax_rate_snapshot' => 'decimal:4',
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * The state machine's collapse condition: with no routed line there is nothing for a
     * kitchen to do, so the order never enters sent/preparing/ready.
     */
    public function hasRoutedLines(): bool
    {
        return $this->lines->contains(
            fn (OrderLine $line) => $line->fulfillment_type === FulfillmentType::Routed,
        );
    }

    /**
     * Derive fulfilment status from the lines. The only correct way to obtain an order's
     * status; `status` on this row is a materialised copy of what this returns.
     */
    public function deriveStatus(): OrderStatus
    {
        if ($this->status === OrderStatus::Voided) {
            return OrderStatus::Voided;
        }

        $routed = $this->lines->filter(
            fn (OrderLine $line) => $line->fulfillment_type === FulfillmentType::Routed,
        );

        if ($routed->isEmpty()) {
            return OrderStatus::Created;
        }

        if ($routed->every(fn (OrderLine $line) => $line->fulfillment_status === LineFulfillmentStatus::Ready)) {
            return OrderStatus::Ready;
        }

        return $this->status === OrderStatus::Created ? OrderStatus::Sent : $this->status;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'source_order_id');
    }
}
