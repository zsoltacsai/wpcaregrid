<?php

namespace App\Backups;

use App\Backups\Storage\StorageManager;
use App\Models\Website;
use App\Pricing\PricingCatalog;
use Illuminate\Support\Carbon;

/**
 * Backup rules for a website, from its plan. Business policy lives here,
 * never in the connector: frequency comes from the plan that was sold
 * (config/pricing.php), retention and the rest from config/backup.php.
 */
class BackupPolicy
{
    public function __construct(
        private readonly PricingCatalog $catalog,
        private readonly StorageManager $storage,
    ) {}

    public function for(Website $website): PolicyDecision
    {
        $subscription = $website->subscription;
        $plan = $subscription?->product_type && $subscription->plan ? $this->catalog->plan($subscription->product_type, $subscription->plan) : null;
        $settings = $plan ? (array) config("backup.plans.{$plan->productType->value}.{$plan->tier->value}", []) : [];
        $storage = $this->storage->defaultName();

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
            ! $website->allowsServiceOperations() => 'SUBSCRIPTION_INACTIVE',
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
            manualAllowed: (bool) ($settings['manual'] ?? false),
            preUpdateRequired: (bool) ($settings['pre_update_backup'] ?? false),
            restoreAllowed: (bool) ($settings['restore'] ?? false) && (bool) config('backup.restore.enabled'),
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
