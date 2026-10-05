<?php

namespace App\Offers;

/**
 * States of a recovery offer.
 *
 *   draft ──send──▶ offered ──accept──▶ accepted ──webhook──▶ paid ──▶ completed
 *     │                │  │                 │
 *     │                │  └─decline─▶ declined
 *     └──cancel──▶ cancelled ◀──────────────┘   (offered/accepted too)
 *                 offered/accepted ──valid_until passes──▶ expired
 *
 * Only the signed Stripe webhook moves an offer to `paid`.
 */
enum OfferStatus: string
{
    case Draft = 'draft';
    case Offered = 'offered';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    /** Owner-facing label (English). */
    public function label(): string
    {
        return __("owner.offers.statuses.{$this->value}");
    }

    /** Customer-facing label, in the customer's language. */
    public function customerLabel(): string
    {
        return __("offers.statuses.{$this->value}");
    }

    public function tone(): string
    {
        return match ($this) {
            self::Paid, self::Completed => 'positive',
            self::Offered, self::Accepted => 'warning',
            self::Declined, self::Expired, self::Cancelled, self::Draft => 'neutral',
        };
    }

    /** Can still lead to a payment. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Offered, self::Accepted], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Declined, self::Expired, self::Cancelled, self::Completed], true);
    }

    /** The owner may still change title, scope, price and validity. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Offered], true);
    }
}
