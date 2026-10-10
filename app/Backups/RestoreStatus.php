<?php

namespace App\Backups;

/**
 *   awaiting_safety_backup → queued → restoring → restored | needs_attention | failed
 *   restoring → recovery_queued → recovering → recovered | recovery_failed
 *   restoring → manual_recovery_required
 *   awaiting_safety_backup → failed (safety backup not verified) | cancelled
 */
enum RestoreStatus: string
{
    case AwaitingSafetyBackup = 'awaiting_safety_backup';
    case Queued = 'queued';
    case Restoring = 'restoring';
    case Restored = 'restored';
    case NeedsAttention = 'needs_attention';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case RecoveryQueued = 'recovery_queued';
    case Recovering = 'recovering';
    case Recovered = 'recovered';
    case RecoveryFailed = 'recovery_failed';
    case ManualRecoveryRequired = 'manual_recovery_required';

    /** @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::AwaitingSafetyBackup => [self::Queued, self::Failed, self::Cancelled],
            self::Queued => [self::Restoring, self::Failed, self::Cancelled],
            self::Restoring => [self::Restored, self::NeedsAttention, self::Failed, self::RecoveryQueued, self::ManualRecoveryRequired],
            self::RecoveryQueued => [self::Recovering, self::RecoveryFailed, self::ManualRecoveryRequired],
            self::Recovering => [self::Recovered, self::RecoveryFailed],
            default => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->transitions(), true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::AwaitingSafetyBackup, self::Queued, self::Restoring, self::RecoveryQueued, self::Recovering], true);
    }

    /** Keep the linked safety/source artifacts out of retention while they are needed for operator recovery. */
    public function needsRecoveryArtifacts(): bool
    {
        return $this->isActive() || in_array($this, [self::RecoveryFailed, self::ManualRecoveryRequired], true);
    }

    public function label(): string
    {
        return __('backups.restore_statuses.'.$this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Restored, self::Recovered => 'positive',
            self::NeedsAttention, self::ManualRecoveryRequired => 'warning',
            self::Failed, self::RecoveryFailed => 'danger',
            self::Cancelled => 'neutral',
            default => 'neutral',
        };
    }
}
