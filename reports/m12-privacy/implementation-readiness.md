# M12 implementation readiness map

Planning baseline: `856f7a03d7624158eb5cf9719fdec4776fbf6893` on `claude/ecstatic-wozniak-oemyuf`, re-fetched 2026-10-14 and unchanged. This document authorizes no runtime work; it classifies later engineering work. Exact legal/accounting decisions are in `final-decision-register.md`.

Wave 1 implementation and staging engineering validation are complete, but formal closure is held pending Owner review of a potential inadvertent unrelated-admin access during browser inspection and any authenticated Owner-view walkthrough. Implementation PR #39 merged as `dcd072a89910078bb7ebed308c88bfa6c7957ebf` and was deployed to staging on 2026-10-14. Final staging evidence was merged through PRs #40–#42; primary SHA at that point is `0a7e9f1bd5ec022dcb32800c64734a723d839e85`. Exact implementation CI run `38590540024` passed all five jobs. See `wave1-implementation-report.md` for details. This does not change any legal/accounting decision or authorize destructive retention.

## Waves

| Wave | Readiness | Scope safe to implement | Dependencies / blocked scope |
|---|---|---|---|
| 1 — Foundation | IMPLEMENTATION COMPLETE — FORMAL CLOSURE HELD | Data classification/purpose registry; privacy request/task/audit models; retention-policy metadata; tenant-scoped policy; technical provider fact registry; read-only Owner privacy case views; fail-closed retention status. Staging migration and health checks passed; rollback-only synthetic request/task lifecycle passed with zero persisted test rows. | Owner review of the potential inadvertent unrelated-admin access and authenticated Owner-view evidence remain. Legal basis, controller/processor roles, retention periods, provider regions/contracts/deletion facts remain unverified; no customer-facing compliance claims or delete action. |
| 2 — Export / rectification | PARTIALLY BLOCKED | Allowlisted export assembler, JSON/CSV/index, secret/tenant exclusion, private temporary artifact and expiry; current-profile correction audit | Legal review L4 must define releasable scope, identity proof, exceptions and response workflow before a production customer-facing export is declared complete |
| 3 — Legal documents / acceptance | PARTIALLY BLOCKED | Immutable version/locale/effective-date/publication machinery, acceptance ledger, legacy `unknown` state, preview and audit | Approved legal content and reacceptance rules (L4/L5) required before publish, force acceptance or claim agreement |
| 4 — Retention dry-run | READY NOW | Versioned policy lookup, eligibility discovery, dry-run preview, counts/reasons, audit, metrics, exclusion checks | Store durations as `unapproved`/unset; no destructive provider calls; exact schedules wait for L3/C1–C4 |
| 5 — Erasure orchestration | PARTIALLY BLOCKED | Async request states, re-authentication, impact graph, reviewer steps, provider-task state machine, idempotency and `manual_review`; all actions no-op/report-only | Legal response scope/timing and accounting exceptions (L3/L4, C1–C4) required before marking a request completed or processing irreversible actions |
| 6 — Backup / provider reconciliation | PARTIALLY BLOCKED | Read-only object/metadata reconciliation; per-object task model; restore suppression/tombstone design; destination capability inventory | Current provider facts P1–P9 and legal backup/tombstone semantics L3/L9; provider delete integration can be mocked, but no live deletion |
| 7 — Destructive execution | BLOCKED | No destructive execution in the initial implementation wave | Requires approved retention schedules and exceptions, legal/accounting approvals, dry-run review, synthetic staging deletion and restore-after-erasure proof, provider verification and separate Owner authorization. `M12_DESTRUCTIVE_RETENTION_ENABLED=false` remains the default and only safe current value |
| 8 — Staging E2E / closure | BLOCKED until prior safe waves | Synthetic requests, exports, report-only retention, policy/version audit, failure/retry tests | Must not use real customer erasure or enable deletion; destructive E2E uses synthetic-only only after Wave 7 approval and guard review |

## Safe implementation guardrails

1. All unknown legal classifications are explicit `pending_review`; never default to a lawful basis or retention period.
2. Export reads only allowlisted tenant-scoped fields. Do not include secrets, other tenants, internal investigation notes or raw provider payloads by default.
3. Provider deletion tasks may be created and reconciled in tests, but production adapters must not call delete APIs while the global gate is false.
4. Account/site cancellation, erasure request and backup expiry remain separate states.
5. Immutable invoice and seller snapshots are never rewritten by privacy workflows.
6. Restored DBs remain in recovery mode: stale destructive WebsiteJobs are quarantined; tombstone replay and queue reconciliation precede worker resume.
7. Legal document engine may ship with unpublished placeholders; no unapproved text becomes effective.
8. Staging data is synthetic-only; no production copy path is enabled.

## Destructive gate

`M12_DESTRUCTIVE_RETENTION_ENABLED=false` at code default, environment default, and UI default. A false or missing value must prevent irreversible app and provider deletion. No Owner UI action may override it. Later activation requires a separately reviewed change with: approved policy records and references; accountant exceptions; counsel sign-off; reviewed dry-run evidence; successful synthetic staging deletion; per-provider confirmation; tombstone replay after restoring a pre-erasure snapshot; recovery/rollback plan; explicit dual review and audit. Planning completion does not satisfy any of these gates.

## Recommended execution order

W1 is complete. The next recommended implementation is Wave 4 report-only retention eligibility, which is marked READY NOW and must remain non-destructive. Develop W2/W3/W5 plumbing only in disabled or preview-only form while L/C/D facts are collected in parallel. Do not begin W7. W6 live deletion and W8 destructive staging validation stay gated until the prerequisites above are satisfied.
