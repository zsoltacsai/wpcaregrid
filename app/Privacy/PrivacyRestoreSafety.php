<?php

namespace App\Privacy;

/** Recovery must stay quarantined until an independent privacy journal can be read and replayed. */
final class PrivacyRestoreSafety
{
    public function __construct(private readonly PrivacyTombstoneJournal $journal) {}

    /** @return array{state:string, reason:string, recovery_mode:bool, destructive_retention_enabled:false, journal_state:string} */
    public function status(): array
    {
        $journal = $this->journal->readiness();
        $ready = ($journal['state'] ?? 'unknown') === 'ready' && ($journal['replay_verified'] ?? false) === true;

        return [
            'state' => $ready ? 'ready' : 'blocked',
            'reason' => $ready ? 'journal_replay_verified' : (string) ($journal['reason'] ?? 'journal_or_replay_not_verified'),
            'recovery_mode' => (bool) config('platform.recovery_mode'),
            'destructive_retention_enabled' => false,
            'journal_state' => (string) ($journal['state'] ?? 'unknown'),
        ];
    }

    /** @return iterable<array{event_id:string, subject_uuid:string, scope:string, action:string, sequence:string}> */
    public function eventsAfter(?string $snapshotWatermark): iterable
    {
        if (($this->status()['state'] ?? 'blocked') !== 'ready') {
            throw new \RuntimeException('Recovery quarantine cannot exit while privacy tombstone journal readiness is blocked.');
        }

        return $this->journal->eventsAfter($snapshotWatermark);
    }
}
