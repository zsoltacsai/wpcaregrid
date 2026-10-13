# M11 implementation baseline

## Repository and commit boundary

- Primary branch: `claude/ecstatic-wozniak-oemyuf`.
- Primary baseline SHA: `9b9f888838f0d269e0cce987accb8c9d28cc6f22` (verified from `origin` and GitHub branch API on 2026-10-12).
- Implementation branch: `codex/m11-billing`, created in a fresh managed worktree at the exact primary SHA.
- The old planning checkout was `f0194bca2d58b8eafccb1dfd1e3fc6176ed132de`, 79 commits behind and dirty. It was not reset or cleaned. The approved 13 planning documents were copied byte-for-byte into this worktree and each SHA-256 was compared.
- The M10 closure report at the baseline declares M10 closed; staging runtime was deployed at `33c961f07c541cd243a411e1310aa9236383e6fb`. M11 implementation work must not rewrite M10 history or change production.

## Reconciled current billing components

Current primary billing paths were inspected locally at the prior checkout and remotely at the exact primary SHA. GitHub compare across the 79 intervening commits showed monitoring/release work and a pricing/account subscription presentation update; the selected remote `SubscriptionStatus`, `SubscriptionSync`, `StripeWebhookHandler`, `InvoiceIssuanceWorkflow`, and `routes/console.php` retained the reviewed foundation.

Reusable foundations: `PricingCatalog`, `PriceDefinition`, `PricingOverrides`, `StripePriceVerifier`; `CheckoutIntent`, `StartCheckout`, `StripeCheckoutGateway` with intent-derived stable idempotency; unique Customer Stripe ID; `Subscription` Stripe mirror and `SubscriptionStatus`; signed webhook controller, unique `StripeEvent`, `StripeWebhookHandler`, `SubscriptionSync`, `InvoiceEvents`; promotion eligibility/reservations; immutable `CustomerBillingIdentity`; `BillingInvoice`; invoice intents/events/tax review/issuance attempt; guarded Számlázz provider interfaces and lookup reconciliation; queued lifecycle mailables; Owner billing/price/coupon/tax screens; customer subscription/billing views; M10 mail-provider signals; platform scheduler and queue conventions.

## Corrected assumptions

- Existing Stripe event handling is transactional/idempotent for already-applied events, but the current `stripe_events` table is not a pending asynchronous inbox with retry/lease/poison states.
- Current internal subscription status maps Stripe names and only `active`/`trialing` grant service operations; approved M11 grace and restriction behavior is new work.
- Published price definitions and retired Price IDs protect some historical mapping, but there is no complete immutable catalog version pinned by every checkout/subscription.
- Customer subscription management is not yet implemented; the existing button is disabled. Existing invoice history is a Stripe mirror; it is not the same as an issued Számlázz document.
- Invoice-intent/provider gates must stay fail-closed. Live automatic Számlázz issuance remains OFF absent separate Owner/accounting approval; synthetic test-provider behavior does not prove live legal issuance.
- M10 mail monitoring is provider health, not a durable billing notification idempotency ledger.
- Local stale worktree findings are not authoritative over the clean primary baseline.

## Approved Owner decisions from the implementation request

- Grace: 7 days from the first failed renewal; duplicates/retries do not extend it.
- During grace: customer login, invoices, monitoring, backup, security and emergency restore remain available.
- After grace: restricted/suspended; do not delete websites, backups, configuration, invoices or customer data.
- Upgrade: immediate intent; entitlement only after Stripe-confirmed successful payment; proration must be explicit.
- Downgrade: next renewal, no mid-cycle destructive change; over-limit/action-required state, no deletions.
- Cancellation: period-end default; paid-through access continues; reactivation allowed before effective date.
- Monthly to annual: may apply immediately only after confirmed payment. Annual to monthly: next renewal.
- Refunds: audited Owner/accountant-controlled workflow; no automatic refund engine.
- Stripe Customer Portal: payment-method management only; CareGrid owns plan/cancel/reactivate.
- Számlázz integration may be completed, but live auto-issuance remains behind explicit Owner/accounting gate.
- No M11 automatic customer/site/billing data deletion; M12 defines legal retention/deletion.
- Existing subscription price cannot silently change; use immutable/versioned provider price references.
- Preserve safety-critical entitlements while commercially restricted, especially emergency restore, invoice access and account recovery.

## Implementation sequence

1. Catalog version and checkout/subscription price snapshots; migrations additive.
2. Normalized lifecycle projector and central entitlement resolver, with approved grace and safety exceptions.
3. Durable webhook inbox/dispatcher and operation ledger; bounded retries, mode binding and reconciliation.
4. Stripe-confirmed upgrade/downgrade/cancel/reactivate and billing-period operations.
5. Invoice-intent hardening and notification outbox; keep live issuance gated off.
6. Customer/Owner/Operator views, audit and M10 health signals.
7. MySQL concurrency/security suite, deterministic test fixtures and Stripe test-mode E2E.
8. Exact-SHA CI, normal primary integration, guarded staging deploy, test-mode validation, evidence-only closure.

## Explicit non-goals

No production deploy, live money movement, live invoice issuance, automatic refunds, M12 data deletion, tax-law redesign, multi-payment-provider abstraction, usage billing, customer-site deletion, uncontrolled Billing Portal plan changes, force push, history rewrite, or cleanup of the stale planning checkout.
