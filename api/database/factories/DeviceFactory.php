<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Device;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Device> */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        $store = Store::factory();

        return [
            'account_id' => Account::factory(),
            'store_id' => $store,
            'device_name' => 'Till '.fake()->unique()->numberBetween(1, 99),
            // The receipt prefix. Unique per store, so a locally generated "T1-0042"
            // cannot collide with another device's numbering while both are offline.
            'number_prefix' => 'T'.fake()->unique()->numberBetween(1, 99),
            'last_seen_sequence' => null,
            'assigned_module' => null,
            'last_seen_at' => now(),
            'last_sync_at' => now(),
            'is_active' => true,
        ];
    }

    public function assignedTo(\App\Enums\Module $module): static
    {
        return $this->state(['assigned_module' => $module]);
    }
}
