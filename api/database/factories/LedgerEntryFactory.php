<?php

namespace Database\Factories;

use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LedgerEntry> */
class LedgerEntryFactory extends Factory
{
    protected $model = LedgerEntry::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'store_id' => Store::factory(),
            'order_id' => null,
            'entry_type' => LedgerEntryType::Sale,
            // Signed minor units. Sales positive, reversals negative.
            'amount' => fake()->numberBetween(500, 20000),
            'reverses_entry_id' => null,
            'payment_method' => PaymentMethod::Cash,
            'authorized_by_user_id' => null,
            'metadata' => null,
            'occurred_at' => now(),
            'recorded_at' => now(),
        ];
    }

    /**
     * A reversal. Never an edit of the original — that is what the append-only trigger
     * exists to make impossible.
     */
    public function reversing(LedgerEntry $original, LedgerEntryType $type = LedgerEntryType::Refund): static
    {
        return $this->state([
            'account_id' => $original->account_id,
            'store_id' => $original->store_id,
            'order_id' => $original->order_id,
            'entry_type' => $type,
            'amount' => -$original->amount,
            'reverses_entry_id' => $original->id,
            'payment_method' => $original->payment_method,
        ]);
    }
}
