<?php

namespace App\Billing;

interface StripeSubscriptionReader
{
    /** Fetches the current provider snapshot for a canonical local Stripe ID. */
    public function retrieve(string $stripeSubscriptionId): object;
}
