# M147-D Attack-Path Security Validation — Closure Report

**Status: CLOSED for the bounded, local Level 2 synthetic validation described here.** This closure records evidence for selected implemented paths. It is not a comprehensive penetration test, proof that untested paths are secure, production scanner validation, or authorization for any operational action.

## Executive summary

M147-D reviewed the implemented authentication, authorization, tenant ownership, Connector, Stripe webhook, backup/restore, security incident/remediation, outbound HTTP, browser-route, AI configuration, and operational-control boundaries. The source-grounded inventory was prepared before tests. Existing deterministic Laravel suites were reused; no new test harness or runtime application change was required.

The selected local synthetic suites completed with **471 passed, 12 skipped, 0 failed from 483 selected tests and 5,635 assertions** on Windows/PHP 8.4.26. The exact PR head and merged-primary commit each passed all seven required CI jobs. The mandatory Server MySQL process/concurrency gate passed on both runs. No new Critical or High issue was reproduced in the tested paths. Partial and untested scenarios remain listed below and in the execution evidence.

The M147-D work is closed only for this bounded validation scope. AS-16 and the specifically open findings below remain open. No staging, production, customer-site, live-feed, live-payment, live-AI, real backup/restore, destructive, or deployment validation is implied.

## GitHub and release evidence

| Evidence | Value |
|---|---|
| Repository / primary | `zsoltacsai/wpcaregrid` / `claude/ecstatic-wozniak-oemyuf` |
| Baseline primary SHA | `b392c5c3f4a934886b574e59ceb67d1dfe04c894` |
| Implementation PR | [#125](https://github.com/zsoltacsai/wpcaregrid/pull/125) |
| Final PR head | `28f92ee4238efad64345eba944531d652e194963` |
| Exact-head CI | [39122868939](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39122868939), success on the exact PR head |
| Merge commit / final primary at this closure | `bc0a4e536abfd573b5b34d5e2152dcf15ad80c80` |
| Merge timestamp | `2026-10-18T11:51:24Z` |
| Merged-primary CI | [39124920641](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39124920641), success on the exact merge commit |

The active repository ruleset `Authoritative primary release controls` (ID `25300175`) targeted the deployed primary branch. It required the PR path, all seven required status contexts, conversation resolution, and blocked force-push and branch deletion. No bypass actors were configured. The ruleset required zero approving reviews under the repository's single-maintainer arrangement; this does not count as an independent review. The merge used the repository's merge-commit method and did not bypass the ruleset.

### Required exact-SHA CI results

All seven jobs passed on PR head `28f92ee4238efad64345eba944531d652e194963` in run `39122868939`, and on merge commit `bc0a4e536abfd573b5b34d5e2152dcf15ad80c80` in run `39124920641`:

1. Quality (lint, style, dependencies, build, secrets) — PASS.
2. Server tests (PHP 8.4, sqlite) — PASS.
3. Server tests (PHP 8.4, mysql) — PASS.
4. Server tests (PHP 8.4, mariadb) — PASS.
5. Connector tests in WordPress (PHP 8.4, sqlite) — PASS.
6. Connector tests in WordPress (PHP 8.4, mysql) — PASS.
7. Connector tests in WordPress (PHP 8.4, mariadb) — PASS.

The internal **Required MySQL process-concurrency gate** completed successfully in the Server MySQL job on both exact-head run `39122868939` and merged-primary run `39124920641`. The job-step evidence was read from the GitHub Actions job records; this claim is not inferred from the parent job result alone.

The merged-primary SHA is the merge commit from PR #125. No additional implementation or dependency changes entered the merge.

## Local test evidence

Local environment: Windows worktree, PHP 8.4.26, committed Composer lockfile, Node 22, Vite 8.3.4, PHPUnit feature suites. Local Laravel requests used synthetic records and test fakes. Dependency installation/build was performed from committed lockfiles; manifests and lockfiles did not change.

| Suite | Outcome | Assertions / skips |
|---|---:|---:|
| `tests/Feature/Security` | 102 / 102 passed | 1,778 assertions |
| `tests/Feature/Connector` | 73 / 73 passed | 424 assertions |
| `tests/Feature/Backups` | 112 passed / 121 selected | 1,085 assertions; 9 skipped |
| `OwnerAccessTest|OperatorSecurityTest` | 25 / 25 passed | 1,176 assertions |
| Selected Stripe webhook, isolation, reconciliation, concurrency, lifecycle, checkout | 116 passed / 119 selected | 707 assertions; 3 skipped |
| AI provider health and Owner credential | 21 / 21 passed | 280 assertions |
| `BillingSnapshotTest` | 22 / 22 passed | 185 assertions |
| **Total** | **471 passed / 483 selected** | **5,635 assertions; 12 skipped; 0 failures after prerequisites** |

The 9 backup skips include MySQL/PCNTL-specific restore process concurrency and platform/service-dependent cases. The 3 Stripe-group skips were not treated as passes. The Windows SQLite tests do not prove multi-process restore concurrency; the required MySQL CI gate validates its own defined concurrency behavior, not every skipped restore case.

An initial parallel local run before the Vite manifest was built had view-manifest failures and compiled-view cache contention. After building the local assets, focused suites were rerun serially and completed as recorded above. A separate full-project PHPUnit run did not complete because the Windows environment lacked the Unix `command -v moto_server` discovery command. It is not counted as a pass. No live feed, payment, AI provider, customer site, staging/production host, or remote storage was contacted by the test execution. OS-level egress blocking was not separately measured.

## Attack-path coverage reconciliation

The full source-grounded inventory and scenario matrix are in [M147-D execution evidence](m14-7-d-execution-evidence.md). Coverage below is limited to the specific assertions in the referenced existing tests.

| Category | Classification | Evidence and remaining limits |
|---|---|---|
| Guest/customer/Operator/Owner boundaries; selected Operator abilities and CSRF | TESTED — PASS (selected cases) | `OwnerAccessTest`, `OperatorSecurityTest`, route-boundary tests. Not every route/method, stale session, account-state transition, or ability combination was exercised. |
| Cross-tenant website and nested backup IDOR; rejected operation state | TESTED — PASS (selected cases) | `BackupSecurityTest`, `AccountBackupsTest`, selected security visibility tests with synthetic tenants and fake queues/storage. Not all exports, billing surfaces, pagination, caches, or resource mutations were covered. |
| Connector pairing/authentication, wrong-site credentials, selected revoked/invalid IDs and job/backup scope | TESTED — PASS (selected cases) | Pairing, Connector API, Jobs and backup security tests. No blanket signed-message or nonce protocol exists; timestamp/reordering and replay resistance across all operations remain untested. |
| Connector duplicate/unknown job result behavior | TESTED — PASS (selected operations) | Job state/idempotency tests. This does not generalize to every Connector operation or prove all disconnect races. |
| Synthetic Stripe signature, duplicates and selected subscription transitions | TESTED — PASS (selected cases) | Local synthetic webhook tests; no Stripe API call. Full event ordering, all event types, and every conflict are not covered. |
| Backup/restore authorization, site binding, preconditions, selected queue/storage isolation | PARTIALLY TESTED | Fake storage/queues and disposable DB in relevant suites. Nine local cases skipped; no real restore; some process-concurrency evidence is environment-specific. |
| Incident, scan and remediation side effects | PARTIALLY TESTED | Selected synthetic incident/security/remediation tests. Retry/race/notification/queue combinations and quarantine/remediation side-effect matrix are incomplete. M147-F04 remains open. |
| Outbound URL/SSRF | PARTIALLY TESTED | Site URL validation and M147-C fake feed transport tests cover selected invalid URLs, redirects and response limits. DNS rebinding, IPv6/link-local/metadata variants and exhaustive redirect destination checks remain untested. M147-F03 remains open. |
| XSS / hostile rendering | PARTIALLY TESTED | A security finding title is asserted as escaped text. Other Blade contexts, DOM/JavaScript insertion, notifications, diagnostics and cross-origin browser behavior were not tested. |
| CSRF / route policy | TESTED — PASS (selected route assertions) | Runtime route boundary plus Owner/Operator tests. No browser-based cross-origin campaign. |
| AI provider boundary | PARTIALLY TESTED; inference NOT APPLICABLE to inspected M14.5 implementation | Owner-only credential/health tests use synthetic transport. No inference client or action path was identified in the inspected implementation; no live AI call occurred. |
| Deployment, shell, environment and host controls | PARTIALLY TESTED — SOURCE REVIEW ONLY | Web route/controller inventory only. No server, SSH, deploy, or host privilege validation occurred. |
| Browser E2E, all password reset/activation misuse, all mass assignment/session variants, exhaustive exports/pagination/cache | NOT TESTED as a complete campaign | Existing selected unit/feature cases do not establish complete attack-path coverage. No browser was started. |

No relevant scenario is reclassified as fully tested solely because the CI matrix passed. The suite provides regression evidence for the tested code paths, not comprehensive penetration-test assurance.

## Findings and disposition

No new confirmed Critical or High issue was reproduced within the completed synthetic test scope. No runtime fix was indicated; PR #125 contains documentation/evidence updates only, with no application code, test, workflow, dependency, or configuration change.

| Finding | Status at M147-D closure | Boundary |
|---|---|---|
| AS-16 / M147-F01 | OPEN | No completed operational scanner campaign. |
| M147-F02 | OPEN / UNKNOWN | All six live M12 values remain UNKNOWN; not read or changed in this work. |
| M147-F03 | OPEN | Live feed provenance/licensing/freshness/runtime configuration and untested SSRF/DNS cases remain unknown. |
| M147-F04 | OPEN | Incident/backup/queue/notification/quarantine/remediation/retry/concurrency combinations are not exhaustive. |
| M147-F05 | OPEN | Browser and cross-origin integration validation not performed. |
| M147-F06 | OPEN | Independent-review residual: no separate qualified reviewer is claimed. |
| M147-F07 | NARROWLY CLOSED | Only the previously validated bounded feed-handling sub-scope; no live-feed claims. |
| M147-C-02 | CLOSED | False Healthy with incomplete scanner coverage was fixed and validated in M147-C. |
| M146-F08 | NARROWLY CLOSED | Only the audited tracked secret-path checker output path. |
| M147-F08 | OPEN / FUTURE CHECKPOINT | External independent penetration test remains an M17 checkpoint; no independent test is claimed. |

These dispositions do not close AS-16, establish a live scanner result, verify live M12 state, or claim a penetration test.

## Authorization and side effects

Performed within the Owner's M147-D Level 2 approval: local source review; synthetic Laravel feature testing; local disposable/test database records; mocked HTTP, Stripe signatures, queues and storage; focused evidence and documentation. Existing tests were reused. No new regression test was added because no in-scope defect was reproduced that required one.

Explicitly not performed: staging/production/customer-site access; live feeds, secrets, Stripe or AI calls; real payments; real backup/restore, incident, quarantine, remediation or notification; deployment; infrastructure changes; M12 gate read/change; browser-based tests; destructive operations. No Level 3 activity occurred.

## Milestone status and handoff

| Stage | Status |
|---|---|
| M147-A | CLOSED — PR #120 and its merged-primary evidence remain authoritative. |
| M147-B | CLOSED — PRs #121/#122 and their closure evidence remain authoritative. |
| M147-C | CLOSED — PRs #123/#124; bounded synthetic security-engine scope. |
| M147-D | CLOSED — bounded local synthetic attack-path validation; this report and PR #125 evidence. |
| M147-E | NOT STARTED — separate Owner authorization required. |
| M147-F | NOT STARTED — findings/retests remain; separate Owner authorization required. |
| M14.7 | Approximately **65% implementation progress** (estimate, not security assurance); residual operational and browser validation remains. |
| M14.8 | **NOT READY** — M14.7 residual findings and future stage evidence remain. |

No new Owner decision was required to close the bounded M147-D scope. Before any future staging scanner validation, a separate operational authorization and a fresh secret-safe read-only check of all six M12 gates are required; any unknown/unreadable/enabled value stops that operation. M147-E/F and M14.8 have not begun. Wait for explicit Owner direction before further work.
