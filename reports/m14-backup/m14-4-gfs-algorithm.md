# M14.4 deterministic GFS algorithm (proposed)

**Mode:** initial implementation must be report/shadow-only. This algorithm does not authorize backup/provider deletion.

## Time and slot identities

All instants normalize to UTC. Daily slot is UTC date `YYYY-MM-DD`; weekly slot is ISO-8601 week, Monday 00:00 UTC through next Monday, key `YYYY-Www`; monthly slot is calendar month, first day 00:00 UTC through next month, key `YYYY-MM`. Retain the current slot and preceding `N-1` slots for each class. DST/local timezone never changes boundaries. Freeze UTC in tests.

## Eligible recovery-point source

A point can satisfy a slot only if it belongs to the same website; is complete and `verified`; has usable verified/completed timestamps; has a complete manifest and all required objects verified/present in recorded logical state; has no deletion/expiry state; is not invalidated/corrupt; and its verified timestamp lies inside the slot. Sort newest `verified_at`, then stable backup ID/UUID tie-break. `created_at`, queued, uploaded, or failed timestamps do not substitute for verification time. If verification is later revoked/corruption found, re-evaluate and report a gap; retain the prior usable point until safe replacement.

## Selection and multiple purpose

For each required slot, choose the newest eligible point within that exact slot. The same physical point may satisfy a DAILY, WEEKLY, and MONTHLY purpose only for its natural UTC day/week/month when all three are in their retained windows. It is one object with multiple logical `GFS_*` reasons/slot links; never copy it or assign it to an unrelated historical window. The maximum target counts are logical slots, not necessarily distinct storage objects.

```text
for class in DAILY, WEEKLY, MONTHLY:
  slots = current_and_previous_slots(class, entitlement.count[class], evaluated_at_utc)
  for slot in slots:
    points = same_site_verified_complete_usable_points_inside(slot)
    if points empty: record RECOVERY_GAP(slot)
    else: protect(max(points, by verified_at then stable_id), GFS_class(slot))

for backup in site_backups:
  reasons = all_gfs_and_non_gfs_protection_reasons(backup)
  if reasons nonempty: KEEP(reasons)
  else if every required fact/policy/hold/provider precondition is known:
    ELIGIBLE(explanation, policy_version)  // candidate only; no provider action
  else: BLOCKED or UNKNOWN(reason_codes)
```

Failed/incomplete points never fill a required slot. An empty slot emits a recovery gap and operational signal; it does not fabricate a past backup or schedule a burst. Scheduler requests at most the current due cadence slot, subject to idempotency, entitlement, M14.3 `BACKUP_READY`, authorization, workflow and safety. Existing hourly scheduler’s unique `(website_id,schedule_window)` is a useful guard but not sufficient for GFS membership.

## Thinning, retention output, idempotency

Daily points age out of daily requirement as the rolling window advances, but may remain selected for weekly/monthly slots or protected by another reason. Recompute selected slots rather than applying age-only deletion. Emit an immutable evaluation with entitlement version, UTC evaluation time, required/satisfied/missing slot identities, kept point IDs and reason codes, candidate IDs and proof, blocked/unknown facts, and source fingerprint. Repeated evaluation of unchanged source state yields the same result and no duplicate transitions. A source change (new backup verification, restore/compensation, hold, subscription transition, Owner override, provider fact) invalidates/recomputes the evaluation.

Output states: `KEEP`, `ELIGIBLE`, `BLOCKED`, `UNKNOWN`; missing recovery promise is separately `RECOVERY_GAP`. Unknown subscription/policy/hold/legal/accounting/provider state means no eligibility. Eligibility is not approval, command authorization, queue permission, provider request, or physical absence.

## Cadence and transition interactions

Schedule cadence remains monthly/weekly/daily from entitlement. The required monthly slot can be satisfied by a verified weekly/daily scheduled point within that calendar month; do not enqueue a second monthly artifact solely to label a slot. Use a unique canonical schedule-slot identity for a request and carry policy/catalogue version at request time. Missed windows remain gaps; request only the next due slot under bounded catch-up. During plan changes, old backup records preserve origin policy, but current slot selection uses the effective entitlement at the evaluation instant plus immutable snapshots. Upgrade does not create historical points; downgrade only recalculates/preview candidates and preserves all non-commercial protection.
