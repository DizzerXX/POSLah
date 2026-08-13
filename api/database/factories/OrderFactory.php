<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SyncStatus;
use App\Models\Account;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $subtotal = fake()->numberBetween(500, 20000);
        $taxRate = '0.0600';
        $tax = (int) round($subtotal * 0.06);

        return [
            'account_id' => Account::factory(),
            'store_id' => Store::factory(),
            'device_id' => null,
            'cashier_user_id' => null,
            'customer_id' => null,
            'order_number' => 'T1-'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'status' => OrderStatus::Created,
            'payment_status' => PaymentStatus::Paid,
            'subtotal' => $subtotal,
            'tax_total' => $tax,
            'discount_total' => 0,
            'grand_total' => $subtotal + $tax,
            // Snapshot, so the receipt stays reproducible after the store's rate changes.
            'tax_rate_snapshot' => $taxRate,
            'occurred_at' => now(),
            'recorded_at' => now(),
            'sync_status' => SyncStatus::Synced,
        ];
    }

    /** An offline sale that has not reached the server yet. */
    public function pendingSync(): static
    {
        return $this->state([
            'sync_status' => SyncStatus::Pending,
            'recorded_at' => null,
        ]);
    }

    /** An F&B ticket: paid at the counter, still being made. */
    public function sentToKitchen(): static
    {
        return $this->state([
            'status' => OrderStatus::Sent,
            'payment_status' => PaymentStatus::Paid,
        ]);
    }

    public function voided(): static
    {
        return $this->state(['status' => OrderStatus::Voided]);
    }
}
