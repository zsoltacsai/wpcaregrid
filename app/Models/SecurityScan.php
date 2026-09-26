<?php

namespace App\Models;

use App\Security\ScanStatus;
use App\Security\ScanType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One security scan of one website. Holds versions, coverage and counts; never file contents or secrets. */
class SecurityScan extends Model
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
            'type' => ScanType::class,
            'status' => ScanStatus::class,
            'coverage' => 'array',
            'summary' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(SecurityIncident::class, 'incident_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(WebsiteJob::class, 'job_id');
    }

    public function transitionTo(ScanStatus $next, array $attributes = []): void
    {
        if (! in_array($next, $this->status->transitions(), true)) {
            throw new LogicException("Security scan {$this->uuid} cannot go from {$this->status->value} to {$next->value}.");
        }

        $this->forceFill(['status' => $next] + $attributes)->save();
    }
}
