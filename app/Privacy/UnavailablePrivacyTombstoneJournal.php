<?php

namespace App\Privacy;

use RuntimeException;

/** Default fail-closed adapter until an independently recoverable journal is approved and verified. */
final class UnavailablePrivacyTombstoneJournal implements PrivacyTombstoneJournal
{
    public function readiness(): array
    {
        return ['state' => 'blocked', 'reason' => 'independent_journal_not_configured', 'watermark' => null, 'replay_verified' => false];
    }

    public function eventsAfter(?string $snapshotWatermark): iterable
    {
        throw new RuntimeException('Privacy tombstone replay is blocked because the independent journal is not configured.');
    }
}
