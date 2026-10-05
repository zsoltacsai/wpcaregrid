<?php

namespace App\Billing;

/**
 * Internal plans: commercial arrangements that are never sold. They are not
 * PlanTier cases on purpose, so they cannot reach the pricing catalogue, the
 * public pricing page, the checkout selection or a Stripe Price. The owner
 * assigns them to a customer (App\Billing\InternalPlans); the subscription
 * then has no public plan (`plan` is null), which every plan-driven rule
 * already reads as "not included".
 *
 * Each plan states what it includes explicitly. A missing price never means
 * "allowed": backups in particular are off unless a plan says otherwise.
 * The boundary is documented in docs/PROBONO.md.
 */
enum InternalPlan: string
{
    case Probono = 'probono';

    public function label(): string
    {
        return match ($this) {
            self::Probono => 'PROBONO',
        };
    }

    /** Plugin, theme and core updates the connector can run (RequestUpdate). */
    public function includesUpdates(): bool
    {
        return true;
    }

    /** Inventory, site health and licence checks (reported by the connector). */
    public function includesHealthChecks(): bool
    {
        return true;
    }

    /**
     * Scheduled, manual and pre-update backups, their retention, and restore.
     * Explicitly off for PROBONO.
     */
    public function includesBackups(): bool
    {
        return match ($this) {
            self::Probono => false,
        };
    }

    /** Scheduled security scans (plan-driven); operators can still run a scan by hand. */
    public function includesScheduledScans(): bool
    {
        return false;
    }

    /** Temporary WordPress admin access for the customer (maintenance or full). */
    public function includesAdminAccess(): bool
    {
        return false;
    }
}
