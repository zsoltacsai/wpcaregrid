<?php

namespace App\Models;

use App\Billing\InternalPlan;
use App\Billing\SubscriptionStatus;
use App\Pricing\BillingInterval;
use App\Pricing\Currency;
use App\Pricing\Money;
use App\Pricing\PlanTier;
use App\Pricing\PricingCatalog;
use App\Pricing\ProductType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
            'internal_plan' => InternalPlan::class,
            'internal_starts_at' => 'datetime',
            'internal_ends_at' => 'datetime',
            'interval' => BillingInterval::class,
            'amount' => 'integer',
            'base_price_amount' => 'integer',
            'discount_percent' => 'decimal:2',
            'discount_amount' => 'integer',
            'initial_discount_percent' => 'decimal:2',
            'initial_discount_amount' => 'integer',
            'initial_discount_duration_months' => 'integer',
            'initial_invoice_discount_total' => 'integer',
            'initial_discount_snapshot_confirmed_at' => 'datetime',
            'current_recurring_amount' => 'integer',
            'billing_snapshot_confirmed_at' => 'datetime',
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

    public function billingInvoices(): HasMany
    {
        return $this->hasMany(BillingInvoice::class)->latest('stripe_created_at');
    }

    /** An internal plan (PROBONO): no Stripe subscription, no public tier, no price. */
    public function isInternal(): bool
    {
        return $this->internal_plan !== null;
    }

    public function grantsAccess(): bool
    {
        if (! $this->status->grantsAccess()) {
            return false;
        }

        // An internal plan also stops at its end date, without waiting for the scheduled sync.
        return ! $this->isInternal() || $this->internalWindowOpen();
    }

    /** Whether "now" is inside the internal plan's optional start and end dates. */
    public function internalWindowOpen(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        return ($this->internal_starts_at === null || $now->gte($this->internal_starts_at))
            && ($this->internal_ends_at === null || $now->lt($this->internal_ends_at));
    }

    /** The plan's name as the pricing catalogue shows it (including wording published in the owner area). */
    public function planName(): string
    {
        if ($this->isInternal()) {
            return $this->internal_plan->label();
        }

        return app(PricingCatalog::class)->plan($this->product_type, $this->plan)->name;
    }

    /** Short English tier label for the owner area. */
    public function tierLabel(): string
    {
        return $this->isInternal() ? $this->internal_plan->label() : __('owner.tiers.'.$this->plan->value);
    }

    public function money(): Money
    {
        $config = config("pricing.currencies.{$this->currency}", []);

        return new Money((int) $this->amount, Currency::fromConfig($this->currency, $config, app()->getLocale()));
    }

    public function currentRecurringMoney(): ?Money
    {
        if (! $this->billing_snapshot_confirmed_at || ! is_int($this->current_recurring_amount) || ! $this->current_recurring_currency) {
            return null;
        }

        $currency = $this->current_recurring_currency;
        $config = config("pricing.currencies.{$currency}", []);

        return isset($config['symbol']) ? new Money($this->current_recurring_amount, Currency::fromConfig($currency, $config, app()->getLocale())) : null;
    }

    public function basePriceMoney(): ?Money
    {
        $amount = is_int($this->base_price_amount) ? $this->base_price_amount : $this->amount;
        $currency = $this->base_price_currency ?: $this->currency;
        if (! is_int($amount) || ! $currency) {
            return null;
        }

        $config = config("pricing.currencies.{$currency}", []);

        return isset($config['symbol']) ? new Money($amount, Currency::fromConfig($currency, $config, app()->getLocale())) : null;
    }

    public function discountPercentLabel(): ?string
    {
        if ($this->discount_percent === null) {
            return null;
        }

        $value = (string) $this->discount_percent;

        return rtrim(rtrim($value, '0'), '.');
    }

    public function initialDiscountPercentLabel(): ?string
    {
        if ($this->initial_discount_percent === null) {
            return null;
        }

        $value = (string) $this->initial_discount_percent;

        return rtrim(rtrim($value, '0'), '.');
    }

    /** Next charge date, or null when the subscription will not renew. */
    public function nextBillingDate(): ?Carbon
    {
        return $this->isInternal() || $this->status->hasEnded() || $this->cancel_at_period_end ? null : $this->current_period_end;
    }
}
