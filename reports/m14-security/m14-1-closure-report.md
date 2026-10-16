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

Closure remains blocked by evidence gaps the task explicitly does not permit treating as medium-only residuals: the complete two-tenant valid-object/action matrix with zero-side-effect proofs; exhaustive object scope and positive/negative mapping for every route row; broad stale/revoked permission and recent-auth negative matrix; per-limiter effectiveness/bypass/fairness proof; complete relevant XSS sink review; and authenticated Owner Console walkthrough. The current authenticated browser showed the Owner overview, but the detailed Privacy & Retention page required a fresh Owner sign-in and was not inspected. New tests are not validated until exact-head CI runs. For these reasons neither A nor B is supported by the available evidence.

No production deployment, M14.2 work, M15 work, destructive privacy execution, or production destructive-provider call occurred. M12 global, profile anonymization, customer backup deletion, provider deletion, bulk execution and normal-staging synthetic gates remain false.
