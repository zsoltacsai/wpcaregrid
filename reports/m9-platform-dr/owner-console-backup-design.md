# Owner Console — Platform Backups

**Planning only.** This design follows the existing Owner web boundary: routes under routes/owner.php use auth:owner plus owner middleware, named owner abilities, throttle middleware for sensitive/external actions, and AdminAudit. OwnerSettings is typed/allowlisted and DB-backed; IntegrationCredentials stores encrypted values and does not return secrets to views. Add a dedicated Infrastructure → Platform Backups page rather than a generic key/value editor. Do not add a customer-facing page or unrestricted database restore action.

## Page structure

### Overview

Show platform-backup system status; last created artifact; last uploaded and last readback-verified S3 primary artifact; optional Drive status with its own latest artifact; next due UTC slot with Owner-local rendering; age versus objective/RPO; current configured cadence; destination health; retention class counts; current storage pressure if reliable; latest failures; recovery readiness level; and timestamp/release context. Status must distinguish disabled, not configured, unavailable, degraded, verification failed, reconciliation required, healthy. A green destination badge means only its stated verification level passed. “Restore tested” is a separate timestamp/state and never implied by checksum verification.

### Configuration

Typed settings, not arbitrary fields. S3 is mandatory to enable platform backup in deployed environment and has no secret value rendered. UI shows configured/non-configured, last credential validation, bucket alias/fingerprint and policy check; credentials are provisioned via secret management, not pasted into ordinary settings or audit records. Drive can be enabled only after OAuth connection passes a private test upload/readback and app-folder ownership check.

Options: primary cadence 6h, 12h, daily, weekly. Secondary Drive independently enabled/disabled and independently scheduled from those options. Recommend primary default 12h only after measured duration/cost and RPO check; Drive default off. Weekly primary selection displays that it cannot meet the 24-hour target and requires an explicit acknowledged objective change. Show selected artifact freshness and bounded catch-up setting, but don’t let the Owner configure unsafe unlimited catch-up. UTC is authoritative; localized timezone is presentation only.

Retention controls are independent by destination. Initial platform target is 7 daily, 4 weekly, 12 monthly, matching existing code/report; current implementation records expiry metadata and performs no automatic platform deletion. Retention controls should initially offer policy preview/dry-run. Actual cleanup remains disabled pending M12 legal policy and provider-side deletion controls. If later enabled, the Owner can choose only validated bounded classes/counts; last verified point protection, active restore/drill references, holds, in-progress/divergent states and minimum backup age are enforced server-side.

### Backup history

Paginated list with artifact ID, created UTC time, source environment, release SHA, encrypted size, destination, immutable remote identity alias, attempt, upload state, verification level/method/time, retention class/expiry candidate, age, failure category, and recovery-drill-tested state. Destination rows are separate for the same artifact. Failure summary is a safe category/message, not raw provider response. No download of raw database from this screen by default. Audit history identifies actors/actions and settings-version changes without hashes of secrets, OAuth tokens, SQL, or URLs containing credentials.

### Manual backup

POST action requires Owner ability, recent authenticated Owner session/reauth policy for sensitive ops if supported, CSRF, rate limit, explicit confirmation, idempotency key, global snapshot lock, one active generation, and audit log. Confirmation states source environment, approximate action, both enabled destinations, and that a backup is not restore-tested. Repeated clicks with same idempotency key return the same occurrence. Manual execution attempts enabled destinations independently. It cannot request a restore, delete recovery points, or change gates.

### Disable safeguards

Disabling primary generation is a high-impact operation. Require typed phrase such as DISABLE PLATFORM BACKUPS, an explicit critical warning about target/RPO loss, Owner-only ability, recent reauthentication where available, CSRF, throttle, second confirmation on separate POST, reason field, and AdminAudit record with actor/environment/before-after non-secret settings version. Do not delete any existing artifacts, keys, metadata, or destination connection. Display persistent disabled state and emit machine-readable signal. If S3 credentials fail while enabled, show failure/degraded; do not silently auto-disable.

### Recovery readiness panel

Read-only checks: most recent S3 artifact age; verification level; remote availability; manifest authentication; backup-encryption key escrow status (boolean/reference only); APP_KEY escrow retrieval drill timestamp (not key); MySQL import drill result and engine version; recovery queue mode/quarantine compatibility; stale destructive jobs; reconciliation anomalies; last tested app release. Provide runbook link and exportable non-secret status report. Never provide an unrestricted production restore button. Promotion of a restored DB remains a human runbook with isolation, approval, queue quarantine and post-restore validation.

## Authorization and persistence

Introduce a named ability such as owner.platform_backups.manage, owner-platform-backups.view, or follow current OwnerAbility naming after implementation review. View and manage should be separable. Every route is explicitly under Owner auth/role; every route has a gate tested by the existing “all owner routes have ability” test. Mutations use throttle:owner-sensitive and/or dedicated external provider throttle; OAuth callbacks require one-time state bound to owner/session and exact redirect URI. Audit events include backup config changed, enable/disable requested/completed, manual backup requested/completed/failed, Drive connected/disconnected, retention preview/cleanup requested. Never audit token values, secret names with values, signed URLs, raw provider error bodies, SQL, or object content.

Persist typed settings in the existing owner_settings pattern or a dedicated typed table if concurrency/versioned schedule history requires it. Existing OwnerSettings validates fixed identifiers, transactions updates and audits; don’t add secret credentials there. Schedule config should have monotonic version and effective-from UTC, transactionally updated with a compare-and-swap/row lock. Workers read the durable current version for each new occurrence, not stale config cache. An active job captures that version and remains deterministic. Database unique occurrence keys protect against multiple schedulers; distributed lock is additional, not sole idempotency. Cache invalidation is not correctness-critical.

## Concurrency and controls

- One global snapshot-generation lock prevents concurrent DB dumps; per-destination delivery lock permits bounded parallel transfers of the same artifact.
- Manual + scheduled requests coalesce when same occurrence/artifact is active, or queue one explicit subsequent run under a configured max; never create unbounded runs.
- Settings update during a run affects only future schedule slots. Destinations enabled at snapshot dispatch are captured; newly-enabled destination gets the latest eligible artifact on its own next slot.
- Scheduler downtime has bounded catch-up: one most-recent due occurrence per destination if within age window, else mark skipped/missed and schedule next slot; no backlog storm.
- Owner sees outage and retry state, but cannot force unlimited retries. Retry-After and provider-specific backoff have hard attempt/time bounds.
- Read model distinguishes last successful upload versus verified copy. A S3 failure plus Drive success must never show overall primary healthy.

## Failure scenarios

1. S3 succeeds, Drive fails: show S3 verified and Drive failed/retry; preserve S3 artifact; primary overall healthy, redundancy degraded.
2. Drive succeeds, S3 fails: Drive preserved, primary degraded/unavailable; system not closure-healthy; alert and retry S3.
3. Both fail: backup service critical; preserve completed encrypted temp only for bounded controlled retry, then securely dispose exact temp after preserving safe diagnostics; no “last backup successful” update.
4. Existing backup running: manual idempotent result or conflict with active job ID; never second dump.
5. Scheduler downtime: bounded catch-up; display skipped slots.
6. Frequency changed during run: retain occurrence settings version; effective at next not-started UTC slot.
7. Manual backup: same artifact + destination workflow; independent audit and rate limit.
8. Storage unavailable: mark unavailable, stop pointless retry storm, retain records.
9. Google authorization revoked: Drive disabled for new upload attempts or suspended while S3 continues; notify Owner/reconnect path without deleting history.
10. Partial upload: resumable/multipart status; not verified; resume or restart same logical delivery safely; orphan cleanup remains manual/report-only until safe.

## Acceptance tests

- owner-only authorization, customer/operator 403/redirect, ability split, route audit coverage;
- durable setting validation, unknown key rejected, invalid cadence rejected, transaction/version CAS under concurrent update;
- secrets absent from page data, rendered HTML, audit event, exception/log;
- disabling requires second confirmation and never deletes artifacts;
- manual duplicate idempotency, rate limiting, active generation lock;
- different destination outcomes render independently;
- age/RPO and next slot respect UTC and DST display boundary;
- retention preview cannot delete, and any later execution preserves last verified point/holds/in-progress/divergent artifacts;
- no restore API/button exposed.

## UX state semantics

Created, encrypted, uploaded, provider-integrity-checked, readback-verified, decrypt-verified, import-verified and recovery-drill-tested each have separate labels. “Available for recovery” requires destination readback/decrypt path and required keys; “tested recovery” requires disposable import/boot check. The UI must not translate a missing status or provider timeout into green.
