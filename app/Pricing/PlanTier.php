<?php

namespace App\Pricing;

/** Plan tiers, lowest first. */
enum PlanTier: string
{
    case Basic = 'basic';
    case Business = 'business';
    case Secure = 'secure';

    public function previous(): ?self
    {
        return match ($this) {
            self::Basic => null,
            self::Business => self::Basic,
            self::Secure => self::Business,
        };
    }
}
