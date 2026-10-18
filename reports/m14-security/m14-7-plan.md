# M14.7 Security Validation and Attack-Path Plan

**Status (2026-10-18):** M147-A is closed by merged PR #120 at `a84d03146ac3ba0ba8c47301f61be41052903905`; merged-primary CI run `39105703168` passed all seven required jobs, including the Server MySQL process/concurrency gate. M147-B received separate Owner Level 2 approval and its local source review, synthetic hostile-filename regression, and documentation are complete on PR #121. Final PR head `7d77311f123dbe7913d46abf77b4f0fe300c975e` passed all seven required jobs in exact-head CI run `39108485648`, including the MySQL process/concurrency gate. At this revision, PR #121 is still open and unmerged; formal M147-B closure awaits protected merge and exact merged-primary CI. M146-F08 is approved for scoped closure for the audited tracked secret-path checker output path; broader runner-command handling is not claimed exhaustively tested. No scanner/browser execution, staging, production, customer-site access, live feed, real credentials, real side effects, privileged operations, or destructive operations are authorized. The earlier statement that planning made no implementation remains a historical description of that planning pass.

**Owner authorization update:** OA-01 through OA-05 and the separate M147-B approval are recorded in [the Owner decision register](m14-7-owner-decisions.md). M147-A is complete. M147-B Level 2 work is complete, with protected integration pending. M147-C and later stages, scanner/feed validation, browser-test execution, and additional implementation outside B are not included. Staging, live feed configuration, secrets, real side effects, Stripe TEST infrastructure, production/customer access, privileged operations, and destructive operations require separate authorization. Level 2 approval does not grant Level 3.

**Execution boundary:** M147-A's disposable local synthetic fixture and M147-B's approved local source/workflow analysis and synthetic filename regression were executed within their respective approvals. Fake-feed tests, scanner execution, browser tests, real application side effects, and all staging/production/customer activity remain outside the completed M147-B work. Staging is not authorized for scanner execution. Any future staging scan needs separate approval of the exact target, Connector pairing, allowed operations, and cleanup, plus a fresh secret-safe read-only check confirming all six M12 gates are false. That fresh six-gate check has **not yet occurred**; unknown, unreadable, or enabled values must stop the operation and must never be changed to proceed.

**Planning baseline:** b0285d36a7aa0dbfe7281282ce8c9c6116c3eb3f on claude/ecstatic-wozniak-oemyuf, verified against the public repository and successful primary CI run 39074237108 (7/7; Server MySQL includes the process/concurrency gate). The planning worktree is based on this commit. This is repository planning evidence, not a fresh runtime/staging readback.

**M147-A baseline:** planning PR #119 merged at `0a0d25c2da256015d5cffad5f419603593ed72b5`; merged-primary CI run `39098281556` passed 7/7. See [the source baseline](m14-7-baseline.md), [the local fixture and harness instructions](../../docs/M14_7_LOCAL_FIXTURE.md), and the M147-A status below. No scan or staging validation is claimed.

## Executive summary

M14.7 is the P0 security-validation work item M14-15 and AS-16 carry-forward. It is an integrated, evidence-led AppSec campaign: exercise the high-impact trust boundaries already identified in M14, complete a truthful security-scanner/feed validation on an authorized isolated target, resolve or disposition the M14.6 log-integrity validation gap M146-F08, and publish a reviewed findings/closure package. It does not authorize new autonomous remediation or destructive behavior.

The security engine already has source-level components for Wordfence vulnerability-feed import and inventory matching, Connector-reported integrity and suspicious-file checks, scan state/coverage, operator-reviewed findings, incident lifecycle, and gated remediation. These components have synthetic unit/feature tests. They do not establish that a current deployed scanner/feed is configured, that a real scan completed with meaningful coverage, or that the attack-path matrix has passed end to end. The older staging warning “No completed security scan yet” remains an open evidence item; no clean-scan conclusion is supported.

Planning does not inspect the staging host, read credentials, run an external scanner, trigger a scan, or read live M12 values. The latest available M14.6 evidence explicitly says a fresh post-deployment readback of all six M12 gates was not supplied. Historical M12 reports contain earlier false readbacks; they must not be reused as current state.

## Authoritative scope and boundaries

| Requirement | Repository authority / evidence | M14.7 disposition |
|---|---|---|
| M14.7 work item | reports/m14/m14-implementation-backlog.md, row M14-15 (P0), plus priority total and M14.8 handoff | Follow the existing M14-15 scope; no substitute scope invented. |
| Security scan and feed | reports/m14-security/security-scan-plan.md, AS-16 in appsec-audit-matrix.md, config/security.php, WordfenceFeed, VulnerabilityScanner, SyncVulnerabilities, scan service/state | Validate truthful coverage and feed freshness; unavailable is not zero findings. |
| Attack-path coverage | reports/m14-security/threat-model.md T01–T24 and M14.1–M14.6 matrices/findings | Re-test relevant high-impact paths against exact current code and seven-job CI. |
| CI/deployment carry-forward | reports/m14-supply-chain/m14-6-findings.md and m14-6-closure-report.md, especially M146-F08 | M147-B validates and closes the specific tracked secret-path checker output concern; retain broader untested log-integrity limits and the single-maintainer review residual. |
| Privacy/destructive gates | config/privacy.php, M12 wave 8 closure and M14.6 closure report | Read-only state capture is mandatory before any later staging activity; current live values are unknown. |
| Scanner execution safety | docs/SECURITY_ENGINE.md, docs/MALWARE_SCANNING.md, docs/SECURITY_INCIDENTS.md, docs/SECURITY_FINDINGS.md | Detection-only; synthetic disposable site; keep remediation and all destructive gates off. |
| Browser tests | playwright.config.ts, playwright.connector-e2e.config.ts, tests/e2e/*.spec.ts, docs/CONNECTOR_E2E_LOCAL.md | Separate ordinary E2E from Connector browser suite; do not run externally dependent suite during planning. |

M14.7 is not M14.8 integrated closure, M15 pilot readiness, M17 production readiness, or an independent penetration-test certification. The independent pentest checkpoint remains before M17/public launch; the repository does not show that it has been commissioned or performed.

## Current implementation and evidence status

### Scanner/feed domain

- config/security.php selects none as the source default; Wordfence source use requires explicit configuration. A feed is available only while its index exists and is within the configured maximum age (default 72 hours).
- security:vulnerabilities:sync downloads only an explicitly configured HTTPS feed URL (optional configured key) or imports a supplied local file. The command is scheduled only when source is wordfence and a feed URL is configured. This plan did not inspect environment secrets or values, so current environment source/feed state is UNVERIFIED.
- WordfenceFeed imports vulnerability metadata and affected version ranges; VulnerabilityScanner compares core/plugin/theme inventory versions against that source. Without an available source or inventory, coverage is unavailable, not clean.
- ScanType covers vulnerability, integrity, malware and full. SecurityScanService records scanner/Connector versions, coverage and normalized findings; partial or unavailable scanners are visible in status/coverage. Test helpers fabricate Connector replies and synthetic findings. These fixtures prove exercised code paths only, not scanner effectiveness on a live site.
- Connector documentation describes official WordPress checksum/integrity comparison and bounded rule-based suspicious-file/database/user/cron/config checks. Exact rule coverage, false-positive behavior and runtime compatibility must be exercised against the pinned Connector release and a disposable fixture. No production-grade malware-detection completeness claim is permitted.
- A scanner alone does not set a suspicious file to confirmed malware. Operator decision is required. High-confidence high/critical detections can open an incident and request a full backup automatically (both config defaults are true); this is a non-destructive safety side effect that still needs explicit staging allowance, synthetic-only target scoping and verification. It must not be misdescribed as “scan-only with zero effects.”
- Remediation is separately gated by SECURITY_REMEDIATION_ENABLED=false by default and plugin disabling by SECURITY_REMEDIATION_ALLOW_DISABLE_PLUGIN=false. Remediation requests require operator capability, confirmation, and a recent verified full backup. Quarantine is reversible. M14.7 validation must keep both controls false; no remediation, quarantine, plugin disable, file repair, restore or provider deletion is authorized.
- The security-scanner warning is an evidence/coverage gap (AS-16), not a confirmed vulnerability and not evidence of zero vulnerabilities.

### Integrated security attack paths

The threat model contains T01–T24 covering tenant isolation/IDOR, Owner and Operator authority, Connector credential and replay, untrusted payload bounds, SSRF/redirect/DNS, archive/restore path handling, Stripe webhook replay, secrets, rate/resource exhaustion, jobs, CSRF/XSS, AI egress boundaries, destructive retention, incident remediation and supply-chain/deployment. Prior milestone reports close some specific implementation defects, while several matrices remain partial or validation-required. M14.7 must build a traceable matrix from current source/tests and rerun relevant negatives; old pass claims are not blanket coverage for new revisions.

The M14.6 closure explicitly retained M146-F08 as a low/medium log-integrity validation gap. M147-B re-audited the current tracked secret-path checker, which uses NUL-delimited `git ls-files -z` output and emits a fixed redacted count rather than attacker-controlled paths. The Windows subprocess subset and full ten-case Linux CI corpus passed without command-file changes or hostile output. With the Owner's approval, close M146-F08 **only for this audited checker output path**; no claim is made that all workflow-command parsing paths were exhaustively tested.

### M12 gate state and procedure

The exact configuration keys in config/privacy.php are:

1. M12_DESTRUCTIVE_RETENTION_ENABLED → privacy.destructive_retention_enabled.
2. M12_PROFILE_ANONYMIZATION_ENABLED → privacy.profile_anonymization_enabled.
3. M12_BACKUP_DELETION_ENABLED → privacy.backup_deletion_enabled.
4. M12_PROVIDER_DELETION_ENABLED → privacy.provider_deletion_enabled.
5. M12_BULK_RETENTION_EXECUTION_ENABLED → privacy.bulk_retention_execution_enabled.
6. M12_SYNTHETIC_PROFILE_ANONYMIZATION_ENABLED → privacy.synthetic_profile_anonymization_enabled; this path additionally requires APP_ENV=testing and is not authorization for a normal staging synthetic run.

Their absence defaults false in application config, and each is an independent action capability. For any later authorized staging scan/validation, a designated operator must use the approved strict-host deployment process and a secret-safe read-only command that prints only six boolean classifications, never .env contents. Capture before and after the validation; require all six false, confirm the app environment/target is staging and scan is detect-only, then independently inspect allowed side effects and cleanup. A mismatch, unknown, or unreadable value stops the activity; do not change it to proceed. Do not make this read during planning.

Current state: NOT FRESHLY VERIFIED. M14.6 documents that the latest prior readback was false but explicitly has no fresh post-deployment six-gate readback. Code defaults and historical evidence do not prove present staging/production environment values. No production or destructive work is in this plan.

## Proposed M14.7 architecture and workstreams

Implementation sequence should be dependency-driven and reviewed as small PRs. Keep scanner campaign and attack-path evidence separate from any future runtime defect fix; a confirmed defect gets a narrow fix PR, exact-head seven-job CI, exact merged-primary CI and approved staging validation before the scan is repeated.

| Stage | Scope and deliverables | Prerequisites / tests | Complexity | Staging needed? |
|---|---|---|---|---|
| M147-A — Baseline and safe evidence harness | Exact-SHA code/config/test inventory, finding schema and safe report format, scan target authorization record, preflight checklist, test data classification. No production data. | Implemented and locally validated under the Owner's explicit M147-A-only Level 2 approval. WordPress `7.1.3` and SQLite drop-in `1.3.3` installed/health-checked; actual cleanup verified. No deployed baseline capture or scanner coverage. | Medium | No; no staging authorization is included. |
| M147-B — M146-F08 and supply-chain attack paths | Adversarial path names containing newlines, control characters, annotation-like, output-command-like, and secret-masking-command-like strings; inspect logs/annotations/summary/environment outputs; verify secret CI token is read-only and no output injection changes job state. Carry deployment-origin, target SHA, receipt/provenance and arbitrary sudo denial evidence from M14.6. | Separately approved by the Owner on 2026-10-18 for Level 2 local/source work only; use a disposable repository/worktree, synthetic markers and a protected PR. No runner-specific behavior is claimed from local simulation. | Medium | No staging required or authorized for this stage. |
| M147-C — Security engine fixture validation | Synthetic WordPress versions and fixture files/database/users/cron/config; known vulnerable and patched component cases; official checksum mismatch/missing/unavailable; rule coverage, malformed/oversized Connector result, partial/unavailable/timeout status; distinguish integrity vs suspicious vs confirmed malware. No real site. | M147-A provides a tested local bootstrap foundation only; no scanner behavior or browser validation was performed. Committing additional code/tests or executing scanner/browser validation requires separate Level 2 approval. Fake feed only; no live-feed configuration. Ensure no remediation controls are enabled. | High | No for deterministic code behavior; execution requires separate Level 2 approval. |
| M147-D — Attack-path regression campaign | Tenant A/B IDOR for web/API/jobs/findings/incidents; Owner/Operator/customer boundaries; CSRF/XSS; Connector replay/scope; SSRF/DNS/redirect; rate/size abuse; Stripe signature/replay without charge; backup/restore/archive and last-good protections; M14.5 provider fixed destination/no egress; M14.6 release/SHA/helper controls. | Define synthetic cases and source-linked matrix. Executing local tests requires Level 2 approval; no live providers, payment, customer content or production. | High | Not for matrix design; narrow staging checks require Level 3 approval if local evidence cannot establish a deployed boundary. |
| M147-E — Separately authorized staging detection-only scan | If separately approved later: establish current SHA/config/feed and all six gates read-only; use the specifically approved synthetic disposable WordPress fixture; record scanner/feed/Connector/rules versions, coverage and completion/failure truth; verify no remediation/quarantine/plugin change; inspect only specifically authorized effects; cleanup and post-check health. | **Not currently authorized.** Requires separate Owner approval of exact target identity, Connector pairing, permitted operations, and cleanup; a fresh read-only all-false M12 gate check; strict SSH/root/deploy controls; and an approved feed if vulnerability coverage is claimed. Unknown/unreadable/true gate stops. | High | Yes; requires Level 3 operational authorization. |
| M147-F — Findings, retest and closure | Severity/impact/tenant scope, root cause, evidence, fix PRs, exact CI, staging retest as applicable; close or explicitly carry nonblocking residuals; independent review disposition; M14.8 handoff and closure report. | No unresolved Critical/High; external residuals have owner and boundary; scan warning only clears after accepted evidence. | Medium | Yes for retests tied to staging scan; closure report can be local. |

### Implementation constraints

- Detection and reporting remain separate from action. Do not introduce auto-remediation, bulk actions, provider delete, security-driven customer backup deletion, or autonomous actions.
- SECURITY_REMEDIATION_ENABLED=false and SECURITY_REMEDIATION_ALLOW_DISABLE_PLUGIN=false remain false throughout M14.7 validation. Reversible remediation remains outside the scan campaign.
- Existing M14.4 backup:retention remains report/comparison-only; no customer backup or provider deletion. If incident auto-backup is triggered by an explicitly authorized synthetic high-confidence fixture, it must be verified, scoped to the synthetic website and cleaned only through safe documented test cleanup.
- M14.5 stays configuration-only: no default/enabled/authorized AI feature and no customer/site/security/backup egress. Use no real provider credential.
- Preserve Owner/customer/Operator role boundaries, service-layer authorization, recent-auth, CSRF, rate limits, two-tenant scopes, backup-before-remediation and audit semantics. Any found regression receives a minimal fix, never a broad security refactor.
- Connector jobs and third-party scanner inputs are untrusted. Validate schema, bounds, identity, source version and freshness. Preserve unavailable, partial, failed, timed out, and completed distinctions.

## Release, staging, and rollback gates

1. Planning PR #119 was merged at `0a0d25c2da256015d5cffad5f419603593ed72b5`; merged-primary CI run `39098281556` passed all seven required jobs. M147-A is in PR #120; final-head CI and protected merge must be recorded before its integration is treated as complete.
2. Each future executable PR comes from current authoritative primary, has focused regression and applicable full regression, git diff --check, Pint/PHP syntax, Composer validation/audit, frontend build/npm audit, full-history secret scan, then exact-head mandatory CI 7/7. Server MySQL process/concurrency must pass inside its named job.
3. Merge only after review and exact-head green; exact merged-primary SHA must then have its own 7/7 successful run. PR-head CI does not authorize staging deployment.
4. For staging scan only: approved exact merged SHA, strict pinned staging SSH, clean checkout, fixed origin, deployment receipt/runtime SHA match, healthy required services, read-only six-gate readback all false, remediation and plugin disable false, no customer target, synthetic fixture authorization, fresh backup/recovery protections as required, feed-source approval, rate/resource bounds and cleanup plan.
5. Abort on unknown gate state, non-synthetic target, feed ambiguity when vulnerability coverage is claimed, strict host mismatch, dirty/unexpected SHA, partial or malformed result, health degradation, or any unexpected mutation. Preserve evidence and stop; do not try to repair customer state during scanner validation.
6. Revert a code defect through reviewed PR/CI and exact-SHA guarded staging release. Do not reverse database migrations or restore backups automatically unless a separately reviewed recovery decision requires it.
7. After validation, confirm synthetic fixture and related rows/jobs/audits are cleaned according to retention policy; verify app/DB/queue/scheduler/workers/Connector/backup/mail/privacy/system health, scanner state and six gates again. Record remaining scan sources as unavailable instead of inventing zero findings.

No M14.7 staging or production action was performed in planning or M147-A. Available staging evidence is historical: scanner warning remains; six gates were not freshly read after the latest deployment.

## CI and test baseline

Retain the seven current required checks exactly: Quality; Server SQLite; Server MySQL; Server MariaDB; Connector WordPress SQLite; Connector WordPress MySQL; Connector WordPress MariaDB. MySQL process/concurrency stays required inside Server MySQL. Add focused static/policy or security integration checks only when they protect a named invariant and can run without secrets or external services. All fake feeds, WordPress inventories, suspicious-file markers, provider responses, customers and findings use generated synthetic sentinels.

No CI test should require actual customer site inventory, production database/keys, real Stripe charge, AI provider key, malware payload execution, or a public service endpoint. Scanner behavior is proved with inert fixtures and read-only status; any standalone staging proof uses only disposable synthetic resources.

## Browser test assessment

Current checked source has four spec files. A fresh direct declaration count is 23 ordinary cases (localization.spec.ts: 8, owner-commercial.spec.ts: 8, stripe-purchase.spec.ts: 7) and 3 Connector cases (connector-browser.spec.ts: 3). This reconciles the prior 26 ordinary-spec report as a counting/scope discrepancy: current source does not support a 26 ordinary case count. Keep these counts tied to the audited SHA and rerun the inventory when tests change.

The normal Playwright config is not CI coverage. stripe-purchase.spec.ts and owner-commercial.spec.ts exercise Stripe TEST webhooks/email flows and require a public HTTPS E2E_BASE_URL, disposable Owner/customer test users, Stripe TEST configuration and Mailpit. Some owner flows create/change billing records. They can run safely only on a dedicated disposable local/test deployment and Stripe TEST account with public webhook ingress intentionally configured; they do not belong in this planning pass or against staging/production/customer data. The localization suite needs a local app and synthetic session; authenticated cases require synthetic test account setup. HTTP smoke checks are not browser-equivalent.

The Connector browser suite targets only 127.0.0.1:8317 and :8318, but needs the dedicated Laravel/WordPress fixture, local PHP/Composer/runtime and disposable SQLite databases. docs/CONNECTOR_E2E_LOCAL.md documents path/branch-guarded setup, loopback-only service settings, synthetic tagged records, no-op mail, blocked non-loopback WordPress egress and cleanup. Run only in its approved isolated worktree and fixture; never adapt it to staging or a customer site. The Playwright package is pinned by the current lockfile at 1.64.1; the reviewed successful Dependabot/primary CI shows Node/package build compatibility, not that Playwright browsers or tests ran. No specific 1.64.1 incompatibility was established by this read-only review.

M14.7 should execute security-specific automated browser tests if existing harnesses cover the relevant console/authorization flows without real Stripe or external services. A separate isolated local Playwright task should validate the three Connector browser cases. The regular Stripe-dependent E2E suite is optional M14.7 evidence only when its disposable local infrastructure and test account exist; otherwise track it as explicit test-infrastructure follow-up. Do not make it a staging prerequisite or require real/customer data. No browser tests were executed during planning.

## Required evidence package and Definition of Done

M14.7 implementation/closure should update reports/m14-security/security-scan-plan.md, appsec-audit-matrix.md, threat model/findings, the M14 master backlog and relevant M12/M14.6 handoffs, then create a closure report containing:

- exact baseline/target/runtime SHA, receipt and exact-head/merged-SHA CI run IDs;
- route/job/command attack-path matrix with source, tests, actor, synthetic fixture, outcome and uncovered status;
- scan UUID, synthetic target marker, versions/rules/feed timestamp, scanner-specific coverage, completion state, severity/confidence/type totals, and explicit unavailable components;
- feed provenance/freshness and the exact policy used for WordPress core/plugin/theme matching;
- no remediation/quarantine/plugin disable/provider deletion/backup deletion result and no unexpected effects;
- current six gate readbacks before/after, cleanup and health evidence;
- M146-F08 adversarial filename/log results and any narrow fix PR;
- findings with reproduction, exploit preconditions, impact, root cause, fix or compensating control, regression, severity, M14/M15 blocking status, owner and expiry;
- browser execution scope/count/environment only where actually run; no inference from test discovery or HTTP smoke;
- independent review status and accepted residuals.

Closure gates: all Critical/High findings fixed and retested or M14 remains blocked; no false clean scan from unavailable coverage; required attack-path negatives pass on exact current code; scan has terminal accepted status and non-empty actual scanner coverage; no unauthorized customer or AI egress; all destructive/remediation gates remain off; no unexpected mutation; exact 7/7 CI and required MySQL gate green; authorized synthetic staging validation, cleanup and post-health complete; M146-F08 closed or explicitly dispositioned with evidence; and independent review residual clearly stated. M14.7 closure does not certify all WordPress sites malware-free, guarantee zero vulnerabilities, authorize remediation, complete independent penetration testing, or close M14.8/M15/M17.

## M147-A implementation status

M147-A adds a source baseline report, a fixed-path disposable WordPress fixture/evidence harness, fake in-memory queue/storage adapters, fail-closed environment and cleanup checks, and focused Node safety tests. On implementation source SHA `5dc8a4618faaff958621ccc6fde5a2795543054c`, locked WordPress `7.1.3` and SQLite drop-in `1.3.3` packages were installed from lockfile-selected official commit-addressed archives; the fixture was installed and health-validated against a fixture-local SQLite file. Connector `0.4.7` was discoverable and inactive. Process-level outbound controls, the WordPress HTTP block, rejection of an altered external Connector destination, and actual owned-fixture cleanup were observed. Exact package references, tree hashes, synthetic IDs, results, and cleanup observations are recorded in [M147-A execution evidence](m14-7-a-execution-evidence.md). The lockfile has no archive `dist.shasum` values, so no archive checksum claim is made.

Validation for M147-A was limited to fixture bootstrap and its safety harness. No web server, scanner, live feed, malware specimen, application side effect, browser E2E, or M12 live-gate read occurred; scanner coverage is `NOT_RUN`. The six M12 values remain unknown and must be freshly verified, secret-safely and read-only, before any separately authorized staging scanner validation. AS-16 remains open. M146-F08 is in the separately authorized M147-B validation scope; see [M147-B execution evidence](m14-7-b-execution-evidence.md). M147-C and later stages are not authorized.
