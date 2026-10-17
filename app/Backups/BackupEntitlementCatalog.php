<?php

namespace App\Backups;

use App\Pricing\PlanTier;
use App\Pricing\PricingCatalog;
use App\Pricing\ProductType;
use InvalidArgumentException;

/** Resolves commercial backup policy only through the canonical pricing plan identity. */
final class BackupEntitlementCatalog
{
    public function __construct(private readonly PricingCatalog $pricing) {}

    public function version(): string
    {
        $version = config('pricing.backup_entitlements.version');
        if (! is_string($version) || preg_match('/^backup-entitlements-v[1-9][0-9]*$/', $version) !== 1) {
            throw new InvalidArgumentException('A valid backup entitlement catalogue version is required.');
        }

        return $version;
    }

    public function for(ProductType $productType, PlanTier $tier): BackupEntitlement
    {
        return BackupEntitlement::fromPlan($this->version(), $this->pricing->plan($productType, $tier));
    }
}
