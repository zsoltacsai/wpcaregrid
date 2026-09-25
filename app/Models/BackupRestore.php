<?php

namespace App\Models;

use App\Backups\BackupType;
use App\Backups\RestoreStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/** A confirmed request to restore one verified backup onto its own website. */
class BackupRestore extends Model
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
            'scope' => BackupType::class,
            'status' => RestoreStatus::class,
            'result' => 'array',
            'confirmed_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    public function safetyBackup(): BelongsTo
    {
        return $this->belongsTo(Backup::class, 'safety_backup_id');
    }

    public function job(): HasOne
    {
        return $this->hasOne(WebsiteJob::class, 'restore_id');
    }

    /** @param  array<string, mixed>  $attributes */
    public function transitionTo(RestoreStatus $next, array $attributes = []): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw new LogicException("Restore {$this->uuid} cannot go from {$this->status->value} to {$next->value}.");
        }

        $this->forceFill(['status' => $next] + $attributes)->save();
    }
}
