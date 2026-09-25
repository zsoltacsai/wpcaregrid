<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Latest inventory reported by a website's connector. */
class WebsiteInventory extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'core' => 'array',
            'plugins' => 'array',
            'themes' => 'array',
            'woocommerce' => 'array',
            'collected_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function updatesAvailable(): int
    {
        return collect($this->plugins)->where('update_available', true)->count()
            + collect($this->themes)->where('update_available', true)->count()
            + (($this->core['update_available'] ?? false) ? 1 : 0);
    }
}
