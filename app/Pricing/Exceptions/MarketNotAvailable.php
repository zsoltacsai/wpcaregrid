<?php

namespace App\Pricing\Exceptions;

/** No prices are configured for this market yet. */
final class MarketNotAvailable extends PricingException
{
    public function reason(): string
    {
        return 'market_not_available';
    }
}
