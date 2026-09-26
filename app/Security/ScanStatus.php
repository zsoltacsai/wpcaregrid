<?php

namespace App\Security;

/**
 *   queued → running → completed | partial | failed
 *   queued | running → cancelled
 *
 * "partial": some of the scan finished (a scanner was unavailable, the
 * site's time budget ran out on the last part); what ran is kept.
 */
enum ScanStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::Queued => [self::Running, self::Completed, self::Partial, self::Failed, self::Cancelled],
            self::Running => [self::Completed, self::Partial, self::Failed, self::Cancelled],
            default => [],
        };
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Partial, self::Failed, self::Cancelled], true);
    }

    public function label(): string
    {
        return __('security.scan_statuses.'.$this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Completed => 'positive',
            self::Partial => 'warning',
            self::Failed => 'danger',
            default => 'neutral',
        };
    }
}
