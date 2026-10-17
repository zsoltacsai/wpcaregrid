# M14.7 Security Validation and Attack-Path Plan

**Status:** planning only; no M14.7 implementation, scan, staging access, or production action was performed in this planning pass.

**Planning baseline:** b0285d36a7aa0dbfe7281282ce8c9c6116c3eb3f on claude/ecstatic-wozniak-oemyuf, verified against the public repository and successful primary CI run 39074237108 (7/7; Server MySQL includes the process/concurrency gate). The planning worktree is based on this commit. This is repository planning evidence, not a fresh runtime/staging readback.

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
| CI/deployment carry-forward | reports/m14-supply-chain/m14-6-findings.md and m14-6-closure-report.md, especially M146-F08 | Validate newline and GitHub-command-like filename/log output handling; retain single-maintainer review residual. |
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

The M14.6 closure explicitly retains M146-F08 as a low/medium log-integrity validation gap. Its described path uses git ls-files-derived sensitive-path matches and workflow log annotations. The planning record does not establish a token-write or deployment path. Treat it as an adversarial validation gap unless a reproducible impact is found; do not inflate or silently close it.

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
| M147-A — Baseline and safe evidence harness | Exact-SHA code/config/test inventory, finding schema and safe report format, scan target authorization record, preflight checklist, test data classification. No production data. | Reviewed scope; route/job/command list; CI seven-job baseline. Unit/feature-only work can start locally. | Medium | No for harness; yes to capture deployed baseline. |
| M147-B — M146-F08 and supply-chain attack paths | Adversarial path names containing newlines, ::warning::, ::error::, ::add-mask::; inspect logs/annotations/summary/environment outputs; verify secret CI token remains read-only and no output injection changes job state. Carry deployment-origin, target SHA, receipt/provenance and arbitrary sudo denial evidence from M14.6. | Static/unit policy tests plus GitHub exact-head CI; use disposable git worktree and synthetic filenames. | Medium | No for filename fixtures; staging only for any host trust readback, not needed to close F08. |
| M147-C — Security engine fixture validation | Synthetic WordPress versions and fixture files/database/users/cron/config; known vulnerable and patched component cases; official checksum mismatch/missing/unavailable; rule coverage, malformed/oversized Connector result, partial/unavailable/timeout status; distinguish integrity vs suspicious vs confirmed malware. No real site. | Fake feed and Connector transport; SQLite/MySQL/MariaDB suites and real-WordPress CI remain intact. Ensure no remediation controls are enabled. | High | No for deterministic code behavior; isolated disposable WordPress needed for browser/real plugin integration proof if supported. |
| M147-D — Attack-path regression campaign | Tenant A/B IDOR for web/API/jobs/findings/incidents; Owner/Operator/customer boundaries; CSRF/XSS; Connector replay/scope; SSRF/DNS/redirect; rate/size abuse; Stripe signature/replay without charge; backup/restore/archive and last-good protections; M14.5 provider fixed destination/no egress; M14.6 release/SHA/helper controls. | Build a source-linked matrix; execute only synthetic/local CI tests. No live providers, payment, customer content or production. | High | Not for the automated matrix; narrow staging checks only if local fixtures cannot establish deployed boundary. |
| M147-E — Authorized staging detection-only scan | Establish current SHA/config/feed and all six gates read-only; synthetic disposable WordPress fixture; one explicit scan; record scanner/feed/Connector/rules versions, coverage and completion/failure truth; verify no remediation/quarantine/plugin change; inspect only synthetic side effects; cleanup and post-check health/gates. | Owner/operator target authorization; valid licensed/trusted feed decision if vulnerability coverage is claimed; strict SSH and root/deploy controls; isolated paired site and teardown path. | High | Yes. This is the only mandatory stage requiring staging. |
| M147-F — Findings, retest and closure | Severity/impact/tenant scope, root cause, evidence, fix PRs, exact CI, staging retest as applicable; close or explicitly carry nonblocking residuals; independent review disposition; M14.8 handoff and closure report. | No unresolved Critical/High; external residuals have owner and boundary; scan warning only clears after accepted evidence. | Medium | Yes for retests tied to staging scan; closure report can be local. |

### Implementation constraints

- Detection and reporting remain separate from action. Do not introduce auto-remediation, bulk actions, provider delete, security-driven customer backup deletion, or autonomous actions.
- SECURITY_REMEDIATION_ENABLED=false and SECURITY_REMEDIATION_ALLOW_DISABLE_PLUGIN=false remain false throughout M14.7 validation. Reversible remediation remains outside the scan campaign.
- Existing M14.4 backup:retention remains report/comparison-only; no customer backup or provider deletion. If incident auto-backup is triggered by an explicitly authorized synthetic high-confidence fixture, it must be verified, scoped to the synthetic website and cleaned only through safe documented test cleanup.
- M14.5 stays configuration-only: no default/enabled/authorized AI feature and no customer/site/security/backup egress. Use no real provider credential.
- Preserve Owner/customer/Operator role boundaries, service-layer authorization, recent-auth, CSRF, rate limits, two-tenant scopes, backup-before-remediation and audit semantics. Any found regression receives a minimal fix, never a broad security refactor.
- Connector jobs and third-party scanner inputs are untrusted. Validate schema, bounds, identity, source version and freshness. Preserve unavailable, partial, failed, timed out, and completed distinctions.

## Release, staging, and rollback gates

1. Planning PR is documentation-only and remains open until Owner review.
2. Each future executable PR comes from current authoritative primary, has focused regression and applicable full regression, git diff --check, Pint/PHP syntax, Composer validation/audit, frontend build/npm audit, full-history secret scan, then exact-head mandatory CI 7/7. Server MySQL process/concurrency must pass inside its named job.
3. Merge only after review and exact-head green; exact merged-primary SHA must then have its own 7/7 successful run. PR-head CI does not authorize staging deployment.
4. For staging scan only: approved exact merged SHA, strict pinned staging SSH, clean checkout, fixed origin, deployment receipt/runtime SHA match, healthy required services, read-only six-gate readback all false, remediation and plugin disable false, no customer target, synthetic fixture authorization, fresh backup/recovery protections as required, feed-source approval, rate/resource bounds and cleanup plan.
5. Abort on unknown gate state, non-synthetic target, feed ambiguity when vulnerability coverage is claimed, strict host mismatch, dirty/unexpected SHA, partial or malformed result, health degradation, or any unexpected mutation. Preserve evidence and stop; do not try to repair customer state during scanner validation.
6. Revert a code defect through reviewed PR/CI and exact-SHA guarded staging release. Do not reverse database migrations or restore backups automatically unless a separately reviewed recovery decision requires it.
7. After validation, confirm synthetic fixture and related rows/jobs/audits are cleaned according to retention policy; verify app/DB/queue/scheduler/workers/Connector/backup/mail/privacy/system health, scanner state and six gates again. Record remaining scan sources as unavailable instead of inventing zero findings.

No M14.7 staging or production action was performed in planning. Available staging evidence is historical: scanner warning remains; six gates were not freshly read after the latest deployment.

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

## Planning status

Planning package is complete for review. Runtime implementation remains 0%. No Owner security-policy decision is required to start local fixture/test design. A future staging scan needs the operational Owner/operator approvals listed in m14-7-owner-decisions.md, and a feed coverage claim needs an available, lawfully configured feed. See also m14-7-test-matrix.md and m14-7-findings.md.
