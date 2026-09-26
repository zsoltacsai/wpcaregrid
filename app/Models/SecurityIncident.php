<?php

namespace App\Models;

use App\Security\IncidentStatus;
use App\Security\Severity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A security incident: findings, actions and notes gathered while a problem is handled. */
class SecurityIncident extends Model
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
            'status' => IncidentStatus::class,
            'severity' => Severity::class,
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function findings(): BelongsToMany
    {
        return $this->belongsToMany(SecurityFinding::class, 'security_incident_finding', 'incident_id', 'finding_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SecurityIncidentEvent::class, 'incident_id');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(SecurityScan::class, 'incident_id');
    }

    public function remediations(): HasMany
    {
        return $this->hasMany(SecurityRemediation::class, 'incident_id');
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
