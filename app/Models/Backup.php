<?php

namespace App\Models;

use App\Backups\BackupObjectName;
use App\Backups\BackupReason;
use App\Backups\BackupStatus;
use App\Backups\BackupType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/** One backup of one website. Holds metadata only; the data lives in backup storage. */
class Backup extends Model
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
            'type' => BackupType::class,
            'reason' => BackupReason::class,
            'status' => BackupStatus::class,
            'checksums' => 'array',
            'manifest' => 'array',
            'update_target' => 'array',
            'files_size' => 'integer',
            'database_size' => 'integer',
            'total_size' => 'integer',
            'started_at' => 'datetime',
            'uploaded_at' => 'datetime',
            'verified_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'expires_at' => 'datetime',
            'expired_at' => 'datetime',
            'objects_deleted_at' => 'datetime',
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

    public function objects(): HasMany
    {
        return $this->hasMany(BackupObject::class);
    }

    public function objectDeletions(): HasMany
    {
        return $this->hasMany(BackupObjectDeletion::class);
    }

    /** The BACKUP_* job that makes this backup. */
    public function job(): HasOne
    {
        return $this->hasOne(WebsiteJob::class)->whereIn('type', ['BACKUP_FILES', 'BACKUP_DATABASE', 'BACKUP_FULL']);
    }

    public function updateJob(): BelongsTo
    {
        return $this->belongsTo(WebsiteJob::class, 'update_job_id');
    }

    public function scopeVerified(Builder $query): void
    {
        $query->where('status', BackupStatus::Verified);
    }

    /** A verified, non-expired logical recovery point not undergoing deletion reconciliation. */
    public function scopeUsableRecoveryPoint(Builder $query): void
    {
        $query->where('status', BackupStatus::Verified)
            ->whereNull('objects_deleted_at')
            ->where(fn (Builder $builder) => $builder->whereNull('deletion_state')->orWhere('deletion_state', 'not_requested'));
    }

    /**
     * Move to another status, or fail loudly: states never jump.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transitionTo(BackupStatus $next, array $attributes = []): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw new LogicException("Backup {$this->uuid} cannot go from {$this->status->value} to {$next->value}.");
        }

        $this->forceFill(['status' => $next] + $attributes)->save();
    }

    /** Deterministic storage prefix: {website_uuid}/{backup_uuid}/ — no names, URLs or internal IDs. */
    public function storagePrefix(): string
    {
        return $this->website->uuid.'/'.$this->uuid.'/';
    }

    public function objectKey(BackupObjectName $name): string
    {
        return $this->storagePrefix().$name->filename();
    }

    /** Can serve as the source of a restore. */
    public function isRestorable(): bool
    {
        return $this->status === BackupStatus::Verified
            && $this->objects_deleted_at === null
            && in_array($this->deletion_state ?? 'not_requested', ['not_requested', ''], true)
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
