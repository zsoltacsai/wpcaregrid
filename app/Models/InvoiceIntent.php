<?php

namespace App\Models;

use App\Invoicing\InvoiceIntentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class InvoiceIntent extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['commercial_snapshot', 'provider_response_metadata'];

    protected function casts(): array
    {
        return [
            'status' => InvoiceIntentStatus::class,
            'amount' => 'integer',
            'retry_count' => 'integer',
            'commercial_snapshot' => 'encrypted:array',
            'provider_response_metadata' => 'array',
            'dry_run_result' => 'array',
            'dry_run_validated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Invoice intents are audit records and cannot be deleted.'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function billingInvoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class);
    }

    public function billingIdentity(): BelongsTo
    {
        return $this->belongsTo(CustomerBillingIdentity::class, 'billing_identity_id');
    }

    public function sellerTaxProfile(): BelongsTo
    {
        return $this->belongsTo(SellerTaxProfile::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(InvoiceIntentEvent::class)->orderBy('id');
    }

    public function taxReviews(): HasMany
    {
        return $this->hasMany(InvoiceIntentTaxReview::class)->orderBy('id');
    }

    public function issuanceAttempt(): HasOne
    {
        return $this->hasOne(InvoiceIssuanceAttempt::class);
    }
}
