<?php

namespace Database\Factories;

use App\Billing\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Subscription> */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'stripe_customer_id' => 'cus_'.Str::random(14),
            'stripe_subscription_id' => 'sub_'.Str::random(14),
            'stripe_price_id' => 'price_wordpress_business_monthly',
            'product_type' => 'wordpress',
            'plan' => 'business',
            'interval' => 'monthly',
            'market' => 'hu',
            'currency' => 'HUF',
            'amount' => 1899000,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->subDays(3),
            'current_period_end' => now()->addDays(27),
            'activated_at' => now()->subDays(3),
        ];
    }

    public function status(SubscriptionStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
