<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function view(User $user, Customer $customer): bool
    {
        return $customer->user_id === $user->id;
    }

    public function update(User $user, Customer $customer): bool
    {
        return $customer->user_id === $user->id;
    }
}
