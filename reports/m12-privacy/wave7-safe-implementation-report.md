# M12 Wave 7 — Safe Implementation Report

**Status: implementation merged and guarded-deployed; isolated staging synthetic E2E remains pending.** This report records engineering evidence only. Production destructive execution remains disabled. No production or staging customer data has been mutated, and no real provider deletion API has been called.

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
