<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;

class SubscriptionPolicy
{
    public function view(User $user, Subscription $subscription): bool
    {
        return $user->customer !== null
            && $subscription->customer_id !== null
            && $subscription->customer_id === $user->customer->id;
    }

    public function manage(User $user, Subscription $subscription): bool
    {
        return $this->view($user, $subscription)
            && $subscription->billing_source === 'stripe'
            && ! $subscription->isInternal();
    }
}
