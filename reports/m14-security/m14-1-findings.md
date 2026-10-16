# M14.1 Implementation Findings and Status

Baseline: planning merge `37f31d0ddd6cc8c62221a5cac20e39b69e6fe45a` on `codex/m14-1-appsec-implementation`. Review date: 2026-10-16. This is a focused application-security implementation record, not a penetration-test or security certification.

| ID | Severity / state | Evidence | Root cause / impact | Resolution / remaining work |
|---|---|---|---|---|
| F-14.1-01 | **High — confirmed; fixed, exact-SHA CI and staging verified** | Regression coverage proves persisted sessions are removed after password reset; exact implementation PR #85 and final PR #86 candidate passed required CI; deployed staging source is `e4659f30140669c9e229c8e583d35e6bb377a979`. | Rotating `remember_token` alone did not revoke already-authenticated persisted sessions. | `App\\Auth\\SessionRevoker` revokes persisted user sessions in the password-reset path; related role/activation revocation is covered by implementation tests. This finding is resolved on the deployed SHA. Broader all-route re-auth/RBAC audit remains a separate partial control. |
| F-14.1-02 | **High-impact stale-scope defect — confirmed and fixed; CI/staging verified** | Adversarial regressions demonstrated that a queued `TransferBackup` could follow a backup after reparenting and update the new tenant’s target. Tests now cover website reparent, child reassignment, provider change, deleted/no-longer-uploaded state, and legacy unscoped jobs. | The queued job previously trusted mutable parent/object relations at execution time, allowing the delayed job’s target to drift from enqueue-time scope. | Job now binds and revalidates enqueue-time website, provider and object identities; stale/legacy state fails closed, and failed callbacks are scope-bound. Focused backup regression: 30 tests / 217 assertions; exact CI and deployed staging SHA pass. No real provider delete was called. |
| F-14.1-03 | **Shared rate-limit cache behavior — tested; route coverage remains PARTIAL** | Staging uses shared database cache. A bounded two-independent-process check observed a shared counter (`1`, then `1→2`) and cleanup to `0`; the temporary staging suite also passed on the exact deployed source. | Shared-store behavior is demonstrated for the exercised limiter/counter. This does not prove every sensitive/expensive route has correct limiter attachment, key dimensions, or abuse response. | Keep route-to-limiter completeness and broad enumeration-neutral 429 assertions open for the later M14 security audit. Do not treat this finding as a distributed-cache defect. |
| F-14.1-04 | **PASS — expanded runtime inventory generated; reconciliation PARTIAL** | `m14-1-runtime-entrypoint-inventory.md`: 236 runtime routes, 36 project command classes, 19 scheduled entries, 9 job classes, plus event/listener scan. | Runtime expansion is now evidenced; it does not itself establish authorization, tenant binding, CSRF, re-auth, limiter and test coverage for every mutator. | Preserve the inventory as current for SHA `e4659f30140669c9e229c8e583d35e6bb377a979`; complete per-entry control/test reconciliation in M14 AppSec work. |

## Final authorization / tenant-isolation continuation — 2026-10-16

The fresh route/control export at staging SHA `e4659f30140669c9e229c8e583d35e6bb377a979` is in [`m14-1-route-control-reconciliation.md`](m14-1-route-control-reconciliation.md). It confirms 236 routes and maps all 141 state-changing registrations: 130 browser, 10 Connector API, one Stripe webhook. The route inventory is unchanged. The sole CSRF exemption is explicit and the webhook controller delegates to signature-verifying `AcceptStripeWebhook`; Connector mutations use `connector.auth` except initial pairing, which uses its dedicated limiter.

The source audit and sampled tests surfaced **no new confirmed Critical, High, or Medium vulnerability in the inspected paths**, but this is not a full adversarial sweep. No finding is marked resolved by route-source metadata. Existing representative foreign-object denial, role crossover, Owner/Operator ability contracts, CSRF rejection, limiter behavior and output escaping remain bounded to their specific tests. The complete two-tenant object/action matrix and zero-side-effect coverage remain open.

Added continuation-only test candidates: `RuntimeRouteBoundaryTest` (route boundary/limiter/exception invariants) and an active-session Operator permission-revocation test. They are not yet validated by exact-head CI. No product/runtime fix was identified or made in this continuation.

Live staging read-only health checks remained green for application, DB, queue, scheduler, Connector, Stripe TEST, Cloudflare, backup storage, mail, privacy retention/restore and export. The scanner warning remains visible. All six M12 privacy execution gates were read back false. The authenticated Owner overview was observed, but the detailed Privacy & Retention route redirected to sign-in and its sensitive forms were not reviewed. No staging state was changed.

**Current closure status: C — audit closure incomplete.** Open evidence gaps are not reclassified as medium-only residuals. AS-16 is M14.7 carry-forward and does not itself block M14.1 closure.

## Final M14.1 closure continuation — 2026-10-16

The preceding C status is historical and superseded by the evidence below. No application/runtime code changed in this continuation; changes are security tests, route/audit reconciliation and evidence reports. No production or customer mutation occurred.

| Evidence item | Result |
|---|---|
| Runtime route/control manifest | 236 / 236 rows reconciled; 141 state-changing: 130 browser, 10 Connector API, one signed webhook. |
| Tenant matrix | 38 valid foreign-object attempts across represented tenant-bound action classes; all denied. The three added matrices contribute 15 attempts and 20 grouped zero-side-effect invariants. |
| Owner/Operator | Owner 122 and Operator 32 route contracts reconciled; non-owner/customer/operator crossover denied; permission and account disablement after login rechecked; synthetic Owner Console walkthrough completed. |
| CSRF / API boundaries | 130 browser mutators classified/protected by CSRF boundary; 10 Connector API mutators use authenticated API controls; one Stripe webhook uses signature verification plus event idempotency. |
| Output sinks | Relevant raw Blade, JSON, script, attribute, Owner-managed content, site/plugin/theme, security finding/message, audit and provider error output paths classified; hostile payload suites cover custom sanitizers/serialization. |
| Limiting | 27 definitions / 31 middleware forms mapped; 12 classes behavior-probed; 15 specialized aliases have no individual fairness/429 probe and remain a documented medium residual. |

No new Critical or High finding was confirmed. New Medium finding F-14.1-03 is limited to behavioral breadth for the 15 specialized limiter aliases; the source/control map and high-risk-class behavior are present. AS-16 remains M14.7 carry-forward.

## Staging baseline (pre-deploy)

- Strict SSH authentication succeeded for `stg1.wpcaregrid.com`; the stored Ed25519 fingerprint is `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`.
- Current runtime SHA: `a335162936afa2933e348fe7b04956e98e45c5a3`; detached checkout clean.
- Laravel 13.33.0, PHP 8.4.26, `APP_ENV=production`, debug off; MySQL, database cache/queue/session; migrations current.
- MySQL, Supervisor and Nginx active; both configured workers RUNNING; no failed queue jobs; homepage returns 200 after one canonical redirect. The Owner URL was reached through the authenticated browser context.
- `config:show privacy` read back global, profile, backup, provider, bulk and synthetic profile execution gates as `false`.
- `SYNTHETIC_FIXTURE_CONTROLLED_EXECUTION_ENABLED=true` is present on staging; this is a fixture-provisioning control, not the profile anonymization execution gate. M14.1 must prove it cannot invoke destructive privacy execution outside `APP_ENV=testing`; do not treat it as permission to mutate staging customers.
- The previously visible “no completed security scan” warning remains an M14 AppSec residual and must not be suppressed.

## Scope limits and disposition

No M12 gate was changed, no customer/staging record was mutated, and no real provider deletion was called. The current change intentionally requires the database session driver for persisted user-wide revocation; `array` is permitted only under the automated `testing` environment, while unsupported persistent drivers fail closed. Any hosting configuration using another persistent session backend must be reconciled before that environment relies on password-reset/role-change revocation.

No Critical/High status may be declared resolved until the regression suite, exact-head required CI, guarded staging deploy and post-deploy checks pass. Full M14.1 closure also requires the outstanding two-process limiter test and stale transfer-job adversarial coverage.

## Validation update (2026-10-16)

- Added and passed regressions for deleted and no-longer-uploaded queued `TransferBackup` jobs. They are no-ops and do not produce a verification event. Reparenting/foreign-child and simultaneous-worker MySQL behavior remain unproven and are still tracked in F-14.1-02.
- Session-revocation coverage uses the configured JSON session serialization, revokes matching `web`, `owner`, and `operator` guard keys for null-`user_id` legacy sessions, preserves another user's session, and fails closed for unsupported session drivers and a separate session database connection.
- Focused Account/Owner/Operator/Security run: 42 passed, 1,053 assertions. Latest focused session-revoker run: 3 passed, 6 assertions. Backup transfer/retention suite: 26 passed, 176 assertions.
- Full Server SQLite: 1,278 passed, 25 skipped, 18,651 assertions, 0 failures (104.67 seconds). The skips are the existing environment-specific opt-ins; MySQL/process coverage remains required in CI.
- Pint: 9 changed PHP files passed. PHP lint passed across application and feature-test PHP files. Composer validation passed; Composer audit reported no advisories. `git diff --check` and the scoped secret-pattern scan passed.
- The earlier statements above that F-14.1-02 still needed adversarial proof and F-14.1-03 lacked process evidence are superseded by the final validation below. F-14.1-04 inventory generation is complete; its control-to-test reconciliation remains partial.

## Final M14.1 validation (2026-10-16)

- Planning PR #84 merged at `37f31d0ddd6cc8c62221a5cac20e39b69e6fe45a`.
- Implementation PR #85 merged at `943af21330451377ef79ddf46a92dd0319372adf` and fix PR #86 merged at `e4659f30140669c9e229c8e583d35e6bb377a979`. PR #86 head `7532df4e692b13047ac30df0ac25c1cd3a5958d9` passed exact-head GitHub Actions run [38903198033](https://github.com/zsoltacsai/wpcaregrid/actions/runs/38903198033): all five mandatory jobs passed.
- CI totals: Server SQLite 1,307 tests / 18,731 assertions / 19 documented skips; Server MySQL 1,307 / 18,929; MySQL process/concurrency gate 15 / 161; Connector SQLite 202 / 1,401 / 24 documented skips; Connector MySQL 202 / 1,775 / 3 documented skips. The required gate’s test summary was parsed from the exact CI job log, not estimated.
- Isolated staging test runtime used exact source `e4659f30140669c9e229c8e583d35e6bb377a979`, `APP_ENV=testing`, separate MySQL schema/user, sync queue, array mail, no public route, Supervisor or scheduler. Focused Account/Operator/OwnerAccess/Security/TransferAndRetention suites passed: 261 tests / 3,320 assertions. Temporary schema, user, runtime and transient resources were removed and absence verified.
- Strict SSH host-key verification matched approved fingerprint `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`. Final read-only staging verification reported clean checkout at `e4659f30140669c9e229c8e583d35e6bb377a979`; application, database, queue, scheduler, Connector API, Stripe, Cloudflare, backup storage, mail, privacy retention, restore safety and exports were healthy. Workers were running in the prior post-deploy health capture. Security scanner warning remains: “No completed security scan yet.”
- All six read-back privacy switches remained false: global destructive retention, profile anonymization, backup deletion, provider deletion, bulk retention execution and synthetic profile anonymization. No ordinary staging customer was mutated and no real provider deletion was called.
- **Disposition:** F-14.1-01 and F-14.1-02 are fixed and validated; F-14.1-03’s shared-counter subtest passes but full route limiter coverage remains partial; F-14.1-04 inventory generation passes but mutator-to-auth/scope/CSRF/limiter/test reconciliation is incomplete. Therefore M14.1 is functionally complete with non-critical audit residuals, not a complete AppSec or tenant-isolation audit. No confirmed Critical/High issue is open from this focused work. The broader security scan stays an M14 residual.
