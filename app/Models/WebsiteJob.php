<?php

namespace App\Models;

use App\Connector\JobStatus;
use App\Connector\JobType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A whitelisted operation for one website's connector. */
class WebsiteJob extends Model
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
            'type' => JobType::class,
            'status' => JobStatus::class,
            'payload' => 'array',
            'result' => 'array',
            'error' => 'array',
            'delivered_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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

    /** BACKUP_*: the backup being made. UPDATE_*: the backup protecting the update. */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    public function restore(): BelongsTo
    {
        return $this->belongsTo(BackupRestore::class, 'restore_id');
    }

    /** What the connector receives: identifiers, type and validated payload only. */
    public function toConnectorArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type->value,
            'payload' => (object) ($this->payload ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
            'attempt' => $this->attempts,
        ];
    }
}
