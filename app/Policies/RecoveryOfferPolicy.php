<?php

namespace App\Policies;

use App\Models\RecoveryOffer;
use App\Models\User;
use App\Offers\OfferStatus;

/**
 * A customer sees, accepts, declines and pays only their own offers, and
 * never a draft. Everything else (price, scope, notes) is the owner's.
 */
class RecoveryOfferPolicy
{
    public function view(User $user, RecoveryOffer $offer): bool
    {
        return $user->customer !== null
            && $offer->customer_id === $user->customer->id
            && strtoupper((string) $user->customer->country_code) === 'HU'
            && $offer->currency === 'HUF'
            && $offer->status !== OfferStatus::Draft
            && ($offer->status !== OfferStatus::Cancelled || $offer->offered_at !== null);
    }

    public function respond(User $user, RecoveryOffer $offer): bool
    {
        return $this->view($user, $offer);
    }
}
