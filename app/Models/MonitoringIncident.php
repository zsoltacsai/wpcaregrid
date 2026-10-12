<?php

namespace App\Models;

use App\Monitoring\MonitoringScope;
use App\Monitoring\MonitoringSeverity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MonitoringIncident extends Model
{
    protected $guarded = ['id'];

    public function transitions(): HasMany
    {
        return $this->hasMany(MonitoringTransition::class, 'incident_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(MonitoringAlertDelivery::class, 'incident_id');
    }

    protected function casts(): array
    {
        return [
            'scope' => MonitoringScope::class,
            'severity' => MonitoringSeverity::class,
            'evidence' => 'array',
            'opened_at' => 'datetime',
            'last_observed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }
}
