<?php

namespace App\Pricing\Exceptions;

/** This price is not currently offered. */
final class PriceInactive extends PricingException
{
    public function reason(): string
    {
        return 'price_inactive';
    }
}
