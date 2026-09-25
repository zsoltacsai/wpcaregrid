<?php

namespace App\Pricing;

use App\Pricing\Exceptions\InvalidPriceSelection;

/**
 * What a customer asked for: a product type, a tier and an interval. Never
 * an amount or a Stripe Price ID — the server resolves those.
 */
final readonly class PriceSelection
{
    public function __construct(
        public ProductType $productType,
        public PlanTier $tier,
        public BillingInterval $interval,
    ) {}

    /** @throws InvalidPriceSelection */
    public static function fromInput(mixed $productType, mixed $tier, mixed $interval): self
    {
        $productType = is_string($productType) ? ProductType::tryFrom($productType) : null;
        $tier = is_string($tier) ? PlanTier::tryFrom($tier) : null;
        $interval = is_string($interval) ? BillingInterval::tryFrom($interval) : null;

        if (! $productType || ! $tier || ! $interval) {
            throw new InvalidPriceSelection;
        }

        return new self($productType, $tier, $interval);
    }

    /** @return array{product: string, plan: string, interval: string} */
    public function toQuery(): array
    {
        return ['product' => $this->productType->value, 'plan' => $this->tier->value, 'interval' => $this->interval->value];
    }
}
