<?php

namespace App\Pricing;

use InvalidArgumentException;

final readonly class Plan
{
    /**
     * @param  list<string>  $features
     * @param  array<string, Price>  $prices  Keyed by BillingInterval value.
     */
    public function __construct(
        public string $productLine,
        public string $key,
        public string $name,
        public string $description,
        public array $features,
        public array $prices,
        public bool $highlighted = false,
        public ?string $badge = null,
        public ?string $backupFrequency = null,
    ) {}

    /** Stable identifier, e.g. "woocommerce-business". Used for checkout and analytics. */
    public function id(): string
    {
        return "{$this->productLine}-{$this->key}";
    }

    public function price(BillingInterval $interval): Price
    {
        return $this->prices[$interval->value]
            ?? throw new InvalidArgumentException("Plan [{$this->id()}] has no {$interval->value} price.");
    }

    /** What an annual subscription saves compared with twelve monthly payments. */
    public function annualSavings(): Money
    {
        $monthly = $this->price(BillingInterval::Monthly)->money;
        $annual = $this->price(BillingInterval::Annual)->money;

        return $monthly->multiply(12)->subtract($annual);
    }

    /** Annual savings expressed in whole months of the monthly price. */
    public function freeMonthsOnAnnual(): int
    {
        $monthly = $this->price(BillingInterval::Monthly)->money->amount;

        return $monthly > 0 ? intdiv($this->annualSavings()->amount, $monthly) : 0;
    }
}
