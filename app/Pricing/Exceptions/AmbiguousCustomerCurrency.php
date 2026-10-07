<?php

namespace App\Pricing\Exceptions;

/** Current Stripe-backed subscription rows cannot establish one safe currency. */
final class AmbiguousCustomerCurrency extends PricingException
{
    public function reason(): string
    {
        return 'customer_currency_ambiguous';
    }
}
