<?php

namespace App\Models;

use App\Backups\BackupProtectionReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/** Additive backup protection relationship; releasing one hold never clears others. */
class BackupProtectionHold extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (self $hold): void {
            if (! in_array($hold->reason_code, array_column(BackupProtectionReason::cases(), 'value'), true)) {
                throw new InvalidArgumentException('Unknown backup protection reason.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'held_at' => 'datetime',
            'released_at' => 'datetime',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
