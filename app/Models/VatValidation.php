<?php

namespace App\Models;

use App\Tax\Vat\VatValidationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Minimal VIES evidence; identifier and returned matching data are encrypted. */
class VatValidation extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['original_vat_identifier', 'vat_identifier', 'vat_identifier_hash', 'provider_name', 'provider_address'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('VAT validation evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('VAT validation evidence cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'status' => VatValidationStatus::class,
            'vat_identifier' => 'encrypted',
            'original_vat_identifier' => 'encrypted',
            'provider_name' => 'encrypted',
            'provider_address' => 'encrypted',
            'checked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function billingIdentity(): BelongsTo
    {
        return $this->belongsTo(CustomerBillingIdentity::class, 'customer_billing_identity_id');
    }
}
