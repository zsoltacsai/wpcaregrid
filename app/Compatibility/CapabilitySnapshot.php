<?php

namespace App\Compatibility;

use App\Models\Connector;
use App\Models\Website;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapabilitySnapshot extends Model
{
    protected $table = 'website_capability_snapshots';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'facts' => 'array',
            'capabilities' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class);
    }

    public function status(Capability $capability): CapabilityStatus
    {
        return CapabilityStatus::tryFrom((string) data_get($this->capabilities, $capability->value.'.status'))
            ?? CapabilityStatus::Unknown;
    }

    /** A missing timestamp or expired dynamic evidence is never fresh. */
    public function isFresh(?int $seconds = null): bool
    {
        return $this->checked_at !== null
            && $this->checked_at->greaterThanOrEqualTo(now()->subSeconds($seconds ?? (int) config('compatibility.dynamic_ttl_seconds')))
            && $this->policy_version === config('compatibility.policy_version')
            && $this->evaluator_version === config('compatibility.evaluator_version');
    }

    public function matchesCurrent(Website $website, ?Connector $connector): bool
    {
        return $connector !== null
            && (int) $this->connector_id === (int) $connector->id
            && $this->connector_version === $connector->connector_version
            && $this->wordpress_version === $website->wordpress_version
            && $this->php_version === $website->php_version;
    }
}
