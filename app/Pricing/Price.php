<?php

namespace App\Pricing;

/** What one plan costs in one market for one billing interval. */
final readonly class Price
{
    public function __construct(
        public ProductType $productType,
        public PlanTier $tier,
        public BillingInterval $interval,
        public string $market,
        public Money $money,
        public ?string $stripePriceId,
        public bool $active,
    ) {}

    public function hasStripePrice(): bool
    {
        return is_string($this->stripePriceId) && $this->stripePriceId !== '';
    }
}
