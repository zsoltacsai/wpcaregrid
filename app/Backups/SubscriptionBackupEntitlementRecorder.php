<?php

namespace App\Backups;

use App\Models\Subscription;
use App\Models\SubscriptionBackupEntitlement;
use App\Pricing\Exceptions\InvalidPriceSelection;
use App\Pricing\PlanTier;
use App\Pricing\ProductType;
use Illuminate\Support\Carbon;

/** Appends a policy snapshot after Stripe has confirmed an active subscription state. */
final class SubscriptionBackupEntitlementRecorder
{
    public function __construct(private readonly BackupEntitlementCatalog $catalog) {}

    public function record(Subscription $subscription, ?string $sourceEventId, ?Carbon $effectiveAt = null, string $sourceType = 'stripe_event'): ?SubscriptionBackupEntitlement
    {
        if ($subscription->internal_plan !== null || ! $subscription->status?->grantsAccess()) {
            return null;
        }

        $rawProduct = $subscription->getRawOriginal('product_type');
        $rawTier = $subscription->getRawOriginal('plan');
        $product = is_string($rawProduct) ? ProductType::tryFrom($rawProduct) : null;
        $tier = is_string($rawTier) ? PlanTier::tryFrom($rawTier) : null;
        $version = $this->catalog->version();
        $effectiveAt ??= $subscription->current_period_start ?? $subscription->activated_at ?? now();
        $effectiveAt = $effectiveAt->copy()->utc();
        $policy = null;

        if ($product && $tier) {
            try {
                $policy = $this->catalog->for($product, $tier);
            } catch (InvalidPriceSelection) {
                // Missing/malformed catalogue data is recorded as UNKNOWN below.
            }
        }

        $resolution = $policy ? 'known' : 'unknown';
        $reason = $policy ? null : 'BACKUP_ENTITLEMENT_MAPPING_UNKNOWN';
        $latest = $subscription->backupEntitlements()->latest('id')->first();
        if ($latest && $latest->effective_at?->greaterThan($effectiveAt)) {
            return $latest;
        }
        if ($latest && $this->sameSnapshot($latest, $resolution, $reason, $policy, $version, $effectiveAt)) {
            return $latest;
        }

        $sourceKey = hash('sha256', implode('|', [
            $subscription->id,
            $sourceType,
            $sourceEventId ?? 'no-event',
            $effectiveAt->toIso8601String(),
            $resolution,
            $policy?->productType->value ?? 'unknown',
            $policy?->tier->value ?? 'unknown',
            $version,
        ]));

        return $subscription->backupEntitlements()->firstOrCreate(
            ['source_key' => $sourceKey],
            [
                'resolution' => $resolution,
                'reason_code' => $reason,
                'source_type' => $sourceType,
                'catalogue_version' => $version,
                'product_type' => $policy?->productType->value,
                'plan' => $policy?->tier->value,
                'cadence' => $policy?->cadence,
                'daily_recovery_points' => $policy?->daily,
                'weekly_recovery_points' => $policy?->weekly,
                'monthly_recovery_points' => $policy?->monthly,
                'pre_update_safety_required' => $policy?->preUpdateSafetyRequired,
                'manual_backup_allowed' => $policy?->manualAllowed,
                'restore_eligible' => $policy?->restoreEligible,
                'effective_at' => $effectiveAt,
                'source_event_id' => $sourceEventId,
                'created_at' => now(),
            ],
        );
    }

    private function sameSnapshot(
        SubscriptionBackupEntitlement $snapshot,
        string $resolution,
        ?string $reason,
        ?BackupEntitlement $policy,
        string $version,
        Carbon $effectiveAt,
    ): bool {
        return $snapshot->resolution === $resolution
            && $snapshot->reason_code === $reason
            && $snapshot->catalogue_version === $version
            && $snapshot->product_type === $policy?->productType->value
            && $snapshot->plan === $policy?->tier->value
            && $snapshot->cadence === $policy?->cadence
            && $snapshot->daily_recovery_points === $policy?->daily
            && $snapshot->weekly_recovery_points === $policy?->weekly
            && $snapshot->monthly_recovery_points === $policy?->monthly
            && $snapshot->pre_update_safety_required === $policy?->preUpdateSafetyRequired
            && $snapshot->manual_backup_allowed === $policy?->manualAllowed
            && $snapshot->restore_eligible === $policy?->restoreEligible
            && $snapshot->effective_at?->equalTo($effectiveAt);
    }
}
