# M15 evidence reconciliation — 2026-10-25

**Status:** Thread 1 local evidence draft for Thread 4 integration. M15 remains proposed; this record does not approve an operational pilot or alter M14 status.

**Prepared:** 2026-10-24 22:01 UTC; refreshed against the current authoritative branch.  
**Worktree:** `codex/m15-pilot-readiness`, rebased to `46540072842a4e876c2af2f1eec200945be627f1`.  
**Staging actions by this workstream:** none.

## Evidence register

| Claim | Classification | Evidence and limits | Required next evidence |
|---|---|---|---|
| Staging identity/runtime snapshot | Owner/ledger-reported, timestamped 2026-10-24 20:31 UTC | Issue #174 records `stg1.wpcaregrid.com`, app SHA `761e0e5d37558da808ac961ca2d2a68b4ffab0db`, clean checkout, maintenance gate inactive and `/up`=200. This was already behind then-current docs-only primary; it is not an independently executed check by Thread 1. | Refresh only under an approved, non-overlapping, operation-specific read-only scope if needed for a later pilot. Capture exact time, target, SHA, CLI/FPM/worker versions and sanitized source evidence. |
| Staging PHP CLI and PHP-FPM | Cross-thread reported observation: PHP 8.4.27 for both; **timestamp/source output unavailable in this thread** | Thread 4 instructed M15 to record an observed 8.4.27 CLI/FPM pair. No command output, package build, exact observation timestamp, or evidence artifact was provided in issue #174 as of this review. Treat as reported, not independently verified here. | Attach sanitized, timestamped readback and package provenance; record CLI, FPM, workers and scheduler separately. |
| PHP-FPM migration window | **UNKNOWN / no exact window published** | Issue #174 reserves the staging runtime-change slot for Hotfix & Emergency, but no exact UTC start/end or completion/unlock notice was present in the ledger reviewed. PR #175 is merged; its CI merge run was reported as still running by Thread 4. The GitHub connector returned 404 when queried directly for the merge SHA checks, so this workstream has no fresh exact-merge CI confirmation. | Thread 4 must publish the exact exclusive UTC window and release it after completion. No M15 staging activity may overlap the reservation. Verify exact merge-SHA CI before any runtime action. |
| Connector reported offline | **UNKNOWN / target unresolved** | Master roadmap carry-forward does not identify a Connector UUID, site or measurement time. The M14 scan used an active Connector for `kiszov-szeged.hu` at 2026-10-24 12:59 UTC; that historical, site-specific observation neither disproves another offline Connector nor proves current presence. | Identify exact Website/Connector pair and owner consent; acquire a fresh, explicitly authorized observation before any repair or poll. |
| Backup freshness | **STALE warning / current target UNKNOWN** | Master roadmap says no backup was verified inside the documented seven-day window, but the carry-forward lacks site, backup ID, timestamp and integrity output. Checklist rows about the platform's own backup/storage are repository status, not proof of a pilot-site backup. | Identify exact target and backup; verify provenance, age, manifest/checksum and destination isolation, then require an isolated restore rehearsal before site-changing operations. |
| M14 Integrity coverage | **Historical measured result; incomplete** | Owner evidence for scan `01a16dfa-afed-735f-a4fd-2eafcd0a8a3d` on `kiszov-szeged.hu`: completed 2026-10-24 12:59:14–12:59:24 UTC; 5,930 verified, 0 modified, 0 extra, 1 missing, 4 unavailable, 0 findings. It is not Malware coverage or a malware-free statement; per-path exceptions and historical notification delta remain unknown. | Preserve as historical M14 evidence. Do not repeat solely to improve the status; triage exceptions only if separately scoped and justified. |
| Operator UI verification | **NOT VERIFIED** | M14 final audit says authenticated visual verification was not completed after the browser session expired. | Separately authorized authenticated UI observation, exact role/tenant scope and sanitized evidence. |
| Pilot-host compatibility | **PARTIAL** | One historical site observation records WordPress 7.1.3 / customer-hosted PHP 8.2.35. It does not establish all operations, database/hosting characteristics or compatibility after a runtime change. | Exact target's current PHP, WordPress, DB and capability snapshot, then operation-specific compatibility evidence. |
| Monitoring / recovery | **NOT READY / live state UNKNOWN** | Repository checklist marks uptime and system/failed-job/connector/backup monitoring not configured; staging values were not read by Thread 1. Restore rehearsal remains not ready in the checklist. | Establish named observer, signals/thresholds/window, escalation, backup integrity, isolated restore and recovery acceptance evidence. |

## M15-02/03/04 source and test review

### Per-site authorization (M15-02)

`WebsitePolicy` binds customer actions to owned Websites; pairing and backup/restore also enforce subscription/entitlement and capability checks. Pairing codes are single-use, expire, are stored hashed, and are tied to a Website. The reviewed app/routes/migrations/tests contain no distinct pilot-consent record. Account entitlement is not the same as consent to a bounded pilot operation. Future authorization should bind exact Website UUID, Connector UUID, actions, time window, exclusions, and stop authority; do not create substitute/synthetic identity or re-pair as a status probe.

Relevant suites inspected: `tests/Feature/Connector/AccountConnectorTest.php`, `PairingTest.php`, `ConnectorApiTest.php`.

### Connector lifecycle (M15-03)

The authenticated API scopes a Connector credential to its paired Website. Heartbeat persists presence/version/site facts; inventory persists site inventory; `/jobs` polls can deliver queued work and update last-poll/job state; result ingestion persists state and applies health/inventory/license data. Job delivery may advance backup, restore, scanner or remediation lifecycle state. Thus heartbeat/poll/result are mutating operations and are not a read-only liveness check. Normal poll delivery has no demonstrated “only the explicitly approved job” guarantee in this evidence review; if queued work cannot be safely bounded, a live poll must remain blocked.

Existing coverage inspected: Connector auth/site mismatch, heartbeat/inventory validation, paired-site jobs and entitlement/capability denials, duplicate result handling, interrupted/revoked/concurrent Connector polls. Relevant suites: `tests/Feature/Connector/ConnectorApiTest.php`, `JobsTest.php`, `PairingTest.php`, `wordpress/tests/Integration/JobsTest.php`, `InventoryTest.php`.

### Backup/recovery (M15-04)

`BackupService::request` creates backup/job/audit records; the Connector reads and packages site files/database, uploads chunks, and the transfer worker writes to configured storage and verifies size/hash before a backup is marked verified. Restore is a site mutation and is gated by a verified safety backup plus additional policy/capability checks. A backup object existing does not establish restore readiness.

Existing local coverage inspected: tenant/site binding, authorization, upload integrity, verified-state transitions, storage failure, restore prerequisite, compensation and interruption cases. Relevant suites: `tests/Feature/Backups/BackupFlowTest.php`, `BackupSecurityTest.php`, `PreUpdateAndRestoreTest.php`, `RestoreRequestConcurrencyTest.php`; Connector archive/restore suites under `wordpress/tests/Integration/`. None proves a current target's remote backup or an isolated staging restore rehearsal.

## Coordination and release evidence

- PR [#175](https://github.com/zsoltacsai/wpcaregrid/pull/175) is merged at `46540072842a4e876c2af2f1eec200945be627f1`. Its exact-head run [40132309733](https://github.com/zsoltacsai/wpcaregrid/actions/runs/40132309733) and merge-SHA run [40133739348](https://github.com/zsoltacsai/wpcaregrid/actions/runs/40133739348) are recorded by Thread 4 as 40/40 PASS. The run’s required PHP 8.4/MySQL and PHP 8.5/MySQL process-concurrency gates are reported PASS. The GitHub connector job listing independently exposed those two MySQL jobs and gate steps as successful; later direct query returned 404, so the 40/40 total relies on the Thread 4 run record.
- The authoritative branch currently points to `46540072842a4e876c2af2f1eec200945be627f1` (verified from the GitHub branch API). The local worktree was fetched and rebased to that exact commit. The merge adds CI/documentation only; no runtime deploy or PHP migration occurred.
- Thread 4 reports staging CLI PHP 8.4.27, active `php8.4-fpm`, and `/up`=200; these are cross-thread read-only observations, not independently executed by M15. No exact command-output artifact/time is present in this worktree. Package signing provenance, scheduler interpreter and legacy PHP 7.4 use remain UNKNOWN.

- PHP-FPM cutover is exclusively reserved to Hotfix & Emergency until Thread 4 posts the exact UTC window and completion/unlock. M15 must record every approved staging observation with UTC timestamp and pre-/post-cutover label, including Laravel CLI and PHP-FPM PHP versions.

## M15-03 implementation finding — inventory freshness

Source review found that `InventoryIngest::store()` saved Connector-supplied compatibility facts and refreshed `CapabilitySnapshot` at server receipt time, despite the Connector providing `collected_at`. A delayed or replayed inventory could therefore make old disk/filesystem/runtime facts pass the snapshot freshness TTL and permit a high-impact operation to proceed on stale evidence. The Connector inventory collector emits `gmdate('c')`; inventory validation already accepts this timestamp.

The local candidate now uses the collector timestamp for compatibility evidence. Missing or future timestamps receive a deliberately stale timestamp, and `CapabilitySnapshot::isFresh()` rejects future timestamps as a second guard. Existing inventory remains stored for diagnostic continuity. Added regressions cover delayed inventory refusing an update without creating a job, and missing/future timestamps not refreshing readiness. The shared test inventory fixture now emits the production Connector timestamp.

Validation so far: `php -l` passed for all four changed PHP files; `git diff --check` passed. Focused PHPUnit and Pint were attempted with local PHP 8.4.25 but stopped before tests because the local runtime lacks `mbstring` (PHPUnit additionally requires DOM/XML extensions). WSL invocation returned `Wsl/E_ACCESSDENIED`. No tests are claimed as passed; exact-head CI is required.

## M15 disposition

M15 remains **PROPOSED / NOT CLOSED**. No target consent, current Connector presence, current verified backup, isolated restore proof, complete compatibility, monitoring period, or current staging runtime package evidence is established in this workstream. No staging or customer operation was performed. M14 remains preserved as CLOSED within its recorded scope.
