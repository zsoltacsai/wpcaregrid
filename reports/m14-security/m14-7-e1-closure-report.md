# M147-E.1 Formal Closure — Staging Readiness Plan

**Decision: CLOSED — PLANNING ONLY.** This closure records offline source review and a future operational proposal. It is not staging validation, a staging access grant, or authorization for M147-E.2.

## Scope and authorization

M147-E.1 was limited to repository/documentation review and planning under Level 2 authorization. No staging or production connection, live M12 read, secret/configuration read, feed request, scanner execution, queue execution, Connector installation/pairing, incident, notification, backup/restore, deployment, or customer-site activity occurred. No runtime, workflow, infrastructure, or operational setting changed.

## GitHub evidence

| Item | Evidence |
|---|---|
| Primary before planning | `57b8cf02d655cb7316648785bd9a1b6b07b148e1` |
| Planning PR | [#127](https://github.com/zsoltacsai/wpcaregrid/pull/127), documentation-only, target `claude/ecstatic-wozniak-oemyuf` |
| Final PR head | `def689fb4ff0ace9e478335eb15944bbcaec5f2c` |
| Exact-head CI | [Run 39129828668](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39129828668), attempt 3, exact head, 7/7 successful |
| Merge | Squash merge `f0bdbf5ad4c06160613fc5283a779707da28aea0`, 2026-10-18 13:22:23 UTC |
| Final primary | `f0bdbf5ad4c06160613fc5283a779707da28aea0` |
| Exact merged-primary CI | [Run 39131255646](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39131255646), exact merge SHA, 7/7 successful |

The primary ruleset `Authoritative primary release controls` was active for `claude/ecstatic-wozniak-oemyuf`; it required the PR path, all seven stable checks, conversation resolution, and prohibited deletion/non-fast-forward updates. The ruleset showed no bypass actors and the current user could not bypass. The PR was merged through GitHub's protected merge API with the expected head SHA. No direct push or override was used.

### Required jobs

| Required check | PR-head attempt 3 | Merged-primary run 39131255646 |
|---|---:|---:|
| Quality (lint, style, dependencies, build, secrets) | PASS | PASS |
| Server tests (PHP 8.4, sqlite) | PASS | PASS |
| Server tests (PHP 8.4, mysql) | PASS | PASS |
| Server tests (PHP 8.4, mariadb) | PASS | PASS |
| Connector tests in WordPress (PHP 8.4, sqlite) | PASS | PASS |
| Connector tests in WordPress (PHP 8.4, mysql) | PASS | PASS |
| Connector tests in WordPress (PHP 8.4, mariadb) | PASS | PASS |

The Server MySQL job's `Required MySQL process-concurrency gate` step completed successfully on both exact heads. The same gate was appropriately skipped in the SQLite and MariaDB Server jobs.

### Connector MariaDB CI reliability observation

On PR head `def689fb4ff0ace9e478335eb15944bbcaec5f2c`, the Connector MariaDB job failed in attempts 1 and 2, then passed in attempt 3 without a source change. Both failures were timeouts waiting for a subprocess synchronization file in `PollLockConcurrencyTest::test_expired_owner_cannot_release_or_refresh_successor_lease` (`wordpress/tests/Integration/PollLockConcurrencyTest.php:505`). Each failed run reported 210 tests, 1 failure and 3 skips; assertion counts differed (1,809 and 1,824). Evidence: [attempt 1](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39129828668/attempts/1), [attempt 2](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39129828668/attempts/2), and successful [attempt 3](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39129828668/attempts/3).

The logs establish repeated test-process synchronization timeouts and a later pass on unchanged code. Runner resource pressure or timing sensitivity is plausible, but not proven. The evidence does not establish a deterministic product defect and does not justify calling the failures harmless. CI correctly blocked the first two attempts. This remains tracked as **CI-REL-01**, an open, non-blocking CI reliability/concurrency-harness issue. It does not alter the E.1 planning result because exact-head attempt 3 and merged-primary CI both passed all seven checks.

## Deliverables reviewed

The planning PR added:

- `m14-7-e1-readiness-plan.md` — repository architecture map, inventory, M12 mapping, sequence diagram, E.2 proposal, recovery distinctions and go/no-go criteria.
- `m14-7-e1-staging-runbook.md` — proposed, explicitly unexecuted future procedure, evidence format and stop conditions.
- `m14-7-e1-risk-matrix.md` — operation-level effects, blast radius, preconditions, stops, cleanup and approvals.
- `m14-7-e1-owner-approval-checklist.md` — exact-target and operation-specific future authorization checklist.
- Updates to the M14.7 plan, test matrix, findings, Owner decision register and `docs/PROJECT_STATUS.md`.

The documents preserve prior M147-A/B/C/D closure evidence and distinguish repository source facts from live state. No operational validation is claimed.

## Scanner side effects and blast radius

Source review maps scan authorization through Owner/Operator routes into `SecurityScanService`, scan records and Connector jobs. The Connector polls using its website-bound credential and handles allowlisted scan jobs; results return to Laravel, where findings, audit and posture state are persisted. Scan completion invokes incident handling. Repository defaults enable incident auto-open and auto-backup; qualifying findings can open incident/timeline records and request a full backup. Queue work, remote storage writes and notifications may follow depending on live configuration. Remediation and plugin deactivation are separate paths whose repository defaults are off, but their effective staging state is unknown.

Accordingly, “scanner” is not treated as read-only. E2-02 and suspicious-file operations remain blocked until configuration, queue, storage and notification effects are known and bounded. Incident creation, backup object writes, notification dispatch, cleanup/deletion, remediation, quarantine and plugin deactivation require distinct approvals. The E.2 proposal does not authorize a blanket scan or side-effect set.

## M12 gates

The source mapping in `config/privacy.php` was reviewed. All live values remain **UNKNOWN**; none was read or changed.

| Gate | Config key | Live value | Future safe result |
|---|---|---|---|
| `M12_DESTRUCTIVE_RETENTION_ENABLED` | `privacy.destructive_retention_enabled` | UNKNOWN | false |
| `M12_PROFILE_ANONYMIZATION_ENABLED` | `privacy.profile_anonymization_enabled` | UNKNOWN | false |
| `M12_BACKUP_DELETION_ENABLED` | `privacy.backup_deletion_enabled` | UNKNOWN | false |
| `M12_PROVIDER_DELETION_ENABLED` | `privacy.provider_deletion_enabled` | UNKNOWN | false |
| `M12_BULK_RETENTION_EXECUTION_ENABLED` | `privacy.bulk_retention_execution_enabled` | UNKNOWN | false |
| `M12_SYNTHETIC_PROFILE_ANONYMIZATION_ENABLED` | `privacy.synthetic_profile_anonymization_enabled` | UNKNOWN | false |

Source defaults do not prove effective staging values. Future evidence must identify environment, runtime SHA, timestamp and operator and contain only the six boolean values. Any true, unknown, stale, unreadable, wrong-environment or ambiguous result means NO-GO. The proposed Artisan read is only a candidate; its output scope and side-effect-free behavior must be established before any separately authorized use. No endpoint or new live verification command was created.

## Connector and staging prerequisites

The repository declares Connector `0.4.7`, package schema 1 and API v1. These are source facts, not proof about staging. Before E.2, an Owner-approved read-only preflight must identify the exact installed artifact, source revision, package fingerprint, API/capability metadata, active pairing and WordPress target. The application SHA, PHP/DB versions, worker/scheduler state, scanner and feed configuration, site ownership, egress policy, notification routing, storage isolation, disk headroom and authorized operator also remain to be verified live. No Connector was built, uploaded, installed, activated or paired in E.1.

## Backup, recovery, feed and SSRF limits

The deployment source documents exact-SHA guarded code rollback and a pre-migration database backup; it does not automatically restore the database or reverse migrations. Stopping a scan, cleaning exact synthetic rows/files, code rollback, database restore, filesystem restore and full environment recovery are distinct actions. Backup existence alone does not prove restore capability. Live backup freshness, isolation, capacity and recovery are unknown. E.2 remains NO-GO until the recovery path appropriate to each approved operation is evidenced.

M147-F03 remains open. The repository supports Wordfence feed configuration, but E.1 did not contact it or verify license, authorization, source provenance, effective runtime URL/key, freshness or live network controls. The prior M147-F07 disposition remains limited to its synthetic/code-tested scope. Live host allowlisting, private/link-local and IPv6 treatment, DNS rebinding, production redirects, licensing, feed availability and runtime freshness remain unverified. Feed failure or insufficient coverage must not be described as CLEAN.

## Go / No-Go and status

M147-E.2 remains **NO-GO / NOT AUTHORIZED**. GO would require a separate written Owner Level 3 approval identifying the exact staging environment and application SHA; synthetic WordPress target; Connector artifact/fingerprint/pairing; named operator and window; individually approved operation IDs; fresh false results for all six M12 gates; customer/production isolation; known effective scanner/feed, queue, scheduler, notification and storage state; sufficient resources; credible action-specific recovery; scoped cleanup; and secret-safe evidence. Any unknown prerequisite stops execution. Planning completion does not satisfy these live gates.

| Stage | Status after E.1 closure |
|---|---|
| M147-A / B / C / D | CLOSED within documented scope |
| M147-E.1 | CLOSED — PLANNING ONLY |
| M147-E.2 | NOT AUTHORIZED / NOT EXECUTED |
| M147-F | NOT STARTED |
| M14.7 | Approximately 65% estimated delivery progress; not a security score |
| M14.8 | NOT READY |

Open findings include AS-16/M147-F01, M147-F02, M147-F03, M147-F04, M147-F05, M147-F06, M147-F08, and CI-REL-01. M147-F07, M147-C-02 and M146-F08 remain closed only within their previously documented boundaries. M147-F04 remains open; incident/backup side-effect combinations are not exhaustively validated.

## Owner handoff

The recommended next proposal is **M147-E.2a — Staging Read-Only Identity and Safety Preflight**, separately authorized and limited to exact staging identity, deployed app SHA, synthetic WordPress identity, Connector artifact/pairing, six M12 boolean values, effective scanner/feed state, queue/scheduler state, notification routing, storage isolation, disk space and recovery evidence inventory. Any future approval must name the permitted read-only commands/interfaces, target, evidence fields, operator, time window and stop criteria. It must not imply authorization to scan, write backup objects, create incidents, change configuration or deploy. Until that distinct approval is given, do not connect to staging.

**Formal result:** M147-E.1 closed as documentation and offline readiness planning only after protected PR merge and exact merged-primary 7/7 CI. No M147-E.2, M147-F or M14.8 work started.
