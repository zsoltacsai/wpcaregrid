# M147-E.1 — Staging Scanner Readiness & Safety Preflight

**Status:** Level 2 offline planning complete; **M147-E.2 NOT EXECUTED — OWNER APPROVAL REQUIRED**.

**Repository baseline:** `57b8cf02d655cb7316648785bd9a1b6b07b148e1` (`claude/ecstatic-wozniak-oemyuf`, verified from `origin`).
**Scope:** documentation and offline source review only. This document is a proposal, not an execution authorization.

## Decision summary

M147-E.1 identifies the actual scan flow and its side effects, the exact six M12 configuration keys, the documented staging identity, and the evidence needed for a later controlled run. It does not establish actual staging state. Every runtime-only fact remains **REQUIRES LIVE READ-ONLY VERIFICATION** or **UNKNOWN / NOT DOCUMENTED**. In particular, all six live M12 values are **UNKNOWN**.

M147-E.2 is currently **NO-GO**. The major blockers are the six unverified M12 values; unknown staging WordPress/Connector pairing and fingerprint; unknown live scanner/feed configuration; uncertain notification and backup routing; and no fresh evidence of a restorable staging backup. These are verification gates, not permission to inspect staging now.

## Source inventory and architecture map

| Concern | Source of truth in repository | What source establishes | Live uncertainty |
|---|---|---|---|
| M12 gates | [`config/privacy.php`](../../config/privacy.php), [M14.7 plan](m14-7-plan.md) | Six environment-backed booleans; missing values default false in source; synthetic anonymization also requires `APP_ENV=testing`. | Effective staging config/cache is unknown; defaults are not live evidence. |
| Scan routes / authorization | [`routes/operator.php`](../../routes/operator.php), [`routes/owner.php`](../../routes/owner.php), `SecurityFindingController`, Owner `WebsiteController` | Operator scan requires `operator.security.scan`; Owner scan requires `owner.websites.operate`; routes are throttled. | Actual authorized operator identity and target ownership are unknown. |
| Scan orchestration | [`SecurityScanService.php`](../../app/Security/SecurityScanService.php), `ScanType`, `JobQueue`, [`config/security.php`](../../config/security.php) | Request writes scan/audit state, performs vulnerability matching, and queues fixed `SECURITY_SCAN` jobs for Connector scans. Per-website concurrent scan is refused; timeout and cancellation exist. | Worker, scheduler, queue depth, active scans, runtime timeout/resource settings unknown. |
| Connector API | [`routes/api.php`](../../routes/api.php), `ConnectorController`, `AuthenticateConnector`, [`docs/CONNECTOR.md`](../../docs/CONNECTOR.md) | Connector-authenticated heartbeat/jobs/results; credential and website identity are bound; job types are allowlisted. | Paired Connector, exact plugin build, credential status, API compatibility at staging unknown. |
| Vulnerability source | [`SyncVulnerabilities.php`](../../app/Console/Commands/Security/SyncVulnerabilities.php), `WordfenceFeed`, `VulnerabilityScanner`, [`config/security.php`](../../config/security.php) | Default source is `none`; Wordfence v2 requires configured feed URL/key; feed freshness default is 72h; bounded transfer/index protections have synthetic tests under the narrow M147-F07 disposition. | Live lawful feed, license, URL/host, key, index freshness and current status unknown; no live feed checked. |
| Connector scanners | `wordpress/wp-caregrid-connector/includes/Security/`, `includes/Jobs/Handlers/SecurityScanHandler.php`, `FileIntegrityScanner`, `MalwareScanner` | Fixed `integrity`, `malware`, `full` job types; repository docs describe read-only inspection and result allowlisting. | Actual staging filesystem permissions, scanner availability and resource use unknown. |
| Posture | [`SecurityPosture.php`](../../app/Security/SecurityPosture.php), [`docs/SECURITY_ENGINE.md`](../../docs/SECURITY_ENGINE.md) | `healthy` requires a recent latest completed scan and all declared scanner coverage completed; missing/partial/unavailable coverage cannot support healthy. | Existing staging posture and latest scan unknown. |
| Incident/backup effects | [`IncidentService.php`](../../app/Security/IncidentService.php), [`config/security.php`](../../config/security.php), [`docs/SECURITY_INCIDENTS.md`](../../docs/SECURITY_INCIDENTS.md) | `finish()` calls `openForDetection`; high/critical, high/confirmed-confidence suspicious-file findings can open an incident. `auto_open` and `auto_backup` default true; a full recovery backup is requested through `BackupService`. | Actual toggles, storage destination, notification fanout and backup queue state unknown. This is a material E.2 gate. |
| Remediation | `RemediationService`, `JobQueue`, [`docs/SECURITY_INCIDENTS.md`](../../docs/SECURITY_INCIDENTS.md) | Separate authorization/policy path; remediation defaults off and plugin deactivation defaults off; fixed job types. | Effective staging config and all runtime authorization remain unknown. |
| Scheduling | [`routes/console.php`](../../routes/console.php), `ScheduleScans`, `MonitorScans` | `security:schedule` is gated by `SECURITY_SCHEDULE_ENABLED` (default false); scan monitor is scheduled every 10 minutes. | Scheduler/cron state and effective setting unknown. A manual scan may coexist with scheduled work. |
| Deployment/recovery | [`scripts/deploy-staging.sh`](../../scripts/deploy-staging.sh), [`docs/DEPLOYMENT.md`](../../docs/DEPLOYMENT.md), [`docs/BACKUP_ENGINE.md`](../../docs/BACKUP_ENGINE.md) | Staging procedure pins exact source, locks deployment, has guarded recovery, takes a pre-migration DB backup; migration failure can leave traffic gated; automatic DB restore is not performed. | Current host identity, current backup, usable restore capability, storage isolation and disk headroom unknown. No script run. |

### Staging inventory

`VERIFIED FROM REPOSITORY` means documentation/source describes the intended design; it does **not** mean the live staging value has been observed.

| Inventory item | Offline status | Evidence / later check |
|---|---|---|
| Intended Laravel staging hostname | VERIFIED FROM REPOSITORY: `staging.wpcaregrid.com` is named in `docs/DEPLOYMENT.md`. | Exact host identity and DNS/TLS at run time: REQUIRES LIVE READ-ONLY VERIFICATION. |
| Exact deployed Laravel revision | UNKNOWN / NOT DOCUMENTED for this preflight. | Receipt/runtime SHA must be read by approved operator and match Owner-approved SHA. |
| WordPress installation / synthetic site | UNKNOWN / NOT DOCUMENTED. No specific staging WP target is approved by this task. | Owner must approve exact target identity and isolation evidence; do not infer from DNS/docs. |
| WordPress version | UNKNOWN live. | Read-only WP health / admin version readback after authorization. |
| Connector version/API | VERIFIED FROM REPOSITORY: source release declares Connector `0.4.7`, package schema 1, API v1 (see `wordpress/wp-caregrid-connector/release.json`, `docs/CONNECTOR.md`). | Staging installed version, artifact SHA-256/source fingerprint and pairing are REQUIRES LIVE READ-ONLY VERIFICATION. |
| Laravel PHP / database | VERIFIED FROM REPOSITORY: deployment target expects PHP 8.4; deployment docs describe MySQL. | Live PHP patch and DB engine/version unknown; read-only health/receipt only. |
| Queue worker configuration | VERIFIED FROM REPOSITORY: two named Supervisor workers, default and backups (`docs/DEPLOYMENT.md`). | Exact live state, queue driver, reservations/depth, worker identity unknown. |
| Scheduler | VERIFIED FROM REPOSITORY: Laravel scheduler includes security monitor and schedule; scan scheduling defaults off. | Cron heartbeat, schedule toggle, locks and recent scheduled dispatch unknown. |
| Scanner toggles/rules | VERIFIED FROM REPOSITORY: config keys/defaults in `config/security.php`. | Effective scan type, time budget, max parts, incident/backup/remediation settings and Connector capability unknown. |
| Vulnerability source/index | VERIFIED FROM REPOSITORY: source defaults `none`; Wordfence v2 supported with 72h default freshness. | Feed source/license/key presence, index age, update result and outbound host unknown. Do not disclose key. |
| Backup destination / isolation | VERIFIED FROM REPOSITORY: S3-compatible or SFTP are supported; staging docs intend a private staging-only S3 bucket/prefix. | Actual destination identity, permissions, encryption, isolation, free space and remote-write risk unknown. |
| Tenant/site ownership | VERIFIED FROM REPOSITORY: scans are website-scoped and app uses distinct accounts/permissions. | Exact synthetic tenant/customer/site IDs and proof of no customer linkage unknown. |
| Incident notifications | UNKNOWN / NOT DOCUMENTED as an operationally isolated route for synthetic findings. | Verify recipients and delivery pathways without exposing addresses; disable or safely sink only with separate explicit operational approval. |
| External integrations | VERIFIED FROM REPOSITORY: staging documentation describes Stripe TEST, mail and backup integration patterns. | Actual active endpoints/credentials unknown; do not call any integration. Confirm endpoints and mode with secret-safe metadata only. |
| Logging | VERIFIED FROM REPOSITORY: Laravel logs configured under app; scan/audit events record safe identifiers/counts. | Live level, retention, access and secret redaction unknown. Capture only bounded, redacted evidence. |
| Disk capacity | UNKNOWN live. | Read-only filesystem capacity and storage staging headroom check required before scan/backup. |
| Network restrictions | VERIFIED FROM REPOSITORY: deployment docs describe firewall ports 22/80/443 and loopback MySQL as intended. | Live rules, egress allowlist and DNS behavior unknown; verify before any external call. |
| Authorized operator | UNKNOWN. | Named Owner/Operator identity and ability set must be approved for the exact window; no credentials in evidence. |

## M12 gate inventory — all live values UNKNOWN

The authoritative source is `config/privacy.php`. It maps exactly these six environment inputs to Laravel config keys. The defaults below are source defaults only and do not prove staging state.

| Exact gate | Purpose / source | Scope | Expected safe value for E.2 | Proposed read-only verification | Secret exposure / side effects | PASS / FAIL / UNKNOWN; freshness, stop and evidence |
|---|---|---|---|---|---|
| `M12_DESTRUCTIVE_RETENTION_ENABLED` → `privacy.destructive_retention_enabled` | Prevents destructive retention execution; `config/privacy.php`. | Platform-wide config. | `false` | Owner/admin-approved console readback of only this resolved key; report boolean only. Do not print `.env` or full config. | The boolean itself is not a secret; broad environment/config output could expose secrets. No mutation/network/job is intended; read method must be reviewed first. | PASS=false; FAIL=true; UNKNOWN=unreadable/ambiguous. Capture immediately before scan window. Any non-PASS stops. Evidence: key, boolean, UTC, environment label, app SHA and operator only. |
| `M12_PROFILE_ANONYMIZATION_ENABLED` → `privacy.profile_anonymization_enabled` | Profile anonymization gate; same source. | Platform-wide config. | `false` | Read only this resolved boolean; redact unrelated output. | Boolean is not secret; full env is sensitive. No mutation intended; confirm read method side-effect-free. | Same criteria and freshness; any true/unknown stops. Capture only the six booleans with environment/SHA/time/operator. |
| `M12_BACKUP_DELETION_ENABLED` → `privacy.backup_deletion_enabled` | Customer backup deletion gate; same source. | Platform-wide config. | `false` | Read only resolved boolean; do not enumerate backup records. | Boolean is not secret; avoid backup inventory/credentials. No deletion or storage operation intended. | Same criteria and freshness; any true/unknown stops. Record no backup IDs or object metadata. |
| `M12_PROVIDER_DELETION_ENABLED` → `privacy.provider_deletion_enabled` | Provider deletion gate; same source. | Platform-wide config. | `false` | Read only resolved boolean; no provider listing or mutation. | Boolean is not secret; provider data is outside the check. No mutation intended. | Same criteria and freshness; any true/unknown stops. Evidence remains the safe six-field capture. |
| `M12_BULK_RETENTION_EXECUTION_ENABLED` → `privacy.bulk_retention_execution_enabled` | Bulk retention execution gate; same source. | Platform-wide config. | `false` | Read only resolved boolean; no retention command invocation. | Boolean is not secret; no retention query or deletion is intended. | Same criteria and freshness; any true/unknown stops. Record only gate state and provenance metadata. |
| `M12_SYNTHETIC_PROFILE_ANONYMIZATION_ENABLED` → `privacy.synthetic_profile_anonymization_enabled` | Test-harness-only synthetic anonymization gate; implementation additionally requires `APP_ENV=testing`. | Platform-wide config plus hard `APP_ENV=testing` runtime constraint. | `false` on staging; staging is not an authorized test harness. | Read only resolved boolean; separately verify environment identity without exposing `.env`. Never invoke anonymization. | Boolean is not a secret; environment identity must be redacted to approved label. No anonymization or data access intended. | PASS=false; true or unknown is NO-GO. Capture immediately before execution; include environment label and SHA. |

**Read-method qualification:** the future operator must first confirm that the installed Laravel version supports a command that reads only the `privacy` config group (candidate: `php artisan config:show privacy`) and that booting the existing app for this command has no side effects. This command is proposed, not executed or certified on staging. If this cannot be safely confirmed, use a reviewed, fixed read-only platform-console procedure that evaluates only the six resolved booleans; do not add a web endpoint or change the gates. Preserve timestamp, environment identity, exact SHA and operator; only six booleans and PASS/FAIL/UNKNOWN enter the evidence. Do not preserve environment dumps.

All live values at M147-E.1: **UNKNOWN / NOT READ**. Verification has no intended mutation, but the execution method itself requires an approved console and must be shown not to launch tasks, jobs, network requests or destructive actions. If not, BLOCKED.

## Scanner execution sequence

```mermaid
sequenceDiagram
    actor Op as Approved Owner/Operator
    participant UI as Owner/Operator route
    participant Auth as Guard + ability + throttle
    participant Scan as SecurityScanService
    participant DB as Laravel DB / Audit
    participant Vuln as VulnerabilitySource
    participant Queue as Website JobQueue
    participant WP as Paired WordPress Connector
    participant Files as WP files/database (read-only scan)
    participant Ingest as ScanResultIngest / FindingRecorder
    participant Posture as SecurityPosture
    participant Incident as IncidentService
    participant Backup as BackupService / backup queue

    Op->>UI: POST scan request (mutation: scan record)
    UI->>Auth: Owner operate or Operator security.scan; throttle
    Auth->>Scan: authorized Website + ScanType
    Scan->>DB: create scan + audit; check plan/connector/in-progress
    Scan->>Vuln: vulnerability match from stored inventory (may be unavailable)
    Scan->>DB: persist vulnerability findings/coverage
    Scan->>Queue: enqueue SECURITY_SCAN part (queue mutation)
    WP->>Queue: outbound authenticated poll; job delivered (network + DB state)
    Queue-->>WP: fixed allowlisted scan payload (network)
    WP->>Files: inspect configured scanners within root (read-only intended)
    WP->>WP: write local resumable cursor/state (filesystem write possible)
    WP->>Ingest: outbound result with bounded allowlisted evidence (network)
    Ingest->>DB: job/result/findings/coverage/audit writes
    Ingest->>Queue: next scan part if partial (queue mutation)
    Ingest->>Scan: jobFinished() settles scan
    Scan->>Posture: recalculate and persist posture
    Scan->>Incident: finish() evaluates suspicious-file detections
    opt qualifying high-confidence high/critical suspicious file and auto_open=true
        Incident->>DB: create/link incident and timeline (database writes)
        opt auto_backup=true (source default)
            Incident->>Backup: request verified full recovery backup
            Backup->>Queue: enqueue backup job (queue mutation)
            Backup->>WP: later Connector poll/upload (network + filesystem + remote storage possible)
        end
    end
    Note over UI,Backup: Remediation/quarantine is a separate authorized workflow, not part of scan; notifications and live routing must be verified before any E.2 run.
```

| Effect class | Actual scan-path assessment |
|---|---|
| Read-only | Stored site inventory; Connector local scan reads files/DB/config/admin/cron within implementation; exact live scope and host permissions require verification. |
| Database writes | Scan, job delivery/result, coverage, findings, resolution state, posture, audit, and possibly incident/timeline rows. |
| Filesystem writes | Connector scan cursor/state may be written locally; feed sync updates private feed/index files but must not be run in this preflight. Backup later uses local temporary/staging files. |
| Queue mutations | Scan job and subsequent parts; timeout/cancel states; a detection may enqueue a backup. |
| Network | Connector outbound poll/results; WordPress.org checksum/clean-copy calls only in relevant integrity/remediation paths; feed sync to configured Wordfence endpoint; backup storage traffic. No network call was made here. |
| Incident / notifications | `finish()` calls `IncidentService::openForDetection`; qualifying signal can create incident. Notification routing is not asserted from source and must be inspected in live-safe metadata before use. |
| Backup | Auto backup is configured true by default. `BackupService::request` may create backup metadata and queue work; a real backup is not authorized by this task. |
| Quarantine / remediation | Separate route/job and explicit policy; defaults off. Do not test in M147-E.2 without separate approval. |
| Emergency stop | Route-level scan cancel marks scan/job cancelled; it cannot undo completed records, incident or backup effects. No single scan-wide operational stop control was established by this offline review. Stop new requests and cancel only the identified scan after authorization. Deployment traffic gates/rollback are separate and are not scanner controls. |

## M147-E.2 proposal (NOT EXECUTED — OWNER APPROVAL REQUIRED)

1. **Phase 0 — Authorization:** obtain a separate written Level 3 approval naming exact staging host, exact Laravel SHA, exact Connector artifact SHA-256 and installed version, synthetic tenant/site identity, paired Connector ID, approved operation IDs, window, operator, evidence custodian, cleanup owner, stop conditions, and rollback contact. Confirm no customer or production linkage.
2. **Phase 1 — Read-only preflight:** collect the six M12 booleans by the qualified method above; verify app/Connector identity and pairing, source/feed/index state without exposing URL keys, scanner/remediation/incident settings, queue depth and worker health, scheduler state, no active scan, notification sink, storage bucket/prefix identity without reading objects, filesystem/disk capacity and egress controls. Do not run sync/schedule/scan commands during preflight.
3. **Phase 2 — Minimal synthetic scan:** only an Owner-approved disposable site, with a documented fixture manifest, fixed test data and cleanup marker. Begin with a scan type whose expected scanners and effects are explicitly listed. Vulnerability-only scan can establish server-side matching but not Connector scanner availability. Any integrity/malware/full scan requires a verified Connector, bounded time/resource settings, and a safe incident/backup configuration. Do not seed high-confidence suspicious-file input while auto incident/backup can create real staging effects. If config cannot safely bound effects, do not execute that scenario.
4. **Phase 3 — Evidence:** capture correlation ID, scan UUID, exact app/Connector revisions, scanner type/coverage statuses, start/end/duration, posture before/after, counts (not file contents), incident/backup row-count delta, queue delta, relevant redacted log event IDs, network destination metadata, disk use, Connector health, and outcome. No secrets, raw request body, customer content, paths beyond synthetic fixture, or feed key.
5. **Phase 4 — Cleanup/reconciliation:** stop new scan dispatch; wait for or safely cancel only the identified test job; verify queue is settled; remove only marked synthetic records/files through approved, scoped cleanup; do not use global retention/deletion. If a real backup was explicitly authorized, retain it until separately approved disposal. Reconcile any incident and notification; restore temporary scanner config only via recorded, approved change; verify site pairing/version and app/queue/scheduler health. Independent operator checks cleanup and artifact list. If cleanup fails, stop and preserve evidence; do not broaden deletion.

## Connector and deployment compatibility

Repository source currently declares Connector `0.4.7`, package schema 1, API v1; Connector requires WordPress and PHP requirements as documented in `docs/CONNECTOR.md` and its plugin header (PHP 8.1). Server source builds/verifies the immutable package through `php artisan connector:package`; release metadata and package/source fingerprint are checked by deployment. Deployment docs specify PHP 8.4, Composer 2, Node 22, and MySQL for Laravel staging. These are intended requirements, not live observations.

Before E.2: record the target installed WordPress and PHP versions; actual Connector semantic version, API/capabilities, pairing and exact package fingerprint; compare against the Owner-approved source artifact from exact SHA. If mismatch, pairing is absent/revoked, or required scanner capability is absent, NO-GO. Any Connector update, upload, activation, pairing, or deployment requires its own explicit operational authorization. Preserve existing deployment lock, SSH host pinning, origin and exact-SHA controls; this plan does not run deployment scripts.

## Backup, rollback and recovery readiness

The repository documents verified backup upload, checksum/manifest checks, remote S3-compatible/SFTP storage, restore safety backup and preconditions. Deployment procedure takes a pre-migration DB backup and deliberately does not automatically reverse migrations or restore a database. This does not prove that staging has a fresh usable backup, correct isolated destination, enough space, or successful restore capability. No backup/restore occurred.

Separate recovery choices:

* **Stop without mutation:** do not start a scan if any gate is missing; for an in-progress scan, use only the identified cancel route and expect already-written rows/effects to remain.
* **Synthetic-state cleanup:** scoped, marker-owned records/files only; never use global retention/deletion.
* **Application rollback:** only an exact previously approved staging release under the guarded deployment procedure; it does not reverse DB/schema state.
* **Database restore:** separately approved; needs a known backup, verified integrity, worker/scheduler coordination, and migration-effect review.
* **Filesystem restore:** separately approved and path-scoped; must account for connector/package state and customer data.
* **Full environment recovery:** requires verified backup/database/filesystem pairing and tested recovery path. Not established by this offline review.

**Readiness:** recovery is **UNKNOWN**. M147-E.2 is NO-GO until Owner/admin provides fresh, secret-safe evidence of backup recency/integrity, target isolation, restore authority and realistic recovery time, and explicitly approves the applicable recovery action. Do not test restore as a readiness shortcut.

## Feed provenance and SSRF

Repository-supported source is Wordfence Intelligence v2 from an environment-configured HTTPS URL with optional bearer key. Scheduled sync only runs when source is `wordfence` and URL is non-empty; default source is `none`. M147-F07 closed a narrow synthetic implementation scope: redirect disabled, connect/overall timeouts, bounded streaming, content-type/record checks, temp/atomic index logic and preservation for tested failures. The closure explicitly excludes live licensing/provenance/freshness, host allowlisting, private/link-local/IPv6/DNS-rebinding checks, rename failures and concurrent sync tests.

No live feed was contacted. Before E.2, a read-only metadata review must establish lawful feed source and license, exact host allowlist, HTTPS-only scheme, no credentials in URL, DNS/IP policy including IPv4/IPv6 private/link-local/metadata ranges, redirect behavior, configured maximum age, last successful sync timestamp and last-known-good index status. Do not reveal URL query secrets or feed keys. The current implementation disables redirects and verifies HTTPS scheme, but source review alone does not show it rejects every private resolved address or DNS rebinding. If that guarantee is required and not proven by existing code/tests, mark the feed-dependent scanner operation BLOCKED; do not assume Wordfence's domain makes arbitrary DNS resolutions safe.

## Go / No-Go

**GO requires every item:** exact target, app SHA, Connector artifact/fingerprint, named synthetic tenant/site and pairing approved; all six M12 gates freshly false; no customer/production linkage; feed lawfulness/freshness known or vulnerability coverage explicitly unavailable; scanner settings and side effects bounded; notification sink known; no uncontrolled remediation/quarantine; backup/recovery ready for any requested backup effect; storage/disk/network controls confirmed; synthetic fixtures and scoped cleanup approved; evidence custody agreed; no active work conflict; and separate written Owner Level 3 approval for exact operations/window.

**NO-GO:** any M12 value true/unknown/unreadable; identity ambiguity; production/customer link; Connector fingerprint mismatch; unknown active queue/scheduled scan; unbounded incident/backup/notification effect; feed destination/provenance uncertainty for a feed-dependent scan; insufficient capacity; no credible recovery; secret exposure; cleanup ambiguity; critical unresolved concern; or missing explicit Level 3 authorization. Do not relax controls to convert NO-GO to GO.

## Current milestone status

M147-A/B/C/D are closed only within their recorded scopes. This document records planning only. M147-E operational validation has not started; M147-F has not started; M14.8 is not ready. M14.7 implementation progress remains an estimated **~65%**, not a security-assurance score; E.1 planning does not count as scanner implementation or execution. AS-16/M147-F01, M147-F02, F03 and F04 remain open (see findings register). This readiness plan neither closes a finding nor claims scanner coverage.
