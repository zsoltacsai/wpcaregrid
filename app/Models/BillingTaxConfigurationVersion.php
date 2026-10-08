<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class BillingTaxConfigurationVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'effective_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Billing tax configuration versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Billing tax configuration versions are immutable.'));
    }
}
