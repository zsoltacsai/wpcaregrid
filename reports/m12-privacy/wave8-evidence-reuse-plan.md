# M12 Wave 8 — Evidence Reuse and Revalidation Plan

Baseline for this planning decision: primary `d415ef3a483b2e80a775b4a11960ad7051396b29`. The latest known staging executable SHA is `19e31d7204a59e08211c4dd9e313b9cccbb01611`; it was not re-read in this planning-only pass.

| Evidence area | Classification | What can be reused | What must be revalidated in Wave 8 |
|---|---|---|---|
| Wave 1 privacy foundation | REUSE AS CURRENT | Owner disposition and closure report; migration/request/provider-task/tenant tests; staging safe status | Include foundation in current full test suite; ensure readiness docs no longer imply Wave1 held |
| Wave 2 export | REUSE + RERUN TESTS | Encrypted allowlisted artifact, repeatable signed download, audit, expiry, tenant denial and staging synthetic checks | Rerun export test and scenario B with current exact source; verify the package stays partial and does not disclose protected categories |
| Wave 2 rectification | REUSE + RERUN TESTS | Current profile/versioned billing identity and immutable snapshot behavior | Scenario C across profile correction and synthetic accounting hashes |
| Wave 3 legal-doc infrastructure | REUSE + RERUN TESTS | Immutable versions, acceptance ledger, unpublished-only state, acceptance default OFF | Scenario D verifies read-only and no fabricated/publicated acceptance; do not create real legal content |
| Wave 4 retention | REUSE + RERUN TESTS | RET-01…RET-33 seed records, policy versioning, deterministic report-only engine and Owner preview | Scenario E checks partial coverage, effective-policy boundary, legal/accounting blockers and `actually deleted=0` |
| Wave 5 closure | REUSE implementation; REVALIDATE report status | Owner synthetic UI preview at 2026-10-15, reversible/non-mutating behavior, separate erasure request semantics | Wave5 report has a stale final disposition (“does not declare Wave5 closed”) after its later UI validation; resolve this documentation contradiction from exact evidence. Scenario A/F cover current behavior |
| Wave 6 backup reconciliation | REUSE safety and staging restore proof | Per-object state, readback/reconciliation, restore/final-point blockers, quarantine and staging database rollback replay | Scenario J/K rechecks no physical deletion; integrated L combines later anonymization event with current quarantine/replay before normal work resumes |
| Wave 6 journal | REUSE code/tests; REVALIDATE provider boundary | Encrypted append-only event/checkpoint chain, watermark, integrity, idempotent replay | Keep provider object-lock/version/failure-domain facts `WAIT_FACT`; no S3 deletion/durability claim from Wave7 fake local storage test |
| Wave 7 synthetic execution | REUSE AS CURRENT for focused path | Exact deployed implementation SHA `19e31d…`; same-second fingerprint correction PR #71; 18-test/165-assertion isolated MySQL E2E; journal-before-mutation, email reuse, accounting hashes, restored T0 replay and cleanup | Rerun targeted test on Wave8 source if executable SHA differs; integrated cross-feature A–O still new evidence |
| Wave 7 CI | REUSE for the merged deployed SHA | Exact PR-head run `38839245477` passed all five mandatory jobs including MySQL process concurrency | New Wave8 candidate/closure PR must run exact-head required CI according to repo policy; no staging runtime claim from docs CI |
| Staging deployment/health | STALE / REVALIDATE | Latest reported executable SHA `19e31d…`, clean checkout, workers healthy, gates false post-cleanup | Read-only staging baseline must re-read actual SHA, health, failures, worker/scheduler, journal readiness and every gate before E2E; no assumptions from old report |
| Legal/accounting/provider decisions | WAIT, not reusable as approval | Exact Owner RET/ER/W2/W3/W6/W7 decisions remain recorded; W7 legal/accounting/provider waits remain | Confirm these statuses are still displayed and fail closed; never convert to PASS/approval |
| General security scan | STALE / M14 residual | CI secret/dependency/security checks and focused privacy authorization tests | Do not suppress missing completed scan warning; plan completed scanner run/triage under M14 unless a Wave8 test exposes critical/high privacy issue |
| Wave-level status documents | NEW EVIDENCE REQUIRED | Preserve old release history | Update readiness/backlog and Wave5 final disposition using latest evidence; final Wave8 matrix/report must supersede stale snapshots |

## Avoid redundant work

- Do not recreate Wave 7 infrastructure or change its test-only guard.
- Do not repeat each historical Owner UI session separately if one authenticated, host-verified Wave 8 review can confirm the current Owner states; keep specific prior evidence as provenance.
- Do not run physical provider deletion or production data access merely to fill a “staging verified” cell.
- Do not repeat historical one-wave CI runs; only a new candidate requires its exact-head release gates.
- Do run the full required current suite once for final Wave8 closure, plus focused reruns for scenario failures/defects.
