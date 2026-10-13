# M11 validation and closure plan

## Pre-implementation baseline

1. Start from a clean worktree at current primary SHA; current reviewed local checkout is `f0194bca…`, 79 behind remote `9b9f888…`. Re-run source audit, inspect merged M10 branch and CI/workflows; do not carry local unrelated changes implicitly.
2. Record schema/data inventory and current Stripe mode separation without exposing secret values. No test may inherit real Stripe/Számlázz credentials.
3. Approve state, grace, cancellation, downgrade, refund and invoice policy from `owner-decisions.md`; get accountant approval for any live Számlázz issuance rule.
4. Build an additive migration/backfill dry run against anonymized/synthetic records; backup/restore plan under M9 before any staging migration.

## Test layers and mandatory suites

### Unit/domain

Subscription state transitions/order, entitlement values/limits, grace UTC boundaries, annual price math, price version compatibility, promotion eligibility/reservation, retry classes, operation key stability, invoice status transitions, notification keys and localized copy.

### Laravel feature

Checkout server-authoritative price/customer ownership; redirect stays pending; valid signed webhook provisions only once; cancellation/upgrade/downgrade/reactivation policy; billing identity version pinning; Owner and Operator abilities; invoice visibility; customer IDOR; stale-state UI; mail failure isolation; scheduler/dunning. Existing `SubscriptionLifecycleTest`, checkout/promotion, billing snapshot/identity, invoice workflow and M10 provider.mail tests are extension points, not an assumption that coverage is complete.

### Webhook/provider

Valid signature; bad signature; duplicate/concurrent event; delayed/async checkout; out-of-order/unknown event; account/mode mismatch; queue dispatch loss; provider API outage; poison event and bounded retry; pending receipt recovery; reconciliation agreement/mismatch.

### MySQL concurrency

Independent-process MySQL tests for unique subscription/receipt, duplicate checkout command, coupon final redemption, simultaneous cancel/upgrade, webhook/reconcile race, one invoice issue and notification outbox. SQLite is useful for ordinary feature tests, insufficient for lock/atomicity proof.

### Invoice and mail

Számlázz synthetic provider and deterministic adapter: dry-run, validation block, one submit, timeout ambiguous lookup, auth failure, duplicate worker, issue record recovery. HU/EN mail content, duplicate outbox, failed delivery retry, no card/secret data, M10 provider health integration.

### Stripe E2E and staging

Stripe test mode only, synthetic customer and test payment method; real signed webhook delivery using Stripe CLI or equivalent reachable test endpoint; monthly initial Checkout; provider confirmation/entitlement; test clock or deterministic webhook fixtures for renewal/failure/recovery/cancel schedule. Verify actual invoice intent boundary and mail sink, never live money. If using real Stripe test-mode provider, preserve request/event IDs, Customer and Subscription IDs only where safe, test-mode marker, timestamps, and teardown/deactivation steps. Do not rely on browser redirect as success.

## Migration and backfill validation

- MySQL and SQLite migration up/down; no destructive column rename/drop in same release.
- Backfill dry run classifies records: complete, derivable, unknown, identity conflict, unsupported status. Unknown remains `reconciliation_required` and is not auto-entitled.
- Compare counts and representative snapshots before/after; no Stripe API writes during backfill.
- Staging backup verified first; migration idempotency/retry and rollback understood; never run against production in M11.

## Release sequence

Local code/tests → narrow commits → push → exact candidate CI (Connector MySQL/SQLite, server MySQL/SQLite/concurrency, quality/security/build) → merge to primary normally → exact deployable primary CI → guarded staging deployment with strict SSH → staging health and worker/scheduler reload → Stripe test-mode E2E → invoice dry-run/approved synthetic issue boundary → Owner billing/alerts review → final report-only commit/push and required CI. Production release is separate and excluded.

## Closure gates

M11 closes only after: subscription/plan states deterministic; no duplicate subscription/charge/invoice; Checkout needs provider evidence; webhook duplicates/order/reconciliation safe; entitlement and limits enforced centrally; upgrades/downgrades/cancel/reactivation and grace meet approved policy; identity and invoice snapshots immutable; invoice issue safe/idempotent; email failure independent; Owner sees safe failures; customer state accurate; Stripe test-mode E2E passed; MySQL concurrency and all relevant CI green; guarded staging checks pass; residual accounting/legal items are explicitly out of M11 and do not falsely claim compliance.

## Evidence to retain

Exact source/CI/deploy SHAs and run IDs; migration/backfill summaries; synthetic Stripe event and provider mode; test counts and skip reasons; E2E timeline; invoice intent/attempt state; notification delivery status; M10 billing signals; Owner decisions; security review; sanitized failure evidence; final gates and production-not-touched statement. Never include API keys, webhook secrets, card data, full tax identity or raw sensitive provider payload.
