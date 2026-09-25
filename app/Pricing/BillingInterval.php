<?php

namespace App\Pricing;

enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';

    public function label(): string
    {
        return __("pricing.intervals.{$this->value}");
    }

    /** Unit shown next to a price, e.g. "month" in "Ft / month". */
    public function unit(): string
    {
        return __("pricing.units.{$this->value}");
    }

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Annual => 12,
        };
    }
}
