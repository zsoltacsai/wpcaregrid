<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The singleton, expiring staging-wide gate for one M14 synthetic Integrity scan. */
class M14SecurityScanWindow extends Model
{
    public $incrementing = false;

    protected $fillable = [
        'active', 'website_id', 'scan_uuid', 'side_effect_isolation', 'opened_by', 'opened_at', 'expires_at', 'closed_by', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'side_effect_isolation' => 'boolean',
            'opened_at' => 'datetime',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function isLive(): bool
    {
        return $this->active && $this->website_id !== null && $this->expires_at?->isFuture() === true;
    }
}
