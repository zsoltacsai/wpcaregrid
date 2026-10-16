# M14.3 — Hosting Compatibility, Capability Preflight and Restore Readiness Plan

**Status:** Planning / compatibility audit only. No runtime changes or deployment authorized by this document.

## Verified baseline

- Authoritative branch: `claude/ecstatic-wozniak-oemyuf`.
- Verified remote primary: `94b9fbafe2c2ab89d81b677af82d0d3d956c0175`.
- Planning branch starts at that exact SHA; see the planning PR for its later documentation-only head.
- Latest verified executable staging SHA: `c5e0edabd8bd1661f72eb20330c5ca2f4375b9c5`; the guarded staging readback reported a clean checkout and healthy application/DB/queue/scheduler/workers/Connector/Stripe TEST/backup/mail/privacy/journal/recovery checks. This is the latest readback in this planning attempt, not a claim that staging cannot change afterward.
- Staging retained the visible warning “No completed security scan yet.” This remains AS-16 / M14.7 carry-forward.
- Source Connector package is `0.4.6`, API v1. The connected synthetic M8 staging fixture reports `0.4.5`; it was inspected, not upgraded or re-paired. Do not call it unsupported based on version recency alone.
- All six M12 destructive/synthetic gates were read false in the staging readback: global retention, profile anonymization, customer backup deletion, provider deletion, bulk execution and normal-staging synthetic execution. Planning does not change them.
- M14.1 and M14.2 closure evidence is retained. M14.2 provides bounded payloads/responses, safe egress/redirect rules, archive/path limits, path containment, restore staging and race/failure safeguards; M14.3 consumes these primitives and does not repeat their audit.

## Scope and closure question

M14.3 implements a single server-owned, versioned, per-site/per-operation capability evaluation. It decides what current evidence permits; it does not grant authorization, entitlement, destructive approval or an override. A customer site may be monitoring-capable but not restore-capable. Unknown evidence never becomes a positive mutation decision.

Approved boundaries are binding: capability support is evidence-specific (OQ-01); initial-launch Multisite is detected but unsupported for ambiguous/network-wide/destructive operations (OQ-02); Bedrock, Composer-managed, arbitrary custom-root and unresolved symlink-root layouts are unsupported for initial launch and must not be guessed (OQ-03). No new Owner decision is required.

## Source-derived architecture facts

- Connector API is v1; pairing and heartbeat report Connector, WordPress and PHP versions plus advertised job capabilities. Inventory includes WooCommerce presence/version and installation/update facts. Current payload does not include a canonical preflight vector for layout, filesystem, disk, DB or cron.
- The Connector capability registry is a whitelist of operations (`inventory`, `health`, `updates`, backup/restore variants, admin access and security operations). It is not a host-compatibility verdict.
- Connector 0.4.6 declares WordPress minimum 6.4 and PHP minimum 8.1. The real WordPress test harness currently pins WP 7.1 and PHP 8.2 as package constraints, and local E2E exercises WP 7.1.2/PHP 8.3.33. These are evidence points, not by themselves an approved complete customer support range.
- Database backup is advertised only when Connector code supports MySQL/MariaDB. Backup manifest includes database engine/version, charset, table prefix, sizes and hashes; files backup intentionally excludes symlinks and WordPress core. Restore has path-guarded staging, temporary tables, validation, safety backup, conflict state, one bounded compensation attempt and manual recovery state.
- Connector has an operation-specific disk check with a 50 MiB margin; disabled/unavailable `disk_free_space` is not treated as proof of sufficient space. Server `Connector::isOutdated()` compares against configurable `connector.latest_version`; source config currently defaults that value to `0.4.1` despite packaged release 0.4.6. Staging evidently has an environment/config value yielding 0.4.6. This default drift is a confirmed configuration finding for M14.3 implementation triage.
- Existing health dimensions, inventory, heartbeat/poll freshness, Owner system checks and Operator site views provide useful facts/surfaces, but do not currently persist a policy-versioned compatibility result. Current health status is not a restore-readiness guarantee.

## Architecture decision

Implement a capability evaluator fed by bounded factual evidence from the authenticated site Connector and current server workflow state. Connector facts are untrusted operational observations. Laravel owns policy, freshness, authorization and the final decision. Every operation checks tenant authorization, entitlement, job capability, current conflicts and safety gates independently of compatibility. There is no `site_supported` boolean and no Owner/Operator force path.

Eight capability outputs are proposed: `CONNECTION_READY`, `MONITORING_READY`, `UPDATE_READY`, `BACKUP_READY`, `RESTORE_READY`, `SECURITY_SCAN_READY`, `SECURITY_REMEDIATION_READY`, and `ADMIN_ACCESS_READY`. WooCommerce-specific evidence is conditional on a Woo-dependent operation rather than a blanket Connector prerequisite.

Each output uses `PASS`, `WARN`, `BLOCK`, or `UNKNOWN`; freshness is metadata. Expired required evidence evaluates as `UNKNOWN`, and unsafe mutating/restore operations treat `UNKNOWN` as not ready. Reason codes are stable identifiers; localized HU/EN messages are presentation mapping, never canonical state.

## Implementation sequence

1. **14.3-A — policy/domain and version drift:** define facts/reason codes, policy versions, status semantics, schema and source-version handling. Resolve the 0.4.1 default mismatch with focused tests.
2. **14.3-B — Connector fact collection/contract:** add optional factual fields (layout category, Multisite, relevant runtime requirements/probe results, DB/disk/cron signals) with strict bounds/redaction. Likely Connector 0.4.7; API v1 can remain if fields are optional and additive.
3. **14.3-C — server preflight and persistence:** collect/validate authenticated facts, evaluate once in a canonical service, persist latest snapshot and bounded meaningful transitions. Migration anticipated; default is UNKNOWN and migration alone cannot grant PASS.
4. **14.3-D — operation readiness integration:** connect backup/update/restore/remediation/admin-access starts to fresh relevant capability facts plus existing auth, entitlement, locks, verified backup/safety point and gates. Never turn readiness into authorization.
5. **14.3-E — diagnostics:** customer summary with safe reason/message; Operator detailed reason/version/age/measurement categories with redaction; minimal Owner aggregate only if existing screens support it without a new dashboard.
6. **14.3-F — compatibility fixtures/regression:** real WordPress SQLite/MySQL tests, fake network, isolated filesystem/quota, unsupported-layout fixtures and process tests for stale facts/races.
7. **14.3-G — guarded synthetic staging and audit:** exact merged SHA, strict SSH pin, synthetic site only, pre/post six-gate readback, safe temporary fixture cleanup and hosting audit disposition.

Expected review shape: 3 focused PRs (policy/data; Connector facts + server evaluation; operation gates/diagnostics and integrated closure), with split only if independent review materially improves safety. Do not create runtime code on this planning branch.

## Validation and release policy

Unit tests for fact validation and capability rules; feature tests for route/job effects; Connector real-WordPress SQLite and MySQL; isolated filesystem and fake network; MySQL/process tests for stale capability and duplicate/conflicting operations; full five mandatory CI jobs. Staging uses only exact merged SHA, strict approved SSH host key, a disposable synthetic site and isolated resources. No customer site, real provider deletion or M12 gate change. Rollback disables new evaluation/diagnostic integration through existing safe deployment rollback; persisted snapshots default unknown and may be re-evaluated without destructive migration.

## Boundaries / handoffs

### Superseding implementation-source reconciliation

The planning text above that describes `connector.latest_version` defaulting to `0.4.1` is superseded. Inspection of the authoritative primary and merged planning SHA shows that `config/connector.php` already reads the version from the canonical Connector `release.json`, with no environment override. F01 was a stale-source finding, not runtime drift. M14.3 should test the single-source relationship and should not change runtime version configuration.

- M14.4 owns commercial backup/GFS entitlements and extension-only retention controls; M14.3 only reports backup capability/readiness.
- M14.7 owns completed scanner campaign. `SECURITY_SCAN_READY` is not `SCAN_COMPLETED` and never means no vulnerabilities.
- M15 independently validates actual pilot hosts and DR; fixture success is not production-host evidence.
- The security scanner warning remains visible. No universal hosting/provider compatibility claim is made.
- M12 destructive execution stays disabled; M14.3 does not authorize production destructive behavior.

## Owner decisions and verdict

**NO NEW OWNER DECISION REQUIRED.** Version ranges and capability thresholds are engineering findings to establish with reproducible fixtures, not Owner preferences. Planning verdict: **A — M14.3 PLAN COMPLETE, IMPLEMENTATION CAN BEGIN AFTER REVIEW**. Implementation must wait for review of the planning PR. M14.4 and M15 remain out of scope.
