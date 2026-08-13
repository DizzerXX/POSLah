<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => 'password',
            'role' => UserRole::Cashier,
            // Distinct from the login password: it gates manager override on void and
            // refund, which the server verifies independently of the client's prompt.
            'pin_hash' => Hash::make('1234'),
            'is_active' => true,
        ];
    }

    public function owner(): static
    {
        return $this->state(['role' => UserRole::Owner]);
    }

    public function manager(): static
    {
        return $this->state(['role' => UserRole::Manager]);
    }

    public function cashier(): static
    {
        return $this->state(['role' => UserRole::Cashier]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
