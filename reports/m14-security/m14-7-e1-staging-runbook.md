# M147-E.2 Controlled Staging Scanner Runbook (Proposal)

**Execution state: NOT EXECUTED — OWNER APPROVAL REQUIRED.** This runbook was authored during M147-E.1 Level 2 offline planning. It is not a command authorization, staging access approval, or permission to alter configuration. The six live M12 gates remain UNKNOWN.

## Operating constraints

- Target under consideration in repository documentation: Laravel `staging.wpcaregrid.com`. This is an intended name only; confirm actual identity at run time. No WordPress site is approved or identified by this plan.
- Exact app SHA, Connector package/source fingerprint, paired site, synthetic tenant, operator, window, queue and storage state must be separately approved and recorded before any E.2 action.
- No operation against production, a customer site, customer data or shared storage. No live feed fetch, Stripe request, AI call, remediation, quarantine, restore or destructive retention.
- Do not paste `.env`, credentials, API keys, raw request payloads, customer content, backup metadata or unrestricted logs into the evidence record.
- A scan can write application records and queues; qualifying suspicious-file evidence can open an incident and request a full backup because both incident auto-open and auto-backup default true in repository config. Scan cancellation does not reverse effects already completed.

## Phase 0 — Authorization verification

The future execution lead must complete this form and obtain separate Owner Level 3 approval. Blank or ambiguous fields mean STOP.

| Field | Required evidence |
|---|---|
| Staging application identity | Host, environment label, independent identity signal; must not rely on a user-provided URL alone. |
| Laravel target | Full 40-character merged-primary SHA and release receipt/runtime HEAD match. |
| WordPress synthetic target | Explicit Owner-approved site identifier, WordPress URL/host, tenant/customer/site ownership proof, and statement that it is disposable and contains no customer information. |
| Connector | Exact version, API/capability list, artifact SHA-256 and source revision; installed package and pairing must match. |
| Operator | Named approved account and minimum required ability; no credential values in evidence. |
| Operation list | Explicit operation IDs from the risk matrix; no catch-all “scan as needed.” |
| Window / stop owner | UTC start/end, responsible operator, escalation contact, and who may call STOP. |
| Cleanup | Named cleanup owner, exact marker/IDs, evidence custodian, and independent readback owner. |
| Recovery | Fresh backup evidence and recovery method applicable to the planned operation; acknowledge code rollback does not undo database changes. |
| Approval | Written Level 3 approval tied to exact identities, SHAs, operation IDs, window, cleanup, and stop conditions. |

## Phase 1 — Read-only preflight

This phase is **not run** in M147-E.1. After separate authorization, read only the minimum metadata below. Do not run a scan, feed sync, scheduler command, queue worker, backup, restore or migration as a “preflight.”

| Check | Safe evidence | PASS condition | STOP condition |
|---|---|---|---|
| Six M12 gates | Six booleans only, approved method, timestamp, environment identity and app SHA. See [readiness plan](m14-7-e1-readiness-plan.md). | All six are explicitly false. | Any true, unknown, unreadable, stale or inconsistent result. Never alter a gate to pass. |
| Application / environment | Receipt/runtime SHA and approved host/environment identity. | Exact equality to authorized staging candidate. | Host ambiguity, production identity, mismatch or unverified receipt. |
| Site / tenant | Owner-approved synthetic identifiers and ownership; no production/customer link. | Exact synthetic target and isolated storage. | Any customer identity, shared destination or uncertain ownership. |
| Connector | Installed version, source revision, package fingerprint, API and capability metadata; pairing state for approved site. | Exact approved artifact and active pairing only to that site. | Any mismatch, extra paired target, revoked/unknown pairing, unsupported capability. |
| Scanner/feed | Effective scan settings, vulnerability-source category, index age/status; do not display feed URL credentials/key. | Planned scanners available or explicitly declared unavailable; no unsupported clean inference. | Feed-dependent test without approved provenance/freshness; unknown config. |
| Side effects | Incident auto-open/backup toggles, remediation/plugin-deactivation flags, notification route metadata. | No unapproved effect can reach real systems; only specifically authorized effects are bounded. | Any unbounded backup, notification, incident or remediation path. Defaults are not assumed. |
| Queue/workers/scheduler | Worker health, queue depth/reservations, active scan, scheduler heartbeat and scan-schedule setting. | No collision or outstanding work; approved worker path. | Active/unknown job, pending scheduled scan, competing deployment or stale worker. |
| Backup/storage | Destination identity and prefix metadata, permissions, retention/deletion state, freshness and available space; no object download. | Isolated authorized storage and credible recovery for approved effects. | Shared/customer/production storage, unknown permissions, insufficient space or no recovery. |
| Network/logging | Egress policy, configured integration destinations as redacted hostnames/categories, log access/redaction. | Only approved endpoints; evidence can be collected safely. | Unknown egress, secret-bearing evidence exposure, customer notification route. |

## M12 gate verification worksheet

The actual live values remain **UNKNOWN**. Do not fill this worksheet until E.2 has separate approval.

| Gate identifier | Config key | Expected | Observed | Observed UTC | Environment/SHA | PASS/FAIL/UNKNOWN | Operator |
|---|---|---|---|---|---|---|---|
| `M12_DESTRUCTIVE_RETENTION_ENABLED` | `privacy.destructive_retention_enabled` | false | UNKNOWN | — | — | UNKNOWN | — |
| `M12_PROFILE_ANONYMIZATION_ENABLED` | `privacy.profile_anonymization_enabled` | false | UNKNOWN | — | — | UNKNOWN | — |
| `M12_BACKUP_DELETION_ENABLED` | `privacy.backup_deletion_enabled` | false | UNKNOWN | — | — | UNKNOWN | — |
| `M12_PROVIDER_DELETION_ENABLED` | `privacy.provider_deletion_enabled` | false | UNKNOWN | — | — | UNKNOWN | — |
| `M12_BULK_RETENTION_EXECUTION_ENABLED` | `privacy.bulk_retention_execution_enabled` | false | UNKNOWN | — | — | UNKNOWN | — |
| `M12_SYNTHETIC_PROFILE_ANONYMIZATION_ENABLED` | `privacy.synthetic_profile_anonymization_enabled` | false | UNKNOWN | — | — | UNKNOWN | — |

Candidate future read method is `php artisan config:show privacy`, subject to installed-framework and no-side-effect verification before use. Capture only the six boolean fields, never full environment/config output. If the command output contains unrelated sensitive values or safe behavior is unverified, stop and design a reviewed safer read method; do not implement an endpoint as part of E.2 execution.

## Phase 2 — Synthetic scanner validation proposal

Use the smallest operation set needed to answer approved questions. Start with a **no-finding synthetic baseline** on the exact disposable site. A vulnerability-only run exercises server matching and does not prove Connector scanner availability. Connector `integrity`/`malware`/`full` runs require the approved pairing and resource limits.

Do not add a suspicious-file marker that can meet the production detection threshold while `security.incidents.auto_open` or `security.incidents.auto_backup` can produce real staging state. The repository defaults both to true. If the Owner wants incident/backup behavior validation, choose one separately approved route:

1. Test the behavior with local fake queue/storage/incident dependencies only; this is not staging evidence; or
2. Approve a tightly bounded staging-side effect after verifying the exact synthetic target, recipients, bucket/prefix and recovery. Otherwise that scenario remains BLOCKED and is not run.

No malware executable is required or allowed. Use inert fixture content only if a reviewed rule maps it to a benign synthetic finding and the side-effect boundary is proven first. No live Wordfence feed request: if source is unavailable, report `unavailable`/incomplete and do not represent the scan as clean.

## Phase 3 — Evidence capture

For each approved operation, record a single structured evidence row:

```text
operation_id, start_utc, end_utc, operator_id, staging_identity,
app_sha, connector_version, connector_source_revision, connector_package_sha256,
synthetic_tenant_ref, synthetic_site_ref, scan_uuid, request/correlation_ref,
scan_type, per_scanner_coverage, expected, actual, posture_before, posture_after,
incident_delta, backup_delta, queue_delta, notifications_delta,
network_destination_categories, disk_before_after, log_event_refs,
cleanup_owner, cleanup_result, classification, limitation
```

Use opaque synthetic references, counts and status codes. Exclude tokens, feed URLs/keys, email addresses, user/site content, file contents, secrets, full logs and raw request/response bodies. Preserve CI/run references and exact source revisions. Classifications are `PASS`, `FAIL`, `BLOCKED`, or `UNAVAILABLE`; absence of evidence is not PASS.

## Phase 4 — Cleanup and post-run checks

1. Stop initiating new scans; do not stop shared workers without separate authorization.
2. Identify only jobs by the approved scan UUID and synthetic site. Verify queue state and reservations before any cleanup.
3. Use only an approved, marker-scoped synthetic cleanup operation. Do not invoke `backup:retention`, bulk deletion, provider deletion, anonymization, or broad table/file cleanup.
4. Preserve a real backup if one was separately authorized; disposal needs a separate approval and must not be inferred from synthetic cleanup approval.
5. Reconcile incident rows, event timeline, backup requests and any notifications by the exact synthetic IDs. Do not close/rewrite incidents merely to make counts zero.
6. Confirm Connector version/pairing, scanner config, queue/scheduler/worker health and site health against preflight values. Any unexpected change or incomplete cleanup is STOP; preserve evidence and request Owner direction.
7. Have a second authorized person verify cleanup and all evidence redaction where available. If no independent reviewer exists, document that limitation; do not claim independent verification.

## Recovery distinction

Application rollback, synthetic-record cleanup, database restore, filesystem restore, and full environment recovery are separate actions. The deployment guide says migrations are not automatically rolled back and a guarded failure can leave traffic gated. A pre-deploy database backup is not proof that restore works. Current staging recovery state is unknown, so execution remains NO-GO until recovery evidence and the exact authorized recovery option are supplied.

## Stop conditions

STOP before or during execution for any M12 gate not explicitly false; wrong host/site/SHA/fingerprint; discovered customer or production linkage; unknown queue/worker state; feed-required operation with unavailable/unapproved feed; unbounded incident/backup/notification effect; remediation/quarantine reachable; unexpected outbound destination; resource threshold exceeded; unexpected state mutation; secret exposure; failed cleanup; or operator uncertainty. On stop, do not attempt broad cleanup or rollback; preserve the scoped evidence and use only the preapproved recovery route.
