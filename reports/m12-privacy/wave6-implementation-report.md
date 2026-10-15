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

### Exact-SHA CI blocker

- Implementation commit: `bab68052cc8795f3ea6d7a24d5e1844200c4f7fc`.
- GitHub Actions run **#228 / 38730339504** was created for this exact PR head. All five required jobs failed before executing any steps (`steps=[]`). GitHub's check annotation states: **“The job was not started because recent account payments have failed or your spending limit needs to be increased.”** This is an account-level CI billing/spending gate, not a test/code failure. No payment, billing limit, or account setting was changed.
- The authorized GitLab fallback project `fountainbridge-group/wpcaregrid` (project 87658222) currently has only its unrelated README-only initial `main` commit (`9940694c2759882dff96c74ad901d62ca96ad9cb`), no CI configuration file, and no pipeline history. It has no common commit ancestry with the verified GitHub candidate baseline, so it cannot validate the identical candidate SHA/tree under the existing gates. No GitLab branch or alternate CI configuration was created.
- Because no mandatory CI job ran green, PR #60 remains unmerged and no staging deployment or staging mutation was performed. The verified starting staging runtime remains the known baseline `8fb5be08108ec81c2de3a28c1ee11890554771a9`; current staging state was not independently re-read after the CI block.

The broad local regression run preceded only the final completed-delete idempotency no-op and Pint formatting; the focused 33-test rerun covers those final changes. No provider deletion API, backup deletion, customer mutation, production action, or staging mutation has been performed in this task.

## Remaining blockers / gates

1. Independent tombstone journal store, snapshot watermark and cryptographic integrity/recovery proof: **provider/infrastructure fact gate**. The code fails closed; the runbook must keep recovery mode/workers/customer operation quarantined until verified.
2. S3/SFTP version, lock, replica, eventual behavior, region/contract and deletion capability evidence: **WAIT_FACT**.
3. Qualified legal approval for tombstone purpose/scope/period and deletion entitlement: **WAIT_LEGAL**.
4. Qualified accounting approval for financial/accounting exceptions: **WAIT_ACCOUNTING**.
5. Full tombstone replay applier and synthetic historical-restore drill remain contingent on 1–4; this report will not mark them complete based only on an interface or mocked journal.

## Release disposition

Pending validation. No Wave 7 destructive retention is started. No GDPR compliance claim is made.

## Final release and GitHub Actions evidence — 2026-10-15

This section supersedes the earlier provisional release disposition above; the earlier evidence remains preserved as history.

- Wave 6 implementation PR #60 was merged to `claude/ecstatic-wozniak-oemyuf` at `a6e79bd381319ee2e18c90fd464cdbaf9cef4908`. The same SHA was deployed to staging on 2026-10-15; the post-deploy checks recorded healthy application/database/queue/scheduler/Connector/Stripe/backup-storage/mail/privacy-retention/export status, zero failed or reserved jobs, zero active mutating WebsiteJobs, HTTP 200 and valid TLS. Destructive retention and recovery mode were both OFF.
- The read-only `backup:reconcile-storage` staging check reported S3 healthy, 24 listed objects and zero anomalies. `privacy:restore-readiness --json` remained blocked with `independent_journal_not_configured`; recovery remained quarantined. No provider deletion or customer data mutation was performed.
- Audit found that `platform:database-backup-reconcile` persists delivery reconciliation status and timestamp despite its former “read-only” description. It was not run against staging. PR #61 corrected the command description and added a regression assertion for those writes; the command does not delete provider objects.
- PR #61 head `39e2ed9951c1d8b56821b4e08dcc63830fa180fe` passed exact-SHA GitHub Actions run #248, run ID `38768895991`, attempt 3. All five mandatory jobs passed: Quality; Server SQLite; Server MySQL; Connector SQLite; Connector MySQL. The run URL is https://github.com/zsoltacsai/wpcaregrid/actions/runs/38768895991.
- Test summaries from that run: Server SQLite 1,266 tests / 18,237 assertions / 19 skips; Server MySQL 1,266 / 18,435 / 0 skips; Connector SQLite 202 / 1,401 / 24 skips; Connector MySQL 202 / 1,775 / 3 skips. Combined: **2,936 tests, 39,848 assertions, 46 skips, 0 failures**. Skips are environment/opt-in cases: SQLite-only suites omit MySQL-specific database and process-concurrency cases (including the MySQL 8.4 dump-import drill); Connector SQLite omits MySQL integration/poll-lock cases; Connector MySQL omits one test meaningful only without MySQL and two opt-in real worker-kill tests requiring the 900-second poll-lock TTL. MySQL server CI ran its required concurrency gate successfully. Quality passed syntax/style/dependency/build/secret-scan checks. No post-merge workflow run was returned for merge SHA `1a45951475a292ecc06c9547a4fd4b94ae5eab77`; the exact PR-head run above is green.
- PR #61 merged normally at merge SHA `1a45951475a292ecc06c9547a4fd4b94ae5eab77`. Verified primary branch SHA is the same merge SHA. PR #61 only changes the operator-facing command description and regression coverage; it does not change reconciliation behavior. Staging runtime remains `a6e79bd381319ee2e18c90fd464cdbaf9cef4908`; this help-text/test-only correction was not separately deployed.
- The corrected command’s state-writing behavior is now explicit and regression-tested. Staging validation intentionally continues to use the read-only reconciler only.

## Current disposition

Wave 6 implementation and exact-head CI are merged and green. Wave 6 is **not fully closed**: independent tombstone-journal/provider recovery facts, the replay applier and synthetic historical-restore replay proof remain outstanding. Recovery readiness correctly remains blocked/quarantined until those prerequisites exist. Provider-side S3/SFTP deletion/versioning/immutability facts remain unverified; legal/accounting decisions remain `WAIT_LEGAL` / `WAIT_ACCOUNTING`. No destructive retention or deletion was enabled.

## Independent journal / replay implementation candidate — 2026-10-15

This section records work on a fresh implementation branch from the currently verified primary. It is candidate evidence only and does not supersede the prior merged/staging status above.

- **Journal:** `ObjectStoragePrivacyTombstoneJournal` stores one encrypted event per deterministic event ID in the M9 platform-backup S3 disk, under a deployment-environment-specific `privacy-tombstones/<environment>/events/` namespace. The namespace is separate from database snapshots and customer backup retention. A genuine historical restore of the application database therefore does not roll back its journal event object. This is database-rollback independence; provider/account failure-domain separation, Object Lock/versioning and overwrite/delete privilege facts remain `WAIT_FACT`.
- **Minimization/integrity:** entries contain opaque internal customer/request/artifact UUIDs, scope, one of the supported privacy-suppression action codes, event time, schema version and sortable sequence. No source email, name, address or profile snapshot is journaled. Records use the existing external M9 platform encryption key ring with domain-separated libsodium XChaCha20-Poly1305 secretstream authentication. Old key IDs must remain recoverable outside the restored database; loss of any required key blocks journal readiness.
- **Ordering/watermark:** snapshot creation and supported privacy actions share a cache lock. The journal uses lexically monotonic ULIDs derived from the durable latest watermark, including clock-backward/same-millisecond handling. Platform backup manifests record the signed watermark/state captured while the lock is held. Replay CLI now requires a recovery ID, source backup UUID and exact watermark bound to out-of-band recovery configuration, and reads the watermark only from the authenticated external backup manifest.
- **Replay/quarantine:** `PrivacyTombstoneReplayApplier` verifies the journal before/after ordered application, checks stable subject/request/tenant mapping, avoids billing-provider calls, blocks active billing/missing mappings/unsupported actions, persists session/event outcomes and is state-idempotent on retry. It only reapplies currently implemented suppressive states (account-access restriction and privacy-export revocation); it does not implement irreversible anonymization/deletion, which does not exist in Wave 5 and remains gated for later work. Reopening an account is deliberately not journaled as a tombstone; an old restore may conservatively leave it restricted for Owner reconciliation. The replay command never releases quarantine. `PrivacyRestoreSafety` requires completed verified replay for the matching recovery ID, source-backup UUID, snapshot watermark and current journal ending watermark.
- **Historical restore test:** `HistoricalPrivacyRestoreTest` creates a real disposable SQLite T0 file with synthetic data, copies it as an older restore target, performs the current application account-restriction path at T1, persists the event through the real encrypted object-storage journal adapter to an isolated test storage, restores the actual T0 file, proves the T1 suppression is absent before replay, applies replay, verifies account restriction and unchanged immutable invoice evidence, then repeats replay and verifies idempotency. This is an isolated local proof, not staging evidence.
- **Local focused validation:** seven targeted test files passed through direct PHPUnit: **40 tests, 237 assertions, 0 failures, 0 skips**. The Laravel wrapper reports warnings caused by the clean worktree lacking `.env`; direct PHPUnit completed without failures. Pint passes for changed files, PHP syntax lint passes for changed code, `composer audit --locked` reports no advisories, `git diff --check` passes, and the local targeted secret-pattern scan found no matches. Gitleaks is not installed locally; exact-head CI must run the repository secret scan.
- **External/staging gates:** no staging state has been mutated. A strict SSH connection to `stg1.wpcaregrid.com` used host-key checking and failed authentication (`Permission denied (publickey)`); current deployed SHA and staging journal/replay state therefore remain unverified. No staging deployment or historical-recovery proof is claimed. GitHub Actions for the new candidate has not run yet. The known current primary remains `b673f5adbec9a23a968b7ee70c5f4df529991422` at the last fetch; candidate CI/merge evidence will be appended only after exact-SHA results.
- **Safety:** `M12_DESTRUCTIVE_RETENTION_ENABLED=false` remains required. No provider deletion, customer anonymization, live Stripe operation, production mutation or primary staging DB restore has occurred. S3 physical-version/delete semantics remain `WAIT_FACT`; legal and accounting approvals remain `WAIT_LEGAL` / `WAIT_ACCOUNTING`.

## Current release disposition

Implementation candidate validation is not yet complete. Do not declare Wave 6 closed until exact-candidate CI is green, the candidate is merged, and guarded staging deployment plus isolated staging recovery evidence pass. If staging SSH authentication remains unavailable, keep the release status open and report the infrastructure/Owner gate without weakening strict host verification.

## Independent journal / replay release evidence — 2026-10-15

This section supersedes the candidate validation status above for PR #63 only. It records verified merge and CI evidence; staging deployment and staging recovery proof remain open.

- Verified primary branch: `claude/ecstatic-wozniak-oemyuf`.
- Verified starting primary SHA: `b673f5adbec9a23a968b7ee70c5f4df529991422`.
- Implementation candidate head: `46bdf7777d9c6fb699ba7512f71a741d2dbe8cf7` (PR #63).
- PR #63 merged normally at merge SHA and resulting primary SHA `190067caebdb818a652b381be36e04d221881165`.
- Exact-head GitHub Actions run `38781589645` for candidate `46bdf7777d9c6fb699ba7512f71a741d2dbe8cf7`: all five required jobs passed (Quality/security/build; Server SQLite; Server MySQL; Connector SQLite; Connector MySQL). Server SQLite: 1,272 tests, 18,292 assertions, 19 documented environment/opt-in skips, 0 failures. Server MySQL: 1,272 tests, 18,490 assertions, 0 skips, 0 failures; the required process-concurrency gate also passed. Connector and quality jobs passed. The earlier candidate run `38780545880` exposed one stale UI-test assumption about the exact blocked-journal reason; both SQLite and MySQL failed only that assertion. The regression test now injects the unavailable adapter explicitly; the corrected exact-head run is green.
- Additional local rerun of `RetentionEligibilityTest.php`: 14 tests, 410 assertions, 0 failures. The isolated test that failed in the first Actions run passed after the deterministic adapter setup (1 test, 10 assertions).
- Current primary was re-fetched after merge and is `190067caebdb818a652b381be36e04d221881165`. The merge contains the independent encrypted object-storage journal, authenticated backup-manifest watermark binding, replay applier, quarantine readiness checks, regression tests, and implementation evidence described above.
- Staging was not deployed or mutated. A strict SSH attempt to `stg1.wpcaregrid.com` with the approved host-key verification failed at user authentication (`Permission denied (publickey)`). The current staging runtime SHA therefore remains unverified; the previously known `a6e79bd381319ee2e18c90fd464cdbaf9cef4908` is historical context only, not a current-state claim. No host-key checking was weakened.
- The local synthetic historical restore proof remains the verified T0/T1/restore/replay/idempotency evidence. Staging-context recovery proof, staging health, and deployment remain pending SSH access. Do not declare Wave 6 closed until those staging gates pass.
- Provider-specific S3 versioning/Object Lock/delete-marker and failure-domain facts remain `WAIT_FACT`. Qualified legal and accounting decisions remain `WAIT_LEGAL` / `WAIT_ACCOUNTING`. These were not converted into approvals.
- `M12_DESTRUCTIVE_RETENTION_ENABLED=false` remains in force. No deletion, anonymization, provider deletion API, live Stripe call, production mutation, or primary staging DB restore occurred.
