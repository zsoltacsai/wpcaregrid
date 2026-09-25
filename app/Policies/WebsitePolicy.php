<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Website;

class WebsitePolicy
{
    public function view(User $user, Website $website): bool
    {
        return $user->customer !== null && $website->customer_id === $user->customer->id;
    }

    /** Adding a website is a paid action: it needs an active plan without a website. */
    public function create(User $user): bool
    {
        return $user->customer?->hasActiveSubscription() === true
            && $user->customer->subscriptionAvailableForWebsite() !== null;
    }
}
