<?php

namespace App\Checkout;

use App\Models\CheckoutIntent;
use App\Models\Promotion;

/**
 * The payment provider as the checkout needs it. Stripe is the only
 * implementation; the interface exists so tests never call Stripe.
 */
interface CheckoutGateway
{
    /** @throws CheckoutUnavailable */
    public function createSession(CheckoutIntent $intent, ?Promotion $promotion): CheckoutSession;

    /** Stop a session from being paid, e.g. after the customer cancelled. */
    public function expireSession(string $sessionId): void;
}
