<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class InvoiceIntentTaxReview extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['justification', 'basis_reference'];

    protected function casts(): array
    {
        return ['justification' => 'encrypted', 'basis_reference' => 'encrypted', 'created_at' => 'immutable_datetime'];
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(InvoiceIntent::class, 'invoice_intent_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function configurationVersion(): BelongsTo
    {
        return $this->belongsTo(BillingTaxConfigurationVersion::class, 'configuration_version_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Invoice tax review entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Invoice tax review entries are append-only.'));
    }
}
