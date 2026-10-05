<?php

namespace App\Offers;

use App\Checkout\CheckoutSession;
use App\Checkout\CheckoutUnavailable;
use App\Models\RecoveryOffer;

/**
 * The payment provider as recovery offers need it: a one-time Checkout
 * Session for the stored price. Stripe is the only implementation; the
 * interface exists so tests never call Stripe.
 */
interface OfferPaymentGateway
{
    /**
     * @param  string  $previousSessionId  The session being replaced ("" for the first), part of the idempotency key.
     *
     * @throws CheckoutUnavailable
     */
    public function createSession(RecoveryOffer $offer, string $previousSessionId = ''): CheckoutSession;

    /** Stop a session from being paid, e.g. after the offer was cancelled or expired. */
    public function expireSession(string $sessionId): void;
}
