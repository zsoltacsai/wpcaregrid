# M10 — Monitoring, Alerting & Infrastructure Health: Closure Report

## Final Owner validation continuation — 2026-10-12

This section supersedes the provisional status and pending-owner-action statements below. The earlier sections remain as historical evidence and are not rewritten. The live SMTP and authenticated maintenance-window gates, exact-head CI, merge, and post-merge CI have all passed.

**Verdict: A — M10 CLOSED.** The final evidence is present on the primary branch. The primary SHA at closure integration was `139705b952d8c3b2062415f52d6fe3af1c591cff`; this report-only follow-up records that completed state. The current primary branch SHA after this evidence update is reported in the final task response.

### Release, CI, and deployment

- The maintenance submit-control correction was merged through PR #17. Merge commit and resulting primary SHA: `33c961f07c541cd243a411e1310aa9236383e6fb`.
- PR #17 exact-head CI run `38296720170` passed all five jobs. Post-merge primary CI run `38298060346` passed all five jobs, including server MySQL concurrency, server SQLite, Connector MySQL, Connector SQLite, and quality/security/build.
- Final closure PR #16 head: `243cedc67140dd755b3b58b76e0c52e1587b0552`; exact-head CI run `38301504636` passed all five jobs. PR #16 merged as `139705b952d8c3b2062415f52d6fe3af1c591cff` on 2026-10-12 20:11:09 UTC. Post-merge primary CI run `38302556871` passed all five jobs.
- Staging was deployed by the guarded script to exact SHA `33c961f07c541cd243a411e1310aa9236383e6fb` using strict SSH host-key verification. The pre-deploy database backup is retained at `/home/deploy/private-backups/wpcaregrid-pre-deploy-20261012T194100Z.sql.gz`. No migration was pending. Workers restarted with new PIDs, scheduler resumed and became healthy, and deployment HTTP/security smoke checks passed. Staging checkout is detached, clean, and at the target SHA.
- Connector remains 0.4.5. No production deployment occurred.

### SMTP alert delivery

- After the Owner configured SMTP through the approved credential path, the actual M10 alert outbox path delivered one opening email and one recovery email for the synthetic M8 monitor signal. Both delivery rows reached `sent` with one attempt and no failure code (opening at 19:13:04 UTC; recovery at 19:15:04 UTC).
- The authoritative `provider.mail` observation recovered to `healthy` / `monitoring.ok` with recent verification and no recent failure. No credentials, recipients, or raw provider response are included.

### Authenticated maintenance-window UI drill

- The Owner Console button defect was confirmed in the live DOM: the shared button component defaulted to `type="button"`, so the maintenance form did not submit. PR #17 fixed the component prop/rendering, kept the ordinary button default, set the maintenance form button to submit, and added targeted render plus controller/audit regressions. The same source/test patch is present in PR #16; after PR #17 merged, this runtime fix was deployed through the guarded staging workflow.
- An initial UI submission returned HTTP 422 because its start time had become more than one minute past; validation rejected it and created no window or audit record. The retry used a future UTC interval and succeeded through the authenticated Owner UI.
- Owner audit event `monitoring.maintenance_created` recorded website scope, check family `website`, and the exact synthetic site `01a12784-ffc5-735b-9064-b2ccb2039ff0`. Window ID 1 ran from 19:46 to 19:56 UTC for reason `M10 authenticated maintenance-window E2E`.
- During the active window, three normal synthetic monitoring samples opened `website.http` incident 11 at 19:46:17 UTC. Its transition recorded `delivery_suppressed=true`, `suppression_reason=maintenance`, and zero alert-delivery rows. The critical observation and incident were still persisted, proving monitoring continued while notification delivery was suppressed.
- After natural expiry, active maintenance-window count returned to zero. Two healthy observations resolved the incident at 19:56:39 UTC with `delivery_suppressed=false`; the in-app recovery was sent and the recovery email reached `sent` at 19:56:41 UTC after one attempt, with no failure code. This proves alert delivery resumed after the window and no suppression remained.

### Post-drill health and residual findings

- A transient `monitoring.pipeline` stale incident appeared during post-deployment probe catch-up. The normal `monitoring:run` path observed the refreshed probe completion, and the pipeline returned to `healthy` / `monitoring.ok` after its required two healthy observations at 19:58:37 UTC. The incident resolved and its recovery delivery completed; it is not active now.
- Final Owner Console state: no active maintenance window; no active `website.http` or `monitoring.pipeline` incident. The remaining visible findings are WordPress Site Health `attention` (seven findings) and stale vulnerability feed; the security-scan freshness state remains unknown. These are correctly represented residual signals, not falsely healthy, and do not indicate an M10 implementation defect.
- Final read-only runtime checks: `system:check` passed application, database, queue, scheduler, Connector API, Stripe configuration, Cloudflare DNS, backup storage, mail configuration, and safety checks. Monitoring dispatch, pipeline, worker, and `provider.mail` observations were healthy. Global restore and synthetic restore were both `false`; active restores, active WebsiteJobs, active maintenance windows, queued Laravel jobs, and failed Laravel jobs were all zero. Staging HEAD is `33c961f07c541cd243a411e1310aa9236383e6fb` and the worktree is clean.

### Current closure gates

- Passed: SMTP delivery and `provider.mail` recovery; authenticated Owner maintenance creation/audit; suppression while observations continue; post-expiry delivery recovery; PR #17 and post-merge primary CI; guarded staging deploy and health; restore gates off; no active jobs, restores, or windows.
- Passed: the authenticated Owner UI drill, SMTP alert delivery and provider recovery, PR #16 exact-head CI and merge, post-merge primary CI, guarded staging deployment and health checks, restore gates OFF, and clean runtime/job state. There are no unresolved critical/high M10 implementation defects or remaining Owner closure actions.
- Focused local `tests/Feature/Monitoring` validation for the current closure regression: **22 tests, 93 assertions, 1 skipped, 0 failures**. The skip is the concurrent first-failure test, which requires an explicitly named local MySQL test database; no migrations were run against an unspecified local database. The PR and post-merge remote MySQL suites passed.

**Final M10 state:** Connector 0.4.5; approved M10 staging runtime SHA `33c961f07c541cd243a411e1310aa9236383e6fb`; global and synthetic restore gates OFF; active restores, WebsiteJobs, maintenance windows, queued jobs, and failed Laravel jobs all zero; monitoring pipeline, dispatch, workers, and provider.mail healthy. The vulnerability feed remains stale, WordPress Site Health remains attention with seven findings, and no completed security scan is available. These findings remain visible as degraded/unknown signals and are not launch blockers for M10. No production or customer mutation occurred.

**Next milestone:** M11 — Subscription Lifecycle, Billing & Transactional Email. Begin with the planning-only phase required by the roadmap.

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
- Closure evidence/test PR #16 (`codex/m10-closure-evidence`) remains open and unmerged. At the latest CI attempt before this report addendum, the PR head was `030687d8e2b129d1da086056085d892652a2f696`; the merge SHA is therefore not applicable. The primary SHA remains `7ba1c9f1f42481968904fd2f2829637725d28fbf`. The initial evidence commit was `6fdebefb6475761222e0caac948e6a0320bc18e0`. Runs `38283927611`, `38284114385`, and `38285866799` did not start any of the five jobs: GitHub reported failed recent account payments or a spending limit. This is an account-level Owner blocker, not a code test failure; no green status is claimed for the closure PR.
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
- Final read-only staging evidence at 17:40 UTC: pipeline, platform database, queue, scheduler, M9 backup, and recovery observations were healthy and fresh; `provider.mail` was `attention` / `provider.mail_unavailable`; `provider.s3` was `unknown` / `monitoring.source_unverified` because no recent matching Owner connection-test evidence was recorded; security scan was `unknown` / `security.scan_overdue`. This distinction is intentional: configured/reachable storage must not be promoted to recently verified provider health without the authoritative test evidence.
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
- Final control counts: Laravel queued jobs 0, failed jobs 0, active synthetic WebsiteJobs 0, active restores 0, and active maintenance windows 0.
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

## Addendum — final Owner validation continuation (2026-10-12)

The Owner authenticated to the staging Owner Console and the maintenance-window form was exercised against the exact M8 synthetic website. This exposed a UI defect that the earlier direct-controller feature test did not cover: the form used the shared `x-ui.button` component, whose default HTML type is `button`, so clicking it did not submit the form. No maintenance window was created by the failed UI attempt, and no synthetic monitoring signal was triggered during that attempt.

The closure branch now explicitly renders this form button as `type="submit"` and adds a regression asserting the Owner page renders a submit button. This is a narrow staging-runtime Blade fix, not deployed. The authenticated live UI drill remains pending until this closure PR can pass exact-head CI and enter the normal release path.

Post-fix local `tests/Feature/Monitoring` suite: **21 passed, 1 skipped, 92 assertions**. Broader `--filter=Monitoring` run: **36 passed, 1 skipped, 109 assertions**. The skip is the simultaneous-first-failure concurrency test, which requires an explicitly named local MySQL test database; no migrations were run against an unspecified database. An initial Windows-shell attempt could not find PHP; WSL was used. The first WSL boot attempt lacked `APP_KEY`; the successful runs used an ephemeral dummy test key and a temporary empty `.env`, removed automatically on exit. No staging key was read or used.

The latest previously checked PR #16 head was `8505feb7bb37e002042192b64636f56805a10fb0`; exact-head Actions run `38285990264` was blocked before jobs started by GitHub account payment/spending-limit policy. PR #16 remains unmerged. Since the submit-button fix is runtime view code, staging must be updated through the reviewed release flow before the authenticated UI drill can be marked passed. SMTP alert delivery also remains failed as recorded above; this task did not change SMTP credentials or claim recovery.

The correction and evidence were committed as `91390e60f40350b9639511edc012fa22b0332faf` and pushed normally to PR #16. Exact-head run `38292212554` immediately failed before any job steps, with the GitHub annotation again stating failed recent account payments or an insufficient spending limit. The Owner Console still serves the old deployed markup: a read-only DOM inspection confirmed the live maintenance button is `type="button"`. The form was not submitted, no maintenance record was created, and no synthetic failure was triggered. This confirms the UI drill is blocked pending the reviewed deployment; no staging code was changed from this task.
