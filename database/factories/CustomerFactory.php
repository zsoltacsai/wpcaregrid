<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->userName().'@company.example',
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'country_code' => 'HU',
            'customer_type' => 'individual',
            'billing_address' => ['line1' => 'Teszt utca 1.', 'postal_code' => '1011', 'city' => 'Budapest'],
            'active' => true,
        ];
    }

    /** With an activated login (verified email, known password "password-123456"). */
    public function withUser(): static
    {
        return $this->afterCreating(function (Customer $customer) {
            $user = User::factory()->create(['email' => $customer->email, 'name' => $customer->fullName(), 'password' => 'password-123456']);
            $customer->user()->associate($user)->save();
        });
    }
}
