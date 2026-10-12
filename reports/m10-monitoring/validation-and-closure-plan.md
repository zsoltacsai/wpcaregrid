# M10 validation, rollout and closure plan

## Test layers

**Unit:** state ranking; status/reason taxonomy; freshness; UTC boundary and DST presentation; debounce/thresholds; dedupe key; incident open/escalate/resolve; flap cooldown; maintenance overlay/expiry; provider correlation; safe evidence allowlist and redaction.

**Feature:** Owner-only infrastructure pages and configuration; Operator website-scoped incidents; acknowledgement/audit; current Site Health compatibility; backup/security source adapter mapping; Owner/Operator recipient policy; no Customer delivery by default; monitoring status unknown when source stale or unsupported.

**Integration:** fake HTTP origin; expected/unexpected status, latency, redirects, DNS error and timeout; local TLS fixtures for expiry/name/chain cases; Connector heartbeat aging and reported health; queue/scheduler/worker state; S3/provider mocks and safe read-only checks; email fake/failed transport; in-app outbox retry.

**Concurrency (MySQL):** simultaneous scheduler claims; duplicate evaluator runs; unique open incident; open/resolve races; duplicate outbox delivery; retention racing unresolved incident. SQLite is useful for ordinary behavior but is not a substitute for MySQL atomicity evidence.

**Abuse/security:** private IPv4/IPv6, mapped addresses, link-local, cloud metadata IPs, DNS rebinding, redirect to private address, URL userinfo/custom port, giant response, slow body, tenant-crossing identifiers, query/token redaction, forged maintenance scope, mass email rate abuse, operator access to another tenant, secret scan.

**Load:** start with synthetic 100 sites, then 1,000; only test 10,000 if bounded queue and staging capacity are explicit. At 5-minute per-site HTTP cadence, 100 sites average 0.33 probes/s (24,000/day); 1,000 average 3.33/s (288,000/day); 10,000 average 33.3/s (2.88 million/day). Scheduler splay smooths, not removes, total work. Record p95 queue delay, DB writes, probe concurrency, error rate, memory and notification volume. Persist only latest successes and transition events; raw success rows per poll would be unacceptable at 10k.

## Staging validation sequence

1. Verify exact candidate SHA, CI, clean staging checkout, current deployment identity and backup/restore gates; use the existing guarded deployment and its pre-deploy backup.
2. Enable checks in shadow/read-only mode for the M8 synthetic fixture and a dedicated harmless synthetic website only. Do not enable customer alerts.
3. Check healthy HTTP/TLS/REST and confirm evidence freshness; compare Connector presence to existing Owner state.
4. Use a test-owned failure stub or synthetic fixture behavior to trigger a confirmed HTTP/TLS failure; observe one incident and one delivery at most.
5. Recover target; verify two-sample resolution and one resolution event; repeat transient failures to validate no alert, then flap to validate suppression.
6. Declare a short maintenance window; prove observations continue while delivery is suppressed; let it expire and prove state resumes.
7. Exercise scheduler pause/deploy, queue backlog, alert mail failure, provider mock/unavailable state and monitoring pipeline stale behavior.
8. Verify Owner global view, Operator tenant-scoped view, no customer notification, no secret leak, and no WebsiteJob/action mutation.
9. Verify incidents, latest results and outbox final states; expire/dispose only synthetic test data under reviewed cleanup policy.
10. Record exact SHA, CI, timestamps, queue/scheduler state, synthetic target identity, test outcomes, skips, storage use, and remaining limitations in closure report; commit/push report only through normal milestone workflow.

No production rollout is required for M10 closure. Staging E2E must use a controlled synthetic target and must not create a customer site action, backup, restore, payment, email to a customer, DNS change or provider write probe unless specifically authorized in the later execution task.

## Objective closure criteria

M10 closes only when all pass:

1. Current source-of-truth map and stable versioned reason/event contracts reviewed.
2. Freshness, unknown, attention, critical and maintenance semantics deterministic and backward-compatible with Site Health.
3. Website HTTP/TLS checks pass healthy and synthetic failure/recovery scenarios with SSRF safeguards.
4. Connector stale/disconnected and WordPress runtime facts map correctly without duplicate presence logic.
5. Queue, worker, scheduler, database, storage and platform backup signals report timestamps and unknown/unavailable accurately.
6. Existing backup and security engines supply authoritative result state.
7. Incidents deduplicate under MySQL concurrency, resolve only after confirmation, and flapping does not spam.
8. Maintenance keeps observations but suppresses configured delivery and expires safely.
9. In-app and email delivery are idempotent; failures are visible; default recipients are Owner/Operator only.
10. Owner infrastructure UI and Operator scoped site view enforce authorization and show safe evidence.
11. Monitoring pipeline stale/failure is itself detectable through an independently functioning path; if no independent provider is configured, do not claim this criterion complete for public production monitoring.
12. Retention is bounded and cannot delete open incidents or M8/M9 evidence.
13. Security review and secret scan pass; no SSRF, tenant isolation, provider mutation or secret exposure regression.
14. Full required local/CI suite passes, including MySQL concurrency and browser/UI checks as applicable; every skip has reason and owner.
15. Staging synthetic E2E passes, app/queue/scheduler/Connector/site health are green, and alert delivery/dedupe/recovery evidence is retained.
16. Exact implementation SHA, CI run, staging deployment, closure report SHA and no production deploy are documented.

## Required validation report fields

Report exact test/CI job counts; passed/failed/skipped/assertions; DB engine and concurrency gates; synthetic check IDs; probe cadence, timeouts, and resulting measurements; incident and delivery transition IDs; maintenance tests; staging SHA; config/queue/scheduler state; known skipped providers; security scan; and limitations. Mocked provider checks must never be called a real-provider validation.

## Owner decisions and recommended defaults

- No further Owner decision is needed to implement the conservative M10 core if defaults below are accepted in the implementation task.
- Default recipients: Owner for infrastructure; Owner plus assigned Operator for critical website incidents; no customer email.
- Default active website probe: every 5 minutes, with jitter; daily TLS; UTC schedule; per-tenant limits and gradual enablement.
- Default alert policy: 3 consecutive HTTP failures, 2 successful recovery samples, 30-minute repeat cooldown, maintenance max 24h for site / 2h for deploy.
- Before claiming production-grade self-monitoring, Owner must choose an independently administered external dead-man/uptime monitor and an independent notification destination/cost. This is a launch-hardening decision, not a blocker to building and staging the core M10 functionality.
- Domain registrar expiry, customer alert preferences, customer email, external chat integrations and AI consumers require separate product/privacy decisions and are out of M10.

