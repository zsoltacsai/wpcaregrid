<?php

namespace App\Backups;

/**
 * Backup lifecycle. Every change goes through Backup::transitionTo(), which
 * refuses anything not listed in transitions().
 *
 *   queued → running → uploading → uploaded → verifying → verified
 *   any unfinished state → failed;  verifying → corrupt
 *   verified → expired | deleted (objects removed, metadata kept)
 */
enum BackupStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Uploading = 'uploading';
    case Uploaded = 'uploaded';
    case Verifying = 'verifying';
    case Verified = 'verified';
    case Failed = 'failed';
    case Corrupt = 'corrupt';
    case Expired = 'expired';
    case Deleted = 'deleted';

    /** @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::Queued => [self::Running, self::Failed],
            self::Running => [self::Uploading, self::Failed],
            self::Uploading => [self::Uploaded, self::Failed],
            // Storing is retried from "uploaded" when storage is temporarily unavailable.
            self::Uploaded => [self::Verifying, self::Failed],
            self::Verifying => [self::Verified, self::Uploaded, self::Failed, self::Corrupt],
            // Re-verification (operator) goes through verifying again.
            self::Verified => [self::Verifying, self::Expired, self::Deleted],
            self::Corrupt => [self::Expired, self::Deleted],
            self::Failed => [self::Deleted],
            self::Expired, self::Deleted => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->transitions(), true);
    }

    /** Still being made. */
    public function inProgress(): bool
    {
        return in_array($this, [self::Queued, self::Running, self::Uploading, self::Uploaded, self::Verifying], true);
    }

    /** The connector may send data only in these states. */
    public function acceptsUploads(): bool
    {
        return in_array($this, [self::Running, self::Uploading], true);
    }

    public function label(): string
    {
        return __('backups.statuses.'.$this->value);
    }

    /** Status-badge tone. Only a verified backup is green. */
    public function tone(): string
    {
        return match ($this) {
            self::Verified => 'positive',
            self::Failed, self::Corrupt => 'danger',
            self::Expired, self::Deleted => 'neutral',
            default => 'neutral',
        };
    }
}
