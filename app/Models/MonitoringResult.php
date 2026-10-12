<?php

namespace App\Models;

use App\Connector\HealthStatus;
use App\Monitoring\MonitoringReason;
use App\Monitoring\MonitoringScope;
use App\Monitoring\MonitoringSeverity;
use Illuminate\Database\Eloquent\Model;

final class MonitoringResult extends Model
{
    protected $guarded = ['id'];

    public function isFresh(): bool
    {
        return $this->fresh_until !== null && $this->fresh_until->isFuture();
    }

    public function displayState(): HealthStatus
    {
        return $this->isFresh() ? $this->state : HealthStatus::Unknown;
    }

    public function displayReasonCode(): string
    {
        return $this->isFresh() ? $this->reason_code : MonitoringReason::MONITORING_SOURCE_UNVERIFIED;
    }

    protected function casts(): array
    {
        return [
            'state' => HealthStatus::class,
            'severity' => MonitoringSeverity::class,
            'scope' => MonitoringScope::class,
            'evidence' => 'array',
            'source_at' => 'datetime',
            'observed_at' => 'datetime',
            'fresh_until' => 'datetime',
            'next_check_at' => 'datetime',
        ];
    }
}
