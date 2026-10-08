<?php

namespace App\Models;

use App\Pricing\Currency;
use App\Pricing\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingInvoice extends Model
{
    protected $guarded = ['id', 'billing_identity_id'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'discount_total' => 'integer',
            'applied_discount_percent' => 'decimal:2',
            'applied_discount_amount' => 'integer',
            'applied_discount_duration_months' => 'integer',
            'discount_snapshot_confirmed_at' => 'datetime',
            'tax_total' => 'integer',
            'total' => 'integer',
            'amount_paid' => 'integer',
            'amount_due' => 'integer',
            'stripe_created_at' => 'datetime',
            'finalized_at' => 'datetime',
            'paid_at' => 'datetime',
            'last_stripe_event_at' => 'datetime',
            'payment_confirmation_notified_at' => 'datetime',
            'payment_failure_notified_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function billingIdentity(): BelongsTo
    {
        return $this->belongsTo(CustomerBillingIdentity::class, 'billing_identity_id');
    }

    public function money(string $field = 'amount_paid'): ?Money
    {
        $amount = $this->{$field};
        $currency = $this->currency;

        $config = is_string($currency) ? config("pricing.currencies.{$currency}") : null;
        if (! is_int($amount) || ! is_array($config) || ! isset($config['symbol'])) {
            return null;
        }

        return new Money($amount, Currency::fromConfig($currency, $config, app()->getLocale()));
    }
}
