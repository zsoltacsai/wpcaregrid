<?php

namespace App\Privacy;

/** Independent append-only source required before a restored database can leave recovery quarantine. */
interface PrivacyTombstoneJournal
{
    /** @return array{state:string, reason:string, watermark:?string, replay_verified:bool, verified_events:int} */
    public function readiness(): array;

    /** The stable event ID must be a lowercase SHA-256 hex digest. */
    public function append(array $event): array;

    public function watermark(): ?string;

    /** @return iterable<array<string, mixed>> */
    public function eventsAfter(?string $snapshotWatermark): iterable;
}
