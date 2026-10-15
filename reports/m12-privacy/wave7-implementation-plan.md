# M12 Wave 7 — Implementation Plan (Future, Not Authorized Here)

**Planning baseline:** `697048bb490e2b5e43c4427d520aa28e09d4dcde`.
**This document contains no implementation authorization.** Keep `M12_DESTRUCTIVE_RETENTION_ENABLED=false` and all prospective action capabilities disabled.

## Scope boundary

Wave7 is a later, safety-gated implementation of scoped retention and erasure execution. Its first deliverable must remain non-destructive until every applicable approval/fact is supplied. Do not absorb M14/M16 commercial backup cadence, package entitlements, or plan pricing. Do not start a general production pilot. No customer WordPress database/files or remote site is included by account erasure unless a separate authorized site-level workflow explicitly scopes it.

## Work packages

| Order | Package / objective | Likely components and dependencies | Migration / data strategy | Required validation and evidence | Gate / rollback |
|---|---|---|---|---|---|
| 0 | Decision and source freeze | Resolve questionnaire IDs; reconcile current code/schema and factual provider capabilities | No migration | Approved references, source coverage, exact current primary and deployed SHA | Legal/accounting/provider blockers remain per action; no destructive code until architecture review |
| 1 | Execution-plan data model and typed states | Reuse `PrivacyRequest`, `PrivacyRequestEvent`, `PrivacyProviderTask`, RET policy and Wave6 journal interfaces; new plan/action/review records only if existing schemas cannot safely represent them | Additive nullable schema, immutable plan revision/hash, opaque subject refs, scoped FK constraints, unique idempotency keys. No cascade | Schema constraints, tenant scoping, state transition/property tests, MySQL process concurrency | Disable routes/worker by default; rollback hides new read surfaces but preserves audit/approval history |
| 2 | Preview binding and approval workflow | Extend `AccountClosureImpactPlanner` read-only behavior; exact policy/source/watermark binding; Owner per-subject approval and recent re-auth | Persist aggregate preview and hash; no PII-rich snapshots | Stale preview invalidation, source change between preview/approval, IDOR, Operator/customer denial, audit; no action side effect | W7-OP-01/02 unresolved can be represented provisionally; must be decided before approval UI is enabled |
| 3 | Legal/accounting/hold reconciliation | Category-specific locked rules, hold/reference model, unsettled billing blockers | No invented durations; store reviewer metadata/reference only; nullable `WAIT_*` values | Open invoice/refund/dispute fixtures, hold scope/expiry, no weakening locked minimum, immutable invoice/seller/tax snapshots | Legal and accounting approval required before actual affected execution; absent state blocks closed |
| 4 | Profile anonymization in isolated synthetic tests | Existing `AnonymizationPlanItem/Action`; user/customer identity graph, sessions/reset tokens, communications, Wave6 tombstone replay | Start with test-only adapter/isolated DB. Preserve opaque IDs and accounting FKs; do not copy old email into tombstone | Original email can re-register without linking; uniqueness/FK/queue/outbox checks; stale webhook; restore old T0 then replay; repeat replay; invoice snapshot byte/hash unchanged | No staging customer mutation until synthetic isolated test passes and applicable reviews exist; kill switch default off |
| 5 | Backup and provider reconciliation adapters | Existing M8 `BackupDeletion`, Wave6 object-level state and provider task model; provider-specific verified capability | Per-object/per-version task states; no generic delete method; keep last verified recovery point | Active restore/update, versioned-object semantics, partial failure, read-after-delete, duplicate request, provider outage, final recovery point | W7-P-01 and legal/Owner scope required; fail closed on unknown provider/version state |
| 6 | Controlled execution worker (only after separate authorization) | One subject per immutable plan; narrow action allowlist; durable task, lease and kill switch | Idempotent at local/provider boundary; no broad cascades; bounded single subject; batch 25 remains inactive and not an initial default for multi-subject erasure | Failure injection before/after journal, local action, provider request/response; concurrency, replay, recovery quarantine; audit | Separate change/approval after all legal/accounting/provider/security gates; runtime flag stays off absent explicit separate deployment authority |
| 7 | Owner/customer operation surfaces | Owner erasure plan review/reconcile; customer request/status; safe notice | PII-minimal views and notification payload; reuse M11 outbox only after state semantics approval | Localization, access matrix, notifications suppressed on anonymized identity, no unsupported “fully erased” wording | Customer UX/policy text requires legal review; no customer deletion toggle |
| 8 | Staging synthetic validation and independent review | Isolated synthetic customer, provider TEST/fake namespaces, recovery DB, journal | No real customer data; use disposable fixtures/namespaced objects | Full scenario matrix, exact CI, guarded deploy only after separate implementation authorization; verify zero live provider calls | Strict pinned host key; staging execution only if explicit approved synthetic destructive test. Production prohibited |
| 9 | Separate destructive enablement gate | Exact candidate SHA, legal/accounting records, provider facts, successful restore/replay proof, kill switch, Owner approval | Configuration change is separate auditable deployment, never bundled with feature merge | Two-person review recommended; no open blockers; verify each action gate and run read-only preflight | Do not enable as a consequence of Wave7 merge. Production remains a later milestone/Owner decision |

## Proposed migration principles

- Prefer additive tables for immutable execution plans, action tasks, approval snapshots and per-action reconciliation; keep existing privacy cases/events as request history.
- Use nullable transitional fields and explicit `legacy_unverified`/`unknown` states; no backfill may imply a past approval or synthetic tag.
- Avoid hard deleting existing foreign-key roots. Current request/event/billing/legal FK constraints deliberately restrict some deletion; design pseudonymization or safe detach only with an explicit reviewed field map.
- Add DB uniqueness and foreign-key constraints for one current plan, deterministic idempotency, subject/action/provider scope, and immutable revision numbers. Verify MySQL/InnoDB locking behavior; SQLite tests alone are insufficient.
- Never make migration rollback erase privacy case, tombstone, provider, legal acceptance, or accounting evidence. Rollback is code exposure reversal, not data-history reversal.
- Do not mutate existing issued invoice snapshots or historical legal acceptance. Backfill no approval references from report prose.

## Test plan for implementation

1. **State and authority:** transitions; separate closure/erasure; Owner vs Operator/customer; re-auth; CSRF; guessed UUID/IDOR; cross-tenant checks.
2. **Preview/approval:** immutable hash; exact policy version and effective UTC; stale on row/policy/provider/watermark changes; no batch; zero-day eligibility without action.
3. **Accounting/legal:** snapshots never modified; open invoice/refund/dispute blocks; provisional schedule stays blocked; hold applied/released only with authorized evidence; acceptance not fabricated.
4. **Profile:** anonymize only approved current fields; retain opaque FK history; session/password reset invalidation; unique non-deliverable email; original email re-registration does not reconnect; no message to placeholder.
5. **Backups/providers:** separate active-plan vs post-service policy; active restore/update, last-recovery-point guards; provider version/readback; missing provider fact; partial/timeout/duplicate; no false success.
6. **Journal/restore:** T0 real snapshot in isolated DB, T1 test action journaled externally, restore T0, replay, verify, replay again, accounting immutable, quarantine held on each injected failure.
7. **Side effects:** assert no provider call, queue dispatch, source update, delete, or anonymization during preview/approval; execution tests use only fake/test adapters and isolated rows.
8. **Concurrency/crash:** competing approval/execution, duplicate jobs, retry after lost response, crash at every durable boundary, kill-switch during action.
9. **Source coverage:** unsupported provider/log source is visibly UNKNOWN/PARTIAL; no synthetic inference from email/domain; no PII in metrics/audit/exception output.
10. **Release gates:** SQLite + MySQL, Connector matrices if shared CI policy requires, process concurrency, Pint/lint, Composer audit, secret scan, frontend build if changed, full exact-SHA CI.

## Future staging plan

- Require a fresh isolated browser context and independently verify hostname `staging.wpcaregrid.com`, application/environment, and authenticated identity before page inspection. Never use an unknown existing tab; if verification fails, use authenticated HTTP/controller/service or read-only database inspection. Browser-tool failure is not product failure. Do not copy unrelated personal data.
- Use synthetic, explicitly tagged customer/site/backup data and provider TEST or isolated namespaces only. Never infer synthetic status from email aliases.
- Validate preview, stale invalidation, approval separation, each blocker, journal-write outage, provider unknown, and kill-switch. A synthetic destructive E2E is only after implementation is approved and must not use production resources or a real customer.
- Keep staging primary DB; T0 restore must target a disposable isolated DB/schema. Prove historical restore/replay and repeat idempotency.
- Recheck application/DB/queue/scheduler/workers, restore gates, provider TEST mode, backups, mail, security/monitoring, and destructive flags. Record no secret values.

## Release / closure criteria

Wave7 execution code can be merged with all gates disabled if report-only and synthetic tests pass, but **Wave7 destructive capability cannot be called operationally enabled** until: per-action authorization is implemented; legal/accounting decisions are recorded; provider scope is evidenced; independent journal/key recovery is verified; exact-SHA CI is green; isolated historical restore passes; staging synthetic E2E passes; no unresolved high/critical safety defect exists; Owner approves a separate exact-SHA enablement change. M14/M16 and production launch gates stay separate.
