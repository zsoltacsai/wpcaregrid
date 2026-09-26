<?php

namespace App\Models;

use App\Security\Confidence;
use App\Security\FindingStatus;
use App\Security\FindingType;
use App\Security\Severity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A security finding, deduplicated per website by its fingerprint: a
 * repeated scan updates last_seen_at and occurrence_count instead of
 * creating another row. Evidence is metadata only (see SECURITY_FINDINGS.md).
 */
class SecurityFinding extends Model
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
            'type' => FindingType::class,
            'severity' => Severity::class,
            'confidence' => Confidence::class,
            'status' => FindingStatus::class,
            'evidence' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * Stable identity. A suspicious file is identified by its path alone: its
     * leading rule may change with a new rule set, it is still the same file.
     */
    public static function fingerprint(FindingType $type, string $code, ?string $component, ?string $path): string
    {
        if ($type === FindingType::SuspiciousFile && $path !== null) {
            return sha1(implode("\0", [$type->value, 'file', $path]));
        }

        return sha1(implode("\0", [$type->value, $code, (string) $component, (string) $path]));
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function firstScan(): BelongsTo
    {
        return $this->belongsTo(SecurityScan::class, 'first_scan_id');
    }

    public function lastScan(): BelongsTo
    {
        return $this->belongsTo(SecurityScan::class, 'last_scan_id');
    }

    public function incidents(): BelongsToMany
    {
        return $this->belongsToMany(SecurityIncident::class, 'security_incident_finding', 'finding_id', 'incident_id');
    }

    public function remediations(): HasMany
    {
        return $this->hasMany(SecurityRemediation::class, 'finding_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [FindingStatus::Open->value, FindingStatus::Confirmed->value]);
    }

    /** Confirmed malware: a suspicious file an operator confirmed. Never set by a scanner. */
    public function isConfirmedMalware(): bool
    {
        return $this->type === FindingType::SuspiciousFile && $this->status === FindingStatus::Confirmed && $this->confidence === Confidence::Confirmed;
    }

    /** The file's SHA-256 as last reported (for quarantine and repair preconditions). */
    public function sha256(): ?string
    {
        $hash = $this->evidence['sha256'] ?? null;

        return is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : null;
    }
}
