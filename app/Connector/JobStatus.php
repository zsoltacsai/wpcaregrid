<?php

namespace App\Connector;

enum JobStatus: string
{
    case Queued = 'queued';
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
        return ! in_array($this, [self::Queued, self::Delivered], true);
    }
}
