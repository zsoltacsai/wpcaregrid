<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Website> */
class WebsiteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'url' => 'https://'.fake()->unique()->domainWord().'.example',
            'type' => 'wordpress',
            'status' => 'pending',
            'connector_status' => 'not_installed',
        ];
    }
}
