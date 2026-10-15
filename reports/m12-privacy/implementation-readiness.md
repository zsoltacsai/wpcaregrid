# M12 implementation readiness map

Planning baseline: `856f7a03d7624158eb5cf9719fdec4776fbf6893` on `claude/ecstatic-wozniak-oemyuf`, re-fetched 2026-10-14 and unchanged. This document authorizes no runtime work; it classifies later engineering work. Exact legal/accounting decisions are in `final-decision-register.md`.

Wave 1 implementation and staging engineering validation are complete, but formal closure is held pending Owner review of a potential inadvertent unrelated-admin access during browser inspection and any authenticated Owner-view walkthrough. Implementation PR #39 merged as `dcd072a89910078bb7ebed308c88bfa6c7957ebf` and was deployed to staging on 2026-10-14. Final staging evidence was merged through PRs #40–#42; primary SHA at that point is `0a7e9f1bd5ec022dcb32800c64734a723d839e85`. Exact implementation CI run `38590540024` passed all five jobs. See `wave1-implementation-report.md` for details. This does not change any legal/accounting decision or authorize destructive retention.

## Current verified planning disposition — 2026-10-14

The preceding status text and historical table reflect the readiness state before Owner disposition. The Owner has now reviewed the browser-session event; no product or tenant-isolation defect was identified, no staging/customer mutation occurred, and the event is recorded as operational tooling hygiene without a legal conclusion. The hold is cleared: **Wave 1 is CLOSED** for engineering/foundation scope. See `wave1-implementation-report.md`.

Remote primary was independently fetched and verified at `133c0ff7d6118c043f9d59f1d5aabd8dd1eb79df`. A fresh clean planning worktree was created from that SHA; the dirty/behind main checkout and existing M12 worktrees were preserved. Wave 4 has a complete report-only plan and 33-item defaults questionnaire. The Owner subsequently accepted all RET-01…RET-33 technical/product defaults on 2026-10-14; see the decision register and Owner summary. No further Owner product-policy question blocks starting the report-only evaluator. Legal/accounting review items remain provisional and blocked from compliance/destructive activation. `M12_DESTRUCTIVE_RETENTION_ENABLED=false` remains mandatory.

## Waves

### Wave 4 implementation update — 2026-10-14

Report-only implementation is underway on `codex/m12-wave4-retention-engine` from verified primary `3e48d488ed1fa01f39ef412ebc65e25fb7f1f14d`. The foundation now has versioned RET-01…RET-33 policy rows, separate Owner/compliance review fields, aggregate source scanning, a pure UTC evaluator, Owner draft/preview/reporting-activation routes, and a privacy/retention settings page. No destructive code or provider deletion path was added. PHP 8.4/PHPUnit are available through a disposable WSL checkout. Focused Wave 4 and relevant regression suites pass; the full local SQLite suite has documented MySQL/S3/SFTP skips and one connector packaging test that requires `.git` metadata, so exact-head remote CI remains required. See `wave4-implementation-report.md`. Guarded staging evidence is still pending.

| Wave | Readiness | Scope safe to implement | Dependencies / blocked scope |
|---|---|---|---|
| 1 — Foundation | IMPLEMENTATION COMPLETE — FORMAL CLOSURE HELD | Data classification/purpose registry; privacy request/task/audit models; retention-policy metadata; tenant-scoped policy; technical provider fact registry; read-only Owner privacy case views; fail-closed retention status. Staging migration and health checks passed; rollback-only synthetic request/task lifecycle passed with zero persisted test rows. | Owner review of the potential inadvertent unrelated-admin access and authenticated Owner-view evidence remain. Legal basis, controller/processor roles, retention periods, provider regions/contracts/deletion facts remain unverified; no customer-facing compliance claims or delete action. |
| 2 — Export / rectification | ENGINEERING COMPLETE; LEGAL RESPONSE SCOPE PROVISIONAL | Deployed allowlisted, encrypted partial-coverage ZIP; reusable 24-hour authenticated download with per-download audit; canonical current-profile correction and versioned billing identity; historical invoice snapshots preserved. See `wave2-implementation-report.md`. | Qualified legal/accounting review still defines releasable scope, identity-proof sufficiency, exceptions and response workflow. Do not claim the current partial package alone fulfills every legal access request. |
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

### Reusable decision protocol

For each new M12 decision, classify it as technical, Owner product policy, legal/privacy, accounting/tax, security policy, provider fact, or already resolved. Resolve routine technical choices with the safest reversible, tenant-safe, fail-closed design without asking the Owner. Collect genuinely new Owner/legal/accounting/security choices in one consolidated questionnaire; continue non-destructive work with explicit provisional states when the answer is not needed to design safely. Verify provider facts rather than inferring them. Never turn an Owner product decision into legal or accounting approval.

`M12_DESTRUCTIVE_RETENTION_ENABLED=false` at code default, environment default, and UI default. A false or missing value must prevent irreversible app and provider deletion. No Owner UI action may override it. Later activation requires a separately reviewed change with: approved policy records and references; accountant exceptions; counsel sign-off; reviewed dry-run evidence; successful synthetic staging deletion; per-provider confirmation; tombstone replay after restoring a pre-erasure snapshot; recovery/rollback plan; explicit dual review and audit. Planning completion does not satisfy any of these gates.

## Recommended execution order

W1 is complete. The next recommended implementation is Wave 4 report-only retention eligibility, which is marked READY NOW and must remain non-destructive. Develop W2/W3/W5 plumbing only in disabled or preview-only form while L/C/D facts are collected in parallel. Do not begin W7. W6 live deletion and W8 destructive staging validation stay gated until the prerequisites above are satisfied.

## Latest verified implementation disposition — 2026-10-14

The earlier “Wave 4 implementation update” and “Wave 4 report-only retention eligibility” execution-order text above are historical planning snapshots. They are superseded by the final implementation and staging evidence in `wave4-implementation-report.md`.

- Wave 1 remains **CLOSED**.
- Wave 4 — retention dry-run/report-only engine — is **CLOSED**. Implementation PR #46 merged as `6aa3b9bb2308f29148dbffae546f4eaa8a496a76`, guarded deployment of that exact code SHA to staging passed, and the final docs-only primary at the time of this update is `73ad1c1e669cd70c8a47edcac04e1d10a036eea0`. Exact implementation, report-merge, and post-merge CI runs are recorded in the implementation report.
- Staging runtime remains the Wave 4 code SHA `6aa3b9bb2308f29148dbffae546f4eaa8a496a76`; later primary advances were report-only and did not require redeployment.
- The engine stays report-only, source coverage is `PARTIAL`, and `M12_DESTRUCTIVE_RETENTION_ENABLED=false`. Legal/accounting/security review values remain provisional; this does not authorize deletion, anonymization, or provider calls.
- Next recommended non-destructive implementation: Wave 5 erasure orchestration foundations (impact graph, request state/review workflow, provider-task status modeling) strictly report-only/no-op, with all unresolved legal/accounting/provider facts represented as explicit blockers. Wave 2/3 legal-dependent customer-facing export and legal-document publication remain gated by their stated reviews. Before starting the next wave, apply the global decision protocol and collect any new Owner/legal/accounting/security questions in one consolidated questionnaire.

## Wave 5 Owner decision resolution — 2026-10-14

ER-01 is OWNER_APPROVED as **HYBRID**. ER-08 is OWNER_APPROVED as **SEPARATE ERASURE REQUEST REQUIRED**. See `wave5-owner-questionnaire.md` and `final-decision-register.md`. The implementation slot is non-destructive: separately typed closure and erasure cases, an aggregate-only impact preview, and a typed anonymization plan with review blockers. The preview does not revoke access, alter billing/subscriptions, mutate customer or site data, delete backups, or call providers. Legal/accounting/security review and provider facts remain pending. Destructive retention remains OFF.

## Wave 2 implementation update — 2026-10-15

Wave 2 access/export/rectification engineering was implemented on fresh branch `codex/m12-wave2-export-rectification` from the then-current fetched primary `a20e95bf66728991a68e319151a0ec5ab176beae`. Owner Q-W2-01 is resolved: repeat downloads are permitted during the 24-hour link window and each successful download is audited. Export is an allowlisted, private, application-encrypted ZIP with explicit partial coverage; immutable accounting snapshots, tax identifiers, backups, raw webhooks, secrets, internal security evidence, and other tenants are excluded. Rectification uses the canonical profile and versioned billing-identity path without rewriting historical invoice snapshots. See the final release evidence below.

## Wave 2 release disposition — 2026-10-15

Wave 2 engineering/release scope is **CLOSED**. PR #54 merged as `8406af8e8584ddc3d1b4fd9ca19af3e878bd3498`; exact-head and post-merge CI passed all five mandatory jobs; that primary SHA was guarded-deployed and validated on staging. Synthetic rollback-only export and rectification checks passed. Staging health, MySQL migration state, workers, scheduler and privacy-export monitor are healthy; the destructive retention gate remains OFF. Qualified legal/accounting scope remains provisional, so this status does not declare M12 complete or claim legal response completeness. Wave 3 legal-document publication and Wave 7 destructive execution remain separately gated.
