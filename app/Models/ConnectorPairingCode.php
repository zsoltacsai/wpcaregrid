<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A short-lived, single-use pairing code. Only its hash is stored. */
class ConnectorPairingCode extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function isUsable(): bool
    {
        return ! $this->used_at && ! $this->invalidated_at && $this->expires_at->isFuture();
    }
}
