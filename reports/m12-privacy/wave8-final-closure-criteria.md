# M12 Wave 8 — Final Engineering Closure Criteria

## Evidence required before M12 engineering closure

1. Verified exact primary branch/SHA and executable staging SHA; stale local/previous reports reconciled without rewriting history.
2. W1–W7 implementation and release records map to the current source/tests; any stale or contradictory status is explicitly superseded. In particular, resolve the Wave5 report's remaining “not closed” statement against its later authenticated staging Owner UI validation.
3. Current full server SQLite and MySQL suites, Connector SQLite and MySQL suites, required MySQL process/concurrency gate, quality/security/build, frontend/dependency checks, secret scan and `git diff --check` pass on the exact candidate. All skips have concrete environment reasons; no test weakening.
4. Integrated synthetic lifecycle scenarios A–O pass, or a true defect is fixed and the affected/full required evidence rerun.
5. Tenant authorization and no-IDOR tests cover preview/export/approval/execution/replay/provider reconciliation; no cross-tenant access or mutation.
6. Export, rectification, accounting immutability, auth revocation, email reuse/isolation, backup restore/final-point safety, provider pending/failure state, audit minimization, and circuit breaker results are recorded with synthetic IDs and hashes only.
7. A genuine disposable T0 DB artifact is imported into a separate recovery DB; a post-T0 tombstone remains outside that DB, replay is verified before quarantine exit, second replay is idempotent, and no stale billing/provider side effect is run.
8. Normal staging gates are read back false before and after. `M12_DESTRUCTIVE_RETENTION_ENABLED=false`; profile anonymization, customer backup deletion, provider deletion, bulk destructive execution and normal staging synthetic execution all remain false.
9. Temporary runtime, test and recovery DBs/users, snapshot artifacts, isolated queue, workers/scheduler and journal test namespace are cleaned or explicitly documented if append-only retention prevents removal. Absence is verified.
10. Post-cleanup staging app/DB/queue/scheduler/workers/Connector/privacy/journal/recovery status is healthy; existing security scanner warning remains visible. No production data or normal staging customer was mutated.
11. `wave8-closure-matrix.md`, `wave8-e2e-matrix.md`, evidence-reuse, regression, external gate, implementation readiness/backlog and technical-control audit evidence are current and merged with exact-head CI.
12. Final report states M12 engineering closure separately from GDPR/legal/accounting/provider/production audit. No production deployment or destructive enablement occurs.

## Launch-blocking conditions

- Any open Critical/High security issue in the affected path; any cross-tenant data access/mutation; destructive endpoint or queue path bypassing approvals/gates; restore that exposes resurrected erased state before replay; changed invoice/tax/payment evidence; duplicate billing/provider side effects; partial failure represented as complete; journal unavailability/integrity ambiguity treated as success; or rollback/recovery inability.
- Any mismatch to the pinned staging SSH host key; any unknown resource that could be a customer/production resource; any normal staging destructive gate unexpectedly true.
- Missing integrated evidence can yield verdict C. A safety invariant failure yields verdict D.

## External statuses allowed at engineering closure

- W7-L-01…03 and the broader L1–L10: remain `WAIT_LEGAL` for their mapped processing/publication/destructive claims.
- C1–C4 and W7-A-01…02: remain `WAIT_ACCOUNTING` for accounting-period/field/delete actions.
- P1–P9 and W7-P-01: remain `WAIT_FACT` for provider-specific physical deletion/absence/durability claims.
- Synthetic validation may pass while case/action status remains `BLOCKED_*`, `PARTIAL`, `PROVIDER_PENDING` or `MANUAL_REVIEW`.

## Final verdict model for execution phase

- **A — M12 ENGINEERING CLOSED, EXTERNAL APPROVAL / PRODUCTION ENABLEMENT GATES REMAIN:** all engineering evidence and cleanup pass; applicable external gates remain fail-closed.
- **B — M12 FUNCTIONALLY COMPLETE, EXTERNAL FACT OR APPROVAL BLOCKS ENGINEERING CLOSURE:** only when a specific missing external item prevents safe technical verification, not merely because an expected legal/accounting wait exists.
- **C — M12 VALIDATION BLOCKER REMAINS:** a required staging/test/release/cleanup/lineage record is unavailable or stale and can be retried safely.
- **D — M12 SAFETY BLOCKER:** tenant/data loss/gate/journal/accounting/billing invariant is violated or execution cannot be made safe.

## Not implied by M12 closure

No GDPR certification or legal opinion; no accounting/tax approval; no provider physical-erasure guarantee; no production destructive authorization; no public legal-document approval; no M14 AppSec/tenant/supply-chain/hosting audit; no M15 production pilot or full DR/billing/privacy/operational safety audit; no M17 production readiness completion.

## Durable post-closure states

- `M12_DESTRUCTIVE_RETENTION_ENABLED=false` remains mandatory after Wave 8.
- The latest staging “no completed security scan” warning remains visible until separately addressed; recommendation is M14 AppSec residual, not Wave8 general-security scope.
- M14 at end: AppSec, tenant isolation, CI/CD supply chain, hosting compatibility and commercial backup package/GFS backend/domain/Owner policy work.
- Before M15 pilot: DR/restore, billing/subscription/invoice, technical privacy-control and operational-safety audits.
- M17: final production-readiness audit on an exact release SHA.
- M16 handles pricing/CRO/customer communication for future backup packages; WordPress Basic monthly+3 monthly, Business weekly+8 weekly+3 monthly, Secure daily+14 daily+8 weekly+3 monthly; WooCommerce Basic weekly+8 weekly+3 monthly, Business daily+14 daily+8 weekly+3 monthly, Secure daily+30 daily+12 weekly+6 monthly; pre-update safety backups remain separate. Wave8 only checks M12 primitives do not conflict; it does not implement these policies.
