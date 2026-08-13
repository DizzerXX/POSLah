<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->name(),
            'phone' => fake()->numerify('01#-#######'),
            'email' => fake()->safeEmail(),
            'notes' => null,
            'occurred_at' => now(),
            'recorded_at' => now(),
            'source_device_id' => null,
        ];
    }
}
