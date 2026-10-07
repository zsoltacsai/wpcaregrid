<?php

namespace App\Models;

use App\Checkout\CheckoutStatus;
use App\Pricing\BillingInterval;
use App\Pricing\PlanTier;
use App\Pricing\ProductType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * What a customer intended to buy, recorded before the Stripe redirect.
 *
 * @property string $uuid
 * @property CheckoutStatus $status
 */
class CheckoutIntent extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['browser_session_hash', 'stripe_checkout_url'];

    /** Only the public `uuid` is generated; the numeric id stays the primary key. */
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
            'status' => CheckoutStatus::class,
            'product_type' => ProductType::class,
            'plan' => PlanTier::class,
            'interval' => BillingInterval::class,
            'amount' => 'integer',
            'billing_address' => 'array',
            'stripe_checkout_expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public static function hashBrowserSession(string $sessionId): string
    {
        return hash_hmac('sha256', $sessionId, (string) config('app.key'));
    }

    /** Whether the Stripe session can still be used, e.g. after a double submit. */
    public function isReusable(): bool
    {
        return $this->status === CheckoutStatus::CheckoutCreated
            && $this->stripe_checkout_url
            && $this->stripe_checkout_expires_at?->isFuture();
    }

    /** Only these moves are allowed; anything else (e.g. completed → cancelled) is ignored. */
    public function canMoveTo(CheckoutStatus $status): bool
    {
        return match ($status) {
            CheckoutStatus::CheckoutCreated => $this->status === CheckoutStatus::Pending,
            // Signed Stripe events can arrive before the create-session
            // response is persisted (or resolve an ambiguous network result).
            CheckoutStatus::Completed => in_array($this->status, [CheckoutStatus::Pending, CheckoutStatus::CheckoutCreated, CheckoutStatus::Cancelled, CheckoutStatus::Expired], true),
            CheckoutStatus::Cancelled => $this->status === CheckoutStatus::CheckoutCreated,
            CheckoutStatus::Expired => in_array($this->status, [CheckoutStatus::Pending, CheckoutStatus::CheckoutCreated, CheckoutStatus::Cancelled], true),
            CheckoutStatus::Failed => ! $this->status->isFinal(),
            CheckoutStatus::Pending => false,
        };
    }
}
