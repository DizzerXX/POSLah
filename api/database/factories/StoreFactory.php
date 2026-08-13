<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Store> */
class StoreFactory extends Factory
{
    protected $model = Store::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->streetName().' Outlet',
            'address' => fake()->address(),
            // Malaysian SST on prepared food and beverages.
            'tax_rate' => '0.0600',
        ];
    }
}
