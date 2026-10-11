# M9 Closure Plan v4 — SaaS Disaster Recovery

**Planning status:** execution plan only; no implementation, provider mutation, deployment, CI run, commit, or live backup/restore was performed for this document set.

## Verified repository and milestone state

Inspection was performed in the isolated worktree at C:\Users\Felhasználó\.codex\worktrees\m9-platform-dr\wpcaregrid, branch codex/m9-platform-dr, HEAD f007e19bde7ffbceed0f468466b3eebb72013f07. The worktree contains the earlier uncommitted M9 application, migration, CI, documentation, and test changes. They are preserved; this planning pass is limited to the seven requested planning documents. The main checkout is a separate worktree and was not edited.

The existing M9 report records a B verdict, not closure. Implemented locally are platform database backup creation and metadata, read-only reconciliation, recovery-mode queue restrictions/quarantine for the database queue, recovery signals/health states, runbooks, and a local SQLite recovery drill. The report records 165 passed / 11 skipped / 1,210 assertions in a combined relevant run; 15 passed / 1 skipped / 74 assertions in the final Platform suite; and 58 passed / 3 skipped / 353 assertions in a separate billing run with overlap. Pint, PHP syntax, diff check, dependency audits, frontend build, and Gitleaks passed. GitHub CI was not run, the MySQL 8.4 import path was not run locally, and no remote provider verification, staging deployment, or staging integration test was performed. These are report claims and repository state, not results newly reproduced here.

The source confirms: platform backup is disabled by default; daily/weekly/monthly schedules are static in routes/console.php; platform metadata stores one storage_disk/object_key plus one manifest sidecar; the MySQL backup service can require isolated MySQL import verification; deployed staging/production reject local backup storage. Customer backups have a streaming storage abstraction, S3-compatible support, SHA-256 verification and readback fallback, object listing/reconciliation, and incomplete multipart upload listing. Owner routes use auth:owner, owner role, named abilities, sensitive-action throttles, and AdminAudit. OwnerSettings is an explicit allowlisted database-backed settings schema; IntegrationCredentials uses Laravel Crypt and never returns saved values to views. Stripe event IDs are recorded with effects for duplicate-event rejection. Recovery-mode queue quarantine is currently database-driver-only; other queue drivers need provider-specific procedure.

## Target architecture and boundary

One platform DB snapshot produces one immutable logical backup artifact. The artifact is encrypted once with a versioned independent backup key, assigned a cryptographic digest, and then independently delivered to destination adapters. Each destination has its own configured state, schedule, object identity, upload lifecycle, readback verification, retention eligibility, health, and history. S3 is mandatory primary. Google Drive is optional secondary and does not gate M9 closure or replace S3.

Do not create duplicate DB dumps merely because two destinations have different schedules. A scheduled destination may replicate the most recent verified encrypted artifact only when its source snapshot age is within its configured freshness bound; otherwise the scheduler creates one new snapshot and offers that same artifact to all due enabled destinations. Record source snapshot/artifact UUID, schedule version, destination attempt, idempotency slot, and immutable result independently. “Created”, “encrypted”, “uploaded”, “provider-checksum-confirmed”, “readback-verified”, and “restore-drill-tested” are different states. A destination is verified only after authenticated artifact validation and a remote readback digest, or an explicitly justified provider-native full-object checksum whose semantics are tested; multipart ETags are not SHA-256 proof.

The current sidecar/database manifest is useful recovery metadata, but an unsigned checksum next to the object does not authenticate metadata if both can be replaced. The new artifact contract must bind the artifact UUID, source environment, DB identity hash, release SHA, schema/version, encrypted byte count and ciphertext digest to an authenticated manifest. Use a reviewed authenticated-encryption/key-management implementation; do not invent an algorithm or reuse APP_KEY as the backup master key. Keep enough non-secret manifest information outside the SaaS database for discovery while authenticating it cryptographically.

## Initial measurable objectives

Existing docs set 24-hour target RPO and 4-hour target RTO, but explicitly say actual DB RPO is currently unbounded until an independent remote destination is configured and verified; the single-host/provider outage can exceed RTO. Keep 24h / 4h as initial objectives, not guarantees. Recommend 12-hour primary S3 cadence as the Owner Console default after measuring DB size and duration; the backup scheduler must alert before max artifact age breaches 24h. This yields a nominal maximum loss window under 12h when all scheduled runs complete, while the contractual target remains 24h. Frequencies 6h/12h/daily/weekly are allowed options. Weekly is not compatible with a 24h RPO; selecting it must show an explicit objective change and confirmation. Secondary Drive cadence is independent and its own age/RPO is displayed.

Targets by data class: platform DB/customer/site/subscription/entitlement/restore/audit records and storage metadata: objective 24h RPO / 4h RTO once independent remote backups and key escrow are proven; code/release: exact immutable source release SHA, objective 2h rollback with pre-release DB compatibility check; runtime configuration: reproducible templates plus secret escrow, target 4h after accessible secrets; customer WordPress objects: no stronger than the latest independently verified upload and actual provider recoverability, no numeric RTO claimed before provider drill; audit/webhook events are in the DB snapshot so inherit DB RPO, with external Stripe/invoicing reconciliation after rollback.

## M9 closure gate versus follow-up

Mandatory before closure: a dedicated remote S3 bucket at the approved provider; distinct least-privilege credential; no public access; verified bucket identity and encryption; platform artifact encryption and escrowed independent decryption key; metadata/sidecar and destination verification; tested failure isolation and report-only reconciliation; successful disposable MySQL 8.4 import/boot sanity drill; CI green including MySQL 8.4; recovery queue protections validated; APP_KEY retrieval procedure and a controlled retrieval drill without exposing the value; reviewed runbooks and measured RPO/RTO evidence; security review; staging deployment/integration validation through the guarded release process if source changes are needed. No production disaster drill or production mutation is required.

Optional, separately tracked: Google Drive adapter/OAuth activation. Architecture and backlog are specified here, but Drive connection, credentials, provider verification, OAuth review and a Drive recovery drill are not M9 blockers. Versioning, Object Lock, replication, separate account/region, and legal retention rules are Owner/provider or M12 decisions. Do not enable or purchase them during this plan.

## Delivery sequence and acceptance

1. Reconcile existing local M9 implementation against this design; retain the platform snapshot and read-only reconciliation work. Correct any test/CI gaps before refactoring.
2. Owner completes approved bucket/credential setup and identifies independent secret escrow. Record non-secret bucket identity, policy evidence, credential version reference, encryption mode, access test and responsible operator.
3. Design destination-neutral artifact metadata and per-destination replica state. Preserve existing backup IDs/sidecars and use an additive migration with safe defaults; legacy records map to one legacy destination and remain readable. Never rewrite or delete existing customer backup rows.
4. Add authenticated encryption and independent remote upload/readback verification; add failure isolation so one destination failure does not erase another destination's verified state.
5. Add DB-backed validated Owner settings and scheduler occurrence idempotency, durable across deployments/restarts. Schedule changes affect the next not-yet-started occurrence; active runs retain their captured settings version. Downtime catch-up launches only the newest still-relevant due slot per destination, never a burst of missed runs.
6. Add Owner-only overview/history/manual run controls with CSRF, role/ability checks, rate limits, idempotency, concurrency lock, explicit confirmation, audit records and secret redaction. No production restore button.
7. Run failure tests, MySQL 8.4 disposable import, queue replay recovery test and CI. Do a non-production staging validation only after CI and guarded deployment prerequisites pass.
8. Complete the evidence checklist in the backlog, then decide closure based on evidence, not feature presence.

## Acceptance criteria

- A platform DB backup cannot be called verified from file existence or sidecar equality alone.
- A verified S3 artifact has remote identity, expected length, ciphertext digest, authenticated manifest and successful readback/import validation as specified by its verification level.
- S3 is distinct from customer backup credentials/bucket configuration and local-only success is rejected in deployed identities.
- Destination status is independent: S3 verified + Drive failed remains S3 verified and Drive failed; the reverse is representable without implying primary success; both failed is a system failure.
- Missing, malformed, partial, checksum-mismatched and unauthenticated artifacts cannot enter recovery-ready status.
- Recovery mode prevents stale destructive WebsiteJobs and Laravel queue payloads from being replayed automatically after DB rollback.
- Owner settings survive config cache, process restart and deployment; updates validate, serialize and audit without logging secrets.
- Retention dry-run is report-only until policy/hold semantics are accepted; no deletion may remove the last verified recovery point or touch in-progress/divergent artifacts.
- Google Drive is clearly marked optional, scoped, encrypted-before-upload, independently reported, and never a substitute for primary S3.

## Risks and assumptions

The configured S3-compatible provider and actual bucket/account/region, versioning, Object Lock, lifecycle, replication, provider checksums, deletion protection, IAM policy, costs and data transfer have not been verified. Google OAuth client, consent screen, account ownership and quota are not configured or verified. There is no checked-in Google API client package in composer.json. APP_KEY escrow is not evidenced. The MySQL recovery test is present in CI workflow but CI did not run per the prior report. A separate destination in the same provider/account improves credential and bucket isolation but does not protect against account takeover, provider-wide outage, region incident or provider-side deletion.

## References

Repository policy baseline: docs/PLATFORM_DISASTER_RECOVERY.md. Owner security conventions: routes/owner.php, app/Owner/OwnerSettings.php, app/Owner/IntegrationCredentials.php, app/Owner/AdminAudit.php. Current backup abstractions: app/Backups/Storage/BackupStorage.php, app/Backups/Storage/S3Storage.php, app/Platform/DisasterRecovery/PlatformDatabaseBackupService.php. Current CI MySQL service: .github/workflows/ci.yml.

Google scope guidance says to choose the narrowest scope and identifies drive.file as non-sensitive, while broad drive access is restricted; public apps using sensitive/restricted scopes require applicable verification. Resumable uploads are supported and their session URIs expire after one week. See [Drive API scope guidance](https://developers.google.com/workspace/drive/api/guides/api-specific-auth), [OAuth verification requirements](https://support.google.com/cloud/answer/13464321?hl=en), and [Drive resumable uploads](https://developers.google.com/workspace/drive/api/guides/manage-uploads), reviewed 2026-10-11.
