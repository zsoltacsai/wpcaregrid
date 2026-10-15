<?php

namespace App\Privacy;

/** Independent append-only source required before a restored database can leave recovery quarantine. */
interface PrivacyTombstoneJournal
{
    /** @return array{state:string, reason:string, watermark:?string, replay_verified:bool} */
    public function readiness(): array;

    /** @return iterable<array{event_id:string, subject_uuid:string, scope:string, action:string, sequence:string}> */
    public function eventsAfter(?string $snapshotWatermark): iterable;
}
