<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionReservation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reserved_until' => 'datetime',
            'consumed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function checkoutIntent(): BelongsTo
    {
        return $this->belongsTo(CheckoutIntent::class);
    }
}
