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

    /** Generating a pairing code is a paid action for the site's own plan. */
    public function pair(User $user, Website $website): bool
    {
        return $this->view($user, $website) && $website->allowsServiceOperations();
    }

    /** Disconnecting is always allowed for the owner, even without an active plan. */
    public function disconnect(User $user, Website $website): bool
    {
        return $this->view($user, $website);
    }
}
