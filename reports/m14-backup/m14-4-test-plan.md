# M14.4 test plan

All tests planned here; none were executed in this planning-only change. All deletion/provider assertions use fakes and assert zero provider calls.

## Domain and plan fixtures

Six data-driven fixtures (`wordpress.basic`, `.business`, `.secure`, `woocommerce.basic`, `.business`, `.secure`) assert exact cadence/counts, schedule slots, UTC rollover, each retained daily/weekly/monthly slot, same-point multi-purpose behavior, recovery gaps, separate safety backup and expected logical maximum (3/11/25/11/25/48). Test checkout Stripe price → canonical plan → effective subscription snapshot → scheduler entitlement contract.

## GFS time/history

Freeze UTC and test daily/week/month/year boundaries, ISO Monday week, February, leap year, UTC DST transition independence, current and prior slots, deterministic timestamp tie-break, duplicate and late completion, 45–180 day synthetic history, missing day/week/month, failed/unverified/corrupt/incomplete object set, manual point, safety point, and evaluation idempotency. Invalidated verification produces a recovery gap without releasing older usable points.

## Protections and lifecycle

Test last-known-good only point and safe replacement; active restore source; compensation; update dependency; safety backup before/during/after linked operation; incident/manual hold; Owner extension above base; reject below-base daily/weekly/monthly and system safety floor; stale preview after new backup/verification/restore/hold/subscription change; upgrade and scheduled downgrade; downgrade only candidate preview; cancellation/offboarding WAIT gates; seven-day grace semantics unchanged; unknown internal/legacy plan remains review/protected. Zero synchronous delete, zero queue, zero storage/provider call for every mutation/reduction/migration/evaluator scenario.

## Migration and rollout

Empty DB, rollback/reapply, existing backups, null legacy count/days, explicit configured count/day, expired_at history, existing `expires_at`, no subscription, internal PROBONO, unmapped Stripe price, current and old policy version. Assert null never becomes zero; no backfill PASS from migration; no provider calls; no subscription mutation; no mass synchronous evaluation. Legacy `backup:retention` path must be proven report-only or execution-gated before new GFS roll-out, including env values set and unset.

## Scheduling and concurrency

One idempotent request per website/cadence slot; duplicate hourly command; missed windows produce current request plus gap, not burst. MySQL/process barriers for duplicate slot, two evaluators, backup verification during selection, restore lock during selection, hold added during selection, Owner reduction vs backup completion, subscription transition vs scheduler, stale preview confirmation. Locks are short DB transactions; no provider network within lock.

## Layers and staging

Unit tests for value objects/evaluator/UTC formula; Feature tests for subscription mapping, owner controls, backup/restore safety; Server SQLite and MySQL; MariaDB if added to mandatory matrix; MySQL/process only for real row-lock/idempotency behavior; fake S3/SFTP providers for candidate lifecycle with zero delete calls; Owner authenticated and two-tenant scope tests; Stripe TEST fixtures/replay; staging synthetic-only: entitlement snapshot, shadow evaluation, gap, preview, stale-preview denial, Owner extension, cleanup. No real customer data mutation or provider deletion. Before/after staging read all six M12 gates and verify false.
