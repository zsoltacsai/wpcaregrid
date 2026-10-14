<?php

namespace App\Policies;

use App\Models\PrivacyRequest;
use App\Models\User;

/** Customer-side policy is explicit even though Wave 1 ships no customer-facing route. */
class PrivacyRequestPolicy
{
    public function view(User $user, PrivacyRequest $request): bool
    {
        return $user->customer()->value('id') === $request->customer_id;
    }
}
