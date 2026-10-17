# M14 Ordered Implementation Backlog

## M14.4 implementation status — 2026-10-17

M14-12 implementation PRs #96–#99 are merged at `29bdd9489eafd6581d9d5b9bc5d7a12fe26b4209` and each exact-head 7-job CI run passed. PR #96 contains legacy scheduled retention; PR #97 adds versioned entitlements; PR #98 adds GFS shadow evaluation/protection/schedule comparison; PR #99 adds extension-only Owner policy preview controls. Exact merged executable SHA is deployed to staging. Synthetic report-only retention, transactional Owner extension/below-floor/stale-preview checks, rollback/cleanup, health and six-gate readback passed. M14-12 awaits only the documentation-only closure PR and its exact-head CI; M14.5–M14.8 remain separate.

Planning baseline primary: `62fc2a74f2b0720e61b73d70c449639363a0eead`. Backlog is ordered by dependency and safety. No item below was implemented in this planning pass. Owner decisions OQ-01–OQ-05 were approved on 2026-10-16; see [Owner decision register](owner-questionnaire.md). Their approved boundaries are dependencies for implementation, not further pending Owner decisions.

| ID | Wave / priority | Dependencies | Files / systems | Risk / migration | Owner / external action | Tests | Staging validation | Rollback / acceptance |
|---|---|---|---|---|---|---|---|---|
| M14-01 | 14.1 P0 | none | routes, middleware, policies, jobs, Connector API | cross-tenant impact; no schema assumed | none | route/job object authorization matrix; two-tenant MySQL/process tests | synthetic-only negative IDOR/role checks | no mutation; all unauthorized reads/writes/exports/jobs denied; no leakage |
| M14-02 | 14.1 P0 | M14-01 | auth/session, Owner/Operator, audit | privilege/session risk; migrations only if evidence justifies | Owner sets any high-risk re-auth policy | reset/session fixation, role transitions, stale session, recent-auth, CSRF tests | authenticated synthetic Owner/Operator checks | revert behavior behind compatible policy; no bypass; all elevated actions audited |
| M14-03 | 14.1 P0 | M14-01 | AppServiceProvider, routes, ingestion/export paths | resource exhaustion; usually config/code only | none | route-to-throttle tests; load/429 scope; payload bounds | non-destructive staging synthetic rate probes | rollback only if health impact; no global blanket limit; critical paths bounded |
| M14-04 | 14.2 P0 | M14-01 | `app/Connector`, plugin auth/protocol, job queue | token theft/replay; migration may be needed for versioned tokens | Owner approves credential lifecycle UX only if user-visible | issuance/rotation/revoke/replay/concurrency and malformed payload tests | isolated synthetic site; exact UUID only | old-token revocation and recovery proven; no customer-wide key exposure |
| M14-05 | 14.2 P0 | M14-04 | Connector payload schemas, Backup APIs | malicious site payload/tenant confusion | none | adversarial schema/depth/size, cross-website job and backup object tests | synthetic Connector traffic only | malformed payload blocked; bounded resources; tenant scope checked at effect time |
| M14-06 | 14.2 P0 | M14-05 | Connector `PathGuard`, `FileRestorer`, backup archive pipeline | file/data loss; bounded limits may need stored metadata | implement approved OQ-01–03 support boundary; no further decision required | traversal, symlink, ZIP bomb, duplicate/case collision, disk-full and race fixtures | disposable WordPress filesystem/database only | failed restore leaves recoverable state; all out-of-root writes rejected |
| M14-07 | 14.2 P0 | M14-04 | Stripe webhook inbox/handler, admin access | financial/account access; schema only if duplicate evidence requires it | Stripe dashboard access for credential rotation evidence | signature, age, replay, duplicate, failure/redrive and key rotation tests | Stripe TEST only; no live charges | idempotent event handling and no sensitive payload logs |
| M14-08 | 14.3 P0 | M14-01 | preflight, Connector capabilities, system diagnostics | unsupported host operation/data loss; possible versioned capability record | implement approved OQ-01–03 boundary: per-capability support; Multisite and Bedrock/custom roots unsupported for initial launch | capability unit/feature matrix for runtime/filesystem/db/network/cron and negative Multisite/custom-root fixtures | disposable host fixture matrix | unknown blocks affected write/restore; excluded layouts are detected/reported where possible; truthful customer and Operator diagnostics |
| M14-09 | 14.3 P0 | M14-06, M14-08 | restore readiness and conflict state | data loss; no migration presumed | none | restore vs update/backup/privacy/retention/duplicate restore process tests | synthetic site only; restore gate off for ordinary staging | no destructive action until preflight/safety backup/lock; recoverable rollback evidence |
| M14-10 | 14.6 P0 | none | `.github/workflows/ci.yml`, deployment scripts, GH settings | release compromise | repository admins provide read-only branch/environment settings | workflow policy tests, deploy failure/fault injection | staging exact SHA, strict host pin, no production credentials | preserves deployment gate/backup; exact source-to-runtime trace and required jobs verified |
| M14-11 | 14.1–14.6 P0 | fixes above | security scanner/feed, system health | unscanned risk; no runtime code assumed | feed/vendor access if approved | scanner success/failure, stale feed, false-positive disposition | complete detect-only staging scan; remediation remains off | warning stays visible until real completed scan; no false “clean” status |
| M14-12 | 14.4 P1 | M14-08/09 | pricing/backup policy domain, migrations, Owner policy UI | entitlement drift, mass delete | apply approved OQ-04 extension-only override; legal/accounting decisions and commercial implications remain separate | six GFS combinations, policy version upgrade, impact preview, extension above entitlement, reduction safety floors, last-good/hold/active-restore invariants | preview-only synthetic policies; deletion gates off | old policy preserved; no deletion at migration or policy edit; never reduce below entitlement/safety floors; customer promise and checkout catalogue align |
| M14-13 | 14.5 P1 | credential primitives | integration credentials, provider registry, Owner UI | data egress / credential exposure | OQ-05 approved; M14.5 planning design ready, implementation not started. Exactly three fixed providers, encrypted Owner-only secret, explicit metadata probe, no data egress/default/feature | secret masking/replacement/removal, fixed endpoints, generation-bound health, re-auth, zero customer-data egress and adversarial tests | fake provider only; no real key or customer data | Configure/test/remove without product data flow; no default, AI feature, autonomous action or AI marketing claim |
| M14-14 | 14.1–14.7 P1 | M14-01–13 | audit/log/monitoring | sensitive logs/poor detection | SRE selects alerts from existing M10 signal patterns | PII/secrets redaction, alert failure, queue backlog and rate abuse tests | staging synthetic signals | no PII labels; failures observable and truthful |
| M14-15 | 14.7 P0 | M14-01–11 | manual AppSec plan, scan report, staging | missed high-impact attack paths | security reviewer/operator schedules scan; independent test later | manual IDOR, RBAC, CSRF, SSRF, replay, secret and kill-switch attack checklist | strict SSH; isolated synthetic target; gates off | stop on Critical/High; triage/retest; independent pentest before M17 |
| M14-16 | 14.8 P0 | all P0/P1 needed for M14 | four audit reports, closure report | overstated readiness | no new decision | exact CI and all focused/full regressions | health + gates before/after; no ordinary customer mutation | closure report only after evidence merged; production destructive gates remain off |
| M14-17 | 14.8 P2 | M14 closure | M15/M17 audit handoffs | launch scope drift | Owner/pilot authorization belongs to M15 | no new runtime tests | none in this planning backlog | handoff names open DR/billing/privacy/ops, pentest and production audit work |

## Priority totals

- **P0:** 13 (M14-01 through M14-11, M14-15 and M14-16).
- **P1:** 3 (M14-12, M14-13, M14-14).
- **P2:** 1 (M14-17).

Each P0 remains required unless evidence proves a row not applicable and the audit report records why.

## M14.6 supply-chain audit carry-forward — 2026-10-17

M14.6 planning audit is documented under `reports/m14-supply-chain/m14-6-*.md`. Read-only GitHub settings show the deployed authoritative branch is unprotected, has no repository ruleset, and does not require any of its seven CI result checks. Read-only staging evidence shows deploy has unrestricted `NOPASSWD: ALL` while target-controlled Composer/npm/Artisan build and migration code runs as deploy. These are confirmed High control-path findings M146-F01 and M146-F02; M14.6 closure and staging promotion must wait for reviewed branch enforcement and a least-privilege deployment boundary. Planning made no setting/runtime/workflow changes. Full M14.7 scanning and M14.8 closure remain separate.

## M14.1 planning refinement — 2026-10-16

Execute M14.1 in this order: (1) expanded route/command authorization inventory; (2) two-tenant customer/Owner/Operator/Connector object matrix; (3) queued-job and command stale-object/relation tests; (4) password reset/activation session revocation and concurrent one-time token tests; (5) recent-auth, CSRF and role-revocation matrix; (6) route-to-limiter coverage and shared-cache verification; (7) targeted output/log/resource-abuse checks; (8) focused plus full regression and exact-SHA CI; (9) guarded synthetic staging proof and audit refresh. Detailed acceptance and test plans are in `reports/m14-security/m14-1-*.md`.

Planning review added a P0 password-reset session-revocation validation gap (not a confirmed exploit) and a P1 queued-backup stale-object validation gap (not a confirmed IDOR). Code changes are conditional on reproducible defects. PHP CLI was unavailable during planning, so the expanded runtime route inventory remains an explicit first task. This pass changed documentation only; it did not change runtime behavior or staging.

## M14.1 completion and carry-forward — 2026-10-16

M14.1 implementation and guarded staging deployment are present on primary SHA `e4659f30140669c9e229c8e583d35e6bb377a979`. PR #85 fixed password-reset persisted-session revocation; PR #86 fixed stale `TransferBackup` scope drift. Required GitHub run `38903198033` passed all five mandatory jobs, with Server SQLite/MySQL, Connector SQLite/MySQL, and MySQL process/concurrency evidence recorded in [`reports/m14-security/m14-1-findings.md`](../m14-security/m14-1-findings.md). A disposable isolated staging MySQL validation passed 261 tests / 3,320 assertions and its temporary runtime/schema/user were removed and absence verified. All six M12 privacy execution gates remained false.

This closes the focused implementation work with a **B — functionally complete, non-critical audit residual remains** disposition. Do not mark M14-01, M14-02, M14-11 or the broader audit work complete: full route-to-control reconciliation, exhaustive two-tenant domain coverage, broad role/CSRF/limiter mapping, and the completed detect-only security scan remain M14 work. Continue M14.2 only after this M14.1 disposition is reviewed; M15 remains gated by the M14 closure criteria and its own authorization.

## M14.1 continuation reconciliation — 2026-10-16

The final-authorization continuation retains **C — M14.1 AUDIT CLOSURE INCOMPLETE**. The prior B disposition is historical and superseded for current milestone decisions. A 236-row route/control manifest now records source facts and explicit partial behavior-test status; 141 state-changing entries split into 130 browser, 10 Connector API and one signed Stripe webhook. A route-boundary invariant and active-session Operator-permission revocation regression were added as test candidates but are pending exact-head CI. The full two-tenant valid-object/action matrix, zero-side-effect checks, limiter behavior/abuse matrix, output-sink closure and authenticated Owner Console walkthrough remain incomplete; do not start M14.2 or M15.

The scanner warning “No completed security scan yet” is **AS-16 / M14.7_CARRY_FORWARD**, not a requirement for M14.1 closure. Preserve it visibly; no scan result is inferred or suppressed. All six M12 destructive/synthetic execution gates are read back `false` in the current staging release.

## M14.1 final audit disposition — 2026-10-16

The earlier continuation status is superseded by final M14.1 evidence on branch `codex/m14-1-audit-closure`. M14-01 (authorization/tenant matrix), M14-02 (session/RBAC/re-auth) and M14-16 (M14.1 closure evidence) are complete for the M14.1 audit scope and are ready for review. AS-08 carries `PASS_WITH_MEDIUM_RESIDUAL`: 15 specialized limiter aliases remain mapped but lack individual 429/fairness probes. This is not a known bypass or Critical/High defect.

AS-16 (“No completed security scan yet”) remains `M14.7_CARRY_FORWARD`; do not suppress the warning or treat it as an M14.1 gate. M14.2 can begin only after this M14.1 closure PR is reviewed and merged. M15 remains outside scope and gated by its own readiness approvals. All six M12 destructive/privacy execution gates remain false; no production destructive functionality was enabled.

## M14.2 planning handoff — 2026-10-16

The M14.2 source/planning package is in reports/m14-security/m14-2-*. Current primary source reports Connector 0.4.5 / API v1, 13 Connector API route declarations (10 mutators, 3 reads), one-time code pairing, one-site hashed bearer credentials, encrypted plugin token storage, exact website UUID binding, scoped job/backup/admin flows, and Stripe signature verification with unique encrypted inbox/idempotent event handling.

Ordered M14.2 batches are credential/pairing lifecycle; request scope and payload bounds; admin-access replay; filesystem/archive adversarial safety; outbound request/SSRF; Stripe webhook replay/rotation; integrated synthetic validation and closure. No runtime implementation is authorized by this planning update. Findings are validation needs unless reproduced. Stripe TEST access for rotation rehearsal is an external action, not a new Owner decision. M14.3 owns general compatibility/preflight UX; M14.7 owns completed security scan. Keep all M12 destructive gates false and preserve AS-08 medium residual / AS-16 M14.7 carry-forward.

## M14.2 implementation continuation — 2026-10-16

M14-04 through M14-07 are now in implementation validation on the fresh branch based on planning-merged primary `bec597c76e668a81c699171c684c0e5e8866d14a`. Server/plugin payload bounding, Connector egress response/redirect handling, Stripe payload/timestamp controls and archive/resource/restore-path hardening have code and local regression coverage. Local full Laravel SQLite and Connector WordPress SQLite/MySQL results are recorded in `reports/m14-security/m14-2-findings.md`. Do not mark M14.2 complete until exact-head required CI (including server MySQL and process/concurrency), merge, guarded exact-SHA staging deploy, isolated synthetic validation, post-deploy health/gate readback, cleanup verification and final closure report are complete. M14-08 and later work remain out of scope; AS-16 remains M14.7_CARRY_FORWARD.

## M14.2 completion reconciliation — 2026-10-16

M14.2 implementation and its isolated validation are closed with the staging paired-site version limitation documented; see [M14.2 closure report](../m14-security/m14-2-closure-report.md). PR #90 is merged; current primary and executable staging SHA are `c5e0edabd8bd1661f72eb20330c5ca2f4375b9c5`; exact-head CI `38961580730` passed. Connector release 0.4.6 passed the real WordPress SQLite/MySQL jobs. Staging synthetic backend tests passed 98 / 808, test runtime/schema/user were removed and verified absent, and all six M12 gates remained false. No unsafe re-pair of the existing synthetic WordPress site was attempted.

M14.3 hosting compatibility/preflight and M14.7 scan/independent security review remain future scope. The warning “No completed security scan yet” is preserved. No M15 or production activity is authorized by this closure.
# M14.3 planning refinement — 2026-10-16

M14.1 and M14.2 remain closed per merged evidence above. M14.3 is now planned on verified primary `94b9fbafe2c2ab89d81b677af82d0d3d956c0175`; no M14.3 runtime changes are included in this planning branch. The planning package decomposes M14-08/09 into policy/domain and version reconciliation, additive Connector fact collection, server evaluator/persistence, operation-time backup/update/restore gating, scoped diagnostics, fixture coverage and guarded synthetic staging closure. It preserves approved OQ-01–03 boundaries and does not start M14.4, M14.7 scan campaign or M15.

Confirmed source item for M14.3-A: Connector release is 0.4.6 but `config/connector.php` default `latest_version` remains 0.4.1 (staging environment reports 0.4.6). Reconcile and test before version diagnostics. No Owner decision is required. Full ordered acceptance/backlog is in [M14.3 implementation backlog](../m14-compatibility/m14-3-implementation-backlog.md) and [closure criteria](../m14-compatibility/m14-3-closure-criteria.md).

## M14.3 completion and bounded carry-forward — 2026-10-17

M14-08 (hosting compatibility/preflight) and M14-09 (restore readiness/conflict gating) are implemented and closed for M14.3’s evidence-backed, capability-specific support envelope. Planning PR #92 merged at `80642e369e86e022f2a2f9d32b6b54d4f2eb28a9`; implementation PR #93 merged as `9e330854f0e7d5ef073db846245f7008d7ef6ec5`; merge-head CI `38984808200` passed. The exact executable SHA is guarded-deployed to staging. See [M14.3 closure report](../m14-compatibility/m14-3-closure-report.md).

M14.3 disposition is **B — functionally complete, non-critical host-evidence residual remains**. Claims are constrained to the tested envelope; declared PHP 8.1 and WordPress 6.4 minima and specialized host fixtures remain UNKNOWN/NOT TESTED and block affected high-impact operations until verified. The staging Connector 0.4.5 fixture remains unchanged.

M14-12 (commercial GFS/backup entitlement) remains M14.4; M14-15 and AS-16 (full security scan and independent review) remain M14.7; M14-16 overall M14 closure remains open; M15 must independently validate pilot hosts and DR. All six M12 privacy/destructive gates remain false. No production destructive functionality was enabled.

## M14.4 planning — 2026-10-17

M14-12 planning is documented on the review branch `codex/m14-4-commercial-backup-plan` (baseline primary `db272400513882bae8fa8d2ab15d0996cd34dcc7`). Source audit confirms `config/pricing.php`/`PricingCatalog` owns canonical six plan identities and cadence, while `config/backup.php` keeps nullable env-driven count/day retention. `backup:retention` is scheduled daily and can reach `BackupDeletion::remove()` when those values are configured. Source defaults are null; deployed staging values were not available for read-only verification. This is a P0 M14.4 cutover gate: contain the legacy effect before shadow GFS; no dual mutating authority. New GFS remains preview/report-only and cannot call provider deletion.

The target matrix remains exactly as approved and is not an active/public promise. OQ-04 is approved; no new Owner decision is required for policy-v1 engineering. `WAIT_LEGAL`, `WAIT_ACCOUNTING`, and `WAIT_FACT` remain fail-closed for post-service/deletion. See `reports/m14-backup/m14-4-*.md`. This is planning only: no runtime changes, deletion enablement, deployment, M14.5 or M15 work.

## M14-13 implementation status — 2026-10-17

M14.5 is implemented in PRs #102 and #103 and deployed to staging at `3debba99c677b68465cf439733f440442fbd0f22`. Exact-head CI runs 39017126211 and 39020595623 passed 7/7; merged-SHA CI 39021792985 passed 7/7 including MySQL/process concurrency. Registry/credential state, generation-bound metadata health probes, Owner security and zero-egress tests are complete for this milestone. No AI product feature, provider default, feature authorization or customer-data transfer is enabled. Closure documentation is in `reports/m14-ai/m14-5-closure-report.md`. M14.6, M14.7 and M14.8 remain separate work.
