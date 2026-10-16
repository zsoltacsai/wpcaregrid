# M12 Wave 7 — Safe Implementation Report

**Latest status (2026-10-16): isolated staging synthetic T0/T1/restore/replay validation passed; production destructive execution remains disabled.** This continuation supersedes the earlier pending-validation statements retained below as history. It records engineering evidence only; it does not establish legal/accounting approval or production execution authority.

## Baseline and decisions

- Planning PR #68 was verified merged to `claude/ecstatic-wozniak-oemyuf`; exact planning head `6a0993e87a88d270fb833ae3c1bbc6fc098aebc7`, merge/baseline `fb6ee0413e91862ed0dce27f302309fce268edad`.
- Exact-head planning CI run `38806895701` was reported green in the approved decision package; the current GitHub status API returned no checks for the planning head during this implementation turn.
- The implementation branch is `codex/m12-wave7-safe-execution-foundation`, created from the merged baseline. PR #69 exact head `b9865b011d2a1360ebba9569043069278801142b` merged as `342f50cd94345dddcb5e5449d22040f3fad187d2`; post-merge fetch verified primary `claude/ecstatic-wozniak-oemyuf` at the same SHA.
- W7-OP-01…05 and W7-SEC-01 remain Owner-approved product/security defaults. W7-L-01…03 are `WAIT_LEGAL`; W7-A-01…02 are `WAIT_ACCOUNTING`; W7-P-01 is `WAIT_FACT`.
- `M12_DESTRUCTIVE_RETENTION_ENABLED=false`; profile anonymization, backup deletion, provider deletion, and bulk execution gates default false. Wave 7 has no production execution route or scheduler path.

## Implemented foundation

- Added immutable versioned erasure execution plans and per-action records with restrictive relationships, deterministic canonical preview hashes, source fingerprints, blocker codes, action idempotency keys, approval evidence, and cancellation boundary. Preview content uses opaque identifiers and aggregate impact values.
- Added Owner-only plan preview, detail, exact-plan approval and pre-execution cancellation routes. Approval requires current Owner password re-authentication, typed confirmation containing plan UUID and exact preview hash, and current source fingerprint. No bulk approval or casual destructive-gate UI exists.
- Added a central fail-closed authorization service for global/action gates, environment, plan/action relation, approval/hash, current source, recovery mode, journal readiness, circuit breaker and unresolved review blockers. It has no destructive executor caller.
- Added durable circuit-breaker state with allowlisted trip reasons and an explicit, re-authenticated, audited Owner reset.
- Added an explicit profile-field anonymizer. It releases current account email to a unique non-deliverable `privacy.invalid` value, suppresses authentication, revokes sessions/reset tokens/personal access tokens and WP CareGrid connector/pairing access, and leaves customer-site content and immutable accounting records alone.
- Added a test-only synthetic execution service. It requires `APP_ENV=testing`, `app.env=testing`, an explicit test flag, an active server-created synthetic fixture, the matching erasure case/tenant/subject, no active subscription, current approved preview, and healthy journal/circuit state. It writes, reads back and verifies a minimized independent tombstone before profile mutation. Provider and customer backup deletion are not implemented.
- Extended the Wave 6 journal/replay path for the minimized `profile_anonymized` event. Replay uses the same allowlisted suppression operation, verifies scope and request type, and is idempotent. The test restores a real older SQLite T0 database copy into a separate database after a synthetic T1 action, proves the original address exists before replay and is suppressed after replay, then repeats replay.
- Synthetic tests hash-check the invoice snapshot before/after, release and reuse the original email, and prove the new account does not inherit websites, subscriptions, privacy requests, or invoice associations. Connector credentials and pairing codes are revoked.
- Backup deletion remains preparation/reconciliation only and is blocked by `WAIT_FACT`; there is no real provider delete adapter, provider call, bulk path, queue worker, or eligibility-to-delete scheduler.

## Local validation evidence

- Focused Wave 7: **7 passed, 65 assertions, 0 failures, 0 skips**. Coverage includes journal commit/readiness and replay safety plus repeat synthetic execution without duplicate journal append.
- Related privacy, backup, platform, Stripe reconciliation/idempotency, billing snapshot/identity, and email regression set: **219 passed, 2,069 assertions, 0 failures, 13 MySQL/environment skips**. Skip reasons state that an explicitly named local MySQL test database was not configured.
- Full local server SQLite suite: **1,256 passed, 25 skipped, 1 failed, 18,367 assertions**. The sole failure is the existing `ConnectorPackageArtifactTest::test_untracked_connector_source_is_rejected_before_packaging`. In this WSL run the worktree `.git` file points to a Windows absolute gitdir; WSL Git reports “not a git repository,” and the packaging check consequently fails to observe the deliberately untracked fixture. The failure was reproduced independently and is unrelated to Wave 7 files. Exact-head Linux CI passed the same source tree and all required server/Connector gates.
- Pint: **932 files passed**.
- `composer audit --locked`: no security advisories.
- Frontend `npm run build`: passed; npm audit reported zero vulnerabilities during install.
- Secret-pattern scan: no credential/private-key patterns matched the changed implementation/report scope.
- `git diff --check`: passed before this release-evidence update; rerun before commit.
- Changed/new PHP syntax is covered by PHPUnit/Pint; a direct bulk lint using WSL `git diff` is not valid because of the Windows-format worktree gitdir pointer.

## Release and remaining gates

- PR #69 exact head `b9865b011d2a1360ebba9569043069278801142b`; GitHub Actions run `38815372947` completed successfully with all five mandatory jobs green, including MySQL process-concurrency and both Connector jobs. Merge SHA and current primary SHA: `342f50cd94345dddcb5e5449d22040f3fad187d2`.
- Guarded staging deployment of exact primary SHA `342f50cd94345dddcb5e5449d22040f3fad187d2` completed from pre-deploy staging SHA `f4559904990f95cf801b76ddeb4a1561cb3392b2`. Strict pinned-host-key SSH succeeded; deployment lock, protected pre-deploy DB backup, scheduler pause, Nginx/Laravel maintenance gate, exact checkout, migration, production build/cache, worker restart/resume and HTTP smoke checks passed. Staging runtime SHA was read back as `342f50cd94345dddcb5e5449d22040f3fad187d2`; checkout was clean.
- Post-deploy `system:check`: application, database, queue, scheduler, Connector API, Stripe, Cloudflare, backup storage, mail, safety, retention, restore safety and privacy exports were OK. The existing security scanner state remained visible as `WARNING` because no completed scan exists.
- Staging configuration was read back from the deployed config cache: global destructive retention, profile anonymization, backup deletion, provider deletion, bulk execution, and synthetic-test execution gates were all `false`.
- The required isolated staging synthetic T0/T1/restore/replay E2E was not run. The deployed tree intentionally requires a testing Laravel environment for synthetic mutation, while staging is production-mode; `vendor/bin/phpunit` is not installed in the production dependency set. The primary staging database and customer rows were not used or mutated. The local isolated T0/T1/restore/replay proof passed, but it is not staging evidence. Do not weaken the test-only guard; a separately provisioned isolated staging test runtime is still needed.
- The isolated local historical T0/T1/restore/replay test is passing, but it is not staging recovery evidence.
- Qualified legal/accounting approvals and provider versioning/deletion facts remain unresolved by design. Those blockers remain visible and cannot be overridden by Owner approval.
- Production destructive gates remain OFF. No production destructive capability is authorized by this report.

### Final staging validation continuation — 2026-10-16

This section records the continuation that closed the isolated Wave 7 staging-validation gap. Earlier interim statements above remain as historical release evidence and are superseded by this section where they say the staging E2E was still pending.

#### SSH recovery and exact source

- The previously reported SSH TCP timeout recovered. Cause of the outage was not established. SSH authentication succeeded with `StrictHostKeyChecking=yes`; the pinned `stg1.wpcaregrid.com` Ed25519 key fingerprint matched the approved `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`.
- The code defect found by the first isolated attempt was same-second stale-preview approval: source freshness depended on database timestamps with second precision. That attempt affected only the disposable synthetic row, produced no valid T0/replay evidence, and did not touch the primary staging database.
- PR #71 (`e48e91379300af67ad6eeceaac37df1a914109f8`) added an HMAC fingerprint over persisted customer/login state without persisting raw profile or credential values. It added a same-second regression proving stale approved plans are invalidated before anonymization. Exact-head GitHub Actions run `38839245477` passed all five mandatory jobs, including MySQL process concurrency; PR #71 merged as `19e31d7204a59e08211c4dd9e313b9cccbb01611`.
- Exact run log totals: server SQLite **1,283 tests / 18,413 assertions / 19 documented skips**; server MySQL **1,283 / 18,611 / 0 skips**; Connector SQLite **202 / 1,401 / 24 documented skips**; Connector MySQL **202 / 1,775 / 3 documented skips**. Quality/security/build and the required MySQL process-concurrency gate passed.
- The authoritative primary and guarded-deployed staging SHA are both `19e31d7204a59e08211c4dd9e313b9cccbb01611`. The deployed checkout was clean. Only the existing missing-completed-security-scan warning remained in `system:check`.

#### Isolated runtime and T0/T1/replay evidence

- Reused the previously prepared `/home/deploy/wpcaregrid-wave7-e2e-20261016a6fhqw/` runtime after inspection. It was owner `deploy`, mode `0700`, private/unrouted, exact deployed source, `APP_ENV=testing`, `APP_DEBUG=false`, separate APP_KEY, sync queue, array mail, and no Nginx, Supervisor, cron, scheduler, or normal-worker registration. No provider credentials or provider mutation path were available.
- The disposable MySQL 8.4.11 databases were `wpcg_wave7_e2e_20261016a6fhqw` and `wpcg_wave7_rec_20261016a6fhqw`; the test user was scoped to those databases. The test used the real journal/encryption/replay services with a test-only local fake object-storage disk and the isolated `platform-database/wave7-e2e-20261016a6fhqw/privacy-tombstones/testing` namespace. No event was written to the normal staging journal or S3 provider.
- The T0 state was captured with `mysqldump` from the disposable source DB. MySQL version: `8.4.11`; artifact size: `212,913` bytes; SHA-256: `ab4c78a716f04d761eb03ba7ca381e9b9ca07ff965eca1999e45fb75b95e733c`. T0 journal watermark was empty (`null`). T1 journal sequence: `01M518Q8GWSZTZCX0XS146ZKM3`.
- Plan version 1 was approved with typed confirmation and invalidated after a bound source fact changed. Fresh plan version 2 was approved against preview hash `f7eb31db45b637b80785514e130c4690912da4e4a15e0abdfe259b5687c2f42e`. The synthetic anonymization service committed/read back/verified the journal entry before profile mutation. The action completed for the synthetic subject while the overall case correctly remained `partial` with `WAIT_LEGAL`, `WAIT_ACCOUNTING` and `WAIT_FACT` blockers.
- The T1 action changed only the synthetic account's mutable identity/authentication state and revoked its Connector/pairing access. The original synthetic email was released and re-registered on a new account. In the recovery database the new identity had zero websites, subscriptions, privacy requests, execution plans, provider tasks and invoice associations. No backup object or WordPress site content was deleted.
- Nine immutable accounting/billing evidence table fingerprints matched before and after the T1 mutation and replay. The genuine T0 dump was imported into the separate recovery database; before replay, the original synthetic T0 identity was present and the T1 anonymization was absent. The independent journal event remained available. Replay completed and restored privacy-safe identity/auth suppression; replaying a second time reported the event already applied. Billing/provider side effects were not invoked.
- Staging-context focused tests passed: plan/journal/replay suite **13 tests, 79 assertions**; synthetic guard-injection suite **4 tests, 12 assertions**; genuine MySQL restore/replay E2E **1 test, 74 assertions**. Total: **18 tests, 165 assertions, 0 failures, 0 skips**. The checks covered stale same-second approval, journal integrity/missing-event handling, cross-tenant replay, circuit breaker, missing synthetic fixture, journal write failure before mutation, cross-subject retargeting, and repeat replay.
- Read-only schedule inventory showed existing `backup:retention` (plan-specific customer backup retention) and `privacy:exports-expire` (temporary export artifacts only), with no account-erasure/anonymization executor. The isolated runtime had no scheduler process or registration. No scheduler command was invoked.

#### Cleanup and final safety state

- Removed both disposable MySQL databases, the scoped MySQL test user, the isolated runtime (including T0 artifact and encrypted test journal objects), and temporary test files. Verified both database names/user absent, runtime path absent, and zero Nginx/Supervisor/cron references. No isolated worker or scheduled task remained.
- Post-cleanup staging SHA remained `19e31d7204a59e08211c4dd9e313b9cccbb01611`; checkout clean; both normal Supervisor workers running. Application, DB, queue, scheduler, Connector API, Stripe, Cloudflare, backup storage, mail, privacy retention, restore safety and export checks passed. The existing security scanner warning remained visible.
- Re-read normal staging configuration after cleanup: global destructive retention, profile anonymization, backup deletion, provider deletion, bulk execution, and synthetic execution were all `false`. No provider deletion API was called. No production or ordinary staging customer record was mutated.
- W7-L-01…03 remain `WAIT_LEGAL`; W7-A-01…02 remain `WAIT_ACCOUNTING`; W7-P-01 remains `WAIT_FACT`. Wave 7 validation closes only for the synthetic-safe engineering scope; Wave 8 and production destructive execution remain out of scope.
