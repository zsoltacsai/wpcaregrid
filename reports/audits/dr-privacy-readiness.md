# DR / Privacy Restore Readiness Evidence — M12 Wave 6

**Audit baseline:** `57fe3ef74b099f5ae6844f1ba6414149cc079d54`
**Scope:** customer WordPress backup deletion reconciliation, M9 platform-backup separation, privacy tombstone replay prerequisite, and platform recovery quarantine. This is reusable evidence for M15/M17, not a final production audit.

## Verified architecture

- Customer-site backups are tracked by `backups` and `backup_objects`; storage access is behind `App\Backups\Storage\BackupStorage`. Logical keys contain website and backup UUIDs plus a fixed object filename; they do not contain customer names or site URLs.
- `BackupDeletion::remove()` previously called `delete()` for the three fixed object names and then set one `backups.objects_deleted_at` timestamp without checking each result. That aggregate flag could report success after a partial delete or provider acceptance without read-back.
- `Backup::isRestorable()` previously relied on `status=verified`, `objects_deleted_at=null`, and expiry. It did not exclude a backup after a partial remote delete.
- `Retention::apply()` is an existing customer-backup deletion path when a plan has configured retention values. This wave does not change plan frequency/retention semantics; its future provider calls now require object-level read-back.
- `backup:reconcile-storage` is read-only and compares provider listing to database metadata. The prior implementation skipped all metadata after `objects_deleted_at` and could miss a remotely visible object after a claimed delete.
- M9 platform DB backups use the separate `platform_backups` disk and dedicated Owner settings/credentials (`PlatformBackupStorage`, `PlatformDatabaseBackupService`). This is separate from customer-site backup storage. It is not evidence of account/provider-level independence, immutability, or a usable privacy event journal.
- `platform:recovery-quarantine-queue` requires recovery mode and stopped workers, quarantines queued Laravel jobs and unsafe WebsiteJobs, and never redispatches them. `JobQueue` blocks unsafe WebsiteJobs when platform recovery mode is enabled. Wave 6 also gates customer account, checkout, and Stripe webhook HTTP routes with 503/Retry-After while recovery mode is active; Owner access remains available. This does not replay privacy events or release recovery without an independently verified journal.
- M12 Wave 1 provider tasks are request-linked status metadata only. `PrivacyProviderTaskService` has no provider client. Provider region, contract, legal deletion entitlement, version retention, and provider-side delete verification remain unverified.

## Wave 6 safeguards implemented

- Added durable per-logical-object deletion records with request time, provider, logical key, provider response state, verification state, absent timestamp, attempts, and safe error code.
- Added optional `InspectableBackupStorage` presence read-back for local, SFTP, and S3 adapters. S3 absence means the logical key is not readable; S3 versioning, delete markers, Object Lock, replicas, and physical erasure remain `WAIT_FACT`.
- A backup is only transitioned to expired/deleted after every fixed logical object key is read back absent. Partial/unknown results retain the backup record and mark it non-restorable; retry remains idempotent.
- RET-24 is enforced without the legacy `--confirm-only-backup` override. Concurrent restore/deletion requests serialize through the website → backup lock order. Active restore/compensation and queued update dependencies block deletion.
- Historical aggregate `objects_deleted_at` rows are imported as `legacy_unverified`, never upgraded to confirmed deletion. Storage reconciliation reports legacy ambiguity and objects present after deletion.
- Corrupt artifact cleanup no longer sends an unverified provider delete from the transfer job. It records a reconciliation-required state for explicit handling.
- Added a `PrivacyTombstoneJournal` interface and a default unavailable adapter. `PrivacyRestoreSafety` and `privacy:restore-readiness` fail closed unless the independent journal is available and replay verification is recorded. No current adapter is configured; a restored deployment must remain quarantined.
- Recovery mode now blocks customer account, checkout, and Stripe webhook HTTP paths as well as unsafe Connector/queue operations; the Owner surface remains available for recovery operations. Stale webhook deliveries receive 503 so the provider can retry after recovery.
- Owner Privacy & Retention shows aggregate backup deletion/provider-task counts, provider facts, and the blocked restore-safety state without customer identifiers. System health reports this as an attention finding.

## Remaining DR gates

1. Select and verify an independent append-only tombstone/replay source. It must survive restoring the platform database and expose a trustworthy snapshot watermark. The current platform backup S3 destination could be evaluated, but source code does not prove immutability/versioning or independent provider failure-domain protection.
2. Verify each deployed provider's delete/read-after-delete, versioning, lock, lifecycle, replica and outage semantics from owner/provider evidence. No provider-side change was made.
3. Complete the replay applier, recovery quarantine exit authorization, and real disposable platform DB restore drill only after the independent journal contract is established. Until then the readiness command intentionally fails.
4. Keep legal/accounting tombstone purpose/duration and provider deletion entitlement at `WAIT_LEGAL` / `WAIT_ACCOUNTING`; this report makes no GDPR conclusion.

## Safety statement

No production recovery, staging provider deletion, customer backup deletion, customer account/site mutation, or live billing mutation was performed for this evidence. Automated deletion tests use local temporary files only. No claim of physical erasure is made. The global gate remains `M12_DESTRUCTIVE_RETENTION_ENABLED=false`; Wave 7 destructive execution is not started.

## Release update — 2026-10-15

Wave 6 PR #60 is merged and staged at `a6e79bd381319ee2e18c90fd464cdbaf9cef4908`. PR #61 (`39e2ed9951c1d8b56821b4e08dcc63830fa180fe`) clarified that `platform:database-backup-reconcile` persists reconciliation metadata and added a regression assertion; it passed all five exact-head GitHub Actions jobs in run `38768895991` (attempt 3), then merged as `1a45951475a292ecc06c9547a4fd4b94ae5eab77`. The primary branch now points to that merge SHA. The #61 help-text/test-only change was not separately deployed; staging runtime remains #60's SHA.

The exact-head run totals were 2,936 tests, 39,848 assertions, 46 environment/opt-in skips, and zero failures. The skips are documented in `reports/m12-privacy/wave6-implementation-report.md`. Staging readiness remains blocked for independent tombstone-journal configuration and verified replay; the recovery quarantine is intentionally retained. No provider deletion or production/customer mutation occurred.

## Independent journal and historical restore candidate evidence — 2026-10-15

**Status: PARTIAL / NOT DEPLOYED.** The current implementation candidate adds encrypted journal objects to the M9 platform-backup S3 namespace, authenticated backup-manifest watermarks, a fail-closed replay applier, and replay evidence in the restored database. The code-level historical proof uses a genuine older disposable SQLite file and the real encrypted object-storage journal adapter; it demonstrates that a T1 synthetic restriction remains in the external journal after a T0 database file is restored, and that replay restores the restriction while preserving synthetic invoice snapshot content and tolerating repeated replay.

This is not yet staging/production DR evidence. The journal shares the existing platform-backup S3 provider/account and keys; provider-side versioning/Object Lock, bucket credential isolation, replicas and account-level failure-domain independence remain `WAIT_FACT`. SSH to the pinned staging host was rejected for lack of an authorized public key, so staging SHA, configuration, deployed replay, worker state and staging recovery proof are unverified. Keep the restore quarantine in place until an exact merged SHA is guarded-deployed and the isolated staging recovery procedure passes. Wave 7 erasure/anonymization is not implemented or enabled; the current replay scope only restores the privacy-safe account restriction and export-revocation states that exist today.

### Final staging historical-restore evidence — 2026-10-15

This supersedes the staging-unverified status above; it does not close the future production DR audit.

- Exact primary `f4559904990f95cf801b76ddeb4a1561cb3392b2` was guarded-deployed to staging with strict pinned Ed25519 host-key checking. Pre-deploy SHA was `a6e79bd381319ee2e18c90fd464cdbaf9cef4908`; the checkout was clean. Post-merge GitHub Actions run `38793418287` passed all five mandatory jobs.
- Staging journal is configured through the dedicated platform-backup S3 storage adapter and external environment-held encryption keyring. Normal `privacy-tombstones/staging` was integrity-ready and remained at zero events. The drill used an isolated `wave6-proof-20261015-f4559904-7d32` namespace with one event and its encrypted chained checkpoint.
- Verified T0 backup UUID `01a1411d-c170-72b9-8332-a6734d9353ae` is an authenticated MySQL 8.4.11 dump with watermark `null`. It was imported into a disposable, prefix-constrained MySQL schema, where the synthetic customer remained active and the closure case remained `requested` before T1 replay. The disposable schema was dropped afterward; the primary staging database was never restored or rewound.
- The real replay command found/applied the post-T0 restriction event; the restored synthetic subject became inactive and its case became `access_restricted`. A second replay was idempotent (`already_applied=1`). Recovery readiness was blocked before replay and became ready only after verified replay. Full content hashes of 16 accounting/billing-related tables were unchanged. Replay did not call Stripe or create invoices. The synthetic fixture was reopened after the drill; the primary record is active with a cancelled closure case.
- Failure injections in isolated namespaces kept readiness/quarantine blocked for an unavailable journal, missing committed event object, corrupt event envelope, and unknown subject mapping. Cross-tenant and checkpoint-chain failure cases are covered in exact-SHA CI. No real provider object was deleted.
- Staging health passed for app, DB, queue, scheduler, both workers, Connector, Stripe TEST, backup storage, mail, safety, privacy retention, privacy exports and restore safety. Failed jobs and active mutating WebsiteJobs were zero; restore gates and destructive retention remain OFF. The pre-existing security scanner warning (no completed scan) remains visible.
- Read-only provider API facts: versioning unset/never enabled; Object Lock not configured; lifecycle rules: 0. This is not a physical deletion test. Version/delete semantics, provider credential scope, same-account failure domain/replication and contract facts not established remain `WAIT_FACT`; legal/accounting remain `WAIT_LEGAL` / `WAIT_ACCOUNTING`.

This establishes a staging-context database-rollback independence proof for the current supported suppression actions. It does not prove provider-account disaster independence, physical object erasure, production recovery, legal compliance, or irreversible anonymization. Those remain outside this non-destructive Wave 6 closure.

### Journal completeness follow-up release evidence — 2026-10-15

The encrypted append-only checkpoint chain and missing-object failure injection are merged in PR #65 at `31ee480be741ab818471e5781a27bbaa8afe2ade`. Exact PR-head run `38788096639` and post-merge primary run `38789787609` both passed all five mandatory GitHub Actions jobs. This improves logical detection of selectively missing journal entries; it does not prove S3 Object Lock/versioning or survival of deletion of the entire namespace/provider failure domain. Staging deployment and historical recovery proof remain unverified because strict SSH user authentication is rejected; maintain quarantine.
