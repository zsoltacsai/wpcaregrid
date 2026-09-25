<?php

namespace App\Pricing\Exceptions;

/** The selected product, plan or billing interval does not exist. */
final class InvalidPriceSelection extends PricingException
{
    public function reason(): string
    {
        return 'invalid_selection';
    }
}
