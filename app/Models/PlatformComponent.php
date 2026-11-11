<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One installed platform component, as read from a verified, read-only source. */
class PlatformComponent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['development_only' => 'boolean', 'present' => 'boolean', 'last_seen_at' => 'datetime', 'advisories_checked_at' => 'datetime'];
    }

    public function advisories(): HasMany
    {
        return $this->hasMany(PlatformAdvisory::class);
    }
}
