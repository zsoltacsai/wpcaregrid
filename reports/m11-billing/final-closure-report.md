# M11 Final Validation and Closure Report

**Status: NOT CLOSED — C, M11 VALIDATION BLOCKER REMAINS**  
**Evidence date:** 2026-10-13  
**Scope:** staging Stripe TEST only; no production or real-customer operations.

> The initial report below is a point-in-time record. The authoritative continuation and final verdict are in **Current final continuation** at the end of this file; earlier evidence is preserved and not reinterpreted.

## Executive result

The merged M11 runtime release remains healthy and its required CI is green. Prior staging evidence covers checkout, paid activation, cancellation/reactivation, plan upgrade, a Business-to-Basic scheduled downgrade on the pre-existing synthetic account, basic lifecycle email delivery, and invoice safety gating. This validation did not establish all required M11 closure scenarios. Account A is confirmed active on Basic Annual after the monthly-to-annual switch. The account page currently shows no pending change. Although the Owner reported seeing a success message for annual-to-monthly, the page was refreshed and still showed Annual with no pending-change banner or effective date. The Owner could not recall whether that banner appeared. No further plan-change request was submitted.

Payment failure/grace/recovery and grace expiry/restriction were not demonstrated on isolated staging accounts. The repository has deterministic tests for grace behavior, but no Stripe Test Clock or equivalent staging fixture path was found in `app`, `routes`, or `tests`. The staging invoice pipeline remains safely blocked by missing synthetic billing identity; no live Számlázz.hu issuance was attempted.

## Repository and release

- Repository: `zsoltacsai/wpcaregrid`
- Primary branch: `claude/ecstatic-wozniak-oemyuf`
- M11 implementation commit: `91c68f2ce4e9757d14f3700c7df13252c0126f02`
- PR #18: merged; merge commit `0ac3c14468a600fbd9aebf3d79b654e351ff26ac`
- Primary branch HEAD at validation: `0ac3c14468a600fbd9aebf3d79b654e351ff26ac`
- Staging deployed SHA reported and rechecked against the approved release: `0ac3c14468a600fbd9aebf3d79b654e351ff26ac`
- No runtime source changes or deployment were made during this closure validation.
- Implementation worktree was clean before this report was created.

## CI and automated validation

Exact-SHA PR CI for implementation SHA `91c68f2ce4e9757d14f3700c7df13252c0126f02`:

- Run `38348453119`: all five mandatory jobs passed (server MySQL, server SQLite, Connector MySQL, Connector SQLite, quality).

Post-merge CI for primary SHA `0ac3c14468a600fbd9aebf3d79b654e351ff26ac`:

- Run `38349339782`: all five mandatory jobs passed.

Focused M11 Laravel suite, executed from WSL PHP 8.4.26:

- **106 passed, 6 skipped, 588 assertions.**
- The six skips are multi-process MySQL-only concurrency cases skipped because the local environment had no explicitly configured MySQL test database. The merged exact-SHA CI MySQL job passed.
- Focused coverage included subscription lifecycle/management, grace, webhook inbox/idempotency, reconciliation, invoice intent/snapshot/issuance gates, billing email outbox and concurrency.

Windows PHP 8.3.33 could not run Composer because the project requires PHP `>=8.4.1`; this was an environment mismatch, not a test failure. The WSL PHP 8.4.26 run provided the focused local result above.

## Staging health and safety

Read-only HTTP checks on 2026-10-13:

- `https://staging.wpcaregrid.com/up` — HTTP 200
- `https://staging.wpcaregrid.com/` — HTTP 200
- `https://staging.wpcaregrid.com/login` — HTTP 200

Earlier M11 evidence reports healthy queue/workers/scheduler, no failed jobs, drained webhook work, restore gates OFF, and Stripe TEST mode. No production Stripe or customer resource was touched in this validation. No live billing provider state was changed by the agent. Owner-completed checkout and plan-switch actions were in Stripe TEST mode; no real-money charge was reported.

The existing synthetic account with a Business-to-Basic downgrade scheduled for its next renewal was not modified.

## Billing lifecycle evidence

| Scenario | Result | Evidence / limitation |
|---|---|---|
| Stripe TEST checkout and activation | PASS | Previously validated on a separate synthetic account. |
| Monthly Basic → Annual Basic | PASS | Account A now visibly shows Basic Annual, HUF 89,900/year; current annual period 2026-10-13 to 2027-10-13. Payment history shows HUF 8,990 and HUF 80,910 test payments. |
| Annual → Monthly scheduled change | NOT VERIFIED | Owner reports success message, but refreshed Account A page shows active Annual, no pending-change banner, and Annual selected. Owner could not recall seeing the effective-date banner. No second request was sent. |
| Cancellation at period end | PASS (historical) | Previously validated; preserved as prior evidence. |
| Reactivation | PASS (historical) | Previously validated; preserved as prior evidence. |
| Basic → Business upgrade and proration | PASS (historical) | Previously validated; preserved as prior evidence. |
| Business → Basic scheduled downgrade | PASS (historical) | Existing synthetic account remains intentionally pending; untouched. |
| Payment failure and grace start on staging | NOT RUN | No isolated Account B evidence in this validation. Automated policy/lifecycle tests pass. |
| Duplicate failure preserves original grace deadline | AUTOMATED | Covered by local suite and CI; no staging duplicate failure was generated. |
| Recovery during grace | NOT RUN | No isolated staging payment recovery evidence. |
| Grace expiry and restriction | NOT RUN | No staging Test Clock or approved deterministic time-advance path found in the repository. Seven real days were not awaited and provider state was not altered to simulate expiry. |
| Safety-critical access while restricted | AUTOMATED ONLY | Central entitlement/policy tests exist; no restricted staging Account C was created. |
| Duplicate webhook replay / out-of-order event | AUTOMATED | Webhook inbox, idempotency, reconciliation and event-order tests are included in the focused suite; no staging event replay was performed. |
| Full reconciliation mismatch and repair | NOT VERIFIED LIVE | Automated reconciliation tests pass; no staging mismatch was introduced. |

## Invoice and transactional email

- Staging invoice intent remains blocked by `billing_identity_missing`, as designed.
- No Számlázz.hu issue attempt was observed in the prior staging evidence; live issuance remains OFF.
- Invoice snapshot, intent, duplicate-prevention, tax/accounting gate and issuance-safety behavior are covered by automated tests.
- Activation, payment, cancellation, reactivation and upgrade lifecycle emails were reported received without duplicates in prior Owner validation.
- Annual-to-monthly scheduled-change email and the payment-failure, recovery and restriction emails were not confirmed in this validation.
- M10 monitoring recognizes billing signals in tests; a complete live staging exercise of every billing signal was not performed.

## Source review findings

- `SubscriptionManagement::changePlan()` classifies annual-to-monthly as a scheduled change and persists `pending_change_status`, target interval/plan and `pending_change_effective_at` before requesting the provider update.
- The account page renders a pending-change banner and hides the change form whenever pending state exists.
- Current Account A page shows no such banner and continues to offer the change form. This is insufficient to claim the annual-to-monthly request is persisted, even though the Owner recalls a success message.
- Repository search found no Stripe Test Clock implementation or test-clock fixture path in `app`, `routes`, or `tests`.
- No code defect was established from the available staging UI evidence; the annual-to-monthly provider/application operation needs an auditable staging result before it can pass.

## Remaining M11 blockers

1. Confirm annual-to-monthly scheduling using a single auditable staging request and visible pending target/effective date; do not repeat until the existing subscription operation/provider state is checked through an approved read-only administrative path.
2. Create isolated synthetic Accounts B, C and D (or equivalent approved test fixtures) for payment failure/grace/recovery, grace expiry/restriction, and webhook replay/reconciliation.
3. Provide a safe deterministic staging time-advance mechanism for seven-day grace expiry, or document/implement an approved fixture path without mutating production-like state.
4. Validate a synthetic billing identity through the invoice snapshot and provider-ready/dry-run gate while live Számlázz.hu issuance remains disabled.
5. Confirm period-change and remaining lifecycle emails, plus the applicable M10 billing-monitoring signals.

## Final verdict

**C — M11 VALIDATION BLOCKER REMAINS.**

The release and automated regression coverage are healthy, but mandatory staging evidence for annual-to-monthly scheduling and the isolated failure/grace/recovery/expiry/reconciliation scenarios is incomplete. M11 is not closed. Stripe TEST mode and the live Számlázz.hu safety gate remain in effect; production deployment is not authorized by this report.

## Closure evidence CI

- PR #19 closure-report commit `50b191cbc1642c1e4e3c81715894f4a1d026da10` passed exact-SHA CI run `38393776604` on 2026-10-13. All five jobs passed: quality/secrets, server SQLite, server MySQL (including required process-concurrency gate), Connector SQLite, and Connector MySQL.
- This report documents an open milestone; green CI does not substitute for the missing staging lifecycle evidence listed above.

## Current final continuation — 2026-10-13

### Release and CI

- PR #23, **Prevent stale grace-expiry billing notices**, was merged normally after its exact head SHA passed CI.
- Fix commits: `7ced5ed4e547553121a0edc8562e8072a72921d` and `4d486df93cb1bd3bb02567592da307e39ed23603`.
- Exact PR-head CI run `38449241673` on `4d486df93cb1bd3bb02567592da307e39ed23603`: all five mandatory jobs passed — Server MySQL (including process-concurrency, migration, system-check and HTTP smoke steps), Server SQLite, Connector MySQL, Connector SQLite, and Quality/security/build.
- Merge commit and current primary/staging release SHA: `ee86faaa7e5a385cdb8816f1387d68e42b5f31ec`.
- No separate push-triggered post-merge workflow run was returned for the merge commit; the merge used the green exact-SHA PR checks.
- Staging was deployed with strict SSH host-key checking and the repository guarded deploy script. The accepted ED25519 fingerprint matched the previously approved `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`.
- Guarded deploy created and retained `/home/deploy/private-backups/wpcaregrid-pre-deploy-20261013T190022Z.sql.gz`; migrations were not pending. Deployment completed on the exact merge SHA.

### Fixes and staging verification

- A synthetic grace-expiry test had left one unreserved future-dated `ProcessBillingEmailOutboxJob` in the default queue. The old backlog health check counted it as overdue and blocked the guarded deploy before mutation. After verifying that it was the sole pending queue row, no job was reserved, and both workers were healthy, the single test-generated queue item was removed with Laravel's `queue:clear` for the default database queue. The system check then returned Queue OK, allowing the unchanged guarded deployment to proceed.
- The new health query excludes jobs whose `available_at` is in the future. The staging harness now fakes dispatch while it advances the request-scoped clock for grace expiry, avoiding another future-dated real queue row.
- The stale Account B `billing_action_required` outbox row from the earlier grace-expiry harness was passed through the deployed `ProcessBillingEmailOutboxJob` handler. Because the subscription was active, paid, and outside grace, the row became `cancelled`; no notice was sent. Regression coverage verifies this condition and verifies that a still-unpaid expired-grace notice remains sendable.
- Post-deploy `system:check`: application, database, queue, scheduler, Connector API, Stripe TEST, Cloudflare, backup storage, mail and safety all OK. Security scanner remains WARNING because no completed scan exists; this is an existing visible finding, not represented as healthy.
- Workers `wpcaregrid-default` and `wpcaregrid-backups` are RUNNING with new PIDs. Scheduler is healthy. There are no pending/reserved Laravel queue jobs, failed jobs, unprocessed TEST webhook events, or pending migrations. `/en/`, `/up`, `/login`, and `/owner/login` returned HTTP 200. Restore/live billing safety flags remain off; production and real-customer resources were not touched.

### Billing-period changes

- Account A's confirmed monthly-to-annual switch remains recorded: Basic Annual is active after the paid TEST proration; its `billing_period_switch` operation is `confirmed`.
- **Account B annual-to-monthly: PASS.** The Owner Console shows Basic Annual active and Basic Monthly scheduled for the annual renewal at `2027-10-13 17:07:47 UTC`; reconciliation is healthy. A read-only Stripe TEST API check confirmed the attached schedule is active, belongs to the same subscription, keeps the current annual price through the current phase end, and changes to the monthly price in the next phase at exactly that renewal boundary. No new checkout or payment was made during this validation.
- A separate synthetic Account A (`contact+m11-period`) has an unresolved mismatch: local state still says monthly is scheduled for `2027-10-13`, but its current Stripe TEST subscription has no attached schedule and reports the current period end as `2026-11-13`. Its downgrade operation remains `awaiting_confirmation`, has a provider request ID, but has no stored provider schedule ID. No repair or provider write was attempted. Preserve this account and its operation as evidence; investigate before treating Account A's prior annual-to-monthly attempt as successful.

### Failure, grace, email and reconciliation evidence

- The earlier Account B lifecycle harness audits preserve payment failure, duplicate failure, recovery, out-of-order event, replay, and request-scoped grace-expiry runs. The simulated expiry audit recorded commercial access false and emergency restore entitlement true, after which the harness restored the synthetic account to its actual active Stripe TEST state.
- A fresh controlled Account A harness sequence signed a TEST-mode payment-failure fixture, then ran actual targeted Stripe reconciliation. The failure run recorded `active → past_due`, a seven-day grace interval, and retained access during grace. Reconciliation fetched the provider's authoritative active subscription and repaired local state (`checked=1, changed=1, failed=0`); the final subscription is active and grace fields are clear. The existing local monthly target was not treated as provider-confirmed by this reconciliation.
- Account A's `payment_failed` and `grace_started` lifecycle emails were sent once each. This harness sequence is a signed application TEST fixture; it is **not** evidence that Stripe itself generated a failed renewal charge. The earlier Account B recovery and other lifecycle emails remain evidenced by the prior audited harness and Owner confirmations.
- The Owner lifecycle harness is intentionally request-scoped and immediately returns the grace-expiry account to active provider state. Restricted-state entitlement is evidenced by the harness snapshot and automated tests, but this task did not leave a staging customer restricted or directly exercise all restricted customer pages.

### Invoice, safety and remaining gates

- Invoice intent remains blocked by `billing_identity_missing`; no synthetic legal/tax identity was invented or inserted. Staging Számlázz settings were read-only verified as `provider_enabled=false`, `dry_run=true`, `issuance_enabled=false`. No issuance attempt was made. A synthetic billing identity and any accountant-approved tax treatment are still Owner/accounting-controlled prerequisites for an invoice snapshot/provider-ready dry run.
- Focused local M11/billing tests before deployment: 77 tests, 385 assertions, 3 MySQL/pcntl-only skips. The updated focused health/harness tests: 35 tests, 350 assertions. Full local Laravel suite for the code fix: 1,177 tests, 16,349 assertions, 24 environment-dependent skips. Exact-SHA CI above passed its required MySQL concurrency gate.
- Automated tests cover policy and entitlement restrictions, grace timing, duplicate-failure idempotency, stale-email cancellation, future delayed-job health, webhook replay/order, reconciliation and invoice safety. Staging evidence does not claim a real provider-originated renewal failure or an end-to-end restricted customer login/billing-page session.

### Current verdict

**C — M11 VALIDATION BLOCKER REMAINS.** Account B's annual-to-monthly provider schedule is now verified and the queue/email regressions are fixed, merged, CI-validated and deployed. M11 is still open because the separate Account A schedule mismatch is unresolved, the renewal-failure evidence is a signed harness fixture rather than a Stripe-originated failed payment, restricted customer page access was not directly exercised while restricted, and invoice snapshot/provider readiness still requires a valid synthetic billing identity under the existing accounting gate. No production or real-customer resource was changed, and no real-money payment occurred during this continuation.
