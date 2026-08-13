<?php

namespace Database\Factories;

use App\Enums\FulfillmentType;
use App\Enums\Module;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Account> */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'timezone' => 'Asia/Kuala_Lumpur',
            'currency' => 'MYR',
            // Never an empty array: the database CHECK rejects it, deliberately.
            'enabled_modules' => [Module::Retail->value],
            'default_fulfillment_type' => FulfillmentType::Direct,
        ];
    }

    public function retailOnly(): static
    {
        return $this->state(['enabled_modules' => [Module::Retail->value]]);
    }

    public function fnbOnly(): static
    {
        return $this->state([
            'enabled_modules' => [Module::Fnb->value],
            'default_fulfillment_type' => FulfillmentType::Routed,
        ]);
    }

    public function bothModules(): static
    {
        return $this->state([
            'enabled_modules' => [Module::Retail->value, Module::Fnb->value],
        ]);
    }
}
