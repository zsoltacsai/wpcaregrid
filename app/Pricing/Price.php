<?php

namespace App\Pricing;

final readonly class Price
{
    public function __construct(
        public Money $money,
        public BillingInterval $interval,
        public ?string $stripePriceId = null,
    ) {}
}
