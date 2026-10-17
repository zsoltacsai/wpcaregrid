<?php

namespace App\Backups;

use App\Pricing\Plan;
use App\Pricing\PlanTier;
use App\Pricing\ProductType;

/** Immutable commercial backup policy for one canonical product × tier. */
final readonly class BackupEntitlement
{
    public function __construct(
        public string $catalogueVersion,
        public ProductType $productType,
        public PlanTier $tier,
        public string $cadence,
        public int $daily,
        public int $weekly,
        public int $monthly,
        public bool $preUpdateSafetyRequired,
        public bool $manualAllowed,
        public bool $restoreEligible,
    ) {}

    public static function fromPlan(string $catalogueVersion, Plan $plan): self
    {
        return new self(
            catalogueVersion: $catalogueVersion,
            productType: $plan->productType,
            tier: $plan->tier,
            cadence: $plan->backupFrequency,
            daily: $plan->backupRecoveryPoints['daily'],
            weekly: $plan->backupRecoveryPoints['weekly'],
            monthly: $plan->backupRecoveryPoints['monthly'],
            preUpdateSafetyRequired: (bool) config("backup.plans.{$plan->productType->value}.{$plan->tier->value}.pre_update_backup", true),
            manualAllowed: $plan->manualBackupAllowed,
            restoreEligible: $plan->restoreEligible,
        );
    }
}
