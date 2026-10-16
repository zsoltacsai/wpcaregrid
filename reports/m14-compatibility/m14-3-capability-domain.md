# M14.3 Capability Domain

## Decision boundaries

There are four independent decision layers: (1) capability evidence/policy, (2) tenant and actor authorization, (3) subscription/operation entitlement and workflow state, (4) safety gates/locks/recovery controls. A capability PASS cannot bypass the other three. Connector-reported facts are authenticated but remain customer-controlled and untrusted for platform authority.

## Capability vector

| Capability | Evidence required (initial contract) | Key blockers / unknowns |
|---|---|---|
| CONNECTION_READY | active authenticated Connector, API/protocol compatibility, recent heartbeat/poll | revoked/offline, unsupported protocol, stale contact |
| MONITORING_READY | connection, health/inventory support, current site heartbeat and probes available | offline, check unavailable, unknown facts are not “healthy” |
| UPDATE_READY | update job capability; supported single-site standard layout; fresh readable/writable target and temp semantics; required verified pre-update safety backup; no update/restore conflict | Multisite/custom root, unknown containment, read-only target, conflict, missing required safety backup |
| BACKUP_READY | one or more advertised backup scopes, supported DB for DB/full, readable eligible files for file scope, temp/disk budget, authenticated upload path | unsupported DB, unreadable required scope, insufficient/unknown space, transfer unavailable |
| RESTORE_READY | compatible Connector; standard proven root; required file/DB capabilities; verified same-site backup and required objects; compatible manifest; current resource checks; no active conflict/quarantine; required safety backup; operation authorization and feature gates separately satisfied | any unsupported/unknown root, invalid/missing artifact, disk/DB/filesystem blocker, stale facts, active conflict, unsafe gate |
| SECURITY_SCAN_READY | scan capability, supported safe traversal, required resources, rules/feed availability and freshness reported separately | unavailable scanner/feed => WARN/UNKNOWN; readiness never means scan completed or clean |
| SECURITY_REMEDIATION_READY | remediator capability, proven path/scope, fresh authorization, current reversible target and existing remediation safety conditions | unsupported layout/symlink ambiguity, missing facts, invalid scope, disabled safety control |
| ADMIN_ACCESS_READY | admin-access capability, active connection, customer-authorized existing flow, temporary-account cleanup mechanism and current authorization | no preflight account creation; unavailable cleanup/connection or revoked consent blocks |

## Status semantics

- `PASS`: current, policy-versioned evidence proves the capability’s defined prerequisites.
- `WARN`: capability can proceed safely but an explicit, non-blocking limitation exists; reason and scope must be visible.
- `BLOCK`: known condition makes this operation unsupported or unsafe.
- `UNKNOWN`: required fact absent, malformed, stale, contradictory or check unavailable.
- `checked_at`, freshness deadline, policy version and source versions are metadata. A stale required fact becomes `UNKNOWN`; stale is not a fifth status. Preserve prior snapshot for audit/history, but do not use it as authority.

For any mutation/restore, `WARN` is permitted only for explicitly enumerated non-safety warnings. `BLOCK` and `UNKNOWN` deny queue/dispatch. Re-evaluate dynamic facts at operation time; never trust an onboarding PASS months later.

## Evidence and persistence

Proposed per-site snapshot: site ID, policy version, preflight schema version, checked time, facts with source/time/normalized value, capability status/reason-code vector, Connector/WordPress/PHP/DB versions, check duration and correlation ID. Do not persist credentials, raw config, full paths, raw phpinfo, customer content, IP topology or unneeded identifiers. Store path/layout as enums; filesystem detail as categories. Keep latest snapshot plus a bounded history of meaningful state transitions, not every heartbeat.

One migration is anticipated unless current schema is extended safely: latest snapshot table/record plus bounded transition records or a capped JSON history. Backfill is harmless evaluation only. New rows default `UNKNOWN`; rollback removes only new capability data/integration and must not touch site/customer records. Avoid table-per-capability and avoid duplicating health/inventory facts where source timestamps are available.

## Policy versioning and freshness

Policy is named/versioned (initial `compatibility-policy-v1`) with evaluator version. Persisted results retain the policy under which they were evaluated. A policy change marks old results stale/re-evaluation-needed; it does not mutate customer sites. Static facts (Connector/WP/PHP/DB engine/version, Multisite/layout, extensions) refresh on heartbeat/inventory and before sensitive operation; dynamic facts (connection, disk, filesystem write/rename probe, active conflict, artifact/object presence) have short or operation-time freshness. Deep/on-demand probes are not run every heartbeat. Exact TTLs are engineering constants to prove in load/scale tests, not Owner-set policy.

## Stable reason-code families

Initial candidates (final names follow code conventions): `CONNECTOR_OFFLINE`, `CONNECTOR_UNSUPPORTED`, `CONNECTOR_UPDATE_RECOMMENDED`, `FACT_MISSING`, `FACT_STALE`, `FACT_MALFORMED`, `MULTISITE_UNSUPPORTED`, `CUSTOM_LAYOUT_UNSUPPORTED`, `CANONICAL_ROOT_UNKNOWN`, `SYMLINK_ROOT_UNRESOLVED`, `PHP_UNSUPPORTED`, `PHP_EXTENSION_MISSING`, `DB_ENGINE_UNSUPPORTED`, `DB_VERSION_UNKNOWN`, `FILESYSTEM_READ_UNAVAILABLE`, `FILESYSTEM_WRITE_UNAVAILABLE`, `FILESYSTEM_RENAME_UNAVAILABLE`, `DISK_SPACE_INSUFFICIENT`, `DISK_SPACE_UNKNOWN`, `OUTBOUND_HTTPS_UNAVAILABLE`, `POLL_STALE`, `SCHEDULING_STALE`, `BACKUP_NOT_VERIFIED`, `BACKUP_OBJECT_MISSING`, `MANIFEST_INCOMPATIBLE`, `RESTORE_CONFLICT`, `SAFETY_BACKUP_REQUIRED`, `QUARANTINE_ACTIVE`, `AUTHORIZATION_REQUIRED`, `SAFETY_GATE_CLOSED`, `SCANNER_UNAVAILABLE`, `SCANNER_FEED_STALE`.

Codes are stable API/domain values. HU/EN message keys map to them. Do not include secrets, raw paths, server IPs or low-level exception strings in customer messages.

## Connector and version policy

Connector reports factual signals, never `supported=true`. Optional additive facts may remain API v1; malformed or absent facts become unknown. New payload likely implies Connector 0.4.7. No version bump happens in planning. Version comparison is capability-specific: current, supported-old, upgrade-recommended, unsupported, unknown-newer. A newer unknown Connector is not automatically blocked for read-only operations; high-risk operation facts/protocol must be understood. The staging 0.4.5 fixture remains a valuable supported-old test for unaffected operations; capabilities requiring M14.2 0.4.6 bounds/path protections must not receive PASS from 0.4.5 unless equivalent behavior is proven.

Known source mismatch: `config/connector.php` source default `latest_version=0.4.1`, while packaged source is 0.4.6 and staging system check reports latest 0.4.6. Fix/reconcile the default and test environment override before deriving outdated-version status.

## Evaluation and race policy

One canonical evaluator consumes facts and policy. Operation-specific readiness adds current workflow facts (backup integrity, object existence, locks/conflicts, safety backup). Immediately before queuing a high-risk job, recheck dynamic evidence and authorization. The Connector repeats local path/resource guards at execution; server readiness is not a replacement. If disk, permissions, layout or connection changes after preflight, reject safely with coded status and no mutation. Existing M14.2 containment/archive primitives remain authoritative.
