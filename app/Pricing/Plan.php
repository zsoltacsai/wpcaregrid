<?php

namespace App\Pricing;

/**
 * A product type × tier, independent of market and price.
 */
final readonly class Plan
{
    /** @param  list<Feature>  $features  The complete feature list, not a diff against a lower tier. */
    public function __construct(
        public ProductType $productType,
        public PlanTier $tier,
        public string $name,
        public string $description,
        public array $features,
        public string $backupFrequency,
        public bool $highlighted = false,
        public ?string $badge = null,
    ) {}

    /** Stable identifier, e.g. "woocommerce-business". */
    public function id(): string
    {
        return "{$this->productType->value}-{$this->tier->value}";
    }

    /** @return list<string> */
    public function featureLabels(): array
    {
        return array_map(fn (Feature $feature) => $feature->label, $this->features);
    }
}
