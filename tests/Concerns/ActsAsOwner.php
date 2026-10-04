<?php

namespace Tests\Concerns;

use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;

/** Owners, and customers with a subscription and a website, for owner area tests. */
trait ActsAsOwner
{
    protected function owner(array $attributes = []): User
    {
        return User::factory()->owner()->create($attributes);
    }

    protected function asOwner(array $attributes = []): User
    {
        $owner = $this->owner($attributes);
        $this->actingAs($owner, 'owner');

        return $owner;
    }

    /** @return array{customer: Customer, subscription: Subscription, website: Website} */
    protected function customerWithWebsite(string $url = 'https://company.example', array $customer = [], array $subscription = []): array
    {
        $model = Customer::factory()->withUser()->create($customer);
        $plan = Subscription::factory()->for($model)->create($subscription);
        $website = Website::factory()->for($model)->create(['url' => $url, 'subscription_id' => $plan->id]);

        return ['customer' => $model->refresh(), 'subscription' => $plan, 'website' => $website];
    }
}
