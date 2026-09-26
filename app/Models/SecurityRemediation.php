<?php

namespace App\Models;

use App\Security\RemediationStatus;
use App\Security\RemediationType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One controlled change to a site, tied to a finding, a backup, an actor and its before/after state. */
class SecurityRemediation extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'type' => RemediationType::class,
            'status' => RemediationStatus::class,
            'before' => 'array',
            'after' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(SecurityFinding::class, 'finding_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(SecurityIncident::class, 'incident_id');
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(WebsiteJob::class, 'job_id');
    }

    public function quarantine(): BelongsTo
    {
        return $this->belongsTo(SecurityQuarantine::class, 'quarantine_id');
    }

    public function transitionTo(RemediationStatus $next, array $attributes = []): void
    {
        if (! in_array($next, $this->status->transitions(), true)) {
            throw new LogicException("Remediation {$this->uuid} cannot go from {$this->status->value} to {$next->value}.");
        }

        $this->forceFill(['status' => $next] + $attributes)->save();
    }
}
