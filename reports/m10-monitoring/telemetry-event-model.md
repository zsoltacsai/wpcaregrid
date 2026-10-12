# M10 telemetry and event model

## Proposed persistence

Use one generic monitoring schema rather than one health table per subsystem. These are planning names, not existing tables or migrations.

1. **MonitoringResult** — latest snapshot, unique `(check_id, scope, subject_key)`. Store normalized state/reason/source, source/observed/valid-until timestamps, duration, allowlisted evidence JSON, and sequence/version. Update on every evaluation but avoid storing full repeated success history.
2. **MonitoringTransition** — append-only transition/event history for state changes, incident open/escalate/resolve, maintenance suppression, and pipeline errors. Index by subject/check/time and correlation key. No update/delete through application UI.
3. **HealthIncident** — one current lifecycle record per dedupe key/generation with first/last seen, confirmed/open/resolved timestamps, severity, count, acknowledgement metadata, and resolution reason. Unique constraint on active dedupe identity; transitions remain append-only.
4. **AlertDelivery** — transactional outbox row by incident transition/channel/recipient, status, safe error category, attempts and timestamps. Store no provider secret or full message body.
5. **MaintenanceWindow** — scoped approved interval and audit actor; one row may cover a site, deployment, or platform/provider check family.

The registry, thresholds, evidence allowlists and reason-code schema stay in source-controlled code/config. Owner UI may later edit bounded cadence/retention settings; it must not create executable checks, destinations or arbitrary URL targets.

## Event contract for later M21 consumption

Use a versioned envelope: `schema_version`, `event_id`, `event_type`, `occurred_at` UTC, `scope`, `subject_key`, optional internal `tenant_key`, `check_id`, `state`, `severity`, `reason_code`, `source`, `correlation_key`, `evidence` (allowlisted), and `fresh_until`. Event types are namespaced and stable, e.g. `monitoring.website.http.state_changed`, `monitoring.connector.presence.changed`, `monitoring.backup.rpo.missed`, `monitoring.platform.scheduler.stale`, `monitoring.alert.delivery.failed`.

This is a durable domain event/history contract, not an AI bus. Future consumers read permission-filtered, versioned events; they do not subscribe to secrets or unrestricted raw Connector reports. M19/M20/M21 need explicit schemas, retention and tenant controls before consumption.

## Initial reason-code taxonomy

**HTTP/TLS/DNS:** `http.unreachable`, `http.timeout`, `http.status_unexpected`, `http.redirect_loop`, `http.latency.high`, `tls.handshake_failed`, `tls.hostname_mismatch`, `tls.chain_invalid`, `tls.expiring`, `dns.nxdomain`, `dns.resolver_failed`, `dns.address_changed`.

**Connector/WordPress:** `connector.never_connected`, `connector.delayed`, `connector.stale`, `connector.disconnected`, `connector.revoked`, `connector.version_incompatible`, `wordpress.rest_failed`, `wordpress.health.attention`, `wordpress.maintenance`, `wordpress.cron.overdue`, `wordpress.disk.low`, `wordpress.temp_unwritable`, `wordpress.capability.unavailable`.

**Backups/security:** `backup.no_verified_backup`, `backup.rpo_missed`, `backup.failed`, `backup.stuck`, `backup.storage_unavailable`, `backup.staging_space.low`, `security.feed.stale`, `security.scan.overdue`, `security.scan.failed`, `security.finding.critical`.

**Platform:** `platform.http.unhealthy`, `platform.database.unavailable`, `platform.database.latency_high`, `platform.migration.pending`, `platform.config.required_missing`, `platform.queue.failed`, `platform.queue.depth_high`, `platform.queue.oldest_age_high`, `platform.queue.worker_stale`, `platform.scheduler.stale`, `platform.scheduler.task_overdue`, `platform.scheduler.paused`, `platform.storage.unavailable`, `platform.storage.reconciliation_required`, `platform.backup.stale`, `platform.backup.verification_failed`, `platform.mail.delivery_failed`, `platform.stripe.webhook_stale`, `platform.monitoring.pipeline_stale`, `platform.alert.delivery_failed`.

Unknown error codes map to `monitoring.check.failed` plus a bounded safe category; they never create user-facing text directly.

## Retention and volume controls

- Latest results: one row per active check/subject, no time series table growth.
- Detailed raw probe evidence: 7 days, then aggregate/trim; no response body retained.
- Transition and incident history: 90 days online initially; preserve security/audit evidence under its existing policy. Retention must be configurable only after policy review and must not delete unresolved incidents.
- Alert delivery status: 90 days; audit evidence for Owner setting/maintenance/acknowledgement actions uses existing audit retention.
- Hourly/daily aggregates for reporting can be added only when a real M23/reporting need exists.

At 10,000 sites, one 5-minute HTTP check per site is 2.88 million scheduled evaluations/day. Persisting all raw successes is wasteful. Keep latest-result upserts, event-only transition writes, splayed bounded queue batches, sampled diagnostics, and measured retention. Do not enable 5-minute cadence fleet-wide until load testing demonstrates capacity.

