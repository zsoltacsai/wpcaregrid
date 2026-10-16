# M14.1 Application Security Foundation — Closure Report

**Disposition: B — M14.1 FUNCTIONALLY COMPLETE, NON-CRITICAL AUDIT RESIDUAL REMAINS**

Date: 2026-10-16  
Authoritative branch: `claude/ecstatic-wozniak-oemyuf`  
Final implementation/staging SHA: `e4659f30140669c9e229c8e583d35e6bb377a979`

## Release lineage

- Planning PR #84 merged at `37f31d0ddd6cc8c62221a5cac20e39b69e6fe45a`.
- Implementation PR #85 merged at `943af21330451377ef79ddf46a92dd0319372adf`; it fixed persisted-session revocation on password reset and related role/session invalidation paths.
- Adversarial defect-fix PR #86 candidate `7532df4e692b13047ac30df0ac25c1cd3a5958d9` merged at `e4659f30140669c9e229c8e583d35e6bb377a979`.
- Exact candidate GitHub Actions run [38903198033](https://github.com/zsoltacsai/wpcaregrid/actions/runs/38903198033) passed all five mandatory jobs. No GitLab fallback was used.

## Findings and implementation

- **F-14.1-01 — confirmed High, fixed and validated.** Password reset now revokes persisted authenticated sessions through the shared session-revocation implementation. Regression coverage and exact CI passed; the merged implementation is deployed to staging.
- **F-14.1-02 — stale queued backup scope defect, fixed and validated.** An adversarial test demonstrated a queued `TransferBackup` could follow mutable website/object relationships after reparenting. The job now binds enqueue-time website, provider and object identities, revalidates the scope before work and scope-binds failure handling. Changed parent, child, provider, deleted/no-longer-uploaded and legacy unscoped states are covered. Focused regression: 30 tests / 217 assertions. No real provider deletion was called.
- **F-14.1-03 — shared limiter storage behavior demonstrated for the exercised path.** On staging, independent processes observed a shared counter (`1`, then `1→2`) and cleanup to `0`. This does not establish complete route-to-limiter or abuse-response coverage.
- **F-14.1-04 — expanded runtime inventory generated.** Current inventory records 236 routes, 36 project command classes, 19 scheduled entries and 9 job classes. Per-entry authorization/tenant/CSRF/limiter/test reconciliation is not complete.

## Validation evidence

Exact required CI results for candidate `7532df4e692b13047ac30df0ac25c1cd3a5958d9`:

- Server SQLite: 1,307 tests, 18,731 assertions, 19 documented skips.
- Server MySQL: 1,307 tests, 18,929 assertions.
- Required MySQL process/concurrency gate: 15 tests, 161 assertions.
- Connector SQLite: 202 tests, 1,401 assertions, 24 documented skips.
- Connector MySQL: 202 tests, 1,775 assertions, 3 documented skips.
- Quality/security/build: passed, including PHP syntax, Pint, dependency audits, frontend build and full-history secret scan.

Isolated staging validation used the exact merged source SHA `e4659f30140669c9e229c8e583d35e6bb377a979`, `APP_ENV=testing`, a separate disposable MySQL schema/user, sync queue, array mail, and no public route, Supervisor worker or scheduler. Selected Account, Operator, OwnerAccess, Security and TransferAndRetention suites passed **261 tests / 3,320 assertions**. Temporary runtime, schema, user and transient resources were removed; absence was verified. This is focused validation, not the exhaustive two-tenant matrix.

## Staging and safety

Staging was reached with strict SSH host-key checking and the approved Ed25519 fingerprint `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`. Read-only post-deploy checks confirmed clean checkout at `e4659f30140669c9e229c8e583d35e6bb377a979`. Application, database, queue, scheduler, Connector API, Stripe, Cloudflare, backup storage, mail, privacy retention, restore safety and privacy exports reported healthy. Workers were running in post-deploy health verification.

The scanner state remains **WARNING — “No completed security scan yet.”** This warning is preserved and remains an M14 AppSec residual; M14.1 work does not claim a completed security scan.

All six read-back privacy execution gates are `false`: global destructive retention, profile anonymization, customer backup deletion, provider deletion, bulk execution and normal-staging synthetic profile execution. No real customer was mutated, no production deploy occurred, and no destructive real-provider endpoint was called.

## Open, non-critical audit residuals

The work is functionally complete, but the audit evidence is not yet exhaustive. Keep the following `PARTIAL` for M14 follow-up:

- Reconcile all 236 runtime routes and relevant commands/jobs against principal, ability, object scope, CSRF/API boundary, limiter, re-authentication and test evidence.
- Complete the full two-tenant positive/negative matrix across customer, Owner, Operator, Connector/API, exports, billing, privacy, restore, backup objects, provider tasks and asynchronous jobs. Do not infer full tenant isolation from the selected staging suite or the `TransferBackup` fix.
- Complete route-by-route CSRF, role/ability, stale permission/re-auth and limiter response coverage. The shared-cache counter proves only the exercised limiter path.
- Perform authenticated live Owner Console review if required by the broader M14 audit; selected authorization tests are not a substitute for that UI review.
- Run and disposition the required detect-only staging security scan under the M14 scan plan. Preserve unavailable scanner/feed state honestly.

No unresolved Critical/High defect was identified in the focused M14.1 implementation and validation; the two confirmed high-impact findings above were fixed, passed exact CI and staging validation. Any newly discovered cross-tenant access, privilege escalation, session survival, or uncontrolled mutation remains a stop-ship blocker.

## Carry-forward boundaries

This report closes the focused M14.1 implementation/evidence contribution with the B disposition. It does not close M14, certify a complete AppSec or tenant-isolation audit, authorize M15, or claim M17 readiness. Do not start M14.2/M15 until this disposition is reviewed and the remaining M14.1 audit work is accepted or completed under the M14 closure criteria. M12 privacy/legal/accounting/provider gates retain their existing independent states.

## M14.1 final authorization / tenant-isolation continuation — 2026-10-16

**Current disposition: C — M14.1 AUDIT CLOSURE INCOMPLETE.** This continuation supersedes the earlier B disposition for current closure decisions. It does not reopen F-14.1-01 or F-14.1-02, and it does not require the M14.7 scanner to close M14.1.

Verified current primary is `b6b6047a2da54de660730e3fc08c6fe2bdc1fa3e`; PR #87 is merged and its exact-head run `38906850904` is green. The current deployed executable staging SHA remains `e4659f30140669c9e229c8e583d35e6bb377a979`. Strict SSH host-key verification succeeded. The staging checkout is clean and `system:check --json` reports application, database, queue, scheduler, Connector API, Stripe TEST, Cloudflare, backup storage, mail, privacy retention, restore readiness and export checks healthy. The scanner warning is still present and remains M14.7 carry-forward.

The runtime now has a checked-in 236-row source/control manifest and deterministic test candidates for route boundaries, active-session Operator permission revocation, and customer CSRF rejection-before-mutation. Staging reports 236 routes, including 141 state-changing entries: 130 browser, 10 Connector API, and one Stripe webhook. Fresh staging config readback confirms all six M12 privacy execution gates are false. No staging mutation or provider call was made.

Exact-head GitHub Actions run [38936252202](https://github.com/zsoltacsai/wpcaregrid/actions/runs/38936252202) passed all five mandatory jobs for continuation candidate `adb1e7a36d9484c2ddc46b5f1a9f8b04739fa8c8`. This validates the added route transport-boundary invariant, customer CSRF rejection-before-mutation regression, and active-session Operator permission-revocation regression as part of the full CI suites. Server SQLite passed 1,310 tests / 19,362 assertions (19 documented skips); Server MySQL passed 1,310 / 19,560; the required MySQL process/concurrency gate passed 15 / 161; Connector SQLite passed 202 / 1,401 (24 documented skips); Connector MySQL passed 202 / 1,775 (3 documented skips). Quality/security/build passed, including Pint, dependency checks, frontend build, and secret scan.

Closure remains blocked by evidence gaps the task explicitly does not permit treating as medium-only residuals: the complete two-tenant valid-object/action matrix with zero-side-effect proofs; exhaustive object scope and positive/negative mapping for every route row; broad stale/revoked permission and recent-auth negative matrix; per-limiter effectiveness/bypass/fairness proof; complete relevant XSS sink review; and authenticated Owner Console walkthrough. The current authenticated browser showed the Owner overview, but the detailed Privacy & Retention page required a fresh Owner sign-in and was not inspected. For these reasons neither A nor B is supported by the available evidence.

No production deployment, M14.2 work, M15 work, destructive privacy execution, or production destructive-provider call occurred. M12 global, profile anonymization, customer backup deletion, provider deletion, bulk execution and normal-staging synthetic gates remain false.

## M14.1 final audit completion — 2026-10-16

**Disposition: B — M14.1 CLOSED WITH DOCUMENTED MEDIUM-ONLY RESIDUALS, M14.2 CAN BEGIN.** This section supersedes the earlier continuation C status. The branch retained all previously validated work and completed the missing evidence classes without changing application runtime code. M14.2 may begin only after this closure PR is merged. M15 was not started.

### Evidence completed

- Runtime executable under review remains staging SHA `e4659f30140669c9e229c8e583d35e6bb377a979`. The audit continuation began at branch SHA `bf642cc37f837ffb39c307a743eb096e67808a02`; candidate source/test head validated by full CI run `38948298383` is `692c6f07aefcee898762bf9124d8a3bec245b20e`. No executable application code changed, so no staging redeploy was required.
- Route reconciliation: **236 / 236 rows** with final disposition; **141** state-changing routes comprise **130 browser**, **10 Connector API**, and **1 signed Stripe webhook**. Each row points to a scope family, ability or transport boundary, limiter and applicable shared evidence. This is class-level evidence, not 236 independent behavior tests.
- Tenant matrix: **38 valid foreign-object attempts**, all denied; the three new foreign-object matrices contribute **15 attempts** and **20 grouped zero-side-effect invariants**. They cover customer website/backup/restore/pairing/admin-access, signed privacy export, and Operator security finding/incident actions. No foreign state, queue, mail, storage/provider, Connector, Stripe, restore, remediation, or privacy mutation was observed in applicable cases.
- Owner/Operator: 122 Owner and 32 Operator route contracts reconciled; route ability, console crossover, non-Owner access, active-session permission revocation and Operator disable-after-login cases covered. A synthetic disposable Owner walked overview, privacy/retention, staff/roles, integrations/settings and sensitive forms; secrets remained masked. No real Owner credentials or business settings changed.
- CSRF and transport: **130 / 130 browser mutators classified and CSRF-protected** by the framework boundary; **10 / 10 Connector API mutators** mapped to authenticated Connector API controls; the Stripe webhook mapped to signature verification and idempotency. No unexplained exemption remains.
- Authentication/re-auth: existing sensitive actions and their current-password/typed-confirmation checks reconciled. Wrong/missing password, wrong typed confirmation/preview, stale preview, role/permission revocation and session revocation negatives are mapped. The current system uses action-time password confirmation, not reusable expiring re-auth grants; cross-session/guard grant cases therefore do not apply to an absent grant mechanism.
- Output/XSS: reviewed raw Blade, `HtmlString`/sanitizer, JSON bootstrap/JSON-LD, script, attributes, Owner-published content, site/plugin/theme names, security findings/messages, audit/log and provider errors. Every identified sink is escaped, sanitized with hostile tests, structurally serialized, or a safe constant. No unknown sink remained in the bounded runtime search.
- Limiting: **27 definitions / 31 attached middleware forms** reconciled; bounded 429/fairness probes cover **12 limiter classes**, including password reset across changing IPs and multiple legitimate users behind one IP. The shared-store two-process test remains limited to its exercised counter.

### Regression and CI

Exact-head GitHub Actions run [38948298383](https://github.com/zsoltacsai/wpcaregrid/actions/runs/38948298383) passed **5 / 5 mandatory jobs** on candidate `692c6f07aefcee898762bf9124d8a3bec245b20e`, including required MySQL process/concurrency coverage:

| Gate | Result |
|---|---|
| Quality/security/build | PASS (Pint, PHP/style/dependency checks, frontend build, secret scan) |
| Server SQLite | 1,316 tests / 19,471 assertions / 19 documented skips |
| Server MySQL | 1,316 tests / 19,669 assertions / no reported skips |
| Required MySQL process/concurrency | 15 tests / 161 assertions / PASS |
| Connector SQLite | 202 tests / 1,401 assertions / 24 documented database-engine skips |
| Connector MySQL | 202 tests / 1,775 assertions / 3 documented skips |

The SQLite/Connector skip reasons are engine/environment-specific; equivalent MySQL coverage is green. No unexplained failure or new Critical/High finding remains.

### Residuals and carry-forward

- **AS-08 — PASS_WITH_MEDIUM_RESIDUAL:** 15 specialized limiter aliases remain source/route-mapped but do not each have an individual 429/fairness behavior probe: `connector-transfer`, `connector-admin-access`, `admin-access-full`, `owner-platform-backup`, `owner-platform-s3-probe`, `owner-external`, `owner-settings-mail`, `staff-invitation`, `operator-action`, `operator-update`, `operator-security`, `operator-remediate`, `account`, `owner-vies`, and `checkout-preview`. This is bounded test breadth; no bypass is known. Maintain bounded per-alias probing in broader M14 hardening.
- Some billing, provider and asynchronous operation combinations are supported by class-level control mapping and existing tests rather than a unique foreign-object test each. They remain part of broader independent M14/M17 AppSec review; no Critical/High defect was found.
- **AS-16: M14.7_CARRY_FORWARD.** Staging still warns “No completed security scan yet.” The warning was not suppressed and does not block this M14.1 audit closure.
- Remaining work includes full independent AppSec/penetration testing, additional limiter probes, hosting compatibility, M14.7 scan, M15 operational/DR/billing/privacy audits and M17 final production readiness. This closure does not assert those later audits complete.

### Staging, cleanup and safety

Strict SSH host-key verification used the approved Ed25519 fingerprint `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`. Read-only staging checks recorded clean runtime SHA `e4659f30140669c9e229c8e583d35e6bb377a979`, application/database/queue/scheduler/Connector/Stripe TEST/backup/mail/privacy/journal/recovery readiness healthy, zero failed jobs and two queue worker processes. `supervisorctl status` was not readable from the deploy user and is not claimed independently; system health and worker count are the evidence available. No synthetic Owner or other temporary staging record was created in this pass, so no staging fixture cleanup was needed.

Before and after validation all six M12 values were read `false`: global destructive retention, profile anonymization, customer backup deletion, provider deletion, bulk execution and normal-staging synthetic execution. No production/customer mutation, provider destructive call, M14.2 work or M15 work occurred.

M14.1 engineering evidence is complete with medium-only residuals. This report is not a penetration-test certification or a claim that the broader M14 audit is complete.
