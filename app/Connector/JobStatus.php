<?php

namespace App\Connector;

enum JobStatus: string
{
    case Queued = 'queued';
    // An update waiting for its pre-update backup to be verified; never delivered.
    case Waiting = 'waiting';
    case Delivered = 'delivered';
    case Success = 'success';
    case Failed = 'failed';
    case Partial = 'partial';
    case Skipped = 'skipped';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /** Outcomes a connector may report. */
    public static function reportable(): array
    {
        return [self::Success, self::Failed, self::Partial, self::Skipped];
    }

    public function isFinal(): bool
    {
        return ! in_array($this, [self::Queued, self::Waiting, self::Delivered], true);
    }
}
