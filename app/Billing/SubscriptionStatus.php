<?php

namespace App\Billing;

/**
 * Subscription states, mirroring Stripe's subscription.status one to one,
 * plus a local `pending` state for a paid checkout whose subscription object
 * has not been received yet.
 *
 * | Stripe status        | Local              | Grants service access |
 * |----------------------|--------------------|-----------------------|
 * | (not received yet)   | pending            | no                    |
 * | incomplete           | incomplete         | no                    |
 * | incomplete_expired   | incomplete_expired | no                    |
 * | trialing             | trialing           | yes                   |
 * | active               | active             | yes                   |
 * | past_due             | past_due           | no (payment problem)  |
 * | unpaid               | unpaid             | no                    |
 * | paused               | paused             | no                    |
 * | canceled             | canceled           | no                    |
 */
enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Incomplete = 'incomplete';
    case IncompleteExpired = 'incomplete_expired';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Unpaid = 'unpaid';
    case Paused = 'paused';
    case Canceled = 'canceled';

    /** Explicit mapping from Stripe's status; null for a value we do not know. */
    public static function fromStripe(?string $status): ?self
    {
        return match ($status) {
            'incomplete' => self::Incomplete,
            'incomplete_expired' => self::IncompleteExpired,
            'trialing' => self::Trialing,
            'active' => self::Active,
            'past_due' => self::PastDue,
            'unpaid' => self::Unpaid,
            'paused' => self::Paused,
            'canceled' => self::Canceled,
            default => null,
        };
    }

    /** Whether the customer may use paid features (onboarding, maintenance). */
    public function grantsAccess(): bool
    {
        return in_array($this, [self::Active, self::Trialing], true);
    }

    public function hasEnded(): bool
    {
        return in_array($this, [self::Canceled, self::IncompleteExpired], true);
    }

    public function label(): string
    {
        return __("account.subscription_statuses.{$this->value}");
    }

    /** Tone for the shared status badge. */
    public function tone(): string
    {
        return match ($this) {
            self::Active, self::Trialing => 'positive',
            self::PastDue, self::Paused, self::Incomplete => 'warning',
            self::Unpaid => 'danger',
            self::Pending, self::Canceled, self::IncompleteExpired => 'neutral',
        };
    }
}
