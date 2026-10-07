<?php

namespace App\Pricing\Exceptions;

/** The requested display currency is not allowed for the billing-country market. */
final class MarketCurrencyMismatch extends PricingException
{
    public function reason(): string
    {
        return 'market_currency_mismatch';
    }
}
