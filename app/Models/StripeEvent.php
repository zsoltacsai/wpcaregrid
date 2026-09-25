<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A Stripe event that has been processed (identifiers only). */
class StripeEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stripe_created_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
