<?php

namespace App\Models;

use App\Tax\VerificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Verification workflow state; it is intentionally not a VAT/tax treatment. */
class TaxVerification extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['metadata'];

    protected function casts(): array
    {
        return [
            'status' => VerificationStatus::class,
            'resolved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function billingIdentity(): BelongsTo
    {
        return $this->belongsTo(CustomerBillingIdentity::class, 'customer_billing_identity_id');
    }
}
