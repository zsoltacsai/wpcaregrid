# M10 — Monitoring, Alerting & Infrastructure Health: Closure Report

**Assessment time:** 2026-10-12 17:22 UTC
**Status:** Functionally implemented and deployed; closure remains conditional on Owner-controlled staging operations below.

## Verdict

**B — M10 FUNCTIONALLY COMPLETE, OWNER ACTION REMAINS**

The monitoring code is deployed on the primary branch and staging. Healthy probes, incident confirmation, in-app alert deduplication, recovery, the actual probe failure regression, and pipeline self-health are evidenced. M10 is not marked closed because current staging SMTP authentication is rejected and the Owner Console session was not authenticated for a final maintenance-window UI drill. The platform detected and surfaced the mail-provider failure rather than claiming delivery.

## Repository, release, and CI

- Repository: `zsoltacsai/wpcaregrid`.
- Primary branch: `claude/ecstatic-wozniak-oemyuf`.
- Runtime implementation PRs: #14 and #15. The deployed probe-evidence fix is commit `a21d83feb9753d53f32b997e74d8079d6097ebd5`; primary merge/deployment SHA is `7ba1c9f1f42481968904fd2f2829637725d28fbf`.
- Staging is at `7ba1c9f1f42481968904fd2f2829637725d28fbf`, detached and clean. Remote primary HEAD matched it at verification.
- The trusted staging SSH ED25519 fingerprint was checked through strict host verification. No production deployment occurred.
- The runtime release had no migrations and no Connector package change; Connector remains 0.4.5.
- Exact primary CI run `38280047188`: all five jobs passed (Connector MySQL, Connector SQLite, server SQLite, server MySQL including its required MySQL concurrency gate, and quality/security/build).
- Earlier implementation CI: `38275369352` (five jobs passed); probe-fix PR CI: `38278996567` (five jobs passed).
- Closure evidence/test PR #16 is pushed at commit `6fdebefb6475761222e0caac948e6a0320bc18e0`. Its CI run `38283927611` did not start any of the five jobs: GitHub reported failed recent account payments or a spending limit. This is an account-level Owner blocker, not a code test failure; no green status is claimed for this commit.
- This closure report and its two regression tests are documentation/test-only changes, so they do not require a staging redeployment. They must not be merged into primary until exact-commit CI can run and pass.

## Implemented monitoring model

The M10 implementation uses the approved deterministic pipeline: signals and observations are evaluated into health states; state transitions open, update, escalate, and resolve deduplicated incidents; idempotent outbox rows deliver in-app and email alerts. Persistent observations, incidents, transitions, maintenance windows, and delivery outcomes carry typed subject/reason/state data and safe evidence. Monitoring uses existing authoritative Site Health, Connector, backup, security, platform-recovery, queue, scheduler, and provider sources instead of duplicating those systems.

Implemented signals include stored-site HTTP/HTTPS availability, TLS, Connector presence and compatibility, WordPress/runtime health, backup freshness, M9 platform backup/recovery, database, queue/workers, scheduler, security freshness/findings, configured providers, and monitoring-pipeline freshness/backlog/failure. Probe destinations come only from validated managed-site origins; DNS results and every redirect are checked against private/special-use address rules, and connection targets are pinned to validated resolution. Owner platform views and authorized Operator site views are covered by authorization tests. Customer-facing low-level infrastructure alerts, AI, external alert channels, and autonomous remediation remain out of scope.

## Noise control and Owner/Operator behavior

- Signal-specific confirmation and recovery thresholds, incident deduplication, escalation cooldowns, flapping handling, and idempotent delivery are implemented.
- Maintenance records observations while suppressing alert delivery; automated feature coverage verifies this behavior. A newly added regression proves Owner creation of a website-scoped maintenance window and its audit record.
- Operator visibility is scoped to authorized sites; unauthorized access is refused in feature tests.
- A new pipeline self-health regression proves stale observations open a single incident/alert and two fresh observations resolve it once.
- Staging synthetic failure/recovery exercise: repeated failures opened exactly one incident (generation 2) and one in-app opening delivery; confirmed recovery resolved it once and generated one in-app recovery delivery. Repeated observations did not duplicate deliveries.

## Staging validation evidence

### Probe diagnosis and correction

The first real staging probe falsely classified a healthy response as `http.unreachable`. The HTTP client had correctly received headers/status before intentionally stopping a bounded response body; `response_truncated` was not in the safe evidence allowlist, so evidence validation threw and the generic handler converted the result to unreachable. PR #15 added the safe evidence key and regression coverage; it did not weaken URL/SSRF protections. A subsequent actual queued probe to the managed synthetic site's stored origin recorded HTTP 200 with bounded-response evidence, and the old false incident resolved after the configured healthy observations. A later queued probe again recorded HTTP 200. The synthetic site's identity was `01a12784-ffc5-735b-9064-b2ccb2039ff0`; no arbitrary URL was supplied.

### Current checks and signals

- Staging application `/up`, homepage, Owner login, synthetic WordPress homepage, REST root, and login route returned expected HTTP 200 responses; unauthenticated Owner monitoring correctly redirects to sign-in.
- HTTP probe and TLS were healthy; TLS certificate had 87 days remaining at the recorded check. Connector presence/heartbeat was healthy and Connector remained 0.4.5.
- `php artisan system:check` reported application, database, queue, scheduler, Connector API, Stripe configuration, Cloudflare DNS, backup storage, mail configuration, and safety switches OK. Security scanner warned that no completed scan exists yet.
- Platform monitoring observations showed database, queue depth, workers, scheduler, M9 backup/recovery readiness, monitoring dispatch, and pipeline healthy at the captured run.
- WordPress Site Health remains `attention` with seven findings. This is a pre-existing site-health warning, not an HTTP or Connector failure. Website backup freshness is `unknown` because the source reports `NO_SCHEDULE`, although a verified backup is available; no backup was initiated or changed for M10.
- Security vulnerability feed is stale (`security.feed_stale`) and the latest scan is unknown because no completed scan is recorded. These remain visible findings, not silently promoted to healthy.
- Mail delivery attempts for M10 alerts failed after bounded retries with SMTP authentication rejected. No recipient address or raw provider exception is included here. A previous M10 staging message had `sent` status, but current delivery is not healthy. The mail provider monitor surfaced `provider.mail_unavailable`; `system:check` only confirms configuration, not successful current delivery.
- Final Owner-validation follow-up at 17:38–17:40 UTC used the real M10 outbox on the exact synthetic fixture: one confirmed opening and one recovery transition; both in-app deliveries reached `sent`. The opening email exhausted its three bounded attempts as `failed` (`mail_transport_failed`); the recovery email had one failed transport attempt and remained pending for its bounded retry. Provider evidence at 17:40 UTC remained `attention` / `provider.mail_unavailable` (`recent_failure=true`, `recently_verified=true`, `interrupted_delivery=false`). This verifies failure detection and bounded outbox behavior, not successful current email delivery. The previously captured safe transport diagnostic classified the rejection as authentication-related; changing credentials remains an Owner action.
- In-app opening and recovery alert deliveries succeeded and were deduplicated. No false active HTTP incident remains; the synthetic incident and earlier false-positive incident are resolved. The WordPress health warning remains open as noted above.
- The Owner browser session is currently unauthenticated. The Owner Console maintenance form could not be exercised live in this final pass; the Owner-only route and audit behavior, along with maintenance suppression, pass automated feature coverage.

## Test results

- Local focused monitoring suite after the closure regressions: **35 passed, 1 skipped, 106 assertions**.
- Skip: concurrent first-failure test requires an explicitly named local MySQL test database; the test deliberately did not run migrations against an unspecified local database. The exact primary CI run above passed the server MySQL job and its required concurrency gate.
- Full Pint check: **783 files passed**.
- `git diff --check`: passed before report authoring; rerun on the final diff.
- Secret scan: no local `gitleaks` executable is available in this Windows/WSL environment. The exact deployed SHA's CI quality job passed its secret scan. The final report/test-only PR must pass the same remote secret scan before integration.

## Runtime and safety state

- Staging `system:check` passed the core application/DB/queue/scheduler/Connector and safety checks; the scanner warning is stated above.
- Queue workers and system services were observed active; pending/failed job counts were zero in the controlled run. Alert-outbox failures are recorded as provider delivery evidence and are not Laravel failed jobs.
- Global restore and synthetic restore remain OFF. No backup, restore, M8 fixture mutation, customer mutation, billing/provider mutation, or production action was performed in this M10 closure work.
- Deployment pre-backups were retained at `/home/deploy/private-backups/wpcaregrid-pre-deploy-20261012T162115Z.sql.gz` and `/home/deploy/private-backups/wpcaregrid-pre-deploy-20261012T165822Z.sql.gz`. No M8 evidence was modified.

## Residual Owner actions and risks

1. Repair/reauthorize the staging SMTP service credential through the approved Owner service-credentials workflow, then run a controlled staging alert delivery check. Do not paste credentials into chat or reports.
2. Sign in to the staging Owner Console and complete a live maintenance-window suppression check if required by the release checklist. Current automated coverage passes, but the live UI drill is not evidenced here.
3. Resolve the GitHub account payment/spending-limit issue so PR #16's exact-SHA CI can start and pass; do not merge before then.
4. Schedule/complete an approved security scan and refresh the vulnerability feed; until then the scanner/feed state must remain attention/unknown.
5. WordPress Site Health still reports seven findings. Assess under the existing Site Health process rather than suppressing them.
6. An external independent dead-man/uptime provider remains an Owner/future infrastructure decision; monitoring on the same VPS cannot report that VPS's total outage.

Deferred by approved scope: M19 AI Site Health/predictive operations; M20 recovery AI; M21 AI framework; M24 Slack/Teams/Discord; M23 advanced SLA/reporting; autonomous remediation.

## Readiness assessment

The M10 engineering implementation and staging monitoring core are functionally complete. Closure is held at **B** because current SMTP authentication needs an Owner-controlled credential correction, the final authenticated Owner UI maintenance drill is not recorded, and GitHub account billing currently prevents exact-SHA PR CI from starting. After those Owner tasks, passing controlled delivery/maintenance checks, and green PR CI, integrate the closure evidence and reassess closure; no runtime code change is currently indicated.

**M10 implementation:** functionally complete. **M10 closure:** not yet complete.
**Next milestone:** M11 — Subscription Lifecycle, Billing & Transactional Email, after the M10 Owner actions and closure review.
