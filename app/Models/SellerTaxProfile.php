<?php

namespace App\Models;

use App\Tax\AamStatus;
use App\Tax\SellerTaxProfileStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Versioned seller facts. Empty profiles are not tax registrations or tax decisions. */
class SellerTaxProfile extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['legal_name', 'seller_address', 'evidence_reference'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Seller tax profile versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Seller tax profile versions cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'aam_status' => AamStatus::class,
            'verification_status' => SellerTaxProfileStatus::class,
            'seller_address' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(SellerTaxRegistration::class);
    }
}
