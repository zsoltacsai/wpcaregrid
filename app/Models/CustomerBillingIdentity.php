<?php

namespace App\Models;

use App\Tax\VerificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Immutable, versioned billing identity; profile edits create a new row. */
class CustomerBillingIdentity extends Model
{
    protected $guarded = ['*'];

    protected $hidden = [
        'legal_name', 'company_name', 'address_line1', 'address_line2', 'postal_code', 'city', 'region',
        'tax_identifier', 'tax_identifier_hash', 'evidence_reference',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Billing identity snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('Billing identity snapshots cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'tax_identifier' => 'encrypted',
            'declared_business_status' => 'boolean',
            'verification_status' => VerificationStatus::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vatValidations(): HasMany
    {
        return $this->hasMany(VatValidation::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(TaxVerification::class);
    }

    public function checkoutIntents(): HasMany
    {
        return $this->hasMany(CheckoutIntent::class, 'billing_identity_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'billing_identity_id');
    }

    public function billingInvoices(): HasMany
    {
        return $this->hasMany(BillingInvoice::class, 'billing_identity_id');
    }

    public function latestVatValidation(): ?VatValidation
    {
        return $this->vatValidations()->latest('checked_at')->first();
    }

    public function lastSuccessfulVatValidation(): ?VatValidation
    {
        return $this->vatValidations()
            ->whereIn('status', ['valid', 'invalid'])
            ->latest('checked_at')
            ->first();
    }
}
