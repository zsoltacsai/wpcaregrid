<?php

namespace App\Backups;

/**
 *   awaiting_safety_backup → queued → restoring → restored | needs_attention | failed
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

    /** @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::AwaitingSafetyBackup => [self::Queued, self::Failed, self::Cancelled],
            self::Queued => [self::Restoring, self::Failed, self::Cancelled],
            self::Restoring => [self::Restored, self::NeedsAttention, self::Failed],
            default => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->transitions(), true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::AwaitingSafetyBackup, self::Queued, self::Restoring], true);
    }

    public function label(): string
    {
        return __('backups.restore_statuses.'.$this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Restored => 'positive',
            self::NeedsAttention => 'warning',
            self::Failed => 'danger',
            self::Cancelled => 'neutral',
            default => 'neutral',
        };
    }
}
