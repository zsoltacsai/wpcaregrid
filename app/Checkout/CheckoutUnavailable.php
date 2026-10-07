<?php

namespace App\Checkout;

use RuntimeException;

/** Checkout cannot continue for a reason that is not the customer's input. */
final class CheckoutUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly bool $outcomeUnknown = false)
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('Stripe is not configured.');
    }

    public static function liveKeyRefused(): self
    {
        return new self('A live Stripe key is configured but live mode is not enabled.');
    }

    public static function providerError(bool $outcomeUnknown = false): self
    {
        return new self('Stripe could not create a checkout session.', $outcomeUnknown);
    }
}
