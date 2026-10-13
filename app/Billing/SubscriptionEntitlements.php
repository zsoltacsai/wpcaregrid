<?php

namespace App\Billing;

use App\Models\Subscription;

/** The single runtime decision point for subscription-backed service access. */
final class SubscriptionEntitlements
{
    public const WEBSITE_LIMIT = 1;

    public function allows(Subscription $subscription, string $capability = 'service'): bool
    {
        if ($subscription->isInternal()) {
            return $subscription->status->grantsAccess() && $subscription->internalWindowOpen();
        }

        if ($subscription->status->grantsAccess()) {
            return true;
        }

        if ($capability === 'restore') {
            // Emergency access is limited to restoration of an already provisioned paid site;
            // the restore policy still requires a verified backup, paired connector and safety backup.
            return $subscription->billing_source === 'stripe' && $subscription->activated_at !== null;
        }

        return $capability === 'service'
            && $subscription->status === SubscriptionStatus::PastDue
            && $subscription->grace_ends_at !== null
            && now()->lt($subscription->grace_ends_at);
    }

    public function websiteLimit(Subscription $subscription): int
    {
        return $this->allows($subscription) ? self::WEBSITE_LIMIT : 0;
    }
}
