<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Central record of a quarantined file. The file itself stays on the site,
 * moved into the connector's non-executable quarantine directory; this row
 * keeps what is needed to put it back exactly (path, hash, size, time, mode).
 */
class SecurityQuarantine extends Model
{
    use HasUuids;

    public const QUARANTINED = 'quarantined';

    public const RESTORED = 'restored';

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
            'original_modified_at' => 'datetime',
            'quarantined_at' => 'datetime',
            'restored_at' => 'datetime',
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

    public function remediation(): BelongsTo
    {
        return $this->belongsTo(SecurityRemediation::class, 'remediation_id');
    }
}
