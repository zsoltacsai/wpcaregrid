<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceIssuanceAttempt extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['provider_response_metadata' => 'array', 'submitted_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(InvoiceIntent::class, 'invoice_intent_id');
    }
}
