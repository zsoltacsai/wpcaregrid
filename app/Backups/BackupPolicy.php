<?php

namespace App\Backups;

use App\Backups\Storage\StorageManager;
use App\Models\Website;
use App\Pricing\PricingCatalog;
use App\SyntheticFixtures\SyntheticFixturePolicy;
use Illuminate\Support\Carbon;

/**
 * Backup rules for a website, from its plan. Business policy lives here,
 * never in the connector: commercial cadence and plan entitlements come from
 * the canonical pricing catalogue; storage and safety mechanics are separate.
 */
class BackupPolicy
{
    public function __construct(
        private readonly PricingCatalog $catalog,
        private readonly StorageManager $storage,
    ) {}

    public function for(Website $website, ?BackupReason $reason = null): PolicyDecision
    {
        $subscription = $website->subscription;
        $plan = $subscription?->product_type && $subscription->plan ? $this->catalog->plan($subscription->product_type, $subscription->plan) : null;
        $settings = $plan ? (array) config("backup.plans.{$plan->productType->value}.{$plan->tier->value}", []) : [];
        $storage = $this->storage->defaultName();

        if ($website->syntheticFixture()->exists()) {
            $fixtures = app(SyntheticFixturePolicy::class);
            $entitled = $fixtures->backupRestoreAllowed($website);
            $controlled = $fixtures->controlledExecutionAllowed($website);
            $allowed = $entitled && $controlled && $storage !== null;
            $refusal = match (true) {
                ! $fixtures->isEnabledFor($website) => 'SYNTHETIC_FIXTURE_NOT_ENABLED',
                ! $entitled => 'SYNTHETIC_ENTITLEMENT_INACTIVE',
                ! $controlled => 'SYNTHETIC_CONTROLLED_EXECUTION_DISABLED',
                $storage === null => 'STORAGE_NOT_CONFIGURED',
                default => null,
            };

            return new PolicyDecision(
                eligible: $allowed,
                refusal: $refusal,
                frequency: null,
                type: BackupType::Full,
                retentionCount: null,
                retentionDays: null,
                storage: $storage,
                manualAllowed: $allowed,
                preUpdateRequired: false,
                restoreAllowed: $allowed && $fixtures->controlledExecutionAllowed($website)
                    && (bool) config('synthetic_fixtures.restore_enabled'),
            );
        }

        // An internal plan states for itself whether it includes backups (PROBONO: it does not).
        // This is checked before anything else, so no setting or missing price can enable backups.
        $internal = $subscription?->internal_plan;
        if ($internal !== null) {
            $plan = null;
            $settings = [];
        }

        $refusal = match (true) {
            $internal !== null && ! $internal->includesBackups() => 'BACKUPS_NOT_INCLUDED',
            $plan === null || $settings === [] => 'NO_BACKUP_PLAN',
            ! $website->allowsServiceOperations($reason === BackupReason::PreRestore ? 'restore' : 'service') => 'SUBSCRIPTION_INACTIVE',
            $storage === null => 'STORAGE_NOT_CONFIGURED',
            default => null,
        };

        return new PolicyDecision(
            eligible: $refusal === null,
            refusal: $refusal,
            frequency: $plan?->backupFrequency,
            type: BackupType::tryFrom((string) ($settings['type'] ?? 'full')) ?? BackupType::Full,
            retentionCount: isset($settings['retention_count']) ? max(1, (int) $settings['retention_count']) : null,
            retentionDays: isset($settings['retention_days']) ? max(1, (int) $settings['retention_days']) : null,
            storage: $storage,
            manualAllowed: $plan?->manualBackupAllowed ?? false,
            preUpdateRequired: (bool) ($settings['pre_update_backup'] ?? false),
            restoreAllowed: ($plan?->restoreEligible ?? false) && (bool) config('backup.restore.enabled'),
        );
    }

    /** The schedule window a scheduled backup belongs to, e.g. "weekly:2026-W39". */
    public static function window(string $frequency, \DateTimeInterface $at): ?string
    {
        $at = Carbon::instance($at)->utc();

        return match ($frequency) {
            'daily' => 'daily:'.$at->format('Y-m-d'),
            'weekly' => 'weekly:'.$at->format('o-\WW'),
            'monthly' => 'monthly:'.$at->format('Y-m'),
            default => null,
        };
    }
}
