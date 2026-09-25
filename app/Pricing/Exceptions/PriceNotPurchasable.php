<?php

namespace App\Pricing\Exceptions;

/** This price has no Stripe Price ID configured. */
final class PriceNotPurchasable extends PricingException
{
    public function reason(): string
    {
        return 'price_not_purchasable';
    }
}
