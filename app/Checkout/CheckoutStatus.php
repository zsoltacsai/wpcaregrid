<?php

namespace App\Checkout;

enum CheckoutStatus: string
{
    /** Local checkout whose Stripe Session state is not yet confirmed. */
    case Pending = 'pending';

    /** Stripe Checkout Session created; the customer has been sent to Stripe. */
    case CheckoutCreated = 'checkout_created';

    /** Payment confirmed by a verified Stripe webhook. Never set from a browser redirect. */
    case Completed = 'completed';

    /** The customer left Stripe through the cancel link. */
    case Cancelled = 'cancelled';

    /** Stripe reported the session expired without payment. */
    case Expired = 'expired';

    /** Stripe could not be reached, or the payment failed. */
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Expired, self::Failed], true);
    }
}
