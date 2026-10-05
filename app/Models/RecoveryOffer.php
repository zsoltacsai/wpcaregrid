<?php

namespace App\Models;

use App\Offers\OfferStatus;
use App\Pricing\Currency;
use App\Pricing\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A custom one-off offer for security recovery work (docs/RECOVERY_OFFERS.md),
 * for exactly one customer and one of that customer's websites. The stored
 * price is the only amount ever charged; the customer cannot change it. The
 * offer describes work to be done: it is not a security finding.
 *
 * @property OfferStatus $status
 */
class RecoveryOffer extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    /** Never serialised: the Checkout URL is a bearer link, the notes are the owner's. */
    protected $hidden = ['stripe_checkout_url', 'internal_notes'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'status' => OfferStatus::class,
            'amount' => 'integer',
            'paid_amount' => 'integer',
            'valid_until' => 'datetime',
            'offered_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'expired_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
            'stripe_checkout_expires_at' => 'datetime',
            'offer_notified_at' => 'datetime',
            'payment_notified_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function money(): Money
    {
        return new Money($this->amount, Currency::fromConfig($this->currency, config("pricing.currencies.{$this->currency}", []), app()->getLocale()));
    }

    public function validityPassed(?Carbon $now = null): bool
    {
        return ($now ?? Carbon::now())->gte($this->valid_until);
    }

    /**
     * The state to show: an open offer whose validity has passed is expired
     * even before the hourly job records it.
     */
    public function effectiveStatus(): OfferStatus
    {
        return $this->status->isOpen() && $this->validityPassed() ? OfferStatus::Expired : $this->status;
    }

    public function canBeAccepted(): bool
    {
        return $this->status === OfferStatus::Offered && ! $this->validityPassed();
    }

    public function canBePaid(): bool
    {
        return $this->status === OfferStatus::Accepted && ! $this->validityPassed();
    }

    /** An open Checkout Session that can be reused. */
    public function hasLiveCheckout(): bool
    {
        return $this->stripe_checkout_url && $this->stripe_checkout_expires_at?->isFuture();
    }

    /** What the customer may see; drafts and cancelled drafts never. */
    public function scopeVisibleToCustomer($query)
    {
        return $query->where('status', '!=', OfferStatus::Draft->value)
            ->where(fn ($q) => $q->where('status', '!=', OfferStatus::Cancelled->value)->orWhereNotNull('offered_at'));
    }
}
