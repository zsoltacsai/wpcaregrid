<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Immutable, effective-dated record of a subscription's backup policy assignment. */
class SubscriptionBackupEntitlement extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Backup entitlement snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('Backup entitlement snapshots are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'daily_recovery_points' => 'integer',
            'weekly_recovery_points' => 'integer',
            'monthly_recovery_points' => 'integer',
            'pre_update_safety_required' => 'boolean',
            'manual_backup_allowed' => 'boolean',
            'restore_eligible' => 'boolean',
            'effective_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
