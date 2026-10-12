# M10 — Monitoring, Alerting & Infrastructure Health

Status: planning only. No application or infrastructure changes were made.

## Evidence baseline

The inspected primary branch reference is `origin/claude/ecstatic-wozniak-oemyuf` at `60f1482c767c659cf98b880f6735b33161e27804`. Its M9 closure report records a successful encrypted S3 platform database backup, remote readback/hash/manifest validation, and import into disposable MySQL 8.4.11; M9 is marked closed. Staging executable code is reported as `3eeba893d6b63ae7ca81ac0caf56e0d745134f85`. These are repository/report findings, not new live checks in this planning task.

The active workspace checkout is older (`f0194bca2d58b8eafccb1dfd1e3fc6176ed132de`, 54 commits behind its origin-tracking ref when inspected) and contains pre-existing local changes: a modified route and untracked `.claude/` and `=` entries. They were preserved. Current architecture claims below were cross-checked against the clean M9 worktree at `ce86a16ceee8b9635ccf666893edfe5d65decdae`, merged source at `60f1482…`, and the M9 reports/docs. No checkout, branch, deployment, runtime, or provider state was changed.

## Verified reusable components

| Concern | Existing implementation | M10 implication |
|---|---|---|
| Website health | `App\SiteHealth\SiteHealth`, `DimensionResult`, `SiteHealthReport`; dimensions are Connector, WordPress, backups, updates, security. Overall is worst known state; unknown is not treated as healthy. | Extend the one calculation with fresh evidence; do not create a second website score. |
| Connector presence | `Connector::presence()` uses `last_seen_at`; configured boundaries are 2,700 seconds for connected/stale and 86,400 seconds for stale/disconnected. The connector heartbeat is documented at 15 minutes. | Consume this source of truth; avoid another heartbeat state machine. |
| Website findings | `HealthIngest` validates Connector health reports and updates website health fields; inventory timestamps and Connector version/capabilities are stored. | Normalize named facts to stable reason codes while keeping the Connector payload contract bounded and versioned. |
| Owner platform status | `Owner\SystemStatus` already reports application, DB/migrations, queue, scheduler, Connector API, Stripe configuration, Cloudflare read diagnostic, backup storage, security scanner, mail configuration and safety switches. `system:check --json` is a machine-readable read-only entry point. | Reuse checks and safe summaries; split expensive/remote probes from page rendering and give each observation an explicit freshness timestamp. |
| Customer/site backup health | Existing `BackupPolicy`, `BackupStatus`, backup records, `SiteHealth` backup dimension, transfer/monitor/retention commands and storage verification. | Read the engine's verified/failed/stale truth; never infer success from object existence or create parallel backup status. |
| Platform DR | M9 platform backup/delivery models, recovery health enum, S3 destination, `platform:database-backup-reconcile`, recovery mode and typed `PlatformRecoverySignal` events. | Adapt these signals into monitoring observations; the signal event is not itself a durable incident/history service. |
| Security | Security scans/findings/incidents and `SecurityPosture` provide subsystem-specific facts. | Link or reference existing incidents; do not replace their workflow or finding state machine. |
| Scheduling | `routes/console.php` uses a single scheduler, `withoutOverlapping()` and `onOneServer()`; tasks include heartbeat, backup/security monitors, and presence update. M9 deployment docs describe UTC and a guarded scheduler wrapper. | Use a bounded dispatcher and shared distributed locks; account for deployment pause/maintenance explicitly. |
| Queues | Documented database queues have default and backups workers; `SystemStatus` checks failed/depth/oldest available jobs and transfer retry safety. | Add queue age/reserved/stuck and worker-liveness evidence, not only failed-job totals. |
| Audit / history | Append-oriented `admin_audit_events`, `website_events`, and security incident events exist. | Keep operator configuration/acknowledgement actions in audit; operational result history needs a distinct retention-aware model. |
| Authorization / consoles | Owner routes require verified Owner authorization plus named abilities; Operator routes have granular capabilities and scoped website bindings. | Add routes under the same authorization patterns; global infrastructure detail Owner-only, site incidents permission-scoped. |
| Notifications | Users use Laravel `Notifiable`; the default worker sends queued email. No generic in-app notification store/table or M10 alert delivery model was found in the inspected migrations/source. | Add in-app delivery deliberately; email delivery is an adapter, not the incident record. |

The repository search found no general website HTTP/TLS monitor, generic MonitoringCheck/Incident pipeline, monitoring maintenance-window model, or customer notification policy. Absence in the inspected code is not proof that no external monitoring service exists; provider-side monitoring was not inspected.

### Post-M9 implementation reconciliation

`SystemStatus` is computed at request time, so it is suitable as a current operator diagnostic but not as a transition history or incident engine. Its current queue check is centered on the default database queue, failed-job count, available age, and backup transfer retry safety; it does not prove that the named Supervisor workers are alive or measure each queue independently. Its scheduler check reads the existing one-minute cache heartbeat, not a per-task completion ledger. Stripe/mail checks are predominantly configuration or cached explicit verification, and backup-storage health is based on recent verified artifacts/staging space. These distinctions are detailed in `implementation-baseline.md` and are required for honest M10 state labels.

The existing Owner System page also performs a read-only Cloudflare diagnostic while computing its snapshot. M10 should replace repeated page-render-time provider calls with bounded scheduled observations, then render the latest result and freshness. M9 platform recovery signals are event objects and backup/delivery records, not a generic durable monitoring pipeline. M10 adapters must not rewrite M9 records or change its verification contract.

## Recommended architecture

1. **Check registry in code.** Stable identifiers and evaluators are versioned in source. Each evaluator declares scope, cadence, timeout, freshness limit, safe evidence schema, and whether its source is a local observation, Connector report, or active probe. Do not let database-stored rules create arbitrary URLs, destinations, or code.
2. **Bounded scheduler dispatcher.** One scheduler task claims due check slots in UTC, spreads work over time, and enqueues idempotent batches. Use a unique `(check_id, scope, subject_key, slot)` claim and a distributed lock. Do not schedule one Laravel schedule entry per website and do not issue an all-sites fan-out at a single minute.
3. **Latest result plus transitions.** Upsert one latest result per check/scope/subject. Append a transition/incident event only for meaningful state changes. This avoids a write for every healthy poll and preserves an operational timeline.
4. **Incident state machine.** Open only after a check-specific confirmation threshold; update the active incident without creating duplicates; resolve only after recovery confirmation. A stable dedupe key identifies one open incident per check and subject, with optional provider/region correlation key.
5. **Delivery outbox.** Persist notification intent transactionally with incident transition, then queue delivery. Unique incident/transition/channel/recipient keys make retries idempotent. Delivery failures remain visible and never roll back health state.
6. **Separate aggregates.** Existing Unified Site Health remains the customer-site aggregate. Add Infrastructure Health for WP CareGrid dependencies. Neither aggregate is a numeric score. A maintenance window suppresses alert delivery while underlying state/evidence continues to update.
7. **Read-only provider observations.** Probes must be bounded, cadence-limited and explicitly classified as configuration, recent operational evidence, or live connectivity. A configured credential is not proof the provider is available.

## Core architectural invariants

- Unknown or stale telemetry is never green.
- Every displayed state includes a stable reason code, source, observed time and freshness.
- Raw free-text exceptions are diagnostic-only, redacted, size-bounded, and are not decision inputs.
- Tenant scope is enforced in query/service boundaries and in unique keys; website evidence cannot cross tenants.
- Monitoring uses no destructive Connector jobs and no autonomous remediation.
- Every active probe has explicit timeouts, concurrency limits, egress/SSRF controls and a maximum request rate.
- M8 restore gates, M9 backup/DR records, Connector pairing and security finding states remain authoritative.

## Internal monitor limitation

An in-process checker cannot alert when its own VPS, DNS, network route, database or queue is completely unavailable. M10 can detect this through an independent external check of a minimal public health endpoint and a heartbeat/dead-man signal, but selecting/operating a third-party monitor is outside repository evidence. Keep core application checks implementable without that service; classify independent external monitoring as a recommended production-launch dependency and Owner/provider decision, not a fake capability.

## Boundaries

M10 includes deterministic monitoring, Owner/Operator presentation, in-app/email delivery, safe probe scheduling, noise suppression, state history and staging validation. It excludes AI, predictive decisions, auto-remediation, advanced uptime SLAs, full APM/tracing, host-agent installation, customer-configurable rules, registrar integration, and Slack/Teams/Discord.

