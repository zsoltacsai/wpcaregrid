<?php

namespace App\Models;

use App\Connector\LicenseStatus;
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

    /** Licenses the plugins themselves report as expired. "unknown" is not counted. */
    public function licensesExpired(): int
    {
        return collect($this->plugins)->filter(fn (array $plugin) => ($plugin['license']['status'] ?? null) === LicenseStatus::Expired->value)->count();
    }

    /** Keep the stored counts (used to filter and sort lists) in line with the lists. */
    public function refreshCounts(): void
    {
        $this->forceFill(['updates_pending' => $this->updatesAvailable(), 'licenses_expired' => $this->licensesExpired()])->save();
    }
}
