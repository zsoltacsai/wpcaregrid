# M10 health state and aggregation model

## Evidence and compatibility

Current `HealthStatus` values are healthy, attention, critical and unknown. Unified Site Health is deterministic: it aggregates existing Connector, WordPress, backup, update and security dimensions by worst known state. Unknown dimensions are visible and are not counted as healthy. Connector presence is derived from the existing Connector timestamps and configured thresholds. Owner `SystemStatus` is a separate current snapshot of platform/system checks.

Preserve these meanings. Do not add a numeric score. Do not reinterpret unknown as success. Add freshness metadata and new dimensions only through the existing domain seams after compatibility tests; new persisted monitoring state is proposed, not current schema.

## Normalized result

Every evaluation returns a typed result with:

- `check_id`: stable namespaced identifier;
- `scope`: `platform`, `website`, `connector`, or `provider`;
- `subject_key`: stable internal UUID/provider key, never a credential;
- `state`: `healthy`, `attention`, `critical`, `unknown`;
- `severity`: `info`, `warning`, `critical` for incident policy only;
- `reason_code`: canonical identifier, not free-text logic;
- `observed_at`, `source_at`, `valid_until`, and `duration_ms`;
- `source`: `active_probe`, `connector_report`, `domain_service`, `queue`, `scheduler`, or `provider_outcome`;
- allowlisted, scalar/structured evidence with bounded length and safe identifiers;
- optional correlation key for a shared provider/platform failure.

Store error text only in restricted diagnostic logs after secret and URL/query redaction; the result row should never hold arbitrary request headers, response bodies, stack traces, auth material, customer content, or APP_KEY.

## State semantics

| State | Exact meaning |
|---|---|
| Healthy | A valid, sufficiently fresh observation meets the check's success contract. |
| Attention | Fresh observation indicates a non-critical degraded condition or policy threshold, such as TLS expiring soon or delayed heartbeat. |
| Critical | Fresh evidence indicates a confirmed high-impact failure, such as DB unavailable, expired certificate, or confirmed public site outage. |
| Unknown | No valid evidence exists, the observation is stale, capability is unavailable, or a check could not run. UI must label why: not configured, not supported, stale, or probe unavailable. |
| Maintenance | Presentation/suppression overlay only. Underlying state and evidence continue to update. It must not imply healthy. |

For aggregation, rank only the underlying states: critical > attention > healthy. Ignore unknown only when at least one other dimension has valid evidence; if every dimension is unknown, aggregate is unknown. Expose each dimension so the aggregate cannot conceal a stale or unsupported check. Maintenance adds a badge and suppression note, not a rank.

`degraded` is not needed as a stored state in v1: existing `attention` already means degraded/noncritical. Infrastructure overview may use labels “Operational”, “Degraded”, “Critical”, “Unknown”, mapped deterministically from the same enum. No separate scoring weights.

## Freshness

Each check defines its own cadence and grace. A derived state is stale when `now > valid_until`; stale becomes unknown with reason `monitoring.result.stale`, not healthy. Connector staleness retains the canonical `Presence` state; M10 derives a monitoring incident from it, rather than changing source timestamps or thresholds silently. Provider `configured` is evidence of configuration only. “Recently verified” requires a timestamped successful operation/probe and a bounded freshness interval.

The first implementation should compute Site Health from current existing records plus the latest monitoring result for HTTP/TLS and availability; it should not persist another overall site state column. Cache only short-lived aggregate reads if invalidation is reliable. Platform Infrastructure Health is a separate aggregate and must not appear in customer-site health dimensions.

