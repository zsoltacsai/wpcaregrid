# M12 Wave 6 — Backup / Provider Deletion Reconciliation

## Baseline and decision state

- Verified primary branch: `claude/ecstatic-wozniak-oemyuf`.
- Verified remote primary implementation baseline: `57fe3ef74b099f5ae6844f1ba6414149cc079d54`.
- Verified staging runtime at start: `8fb5be08108ec81c2de3a28c1ee11890554771a9`.
- Implementation branch: `codex/m12-wave6-backup-provider-reconciliation`.
- Owner questionnaire: **0 new Owner product-policy questions block safe non-destructive work**. See `wave6-owner-questionnaire.md`.
- The original user checkout had unrelated dirty/untracked items; they remain untouched. The implementation uses a separate clean managed worktree.

## Verified defects and changes

### Customer backup object deletion

Before Wave 6, `BackupDeletion::remove()` called three provider deletes and immediately wrote one `objects_deleted_at` timestamp. It had no per-object state and no read-after-delete; a partial provider outcome could be represented as complete. A partially deleted verified backup could also remain restore-eligible. The last verified recovery point could be deleted after the legacy `--confirm-only-backup` flag.

Changes in this branch:

- Added `backup_object_deletions` with object/provider/key, request timestamp, provider response state, read-back state, absent timestamp, attempts and safe error category. Historical aggregate delete timestamps are backfilled as `legacy_unverified`, never as provider-confirmed.
- Added optional presence inspection for S3, SFTP and local storage. `BackupDeletion` transitions the backup to expired/deleted only after all fixed logical keys are confirmed absent. Partial/unknown results stay visible, retain metadata and make the backup non-restorable.
- Preserved idempotent retries. Restore and deletion take the same website → backup lock order; queued restore/compensation and update dependencies block deletion.
- RET-24 is now a hard safety guard. The legacy CLI confirmation option cannot delete the only usable recovery point.
- Storage reconciliation now detects confirmed-deleted keys that reappear, pending/legacy-unverified deletion state, and deletion task records without corresponding `BackupObject` metadata.
- Corrupt artifact cleanup now records reconciliation-required state and performs no unverified provider call from the upload job.
- Owner Privacy & Retention shows aggregate counts, provider capability facts, and restore readiness without customer identifiers. `system:check` exposes missing independent tombstone-journal readiness as an attention state.

Provider absence is **logical key absence only**. Current code cannot establish whether S3 versioning, delete markers, Object Lock, replicas, provider retention, or legal deletion entitlement apply. These remain `WAIT_FACT`, `WAIT_LEGAL`, and/or `WAIT_ACCOUNTING`.

### Tombstone journal and restore quarantine

- Added `PrivacyTombstoneJournal` interface and a default unavailable adapter. No independent journal adapter is configured because source code does not establish an immutable, provider-independent, recoverable store.
- `PrivacyRestoreSafety` reports blocked until the journal is available and replay has been verified. `privacy:restore-readiness` exits non-zero while blocked. The status cannot be satisfied by setting the destructive-retention gate.
- Existing M9 `platform.recovery_mode` and queue quarantine continue to block unsafe connector work. A full replay applier and HTTP/customer-access recovery gate cannot safely be completed until the independent journal contract and provider recovery proof exist; recovery must remain quarantined in the meantime.
- M9 platform DB backups remain a separate plane on the dedicated `platform_backups` disk, not subject to customer backup retention. Their S3 configuration alone does not prove independence from provider/account failure.

### Provider/billing reconciliation

- Privacy provider task statuses now preserve `unknown` as an explicit state and show a localized Owner label. The provider task service remains metadata-only and makes no provider call.
- M11 Stripe webhook inbox/idempotency code remains unchanged; no Stripe state is replayed or mutated. Historical DB restore still requires provider-truth reconciliation before normal billing processing resumes.
- No accounting snapshot, seller snapshot, invoice record, legal acceptance, privacy request, or customer profile is rewritten by Wave 6 code.

## Safety boundary

- `M12_DESTRUCTIVE_RETENTION_ENABLED=false` remains the required state.
- This wave does not add a Wave 7 execution path or enable account/site/provider deletion.
- No provider deletion API, live Stripe call, production recovery, or customer mutation is part of automated validation. Backup unit/integration tests use local temporary storage; their disposable test files are removed during test teardown.
- Do not claim physical erasure or GDPR compliance.

## Validation and release evidence

Validation, CI, merge, staging deployment, health, and synthetic staging checks are recorded below after execution. Staging SSH must use strict host verification for `stg1.wpcaregrid.com` against the approved fingerprint from the task. No staging deletion drill may use a real customer backup.

### Local validation — implementation candidate

- Broad relevant regression set (Backups, Privacy, Platform, Connector, Stripe webhook/subscription reconciliation and concurrency, InvoiceIntent/InvoiceIssuance concurrency): **257 tests, 2,130 assertions, 0 failures, 0 errors, 14 skips**. Skips: local loopback MySQL/PCNTL concurrency tests and platform MySQL 8.4 drill require the isolated MySQL CI service; S3 adapter cases require `moto_server`; SFTP adapter cases require `sshd`. The CI workflow provisions those required services and its strict MySQL job fails on unexpected skips.
- Final-code targeted rerun after the idempotent completed-delete retry change: **33 tests, 218 assertions, 0 failures, 0 errors, 0 skips**.
- Focused privacy-foundation update: **12 tests, 230 assertions, 0 failures, 0 errors, 0 skips**.
- Final storage/backup targeted rerun after local-storage unavailable-root handling: **49 tests, 266 assertions, 0 failures, 0 errors, 6 skips**. All six skips are the S3 cases requiring `moto_server` and SFTP cases requiring `sshd`; CI provisions both.
- Laravel Pint: **573 PHP files passed** after formatting six changed files.
- PHP syntax lint: all PHP under `app`, `routes`, `database/migrations`, and the relevant backup/privacy/platform test suites passed.
- `composer audit --locked`: no advisories. `npm ci`: 0 reported vulnerabilities. `npm audit --audit-level=high`: 0 vulnerabilities. `npm run build`: passed.
- `git diff --check`: passed. Local secret-pattern scan: no matches in changed source/report areas. Gitleaks is not installed locally; the required full-history Gitleaks scan remains part of exact-head CI.
- CI, guarded staging deployment, staging health, and synthetic staging validation: **pending**.

The broad local regression run preceded only the final completed-delete idempotency no-op and Pint formatting; the focused 33-test rerun covers those final changes. No provider deletion API, backup deletion, customer mutation, production action, or staging mutation has been performed in this task.

## Remaining blockers / gates

1. Independent tombstone journal store, snapshot watermark and cryptographic integrity/recovery proof: **provider/infrastructure fact gate**. The code fails closed; the runbook must keep recovery mode/workers/customer operation quarantined until verified.
2. S3/SFTP version, lock, replica, eventual behavior, region/contract and deletion capability evidence: **WAIT_FACT**.
3. Qualified legal approval for tombstone purpose/scope/period and deletion entitlement: **WAIT_LEGAL**.
4. Qualified accounting approval for financial/accounting exceptions: **WAIT_ACCOUNTING**.
5. Full tombstone replay applier and synthetic historical-restore drill remain contingent on 1–4; this report will not mark them complete based only on an interface or mocked journal.

## Release disposition

Pending validation. No Wave 7 destructive retention is started. No GDPR compliance claim is made.
