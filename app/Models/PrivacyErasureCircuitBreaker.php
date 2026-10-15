<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Durable fail-closed execution breaker; it never authorizes execution itself. */
final class PrivacyErasureCircuitBreaker extends Model
{
    protected $primaryKey = 'breaker_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_open' => 'boolean',
            'opened_at' => 'datetime',
            'reset_at' => 'datetime',
        ];
    }
}
