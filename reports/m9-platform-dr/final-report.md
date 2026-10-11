# M9 — WP CareGrid Platform Disaster Recovery

**Verdict: B — M9 FUNCTIONALLY COMPLETE, OWNER INFRASTRUCTURE DECISION REMAINS**

M9 code, policy, recovery controls, local recovery drill, and relevant local regression suites are implemented in the isolated `codex/m9-platform-dr` worktree. M9 is not closed: no independently administered platform-backup destination or approved APP_KEY escrow was configured or verified. These require an Owner infrastructure/secret-management decision. The MySQL import test is present and configured for CI's disposable MySQL 8.4 service, but could not run in this local environment.

## Architecture and recovery behavior

The platform recovery policy is documented in [PLATFORM_DISASTER_RECOVERY.md](../../docs/PLATFORM_DISASTER_RECOVERY.md). It maps Laravel, MySQL, database-backed queue/cache/session, workers, scheduler, customer backup storage, billing/webhooks, Connectors, deployment configuration, and secret categories.

Platform DB backups are implemented by `platform:database-backup` and `PlatformDatabaseBackupService`. Scheduling is disabled by default and defines daily, weekly, and monthly classes. MySQL uses a single-transaction logical dump with routines, triggers, events, hex blobs, and gzip; SQLite uses `VACUUM INTO` for the local drill. Metadata records source environment, DB identity hash, engine/version, release SHA, size, digest, retention class, expiry target, verification state, and a private sidecar manifest. Missing/unreadable objects, missing/mismatched manifests, hash/size errors, invalid gzip, and failed sanity/import checks cannot be marked verified.

Both staging and production deployment identities reject a local platform-backup disk. A declared disk-driver mismatch is also refused. S3 storage is private and requests SSE-S3; bucket/account independence, provider-side encryption, versioning, replication, and immutable retention remain unverified and are not enabled.

`backup:reconcile-storage` reports customer-backup metadata/object divergence, checksums, listed orphans, and incomplete uploads. `platform:database-backup-reconcile` separately checks platform backup metadata, object and sidecar presence, size/hash/manifest agreement, failed/pending verification state, and orphaned objects—even when the application DB has lost every backup metadata row. Both are read-only and never delete objects. Storage/provider failures are classified and emit recovery signals.

When `PLATFORM_RECOVERY_MODE` is active, only inventory, health, and license WebsiteJobs can be queued/delivered. `platform:recovery-quarantine-queue --confirm-workers-stopped` requires idle workers, a database queue, common DB connection, required migrations, and no reserved queue rows. It preserves payloads with SHA-256 in inert quarantine, cancels pending/delivered destructive WebsiteJobs, and never automatically replays quarantined work. Unsupported queue drivers fail closed for provider-specific handling.

The runbook's recovery order is: isolate writes and stop workers/scheduler; recover to an isolated DB; verify schema and representative records; enable recovery mode; quarantine historical runnable jobs; reconcile storage and external state; approve/promotion; then start workers gradually and refresh Connector inventory/health. Stripe event IDs are persisted with effects for duplicate rejection; after rollback, provider state still requires read-only reconciliation before idempotent application reconciliation. Pairings are preserved; mass re-pairing is not the default.

## RPO/RTO and policy

Initial targets are **24-hour application DB/object-metadata/audit RPO and 4-hour RTO**; release rollback target is 2 hours; customer backup objects target recovery to each successfully verified upload with provider-dependent RTO. These are targets, not achieved guarantees. Until remote platform backup and secret escrow are configured and drilled, actual platform DB RPO and APP_KEY recovery time remain unbounded/unverified. The single-VPS/provider outage can exceed the 4-hour target.

Platform retention targets are 7 daily, 4 weekly, and 12 monthly. Expiry targets are recorded; M9 performs no automatic platform-backup deletion. Customer backup frequencies remain unchanged (WP Basic monthly, Business weekly, Secure daily; WooCommerce Basic weekly, Business daily, Secure daily). Existing plan retention remains unchanged; cancellation/deletion/legal-hold choices remain for M12. High storage pressure triggers investigation/capacity planning, never automatic deletion of verified customer backups.

APP_KEY protects encrypted fields and integration credentials. It must be escrowed through an Owner-approved, independent encrypted secret manager with restricted break-glass retrieval. No key was read, printed, copied, rotated, or added to source. Loss of the original key can make existing ciphertext unrecoverable.

## Recovery drill and failure coverage

The local SQLite drill created a consistent SQLite image, compressed and stored it in a temporary private local test disk, verified digest/gzip/`PRAGMA quick_check`/table and selected counts, decompressed it into a new disposable database, booted a Laravel DB connection against that copy, and read representative synthetic rows. The test temp objects/databases are cleaned by the test. This proves the SQLite/local code path, not production MySQL recovery.

Automated coverage exercises missing object, missing sidecar, hash mismatch, gzip-valid invalid DB image, local disk refusal for deployed environments, disk-driver mismatch, queue read-only delivery, destructive queue refusal, reserved-job refusal, checksummed quarantine, cancellation of stale destructive WebsiteJobs, object/metadata divergence, orphan discovery with and without metadata, and read-only reconciliation. Existing S3 outage/provider, Connector/WebsiteJob, backup, and Stripe replay/idempotency tests were included in the relevant suites.

## Test and quality results

- Platform + Backups + Connector + Stripe subscription concurrency: **165 passed, 11 skipped, 1,210 assertions**.
- Final Platform suite after the last backup-key/prefix correction: **15 passed, 1 skipped, 74 assertions**.
- Billing snapshot + subscription lifecycle + Stripe subscription concurrency: **58 passed, 3 skipped, 353 assertions**. This is a separate run and overlaps the combined suite.
- Skip reasons directly inspected: MySQL backup import requires the isolated MySQL 8.4 CI service; S3 integration cases require `moto_server`; SFTP integration cases require `sshd`. The CI workflow provisions these services. No local database/provider service was substituted or contacted.
- Laravel Pint: **736 files passed**.
- PHP syntax: app/config/routes/migrations/platform tests parsed successfully.
- `git diff --check`: passed.
- Composer audit: no advisories found.
- `npm ci`/frontend build: passed; npm audit reported 0 vulnerabilities.
- Gitleaks 8.21.2 with the repository `.gitleaks.toml` scanned the 109-commit Git history and worktree: **no leaks found**. No live secret values are in the diff.
- GitHub CI: **not run**. The implementation remains uncommitted in the isolated worktree; no push or remote workflow run was performed. MySQL 8.4 verification therefore remains pending CI execution.

During validation, an empty local `.env` lacked a test `APP_KEY`; a throwaway test-only key was used to run tests, then the `.env` was restored to its original empty state. Vite tests initially lacked the generated manifest; the standard frontend build fixed that. WSL's Git worktree pointer needed explicit `GIT_DIR`/`GIT_WORK_TREE` for the Connector package tests. These were local harness conditions; no production application behavior was weakened.

## Fixes discovered during M9

- Reconciliation originally listed only disks present in backup metadata, missing the all-metadata-lost/orphan case. It now always scans the configured platform disk and has a regression test.
- A staging deployment may use `APP_ENV=production`; the storage safety check now uses the explicit deployment identity and refuses same-failure-domain local backup storage for both staging and production. Driver declaration mismatch fails closed.
- SQLite drill artifacts now use `.sqlite.gz`, not a misleading SQL dump extension.
- Gzip writes and private MySQL option-file writes are checked for partial/write failure.
- No queue, billing, provider, or live runtime mutation occurred.

## Deployment and remaining Owner decisions

Base SHA: `f007e19bde7ffbceed0f468466b3eebb72013f07`. Changes are uncommitted on `codex/m9-platform-dr`. No staging/production deploy, DB migration on a live environment, backup run, provider call, DNS change, secret rotation, billing mutation, customer mutation, or restore occurred. M8 evidence and runtime gates were untouched.

Before M9 closure, Owner decisions/evidence are required for:

1. A dedicated, independently managed platform backup bucket/account/failure domain and its cost/access policy.
2. Approved APP_KEY escrow and a retrieval drill.
3. Provider-side encryption/versioning/replication/immutability choices.
4. A successful MySQL 8.4 CI run and a controlled disposable MySQL import/boot drill.
5. Any cancellation, deletion, legal-hold, or audit-retention policy reserved for M12.

Classification:

- **M9 closure prerequisites:** configure and verify the independent remote platform-backup destination, escrow/retrieve APP_KEY, and pass the MySQL 8.4 CI/import drill.
- **Provider/cost decision:** storage account/region separation, versioning, replication, and Object Lock/immutability.
- **Post-launch improvement:** calibrate storage-age/capacity alerts from measured production growth and run recurring recovery drills.
- **M12 legal decision:** cancellation/deletion, legal hold, and audit/event retention periods.

No production or staging runtime state changed. No customer backup was deleted. No backup/restore was run against a live platform/customer database. No active test worker or recovery process remains.
