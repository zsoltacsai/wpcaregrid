# WP CareGrid platform disaster recovery

This is the canonical v1 platform-DR policy and runbook. It concerns the Laravel SaaS and its own database/configuration. WordPress backup and restore behavior is covered by [BACKUP_ENGINE.md](BACKUP_ENGINE.md) and M8 evidence; a customer-site restore is not a platform recovery.

## Protected service and dependency map

```text
GitHub source + CI ──> deploy checkout ──> Nginx/PHP-FPM Laravel
                                              │
                         MySQL (accounts, sites, plans, pairings,
                         backup metadata, WebsiteJobs, audit,
                         Stripe event ledger, settings)
                           │          │             │
                           │          ├── database queue/workers + scheduler
                           │          └── cache/session (currently DB-backed)
                           ├── Connector API / webhook ingress
                           └── S3-compatible or SFTP customer-backup storage

External dependencies: DNS/TLS, hosting/VPS, GitHub/CI, Stripe,
transactional email, backup object storage, invoicing integration,
monitoring/log delivery.
```

Authoritative recovery data is the application database plus release source, deployment configuration, and secret recovery material. The database includes customer/account/subscription/site records, pairings, WebsiteJobs, backup/restore metadata, incidents, audit events, settings, and the Stripe event-id ledger. Customer backup bytes live in the configured S3-compatible or SFTP provider; they are separate from the SaaS platform database. Current deployment documentation describes one VPS and MySQL 8.4, database-backed queue/cache/session, two workers, and one scheduler. Multi-region failover is not configured or claimed.

Secrets are categories only: Laravel `APP_KEY`; DB credentials; customer-backup S3/SFTP credentials and optional SSE-C key; Stripe API/webhook secrets; email credentials; invoicing credentials; DNS/hosting/deployment credentials; Connector pairing/signing material and encrypted integration credentials; future AI credentials. Values belong in the approved secret store and never in Git, manifests, logs, or reports.

## v1 recovery objectives

These are initial operating targets, not measured guarantees. They become supportable only after the backup destination and secret escrow decisions below are implemented and periodically drilled.

| Data/service | Initial RPO | Initial RTO | Current evidence/limit |
|---|---:|---:|---|
| Application DB | 24 h | 4 h | No platform DB backup schedule/destination was configured at M9 start; until deployed and monitored, actual RPO is unbounded. |
| Application release/source | latest reviewed release (Git SHA) | 2 h | Reproducible from GitHub while account/repository are available; deployment artifact rollback is not a substitute for DB recovery. |
| Non-secret configuration | 24 h / source-control version | 4 h | Templates and defaults are reproducible; host-specific configuration must be inventoried. |
| Secret configuration / APP_KEY | no loss of the last escrowed version | 4 h | Actual RPO/RTO is unverified until independent escrow and access recovery are tested. APP_KEY loss can permanently invalidate encrypted values. |
| Object metadata (customer backup records) | 24 h | 4 h | Same application DB recovery point; object reconciliation is required before customer backup availability is asserted. |
| Customer backup objects | after each successful verified upload | provider-dependent | Integrity is checked by WP CareGrid. Provider durability, versioning, replication, and recovery times are not independently verified. |
| Audit/event records | 24 h | 4 h | Database-backed; records after the recovery point may be lost. Stripe event IDs reduce duplicate replay only for IDs retained in the restored DB. |
| Public application availability | — | 4 h target | Single-VPS topology; provider outage or replacement provisioning may exceed target. |

Measure RPO from the newest independently verified backup timestamp to incident time. Measure RTO from incident declaration to the documented service-ready checks below. Record actual drill times; do not report targets as achieved until measured.

## Backup policy

### Platform database

The implemented path is `platform:database-backup` plus `platform:database-backup-verify {uuid}`. MySQL uses a consistent logical dump (`--single-transaction`, routines, triggers, events, hex blobs); SQLite uses `VACUUM INTO` for local drills. The compressed snapshot is encrypted as a streamed, versioned libsodium `secretstream-xchacha20poly1305` artifact. A separate HMAC-authenticated manifest binds backup UUID, source environment, DB identity hash, release SHA, ciphertext size/hash, encryption key ID and table/count manifest. The encryption keyring is independent of `APP_KEY`; key IDs allow staged rotation, but every historical key needed for retained artifacts must remain recoverable. The artifact stores no key. Plaintext work files use mode 0600 inside a mode 0700 directory and are removed on success/failure. MySQL client credentials are supplied through a temporary mode-0600 defaults file, not process arguments or logs.

Lifecycle is separated: `creating` → `stored` → `verified` (or a typed failure); per-destination delivery rows separately record `pending`/`uploaded`, verification, reconciliation and retention state. Stored-object verification reads destination bytes back, checks size and SHA-256, authenticates the manifest, decrypts and validates gzip, then runs SQLite `quick_check` or imports MySQL into a generated scratch schema on an explicitly isolated verifier. The verifier compares table names and configured critical row counts, then drops only that generated schema. MySQL records cannot become `verified` if the isolated importer is not configured. The local MySQL 8.4 drill uses synthetic test data and passed the real create → encrypt → disposable-local-disk upload → readback → decrypt → import → sanity path; it did not validate the remote S3 provider or boot a separate application instance from the scratch schema.

Owner-managed frequency, retention and enabled state live in the database-backed `backups` settings group, so scheduler and worker decisions read persisted values instead of relying on long-running config cache. The UTC scheduler checks every ten minutes and creates only the current due slot (`6h`, `12h`, `daily`, or `weekly`), with a unique slot, a distributed generation lock, bounded retries and no historical burst after downtime. Defaults are disabled, `12h`, and retention targets of 7 daily / 4 weekly / 12 monthly. Manual runs require Owner authorization, confirmation, rate limiting, a unique queued job and the same service/verification path. Primary disable is audited and preserves existing recovery points. Retention values produce expiry targets (daily days, weekly count × 7, monthly count × 30); automatic deletion is deliberately not implemented, so policy reductions cannot mass-delete recovery points. Failed artifacts are retained for diagnosis.

The platform disk identity is fixed as `platform_backups`. Its typed, non-secret destination settings (driver, endpoint, region, bucket, path-style mode) are owner-managed on Owner → Infrastructure → Platform Backups, with environment values as the initial fallback. Staging and production accept only HTTPS Hetzner Object Storage endpoints and refuse a local driver. The endpoint constraint prevents this Owner field from becoming a general outbound-request/SSRF control. The two fixed platform credential IDs (`platform_backup_s3_access_key`, `platform_backup_s3_secret_key`) are managed in Owner → Integrations, encrypted with Laravel `APP_KEY`, and never read back by the UI. Their environment fallbacks are `PLATFORM_DB_BACKUP_S3_KEY` and `PLATFORM_DB_BACKUP_S3_SECRET`; customer backup credential IDs/config are never considered. The platform backup service resolves the current encrypted credential rows when it creates each operation's S3 client, so idle long-running workers do not need a restart after a credential replacement. A running upload uses the immutable client credential snapshot created at operation start; revoking it mid-transfer can fail that operation, which remains unverified and can be retried using the new credential. No plaintext credential enters a queued job or persistent cache.

The Owner page distinguishes configuration presence from provider connection and backup verification. Its optional rate-limited connection test performs only S3 `HeadBucket`; it is read-only and does not prove object write/read access. Only the regular encrypted backup lifecycle plus stored-object readback, checksum, manifest authentication and DB-format checks can mark an artifact verified. Changing platform destination identity is blocked after any backup record exists because the current metadata does not persist a complete historical endpoint/bucket snapshot or implement migration; credentials may still be rotated against the same destination. APP_KEY escrow remains mandatory because losing the matching key makes Owner-managed platform credentials undecryptable. S3 objects are private and the existing SSE-S3 setting remains enabled. Bucket isolation, scoped provider permissions, provider-side versioning/immutability, deletion protection and independent failure-domain separation require separate Owner/provider verification; this code does not claim those controls are enabled. Optional Google Drive is not enabled and remains a separately planned follow-up. Until dedicated S3, APP_KEY escrow/retrieval and remote CI/staging validation pass, actual platform DB RPO remains unbounded despite the 24-hour objective.

### Customer WordPress backups

Frequency remains a product promise and is not changed here: WordPress Basic monthly, Business weekly, Secure daily; WooCommerce Basic weekly, Business daily, Secure daily. Existing plan-specific retention values are unset, so current behavior is to retain rather than delete. Preserve verified customer objects and their metadata. Cancellation/deletion/legal-hold retention needs an M12 legal/product decision; do not invent account-erasure deadlines in M9. Failed/unverified artifacts are not presented as available. Superseded backups remain subject to the existing `BackupPolicy` and `Retention` rules.

### Object storage

The application supports S3-compatible storage and SFTP for customer backup objects, streaming checksum verification, configured encryption, and fixed per-site object keys. Platform backups use a separate named disk, credential variables, prefix and verification records; no customer backup credential is reused. The existing provider account may still be a shared failure domain even when buckets and credentials differ. The provider, bucket/account separation, versioning, Object Lock, replication, lifecycle and recovery after provider-side deletion are not established by source code; verify these in provider configuration before claiming resilience. Do not enable versioning/replication/Object Lock without the already-required Owner cost/provider decision.

## Recovery mode and queue safety

After a DB rollback, never start workers immediately. Keep default and backups workers stopped; disable scheduler entry; enable `PLATFORM_RECOVERY_MODE=true`; restore DB into an isolated target; compare schema and critical records; review/apply only compatible additive migrations to that isolated target so the quarantine schema exists; run `php artisan platform:recovery-quarantine-queue --confirm-workers-stopped`; reconcile WebsiteJobs; verify storage; then restart workers only after an operator approves each queue class. The command refuses unless recovery mode is active, workers-stopped confirmation is explicit, all tables share one DB connection, and there are no reserved queue rows. It copies exact Laravel queue payloads and destructive queued/delivered WebsiteJobs into checksum-protected quarantine, marks those WebsiteJobs cancelled, and never re-dispatches them. Quarantined payloads are not automatically replayed. Recreate safe mail/monitoring tasks through their normal idempotent workflows. Never replay billing, invoice, backup-transfer, restore, update, remediation, or admin-access jobs from a historical snapshot without an individual reviewed reconciliation.

While recovery mode is active, the Connector poll API may deliver only inventory, health, and license checks. Site-changing, backup, restore, security, and administrative jobs remain queued and are not marked delivered. Ordinary operation is unchanged when the flag is off. Keep recovery mode active until the recovered DB and queue state are reconciled. Connector heartbeats may continue; use fresh inventory/health after enabling normal polling.

`platform:recovery-quarantine-queue` supports the database queue driver only. It copies each pending queue payload and scheduling metadata, hashes the exact payload, and removes it from the runnable queue in one transaction per bounded batch. It requires recovery mode and the quarantine migration. For Redis/SQS/other drivers, keep workers stopped and use the provider-specific export/quarantine procedure; the command refuses unsupported drivers. Failed-job rows remain evidence and are not retried automatically.

## Stripe, Connector, and external-state reconciliation

Stripe webhook handling stores each verified event ID in the same DB transaction as its effects and rejects duplicate IDs. After restoring an older DB, Stripe may contain newer subscription state than the restored snapshot. Pause billing-side actions, preserve signed webhook delivery/retry, compare current Stripe customer/subscription state read-only, then reconcile through existing idempotent application workflows. Never infer paid state from a browser redirect or synthesize provider events. Invoice-provider submissions with ambiguous outcomes remain manual reconciliation cases; do not blind retry.

Connectors authenticate with their stored credentials and can continue polling while the SaaS is unavailable only to the extent defined by their plugin. After DB recovery, preserve pairings and credentials; do not mass re-pair. Recovery mode holds mutating commands. Once the database and queue are reconciled, request fresh inventory, health, and heartbeat; inspect pending WebsiteJobs and cancel stale commands under the documented operator process. A restored old WebsiteJob must not become executable merely because the database snapshot contains it.

## Platform backup integrity and reconciliation

Customer backup state is `backups`/`backup_objects` metadata versus provider objects. For each metadata object, confirm present, size, and SHA-256 with the existing storage abstraction; classify missing, mismatch, unavailable, expired/deleted, and unverifiable separately. Detection is report-only: no orphan or mismatched object is deleted automatically. Full orphan discovery requires a provider listing permission and an implementation that safely parses only the fixed `{website_uuid}/{backup_uuid}/{known filename}` layout; partial multipart uploads require provider lifecycle/inspection. Until that capability is available, classify orphan detection as incomplete rather than clean.

`backup:reconcile-storage` is read-only for customer objects. `platform:database-backup-reconcile` performs report-only platform reconciliation: it compares database backup and per-destination delivery metadata against object existence, size, ciphertext SHA-256 and the authenticated manifest; it reports pending/failed states and orphan objects, updates reconciliation status, and never deletes. Provider listing/read failures become `unavailable` or `reconciliation_required`, never healthy. The authenticated sidecar supports discovery when the application DB is unavailable, but its HMAC still requires the independent platform-backup key. Legacy unencrypted records remain readable as legacy evidence; they do not receive new encryption guarantees retroactively.

MySQL platform backup artifacts are imported into a generated scratch schema only on a separately configured and explicitly marked isolated verification endpoint with a constrained `wpcg_m9_dr_` schema prefix. The verifier compares the table set and configured critical row counts, then drops only its generated scratch schema. SQLite drill images are opened with `PRAGMA quick_check` and table/count sanity checks. No live bucket enumeration was performed; production provider-side inventory, sidecar recovery, and account/region independence still require owner-approved read-only verification.

## Disaster scenarios and response matrix

| Scenario | Detection / impact | Recovery prerequisites and sequence | Human approval / safe fallback |
|---|---|---|---|
| A. Bad app deploy | health/smoke failure; requests/jobs may fail | pause deploy; retain DB; rollback exact reviewed release; rebuild caches; graceful worker restart; health checks | release operator approves; keep site read-only if migration compatibility uncertain |
| B. DB corruption/deletion | DB errors, integrity alarms, missing rows | stop workers/scheduler and writes; recover latest verified platform DB backup to isolated DB; sanity check; quarantine jobs; promote only after review | Owner/incident lead approves promotion; remain maintenance/read-only if backup fails |
| C. VPS loss | host/network unavailable | provision replacement, restore code/release/config/secrets, TLS/network, DB, cache/session, queues and storage connectivity in order | provider access/cost approval; do not point DNS until checks pass |
| D. Object-storage outage | storage API unavailable or reads fail | pause new restore claims and mark customer backup availability degraded; retain metadata; retry bounded operations; do not delete/reupload blindly | Owner approves customer comms; keep sites operating, restore unavailable |
| E. Storage credential compromise | unauthorized access/alerts | contain/revoke compromised credential; issue least-privilege replacement; test read-only then verify objects and hashes; rotate dependent worker config gracefully | security lead approves; do not destroy object history |
| F. Accidental object deletion | reconciliation missing object | check provider versioning/audit; recover provider version to exact key; verify against metadata; preserve incident evidence | Owner approval before provider restore; mark affected backup unavailable until verified |
| G. Queue corruption/stuck jobs | queue depth/age, failed jobs | stop affected workers; snapshot queue; recovery mode; quarantine stale pending payloads; classify and create safe replacements | operator approves class-by-class restart; no blanket retry |
| H. Config loss | app/worker boot or dependency failures | rebuild source-controlled config; retrieve secrets from approved escrow; validate APP_KEY before decrypting DB credentials; cache config and restart only idle workers | secret owner authorization; remain unavailable if a key is missing |
| I. Operator error | audit event/data anomaly | freeze affected action; preserve audit and DB snapshot; recover only affected subsystem if supported, otherwise isolated full DB drill | incident lead approves; do not use broad production restore without a plan |
| J. DB/object divergence | reconciliation detects missing/mismatched object or metadata | report-only classify; recover object version or repair metadata only with provenance; reverify | Owner approves any object/metadata mutation; treat uncertain backup as unavailable |
| K. Region/provider incident | provider status / connectivity loss | invoke replacement-provider plan only after credentials/data availability confirmed; restore DB and storage copies; use tested DNS/TLS cutover plan | new provider/cost/DNS requires Owner approval |
| L. APP_KEY/secret loss | decrypt errors / unavailable vault | retrieve original escrowed key; if unavailable, encrypted integration data may be unrecoverable and dependent secrets must be reissued/re-paired individually | Owner/security approval; do not generate a new APP_KEY expecting old ciphertext to decrypt |
| M. Security incident | integrity or access indicators | isolate write paths, stop queues/scheduler, preserve logs/images/backups, rotate compromised credentials after forensics, rebuild from known release and verified backup | security lead/Owner approval; never restore into an untrusted host |

## Runbooks

Every runbook starts by recording incident ID/time, operator, source/target identities, and verified artifact hashes. Use strict SSH host-key verification. Never place secrets in shell history or reports.

### 1. Application rollback

Prerequisites: known-good release SHA, compatible DB schema, healthy DB, deployment lock. Stop new deploys; inspect current workers and allow active jobs to finish; use the guarded deployment rollback to the known SHA; rebuild config/route/view caches; restart only idle workers gracefully; run `/up`, login, REST, connector heartbeat, and queue checks. If schema is not backward compatible, stop and use the DB recovery runbook. Roll forward only after the failed release is understood.

### 2. Database recovery

Prerequisites: independently stored verified platform backup, matching encryption key, isolated target DB, source/target identity confirmation. Stop web writes, workers, and scheduler. Verify artifact hash/decryption/format, import to a new database, run engine integrity, migration ledger and representative customer/site/subscription/backup/audit sanity checks, enable recovery mode, quarantine queued jobs, and verify no stale mutating WebsiteJobs can be polled. Switch application DB only after review; keep workers stopped for post-switch checks. If any check fails, do not promote; preserve target and return service to read-only/unavailable mode.

### 3. Full VPS loss

Provision a clean supported host with approved network controls; deploy reviewed release; reconstruct non-secret config from templates; retrieve secrets and APP_KEY from independent escrow; restore/verify DB; quarantine queues and enable recovery mode; check storage without mutating objects; restore TLS/web server/supervisor/scheduler definitions; keep scheduler/workers disabled until DB, queue, and storage checks pass. Then start one worker at a time and observe. DNS cutover is a separately approved change. Roll back by keeping old endpoint/DNS until the new host passes health and connector checks.

### 4. Object-storage outage

Confirm provider status and exact affected bucket/endpoint using read-only checks. Do not mark backup records verified or delete metadata. Customer restore remains unavailable for affected objects; backups already present in DB remain historical but their availability is degraded. Retry only bounded transient operations; monitor queue age and failed transfers. Resume after storage connectivity returns and reverify affected objects. If the provider incident is prolonged, request an Owner-approved alternate destination; no unapproved migration/copy.

### 5. Storage credential rotation

Identify credential scope and blast radius; preserve existing credentials until replacement works unless active compromise requires immediate revocation. Create least-privilege replacement through approved secret management; test read-only listing and hash verification on a known test object; update secret/config; rebuild config cache and gracefully restart idle backup workers; reverify sample objects and record the credential version, never its value. Revoke old credentials after success. If access fails, restore prior secret version only when not compromised.

### 6. DB/storage inconsistency

Run report-only reconciliation. Capture backup UUID, object name, expected/actual size/hash and provider request ID (redacted). Check provider version/audit history. Restore an exact provider version only with Owner approval and verify it. Missing metadata/object pairs remain unavailable; do not infer metadata or delete orphaned objects. If the source of truth cannot be proven, preserve both sides and escalate.

### 7. Security incident

Declare incident; isolate compromised ingress/credentials; preserve immutable logs and snapshots; stop workers/scheduler and destructive command delivery; keep recovery mode on. Establish a trusted release, host, DB backup, object store, and secret chain before recovery. Rotate affected secrets with separate authorization, inspect/reconcile Stripe and invoice-provider state read-only, then rebuild on trusted infrastructure. Do not restore a backup produced after compromise into a trusted state without review. Reopen traffic only after security owner approval and all health checks pass.

## Configuration and APP_KEY recovery

Reproducible items: source-controlled Laravel config, migrations, deploy templates, Nginx/Supervisor templates, dependency lockfiles, build pipeline. Host-specific values: environment classification, paths, PHP-FPM socket, DB endpoint, cron wrapper, TLS certificate paths, S3 endpoint/bucket, SFTP host-key fingerprint. Secret items: `APP_KEY`, DB credentials, object-storage credentials and any SSE-C key, Stripe secrets, webhook signing secret, mail credentials, invoicing credentials, DNS/SSH deployment credentials, and encrypted Connector/integration material.

`APP_KEY` protects Laravel encrypted casts and Owner-managed integration credentials (including provider credentials). Losing it does not make ciphertext decryptable by a newly generated key. Preserve the exact key in an approved independent encrypted secret manager/escrow with restricted break-glass access and tested retrieval. Do not print it, commit it, include it in backup manifests, or rotate it as a routine response. If it is lost, identify each dependent value and reissue/reauthorize it; Connector credentials may require site-specific re-pairing only if their stored authentication material is unrecoverable.

## Storage pressure and retention governance

Alert groundwork thresholds: warn when platform backup age exceeds 26 hours; critical at 48 hours; warn at 70% staging-disk utilization and critical at 85%; alert on oldest pending transfer age > 30 minutes and any failed verification. These are proposed initial thresholds and must be calibrated from measured site sizes and capacity. At high pressure, pause new large transfers, identify largest objects and incomplete multipart uploads, expand approved capacity or resolve failed cleanup. Never remove verified customer backups automatically in response to pressure. Retention cleanup failures emit an operator event and leave objects intact.

Unresolved legal retention for account/site cancellation, deletion, incident/legal hold, and audit retention belongs to M12. Storage versioning/immutability, independent account/region, and a dedicated platform-DR destination require provider/cost approval. These are not silently enabled here.

## Health states and M10 event contract

Reusable health states are represented by `PlatformRecoveryHealth`: `healthy`, `degraded`, `unavailable`, `verification_failed`, `reconciliation_required`, `stale`, and `not_configured`. Current Owner readiness also considers a verified artifact younger than 24 hours, object presence, S3 configuration and explicit Owner APP_KEY escrow attestation; it does not infer escrow from a running key. Machine-readable `PlatformRecoverySignal` event types include `platform_db_backup_failed`, `platform_db_backup_verification_failed`, `storage_unavailable`, `storage_reconciliation_anomaly`, `retention_cleanup_failed`, `dr_drill_failed`, and `secret_recovery_prerequisite_missing`. Payloads contain identifiers, state and safe error codes, never credentials, serialized queue payloads, or customer content. M9 defines the contract; notification routing is deferred to M10.

## Drill acceptance record

Each disposable DB drill must record source/target driver and schema version, artifact SHA-256, verification/import result, representative critical-row counts, elapsed backup/verify/import times, queue-quarantine test result and webhook-idempotency test result. Use synthetic data only. The local MySQL 8.4 exercise used a disposable container and scratch schema, proved import/table/count sanity and cleaned the container; independent S3 recovery, restored-app boot, remote CI and production MySQL recovery remain unverified. A local SQLite drill does not prove remote MySQL recovery.
