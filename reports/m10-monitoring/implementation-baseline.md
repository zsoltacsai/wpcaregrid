# M10 implementation baseline

## Repository baseline

- Repository: `zsoltacsai/wpcaregrid`
- Primary branch: `claude/ecstatic-wozniak-oemyuf`
- Baseline SHA: `60f1482c767c659cf98b880f6735b33161e27804`
- Baseline source: fresh `origin/claude/ecstatic-wozniak-oemyuf` fetch on 2026-10-12.
- Work branch: `codex/m10-monitoring`, created as a clean worktree directly from that SHA.
- M9 evidence: `reports/m9-platform-dr/final-closure-report.md` in the merged baseline marks M9 CLOSED. It records one verified encrypted platform backup and a disposable MySQL 8.4.11 import/readback check. Staging executable source is recorded as `3eeba893d6b63ae7ca81ac0caf56e0d745134f85`. No new staging or provider check is asserted here.
- The previous planning checkout at `f0194bca2d58b8eafccb1dfd1e3fc6176ed132de` was 54 commits behind and dirty. It remains untouched; the nine approved planning files were copied from it into this clean worktree.

## Reconciliation against current post-M9 source

The source diff between the reviewed M9 worktree at `ce86a16ceee8b9635ccf666893edfe5d65decdae` and the selected primary SHA `60f1482…` is empty; the latter adds the M9 closure report/evidence. The approved plan is therefore based on the same application implementation plus authoritative closure evidence.

Corrections/clarifications required before coding:

1. **SystemStatus is a snapshot, not durable monitoring.** It computes checks when queried and returns a JSON/table report. The result has no generic persisted freshness/history. Keep it as an adapter/diagnostic interface, not the incident source of truth.
2. **Queue status is incomplete for M10.** Current `SystemStatus` counts failed jobs and checks the default database queue's available depth/age and transfer timeout safety. It does not establish live process health for the default/backups workers and does not provide complete per-queue backlog/oldest-reserved/stuck metrics. M10 must add safe queue/worker observations; do not label current queue status “worker healthy.”
3. **Scheduler heartbeat exists, task ledger does not.** A one-minute cache timestamp proves the scheduled heartbeat task ran; it does not prove every scheduled task completed. M10 should add a small completion ledger only for monitored tasks and preserve the heartbeat meaning.
4. **Provider checks differ by source.** Stripe status in SystemStatus is configuration/catalog plus cached explicit verification, not a continuous Stripe ping. Mail status is configuration/driver only. Backup storage status uses recent verified backups and staging free space, not a continuous provider availability check. Cloudflare diagnosis is an explicit read-only provider call from the current status calculation; move provider probing to bounded scheduled evaluation before using the SystemStatus page as a low-latency dashboard.
5. **M9 platform signals are not the generic incident system.** `PlatformRecoverySignal` carries typed event names/context, but no generic durable MonitoringResult/HealthIncident/outbox consumer was found. M10 should adapt those events and existing M9 records without rewriting them.
6. **No generic in-app notification persistence was found.** User models use Laravel Notifiable and email uses the default queue, but the inspected migrations do not define a generic notifications table. In-app alert history therefore needs an explicit M10 persistence design (incident list can be the authoritative in-app surface).
7. **Site Health has no persisted per-dimension source freshness.** Current `DimensionResult` is computed with state/reason/details; upstream records have timestamps. M10 must derive freshness from authoritative source timestamps and never mark stale evidence healthy.
8. **Connector cadence is already defined.** Preserve `Connector::presence()` thresholds and current heartbeat/poll contract; do not create a second presence timer. Use runtime capabilities as optional telemetry and distinguish unsupported from failing.
9. **The deployment baseline is single-VPS oriented.** M9 deployment documentation describes MySQL-backed queue/cache/session, default and backup workers, UTC scheduler and a guarded pause/lock wrapper. M10 can observe those configured components but cannot safely infer process liveness from queue rows alone.

## Implementation phases

1. Contract: stable check/reason identifiers, evidence allowlists, source timestamp/freshness, reason taxonomy, backwards-compatible mapping to `HealthStatus` and `Presence`.
2. Durable state: latest observations, append-only transitions, deduplicated incidents, delivery outbox, bounded maintenance windows.
3. Source adapters: current Site Health, Connector presence/report, backups, security, M9 DR, SystemStatus, DB queues and scheduler.
4. Safe platform health: queue/worker pulse and task completion ledger, monitor-pipeline self-health, low-frequency provider evidence.
5. Active website probes: validated managed origins, SSRF/DNS-rebinding defenses, bounded HTTP/TLS/DNS checks, distributed batch scheduling.
6. Incident/noise policy: confirmation, recovery, correlation, cooldown, flapping, maintenance suppression.
7. Delivery and UX: Owner in-app/email, scoped Operator incidents; no customer alert delivery by default.
8. Retention, security hardening, MySQL concurrency and scale validation.
9. Exact-SHA CI, guarded staging deployment, synthetic healthy/failure/recovery/maintenance E2E, closure evidence.

## Deferred non-goals

AI/predictive actions, M19-M21 consumers, autonomous remediation, Slack/Teams/Discord/PagerDuty, M23 SLA reporting, customer-configurable alert rules, customer email delivery pending explicit policy, registrar/domain expiry, DNS changes, a host agent, APM/tracing replacement, production rollout, and selecting/provisioning an independent external dead-man provider.

## Implementation constraints

- Preserve M8 restore and M9 backup/recovery behavior/evidence.
- No active checks against arbitrary or customer-supplied URL strings; use the website's server-validated canonical origin only.
- Do not create one scheduler event per site or persist every unchanged healthy poll.
- Use MySQL concurrency evidence for atomic incident/outbox claims; SQLite-only tests are insufficient.
- Infrastructure incident recipient is Owner; site incident recipient is Owner/authorized Operator; customer delivery remains disabled.
- Staging E2E only uses synthetic or explicitly approved targets and does not invoke WebsiteJobs, backups/restores, payment, DNS mutation or customer mail.

