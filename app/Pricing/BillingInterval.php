<?php

namespace App\Pricing;

enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Annual => 'Annual',
        };
    }

    /** Unit shown next to a price, e.g. "Ft/month". */
    public function unit(): string
    {
        return match ($this) {
            self::Monthly => 'month',
            self::Annual => 'year',
        };
    }

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Annual => 12,
        };
    }
}
