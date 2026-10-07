<?php

namespace App\Pricing\Exceptions;

/** A signed-in Stripe customer already has current billing in another currency. */
final class CustomerCurrencyMismatch extends PricingException
{
    public function __construct(public readonly string $lockedCurrency)
    {
        parent::__construct();
    }

    public function reason(): string
    {
        return 'customer_currency_mismatch';
    }

    public function customerMessage(): string
    {
        return __('checkout.errors.customer_currency_mismatch', ['currency' => $this->lockedCurrency]);
    }
}
