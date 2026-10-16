# M12 Wave 8 — Final Privacy Lifecycle Validation Plan

**Status: PLANNING ONLY.** No runtime code, staging state, deployment, legal publication, or destructive switch is changed by this plan.

## Verified planning baseline

- Authoritative remote branch: `claude/ecstatic-wozniak-oemyuf`.
- Remote primary SHA fetched for this plan: `d415ef3a483b2e80a775b4a11960ad7051396b29`.
- Planning worktree: fresh, clean, detached from the user's dirty/behind primary checkout; planning branch created from the exact SHA above.
- Latest known executable staging SHA from the Wave 7 final report: `19e31d7204a59e08211c4dd9e313b9cccbb01611`. This is historical evidence, **not a current staging observation**; staging was intentionally not queried in this planning-only pass.
- Latest Wave 7 evidence: PR #71 code fix (`e48e913…`) and exact-head Actions run `38839245477`; deployed implementation SHA `19e31d…`; isolated synthetic MySQL T0/T1/replay passed; PR #72 closure report merged at current primary `d415ef3…`.
- The repository's `config/privacy.php` defaults global retention, profile anonymization, backup deletion, provider deletion, bulk execution and synthetic profile execution to `false`; `PrivacyExecutionAuthorization` also fails closed when the global or action gate is false/missing. Staging runtime values still require read-back before and after future Wave 8 E2E.
- Wave 7 external states remain W7-L-01…03 `WAIT_LEGAL`, W7-A-01…02 `WAIT_ACCOUNTING`, W7-P-01 `WAIT_FACT`.

## Evidence-backed finding

Wave 1–7 engineering components are present and the latest Wave 7 staging report provides synthetic T0/T1 restore/replay evidence. The M12 evidence package is not yet a clean single closure record: `implementation-readiness.md` and `implementation-backlog.md` end with older pending Wave 6/7 snapshots, `wave5-implementation-report.md` still says it does not declare Wave 5 closed even though a later authenticated Owner UI validation is appended, and the Wave 3 report accurately records no legal text published or acceptance enforcement enabled. Wave 8 must reconcile those records from their latest evidence; historical entries remain intact and should be explicitly superseded, not erased.

The Wave 7 restore proof used a genuine MySQL 8.4.11 dump and separate recovery DB, but the journal backend for that test was isolated local fake storage. It proves database rollback independence for the exercised journal/replay code path, not S3 physical durability, provider-account failure-domain separation, or object deletion semantics. Keep W7-P-01 `WAIT_FACT`.

## Wave 8 goal and closure meaning

Run one integrated, synthetic-only lifecycle against current verified code, then a complete regression/release review. Reuse Wave 1–7 implementations; do not redesign or add runtime behavior merely to make a milestone feel active. If integrated validation exposes a real product defect, make the smallest fix and regression test, then repeat the affected flow and full exact-SHA release gates.

**M12 ENGINEERING CLOSED** will mean the privacy-engineering machinery has current test/release evidence, the integrated synthetic staging lifecycle and restore/replay work, tenant and financial-history boundaries hold, cleanup is verified, audit evidence is reconciled, and every unresolved external gate remains explicit and fail-closed. It does not mean GDPR certification, qualified legal/accounting approval, provider physical erasure, production destructive execution, or completion of M14/M15/M17 audits.

## Owner decision protocol

**NO NEW OWNER DECISIONS REQUIRED FOR WAVE 8 ENGINEERING CLOSURE.** Reuse the approved RET-01…RET-33, ER-01/ER-08, W2, W3, W6 and W7 decisions. No legal, accounting, provider or security conclusion is inferred from them. If execution discovers a genuinely new decision, collect it once in a consolidated questionnaire; continue independent safe work while the affected action remains blocked.

## Security scanner warning

The latest staging evidence still reports “no completed security scan.” This is a missing scan result, not evidence that no vulnerability exists. Recommendation: do not expand Wave 8 into general scanner/feed/hosting hardening. Keep the warning visible, require the M12-specific authorization/IDOR/tenant/synthetic-bypass tests and CI security/secret checks in Wave 8, and carry a completed environment-appropriate scan plus triage of any Critical/High findings into M14's AppSec gate. If the integrated M12 tests reveal a Critical/High privacy defect, it blocks Wave 8 closure until fixed. M14/M17 audits remain open.

## Staging isolation and operating safety

- Start with a read-only baseline: exact staging SHA, clean checkout, system health, queue/scheduler/workers, privacy/journal status, existing scanner warning and gate read-back. Strict SSH host verification is mandatory; expected Ed25519 fingerprint is `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`.
- Keep normal staging at production-like `APP_ENV`; never install PHPUnit/dev dependencies into the deployed application directory. Create/reconcile a private isolated runtime from the exact validated executable SHA only when the test requires mutation.
- Use explicit trusted synthetic fixture markers, one-subject limits, a disposable MySQL 8.4 database and separate recovery database, a unique journal test namespace, sync or isolated queue, no scheduler/workers, array/log mail, no production credentials, and fake/sandbox providers only.
- Never restore a snapshot over the primary staging DB. Never mutate ordinary staging customers or WordPress content. Never call a real destructive provider API or use normal customer/platform S3 deletion paths.
- Preserve evidence with opaque IDs/counts and hashes only. Do not record original identities, secrets, full billing payloads or provider credentials.
- Cleanup is a closure gate: remove runtime, both DBs/users, snapshot files, temporary queues/caches, test workers/scheduler entries and test journal objects where the design permits; verify absence. If append-only evidence cannot be removed, use an isolated namespace and document the retained synthetic event.
- Read back every normal-staging destructive switch before and after the isolated run; all must remain false. Any unexpected true value is a safety stop.

## Ordered Wave 8 execution backlog

### NO-CODE VALIDATION

| Order | Objective | Existing evidence reused | Code change expected? | Local tests / CI | Staging operation | Rollback / safety gate | Closure evidence |
|---|---|---|---|---|---|---|---|
| 0 | Reconcile evidence conflicts and confirm primary/runtime source lineage | Current primary `d415ef3…`; Wave 5 latest Owner walkthrough; Wave 7 final report; audit appendices | No | Document inventory; exact-sha CI only for report PR | No staging query until execution request | Preserve user dirty checkout; no state changes | Dated source/evidence ledger and corrected readiness/backlog statements |
| 1 | Read-only staging baseline and all gate values | Wave 7 post-cleanup health/gate evidence is historical only | No | None | Strict pinned SSH, read-only commands; verify app/DB/queue/scheduler/workers/Connector/providers/privacy/journal | Stop on host mismatch, unhealthy recovery/quarantine, unknown runtime identity, or any gate true | Exact SHA, health and gate transcript without secrets |
| 2 | Validate Owner and customer interfaces using isolated/authenticated context | Wave 2 export/rectification; Wave 3 legal history; Wave 4 policy center; Wave 5 Owner preview; Wave 7 execution plan | No | Relevant feature suites | Read-only authenticated Owner/customer screens; no document publish/acceptance forcing and no plan execution | Verify host/environment/identity before content; no guessed IDs or cross-tenant access | Screen/controller evidence and expected neutral statuses |
| 3 | Run account/export/rectification/legal/retention/closure lifecycle scenarios A–F | W2/W3/W4/W5 suite evidence and user decisions | No unless defect | Privacy, legal, account, billing snapshot focused suites | Rollback-only/synthetic fixtures in disposable runtime; no source deletion | Current gates false; legal acceptance stays OFF; export scope remains partial/approved allowlist | Scenario logs, audit IDs, artifact cleanup verification |
| 4 | Run synthetic anonymization/email reuse/accounting and provider/backup scenarios G–K | Wave 6/7 staging E2E, `Wave7SyntheticAnonymizationRestoreTest`, backup safety and reconciliation tests | No unless defect | Privacy anonymization/plan/journal, billing hashes, backup/provider tests | Isolated MySQL test DB + fake provider and test journal; no normal S3 delete | Trusted synthetic marker, single subject, global production gates false, no real-provider credentials | Before/after field/table fingerprints, fake-provider trace, no mutation proof |
| 5 | Run integrated historical T0/T1/replay and failure scenarios L–N | Wave 6 journal/replay/quarantine and Wave 7 genuine MySQL T0 restore | No unless defect | Restore/replay, failure injection, tenant and process-concurrency suites | Genuine T0 dump; restore into separate disposable DB; unique journal namespace; quarantine until verification | Fail closed on missing/integrity/tenant/provider state; no primary DB restore | T0 identity/hash, journal watermark, replay and second-replay evidence |
| 6 | Cleanup, post-E2E health and matrix reconciliation | Wave 7 cleanup report | No | `git diff --check`; no runtime validation substitute | Remove and verify all temporary resources; read-only staging health and gate read-back | No cleanup that touches normal customer/platform backups | Cleanup inventory with absent/preserved states and final health/gates |
| 7 | Produce final M12 engineering closure evidence | All prior reports and Wave 8 scenario evidence | Report only | Docs lint/secret scan and exact-head CI | No redeploy for report-only changes | Do not claim legal/accounting/provider approval or production readiness | Final M12 report/matrix, audit update and PR merge |

### CODE FIX ONLY IF A DEFECT IS FOUND

| Order | Objective | Expected work | Tests / CI | Staging / rollback | Gate |
|---|---|---|---|---|---|
| F1 | Reproduce the defect in the smallest isolated test | No scope expansion; preserve current safety behavior | Add regression first; run focused suite | No primary staging mutation | Stop if correction needs legal policy, provider facts, or architectural redesign |
| F2 | Correct root cause | Minimal runtime change on fresh branch from then-current primary | Full Server SQLite/MySQL, MySQL process/concurrency, Connector SQLite/MySQL, quality/security/build | Review migration and rollback; no destructive enablement | Exact-head CI green before merge |
| F3 | Deploy and revalidate | Guarded deploy exact green executable SHA | Repeat affected and full integrated E2E | Strict host key, deployment lock/predeploy backup/health; maintain false gates | No production deployment; no provider deletion |
| F4 | Final report-only closure | Update matrix/readiness/audits with actual evidence | Exact-head docs CI; normal merge | No redeploy for docs-only | External WAIT states stay unchanged |

## Release sequence and expected verdict

Future execution order: reverify current primary and deployed SHA → local focused/full regression → implementation commit only if required → push GitHub → exact-head five-job Actions green, with MySQL process/concurrency actually run → normal merge → guarded staging deployment only for executable code changes → integrated isolated E2E and cleanup → final report-only PR with exact-head CI → merge → verify primary SHA. GitLab fallback is only for evidenced GitHub account/quota/runner/service failure and must test the same candidate tree. No production deploy. Strict host-key mismatch means stop.

Future verdict A is preferred if all engineering evidence passes while external gates remain accurately blocked. B means an external fact/approval has prevented a technical verification required for safe behavior; ordinary WAIT states alone do not force B. C means validation evidence is missing/stale. D means a safety invariant failed.

## Out of scope

No legal policy publication or acceptance enforcement; no production destructive enablement; no M13 analytics; no M14 scanner hardening or commercial backup policy redesign; no M15 pilot or complete DR/billing/privacy audit; no M16 pricing/CRO; no M17 production readiness audit; no AI, agency features or general performance work.
