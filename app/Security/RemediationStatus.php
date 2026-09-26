<?php

namespace App\Security;

/**
 *   awaiting_backup → queued → running → completed | failed
 *   awaiting_backup → blocked (BACKUP_REQUIRED_FAILED)
 *   awaiting_backup | queued → cancelled
 */
enum RemediationStatus: string
{
    case AwaitingBackup = 'awaiting_backup';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Blocked = 'blocked';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::AwaitingBackup => [self::Queued, self::Blocked, self::Failed, self::Cancelled],
            self::Queued => [self::Running, self::Completed, self::Failed, self::Cancelled],
            self::Running => [self::Completed, self::Failed],
            default => [],
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::AwaitingBackup, self::Queued, self::Running], true);
    }

    public function label(): string
    {
        return __('security.remediation_statuses.'.$this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Completed => 'positive',
            self::Failed, self::Blocked => 'danger',
            default => 'neutral',
        };
    }
}
