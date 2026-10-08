<?php

namespace App\Models;

use App\Tax\SellerTaxProfileStatus;
use App\Tax\SellerTaxRegistrationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SellerTaxRegistration extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['tax_identifier', 'tax_identifier_hash'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Seller tax registration versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Seller tax registration versions cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'registration_type' => SellerTaxRegistrationType::class,
            'verification_status' => SellerTaxProfileStatus::class,
            'tax_identifier' => 'encrypted',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(SellerTaxProfile::class, 'seller_tax_profile_id');
    }
}
