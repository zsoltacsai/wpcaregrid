# M14 Staging Security Scan and Attack-Path Plan

**Current status:** incomplete. Read-only staging `system:check` at SHA `a335162936afa2933e348fe7b04956e98e45c5a3` reported `security_scanner=WARNING`, “No completed security scan yet,” vulnerability data not configured, last scan absent, remediation Off. Do not suppress or relabel this warning.

## Completed-scan acceptance

Before M14 closure run an authorized, read-only/detection-only scan on staging after P0 fixes. Use the application’s supported scanner flow and a clearly identified synthetic test website when scan behavior can affect a site; no remediation, plugin deactivation, quarantine, or real customer site mutation as part of scan validation. A scan counts as completed only if the application records terminal `completed` status, at least one real scanner reports coverage, and `system:check` reflects the newest result as completed/healthy under configured freshness policy. `partial`, failed, unavailable vulnerability source, or no scanner complete is not a completed scan.

Record scan UUID (not sensitive payload), target class/synthetic marker, exact deployed SHA, server scanner version, Connector version/rules version, vulnerability source/feed version and timestamp, start/end/duration, coverage by scanner, findings by severity/confidence/type, false-positive rationale/approver, remediation status (must remain Off), and follow-up ticket/disposition. Never store source code, customer database/content, credentials, full vulnerable file contents or private hostnames in the public report.

## Scan scope and classification

- **Application/dependency:** locked Composer and npm dependencies, PHP source/static rules, Connector package integrity and known advisory review.
- **Staging web surface:** authenticated and unauthenticated routes, TLS/header/CSP posture, private noindex routes (security property is authorization, not robots), error behavior and exposed assets.
- **WordPress synthetic fixture:** configured malware/integrity scanners and available vulnerability matching; report unavailable scanners as unavailable, never “zero vulnerabilities.”
- **Attack paths:** two-tenant IDOR, Owner/Operator privilege boundaries, stale sessions and re-auth, CSRF, XSS sinks, SSRF/DNS/redirect, Connector auth/replay, Stripe webhook signature/replay, secret exposure, backup/restore/archive safety, kill-switch bypass and CI/deploy target controls.

## Finding handling

Severity uses impact, exploitability, tenant scope, data loss and blast radius. Critical/High findings block M14 closure and M15. Medium requires a named owner, compensating control and dated risk disposition; no silent waiver. False positives must show reproducible evidence, exact rule/source, affected code/config and independent review. A vulnerability-feed outage is `unavailable` and a scan cannot claim vulnerability coverage based on it.

## Execution safety and dependencies

1. Confirm strict staging host identity, deployed SHA, clean checkout, current scan target authorization and all destructive gates OFF.
2. Use scanner in detect-only mode; leave `SECURITY_REMEDIATION_ENABLED=false`, `SECURITY_REMEDIATION_ALLOW_DISABLE_PLUGIN=false`, live restore and all M12 destructive gates OFF.
3. Ensure scan queue and target are staging-only; do not use production credentials or real provider deletion endpoints.
4. Capture system health and scanner state before and after; verify no customer-data mutations or unexpected jobs.
5. Remediate code defects on a reviewed branch, exact-SHA CI and guarded staging deployment; rerun affected path and full scan.

## Independent review checkpoint

Schedule independent penetration testing before M17/public launch with auth, tenant isolation, Owner/Operator, Connector API, admin access, webhook, SSRF, restore and destructive endpoint scope. The M14 scan is not a substitute, and this plan does not claim that test has been commissioned or performed.

## M14.7 planning handoff — 2026-10-18

Planning package: [M14.7 plan](m14-7-plan.md), [test matrix](m14-7-test-matrix.md), and [Owner authorization register](m14-7-owner-decisions.md), based on primary b0285d36a7aa0dbfe7281282ce8c9c6116c3eb3f. No runtime/security scanner validation has been performed. AS-16 remains open and scanner coverage is not claimed. Latest M14.6 post-deployment six-gate readback was not supplied; current live state remains unknown. Keep all gates unchanged and require a secret-safe before/after read for any later authorized staging activity.
