# M12 Wave 2 — Data access, export and rectification implementation

Status: **engineering implementation, exact-SHA CI, merge and guarded staging validation complete.** Final legal export scope and accounting disclosure remain provisional; this is not a legal-compliance conclusion.

## Baseline and decision

- Implemented on fresh branch `codex/m12-wave2-export-rectification` from remote primary `claude/ecstatic-wozniak-oemyuf` at `a20e95bf66728991a68e319151a0ec5ab176beae` (verified at task start; reverify before release).
- Owner decision Q-W2-01: **reusable signed download link for 24 hours, customer authentication required, every successful download audited**. This is an Owner product/security decision only.
- Existing RET-05 (24 hours) and RET-06 (7 days) activated report-only values are consumed fail-closed. Link lifetime and encrypted artifact lifetime are separate.
- Final legal export scope, identity-proof sufficiency, accounting disclosure, and external-provider facts remain unresolved as recorded in the decision register. No legal response-completeness claim is made.

## Implemented behavior

### Customer access and export

- Added authenticated customer privacy page, password-confirmed export request, status/history, and password-confirmed link renewal. Requests use the existing privacy-case and append-only event model.
- Added a customer-bound, time-limited signed route. The signed URL alone is insufficient: a current authenticated customer session and tenant ownership are required. Every successful download increments an artifact counter and records a minimized case event.
- Export generation is queued, unique per artifact, overlap-locked, bounded to 5,000 rows per section and a 25 MiB encrypted archive. It emits a versioned partial-coverage JSON ZIP with a manifest and section checksums; it never serializes Eloquent models wholesale.
- The export allowlist includes account/profile, website, subscription, customer-visible invoice summaries, and privacy-request metadata. It excludes tax identifiers and immutable invoice/tax snapshots, raw webhooks, backup archives/metadata, secrets, internal security/operator evidence, and other tenants. Registry approval flags are checked before generation.
- The ZIP is encrypted with Laravel's configured application encryption, stored only on the private local disk, read back and hash-checked before publication. Temporary plaintext/encrypted files use restrictive permissions and are removed on success or failure. No public object URL is produced.
- The temporary encrypted package expires after the policy value (7 days by default). A scheduled, bounded cleanup removes only the exact generated export object; it never deletes source customer data. Owner actions can retry a failed generation or revoke a package; Owners cannot download it through the Owner case view.
- System health exposes aggregate failure/stuck/available counts only, without personal-data labels.

### Rectification

- Added a password-confirmed customer rectification request and connected it to the canonical profile editor. Field-change audit records contain codes only.
- Current profile changes use the existing versioned billing-identity service where relevant. Prior invoice and immutable billing-identity snapshots are not rewritten. Email identity changes remain outside this workflow.
- Customer and Owner views are localized in English and Hungarian. Existing request authorization and audit patterns are reused.

## Security and failure behavior

- Tenant binding is checked on page, renewal and download; guessed or cross-tenant artifact references return not found.
- Wrong password creates no request/artifact. Expired/revoked artifacts cannot be downloaded. Integrity or archive-format failure returns no bytes.
- Duplicate submissions for an active case reuse one artifact; only one active temporary artifact per customer is allowed. Stale or unsafe storage references fail closed. Cleanup failures remain explicit and retryable for Owner review.
- An initial test exposed a Laravel enum-cast comparison defect in the generation guard. The code now locks and reads the typed `PrivacyRequest` model before comparing its enum status. Regression coverage passes.
- No export implementation invokes provider deletion, source-record deletion, customer anonymization, backup deletion, or the destructive-retention gate. `M12_DESTRUCTIVE_RETENTION_ENABLED` remains false.

## Local validation

Executed against a disposable Linux-native source copy (no `.env`, `.git`, production/staging data, or existing runtime state):

- Focused Wave 2 test file: **6 tests, 43 assertions passed**.
- Combined privacy, customer-area, authorization, Owner health, customer billing snapshot, and billing snapshot regression suites: **94 tests, 1,092 assertions passed; 1 skipped**.
- Full local suite: **1,249 tests, 17,856 assertions passed; 25 skipped; 0 failures** after updating the existing system-health count assertion for the new check and running with Git metadata for the Connector package artifact test.
- Skips are environment-gated tests requiring dedicated local MySQL and independent processes/`pcntl`, the isolated MySQL 8.4 CI service and CI-only root credential, or configured external S3/SFTP test services. The privacy-request process-concurrency case is among the MySQL-gated skips. These tests remain required in their configured CI/provider environments.
- Pint: **21 files passed** after correcting eight formatting findings.
- Vite production build: passed.
- Composer audit: no security advisories.
- PHP syntax scan: all changed PHP files passed PHP 8.4 syntax checks before the final formatting-only edits; focused tests and Pint subsequently loaded the formatted PHP successfully.
- `git diff --check` and a local credential-pattern scan passed. Gitleaks and the required security checks passed in exact-head CI run `38697382717`.

## Pre-release status snapshot (superseded by final evidence below)

- Exact-head mandatory CI, including MySQL and both Connector jobs.
- Guarded staging deployment of the exact green primary SHA and migration health.
- Synthetic staging export/download/rectification checks, including repeated download audit, expiry, tenant isolation, and immutable invoice snapshot preservation.
- Staging checks for queue/scheduler health, privacy export health, and unchanged destructive-retention OFF state.

No deployment, provider deletion, production mutation, real-customer export, account deletion, or destructive retention was performed as part of local validation.

## Pre-release closure disposition (superseded)

Wave 2 is not yet closed. It may be closed only after exact-SHA CI, normal merge, guarded staging deployment, synthetic staging evidence, and final report merge are recorded. Legal/accounting questions remain open and must continue to be represented as limits on disclosure, not as completed approvals.

## Final release and staging evidence — 2026-10-15

### Repository and CI

- Implementation PR: [#54](https://github.com/zsoltacsai/wpcaregrid/pull/54), head `720396d5dca8e25f7ea1ec57ea2641982e0d616f`; merged normally as `8406af8e8584ddc3d1b4fd9ca19af3e878bd3498` into `claude/ecstatic-wozniak-oemyuf`.
- Exact PR-head CI run `38697382717`: all five mandatory jobs passed (Quality/security/build; Server SQLite; Server MySQL; Connector SQLite; Connector MySQL).
- Exact post-merge primary CI run `38698542019`: all five mandatory jobs passed, including the Server MySQL suite and required MySQL process-concurrency gate. The SQLite job's MySQL-process gate is intentionally skipped because that job uses SQLite; the MySQL job ran the gate successfully.
- Primary branch was fetched after merge and confirmed at `8406af8e8584ddc3d1b4fd9ca19af3e878bd3498`. Implementation worktree was clean before the documentation-only closure update.

### Guarded staging deployment

- Deployed exact primary/runtime SHA `8406af8e8584ddc3d1b4fd9ca19af3e878bd3498` to staging on 2026-10-15 with `scripts/deploy-staging.sh` over SSH using strict host-key checking. The verified ED25519 fingerprint matched the approved staging fingerprint `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`.
- Deployment created a protected pre-deploy database backup, applied migration `2026_10_15_000001_create_m12_privacy_export_artifacts`, built production assets/caches, restarted both workers, resumed the guarded scheduler and completed the deployment smoke checks successfully.
- Final staging SHA: `8406af8e8584ddc3d1b4fd9ca19af3e878bd3498`; staging checkout is clean. MySQL 8.4.11 reports zero pending migrations. Both `wpcaregrid-default` and `wpcaregrid-backups` workers are RUNNING; scheduler heartbeat is healthy; failed jobs and queued jobs are zero.
- `system:check --json`: application, database, queue, scheduler, Connector API, Stripe TEST, Cloudflare, backup storage, mail, safety, privacy retention, and privacy exports are OK. The pre-existing security scanner warning remains: no completed security scan and vulnerability feed not configured. This finding is represented accurately and is not an M12 Wave 2 defect.
- Privacy state remains `REPORT ONLY` / `SAFE / DISABLED BY POLICY`; `M12_DESTRUCTIVE_RETENTION_ENABLED` is OFF. Export health reports zero failed, stuck, or available packages and zero source records deleted. Synthetic fixture count remains one; privacy request, privacy export artifact, and pending/failed job counts are zero after validation.

### Synthetic staging validation

- Export generation, encryption, storage readback/integrity, and two successful downloads were exercised against staging MySQL and the deployed application services using only transient `example.invalid` synthetic identities inside a rollback-only database transaction. The archive file was removed before rollback; all temporary user, customer, privacy request, artifact, event and audit rows rolled back. No queue job was dispatched and no provider API or mail was called.
- Results: generated/verified package PASS; repeated download bytes matched PASS; download count `2`; `export_downloaded` audit events `2`; link lifetime exactly `86,400` seconds (24 hours); cross-tenant download denied PASS; database rollback PASS; temporary package absence PASS.
- Rectification used a separate rollback-only synthetic transaction through the deployed canonical profile controller and services. Results: rectification completed PASS; current profile updated PASS; new billing identity version created PASS; historical identity snapshot unchanged PASS; invoice still referenced the original snapshot PASS; all temporary records rolled back PASS.
- These were controlled application/controller-service integration checks, not an Owner's browser session or an external mail/provider integration test. Existing exact-SHA tests cover request authentication, signed-route behavior, expiry, integrity failure, and authorization boundaries.

### Final disposition

**M12 Wave 2 engineering/release scope: CLOSED.** Access/export/rectification implementation is deployed and validated in staging. The export explicitly has partial coverage; qualified legal review still owns final response scope, identity-proof sufficiency, exceptions and accounting disclosure. No claim is made that the current package alone fulfills every legal access request.

No production deployment or mutation occurred. No real customer record was exported or changed. No account was deleted or anonymized, no source backup was deleted, and no provider deletion was attempted. The pre-existing M8 synthetic fixture and historical M8/M9 evidence were preserved.
