<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Store;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TimeEntry> */
class TimeEntryFactory extends Factory
{
    protected $model = TimeEntry::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'store_id' => Store::factory(),
            'user_id' => User::factory(),
            'clocked_in_at' => now()->subHours(6),
            'clocked_out_at' => now(),
            'source_device_id' => null,
            'recorded_at' => now(),
        ];
    }

    /** A shift still in progress. At most one of these per user, enforced by a partial index. */
    public function open(): static
    {
        return $this->state(['clocked_out_at' => null]);
    }
}
