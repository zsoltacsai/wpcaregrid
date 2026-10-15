# M12 Wave 7 — Destructive Safety Architecture

**Planning only.** No execution path, migration, runtime change, or destructive action is authorized by this document. The deployment invariant remains `M12_DESTRUCTIVE_RETENTION_ENABLED=false`.

## 1. Verified foundation and limits

| Area | Current evidence | Wave 7 consequence |
|---|---|---|
| Privacy request orchestration | `app/Privacy/PrivacyRequestService.php`, `PrivacyRequestStatus`, migration `2026_10_14_000002_create_m12_privacy_foundation_tables.php` | Wave5 can create/verify requests, restrict account access for closure, and restore access through a separate Owner path. Closure is not erasure; no profile anonymizer currently executes. Existing event/request FKs restrict deletion. |
| Impact/anonymization preview | `AccountClosureImpactPlanner`, `AnonymizationPlanItem`, `AnonymizationAction` | Read-only aggregate preview and typed future actions; blockers remain legal/accounting/security. It is not a mutation service. |
| Retention engine | `PrivacyRetentionPolicy`, `RetentionSourceScanner`, `config/privacy.php`, `DestructiveRetentionGate` | Wave4 is report-only. A policy predicate (`isExecutable`) is not a worker/provider client. Overall default gate is false; Wave7 must retain separate no-execution-by-default action gates. |
| Provider reconciliation | `PrivacyProviderTaskService` | Stores task metadata/states; it does not call provider delete APIs. Provider fact state must remain explicit. |
| Backup deletion | `BackupDeletion`, `Retention::apply`, `backup_object_deletions`, backup/restore/job models | Normal plan-based backup retention and M8 safe deletion exist. These are not a Wave7 account-erasure executor. Preserve active restore/update, per-object, last verified recovery point and provider readback checks. |
| Restore safety | Wave6 `PrivacyTombstoneJournal`, object-storage adapter, replay applier, restore safety/quarantine and persisted replay evidence | Independent journal/replay is present on the verified primary. Latest Wave6 report describes staging T0/T1 proof. Current staging was not re-observed in this planning-only round; use report evidence as last known, not as live current state. |
| Financial boundary | Invoice intent/events, billing identities, invoices, subscription, webhook and outbox migrations/models | Restrictive foreign keys and immutable snapshots protect some history; nullable FKs elsewhere do not mean the evidence may be deleted. Open billing state blocks affected execution pending accounting review. |
| Legal acceptance | `legal_document_versions`, `legal_document_acceptances`, checkout snapshot fields | Immutable version/hash/acceptance evidence and restrictive FKs. Wave7 must not rewrite or fabricate legal acceptance. |

The remote primary was fetched and verified at `697048bb490e2b5e43c4427d520aa28e09d4dcde`. The clean planning worktree is based on that exact SHA. The latest staging SHA recorded by the Wave6 report is `f4559904990f95cf801b76ddeb4a1561cb3392b2`; it was not independently queried in this documentation task.

## 2. Non-negotiable invariants

1. `M12_DESTRUCTIVE_RETENTION_ENABLED=false` at every environment. Wave7 must add no path that can execute merely by changing this environment flag.
2. Each destructive action has an independently disabled action capability; a future policy activation, scheduler tick, preview, or status transition never executes it.
3. Account closure is reversible access restriction; erasure is a separately requested and verified case (ER-01/ER-08).
4. A record is never deleted solely because a retention date elapsed. Eligibility is only an input to a subject-scoped preview and approvals.
5. No legal/accounting/provider/security `WAIT_*` state can be overridden by a generic Owner checkbox.
6. No customer site, WordPress database/file, Connector, remote credential, or customer backup is implied in a profile erasure scope.
7. No issued invoice, seller snapshot, tax evidence, correction/storno or payment/refund history is rewritten by profile anonymization.
8. Before an irreversible primary-DB change, a minimal independent journal event must be appended, read back, integrity-verified, and linked to the exact action. Failure leaves the case blocked and no mutation begins.
9. Restore quarantine remains active until journal replay, billing/provider reconciliation, privacy verification, health checks, and a separate authorized release all pass.
10. A provider request is not provider deletion. Confirmed absence means the expected namespace/object/version scope was independently reconciled.
11. No multi-customer batch in first destructive rollout; future default 25 is not authorization and remains inactive.
12. No customer-visible “fully erased” claim while required copies/actions are pending, unknown, failed, legally retained, or unverified.

## 3. Proposed two-layer state model

Do not overload `PrivacyRequestStatus` with worker retries or provider states. Keep the request/case lifecycle distinct from a future immutable execution plan and per-action task lifecycle.

### Request / case (reuse current states; add only by approved additive migration if needed)

`requested → identity_verification_required → verified → cooling_off → retention_analysis → review_pending → [blocked_policy | blocked_legal | blocked_accounting | manual_review] → prepared`

Terminal case outcomes: `cancelled`, `completed` (only after the approved completion semantics), or a reviewed retained/partial outcome. Existing Wave5 `access_restricted` belongs to reversible closure, not this erasure execution sequence. A new request is linked to a terminal case; never reopen it by mutating the old history.

### Execution plan (proposed, immutable approval snapshot)

`draft → previewed → stale | awaiting_approval → approved → executing → reconciling → verified_partial | verified_complete | failed_manual_review | cancelled_before_irreversible_step`

- One subject per plan; no cross-tenant/global action list.
- Preview binds request UUID, opaque subject UUID, allowed action list, policy versions, UTC evaluation timestamp, source watermark/version, exception and provider-fact references, and deterministic hash.
- Any changed source, policy, retention/hold state, provider capability, or journal watermark invalidates preview/approval; regenerate and re-approve.
- Approval is current Owner, recent re-auth, per W7-OP-01; product choice remains pending. No approval impersonation by Operator or background job.
- Immediately before action, re-check the exact source row and all blockers in a transaction/lock where supported (TOCTOU guard). Database transaction does not pretend to cover external provider calls.

### Per-action task

States: `planned`, `blocked`, `ready_for_approval`, `approved`, `dispatching`, `requested`, `confirmed`, `retained_legal`, `failed`, `unknown`, `manual_review`, `cancelled`. Use idempotency key derived from case/plan/action/provider-scope/object-version. Persist attempt and evidence metadata only; never put secrets, full provider payloads, or source PII in an error/audit field.

## 4. Execution sequence (future implementation, disabled)

`request + identity proof`
→ `cooling-off and legal-clock evaluation`
→ `retention / billing / site / restore / hold analysis`
→ `aggregate preview and coverage statement`
→ `immutable plan + approval hash`
→ `Owner approval + recent re-auth`
→ `final source/approval/provider recheck`
→ `append independent tombstone intent; verify readback/integrity`
→ `perform one explicitly allowed local action`
→ `verify local state; append outcome`
→ `provider task, if separately approved and supported`
→ `provider readback/reconciliation`
→ `privacy/accounting/history checks`
→ `per-action terminal outcomes and reviewed case outcome`.

For a backup delete, each provider object/version follows its own prepare/approve/request/readback cycle and must keep the last verified recovery point. For profile anonymization, write/verify the tombstone before changing current profile fields, revoke credentials/sessions and suppress messages, preserve the opaque relational key, and exclude immutable accounting rows.

## 5. Journal, restore and quarantine

Wave6 implementation is reused, not replaced. The primary DB restore cannot be the source of truth for post-snapshot privacy actions. A future action event should contain only opaque subject/tenant IDs, stable event/action IDs, allowed suppression instruction, ordering/watermark, schema version and integrity metadata. No previous email/name/address, export body, invoice payload, or full privacy request belongs in the journal.

- Check journal availability, supported schema, ordering and integrity before preparing irreversible work.
- If append/readback fails, stop before mutation; record safe error category and keep action blocked.
- After old DB restore, quarantine blocks queue workers/schedulers and all mutating WebsiteJob, backup, billing, provider and privacy tasks. Read-only health checks may remain available.
- Replay is deterministic and idempotent; unknown subject mapping, unsupported action/schema, duplicate conflict, missing object, or accounting mismatch remains quarantined/manual review.
- Replayed state suppresses restored identity/export/session state as supported by Wave6. It does not modify legal acceptance, invoice, seller, tax or historical payment evidence.
- Quarantine exit is a distinct Owner/SRE action after persisted session verification; process exit code alone is insufficient.
- Tombstone retention and independent backup/export policy remain `WAIT_LEGAL` and `WAIT_SECURITY`; M9 platform backup retention is not the journal's retention schedule.

## 6. Fail-closed approval matrix

| Gate | Required evidence | If missing |
|---|---|---|
| Subject/request | verified identity, separate erasure request, current case state, no unresolved identity conflict | `BLOCKED_IDENTITY` / manual review |
| Policy | active immutable policy version, trigger, effective UTC time, current impact preview | `BLOCKED_POLICY_MISSING` or stale preview |
| Legal | approved lawful process, response/cooling-off exception rule, field/scope and hold decision | `BLOCKED_LEGAL_APPROVAL` |
| Accounting | approved categories/fields/period and no unsettled financial event | `BLOCKED_ACCOUNTING_APPROVAL` |
| Security | approved operator/Owner boundary, recent auth, action-level safeguard, secret-safe logs | `BLOCKED_SECURITY_REVIEW` |
| Provider | capability, namespace/version semantics, credential scope, reconciliation evidence | `BLOCKED_PROVIDER_FACT` |
| Recovery | independent journal healthy; key retrievable; non-final recovery point preserved; no active restore/update | `BLOCKED_RECOVERY_SAFETY` |
| Approval | per-subject explicit Owner approval bound to exact preview and actor | `AWAITING_OWNER_APPROVAL` |
| Execution feature | global and action-specific flags off by default, exact build and kill switch | `DISABLED_BY_POLICY` |

All gates must pass for an action to be *eligible for separately authorized execution*. That is still not a claim of legal sufficiency or complete provider erasure.

## 7. Owner console and audit design

Owner Console → Privacy & Retention → Erasure Plans:

- Queue grouped by safe status and blocker counts; no emails or raw identifiers in list view.
- Detail view uses opaque subject reference, requested action/categories, data-source coverage, retained exceptions, backup/recovery blockers, billing/provider state, active policy version, eligible time, and preview timestamp.
- Buttons in implementation should be capability-gated separately: refresh preview, record evidence/reference, approve exact plan, cancel before first irreversible action, retry failed reconciliation, quarantine/replay status, and no generic “delete account” one-click control.
- Display a persistent `DESTRUCTIVE EXECUTION DISABLED` banner while global or any required action gate is off.
- Compliance-locked fields are visibly locked, with approval type/reference/effective time and reviewer; Owner cannot reduce approved floor.
- Audit events: request verified/restricted, preview created/stale, approval/revocation, action intent, journal committed, action attempted, local verification, provider request/confirmation/failure, manual hold/review, case outcome, quarantine release. Use opaque references and field codes only.
- Customers see request status and clearly scoped outcome only after legal copy is approved; no promise of full deletion for retained/unknown copies.

## 8. Concurrency, idempotency, failure recovery

- Unique active erasure request and unique plan revision/approval hash; use row locks and transaction for plan/case state transitions.
- Acquire a subject-scoped execution lease; one plan per subject may be executing. Database unique constraints, not cache-only locks, prevent duplicate current execution where feasible.
- External provider calls use durable task IDs and per-object idempotency keys; never hold a DB transaction open around network calls.
- Before each action: compare plan's source/policy/watermark hash; a mismatch returns stale and requires a new preview.
- Crash after intent append but before local action: replay safely sees intent; reconcile actual local state and resume idempotently.
- Crash after local action but before outcome append: tombstone replay applies suppression; reconciler inspects current row and journals verified result, without repeating unsafe provider action blindly.
- Timeout/ambiguous provider result: `unknown`, then read-only reconciliation; never resend unless provider idempotency and state make retry safe.
- Partial provider success leaves plan partial and case open/reviewed; no false completion.
- Kill switch is checked before every action and provider dispatch. Turning off stops new actions; in-flight external calls are reconciled, not presumed cancelled.
- Active restore, update/compensation, billing reconciliation, final recovery point, unknown ownership, missing journal, key issue, invalid hold, stale preview, or unsupported data mapping blocks.

## 9. Monitoring, records and privacy

Reuse M10 aggregate health/alerts and M12 audit conventions. Proposed aggregate-only counters: `privacy.erasure.plan_blocked`, `privacy.erasure.action_failed`, `privacy.provider_delete_failed`, `privacy.journal.write_failed`, `privacy.replay.failed`, `privacy.retention.overdue`. Labels may contain category/provider/state only—no subject UUID, email, site domain, invoice ID, or payload. Separate ordinary logs from security/audit evidence; final retention remains provisional. Export artifacts and tombstones are independent classes. No raw request body or provider response in exception traces.

## 10. Release boundary

Wave7 implementation can safely begin with schema/state/approval models and synthetic-only tests while all real execution gates remain off. A later enablement is a separate explicit gate: exact primary SHA, complete CI, legal/accounting approvals, provider facts, successful isolated historical restore/replay, synthetic end-to-end, recovery-point protection, monitoring/kill-switch evidence, and Owner authorization. Production is not part of this plan. M14/M16 commercial package/cadence rules remain outside scope.
