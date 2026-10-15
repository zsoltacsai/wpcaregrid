# M12 Wave 2 — Data access, export and rectification implementation

Status: **local implementation and focused validation complete; release, CI and staging evidence pending.** This is an engineering status, not a legal-compliance conclusion.

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
- `git diff --check` and a local credential-pattern scan passed. Gitleaks and all other required security checks remain subject to exact-SHA remote CI.

## Staging/release gates not yet evidenced

- Exact-head mandatory CI, including MySQL and both Connector jobs.
- Guarded staging deployment of the exact green primary SHA and migration health.
- Synthetic staging export/download/rectification checks, including repeated download audit, expiry, tenant isolation, and immutable invoice snapshot preservation.
- Staging checks for queue/scheduler health, privacy export health, and unchanged destructive-retention OFF state.

No deployment, provider deletion, production mutation, real-customer export, account deletion, or destructive retention was performed as part of local validation.

## Closure disposition

Wave 2 is not yet closed. It may be closed only after exact-SHA CI, normal merge, guarded staging deployment, synthetic staging evidence, and final report merge are recorded. Legal/accounting questions remain open and must continue to be represented as limits on disclosure, not as completed approvals.
