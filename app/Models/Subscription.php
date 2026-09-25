<?php

namespace App\Models;

use App\Billing\SubscriptionStatus;
use App\Pricing\BillingInterval;
use App\Pricing\Currency;
use App\Pricing\Money;
use App\Pricing\PlanTier;
use App\Pricing\ProductType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Local mirror of a Stripe subscription. Status and periods come only from
 * verified Stripe events; the local amount is informational.
 */
class Subscription extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id', 'uuid'];

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
            'status' => SubscriptionStatus::class,
            'product_type' => ProductType::class,
            'plan' => PlanTier::class,
            'interval' => BillingInterval::class,
            'amount' => 'integer',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'canceled_at' => 'datetime',
            'ended_at' => 'datetime',
            'last_payment_at' => 'datetime',
            'activated_at' => 'datetime',
            'activation_notified_at' => 'datetime',
            'stripe_synced_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function checkoutIntent(): BelongsTo
    {
        return $this->belongsTo(CheckoutIntent::class);
    }

    public function website(): HasOne
    {
        return $this->hasOne(Website::class);
    }

    public function grantsAccess(): bool
    {
        return $this->status->grantsAccess();
    }

    public function planName(): string
    {
        return __("pricing.plans.{$this->product_type->value}.{$this->plan->value}.name");
    }

    public function money(): Money
    {
        $config = config("pricing.currencies.{$this->currency}", []);

        return new Money($this->amount, Currency::fromConfig($this->currency, $config, app()->getLocale()));
    }

    /** Next charge date, or null when the subscription will not renew. */
    public function nextBillingDate(): ?Carbon
    {
        return $this->status->hasEnded() || $this->cancel_at_period_end ? null : $this->current_period_end;
    }
}
