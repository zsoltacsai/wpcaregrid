# M11 architecture plan — Subscription lifecycle, billing and transactional email

## Status and evidence boundary

This is a planning-only design. No code, migration, provider resource, staging configuration, Stripe object, invoice or customer email was changed by this planning work.

Repository evidence was inspected at local `HEAD f0194bca2d58b8eafccb1dfd1e3fc6176ed132de`. That checkout is 79 commits behind the primary remote and has pre-existing modifications/untracked items (`routes/web.php`, `.claude/`, `=`, and `reports/`); these were preserved. The current GitHub primary was independently read as `9b9f888838f0d269e0cce987accb8c9d28cc6f22`. GitHub compare showed the intervening billing-relevant paths were pricing presentation/admin and the account subscription view; the selected remote webhook, subscription sync, status enum, invoice issuance and scheduler files match the reviewed lifecycle foundation. Implementation must begin from a fresh, clean current primary checkout and re-audit all paths before changing code.

## Verified current foundation

- `PricingCatalog` resolves product, tier, interval, market, currency and Stripe Price ID server-side from `config/pricing.php` with published `PriceDefinition` overlays. `PricingOverrides` retains retired Stripe Price IDs for existing subscriptions. The owner editor has a draft/publish flow and price verification. Published rows are mutable current definitions, not a complete immutable price-version ledger.
- `CheckoutIntent` snapshots the selected plan/cadence/currency/amount/price before redirect. `StripeCheckoutGateway` uses subscription Checkout and a stable `checkout-intent-{uuid}` idempotency key. The browser success route is not proof of payment; only signed Stripe webhook processing completes the intent and provisions access.
- `Customer.stripe_customer_id` is unique; `Subscription.stripe_subscription_id` is unique. `Subscription` is a local Stripe mirror with provider IDs, price/cadence, period, cancel-at-period-end flag and confirmed recurring-price snapshots.
- `StripeWebhookController` validates signatures. `StripeWebhookHandler` records unique event IDs in the same DB transaction as event effects, handles selected checkout/subscription/invoice/refund-review events, and retries bounded DB deadlocks. `SubscriptionSync` compares event timestamps/IDs and checks customer/subscription identity. Existing Stripe event rows are processed-event identifiers, not a durable pending inbox with attempts/lease/error state.
- Internal `SubscriptionStatus` mirrors supported Stripe statuses plus local `pending`. Only `active` and `trialing` grant service access today; `past_due` and `unpaid` do not. There is no explicit grace business state or full customer-managed lifecycle command path.
- Promotion rules are local and server-validated, Stripe applies the discount; reservations bound redemption races. Catalog prices support monthly and annual intervals and multiple currencies/markets.
- Billing identity is immutable/versioned. `BillingInvoice`, invoice intents, event history, tax review and issuance attempts exist. The Számlázz submission workflow is single-submit: an ambiguous result goes to reconciliation by external reference and is not blindly resubmitted. The invoice pipeline has environment/credential/tax-review gates; current configuration defaults to dry run and issuance off.
- Customer subscription/invoice read views and Owner pricing/coupon/invoice/tax screens exist. Customer management is explicitly disabled/placeholder; a refund review marker exists, not an automated refund/correction flow.
- Lifecycle mailables exist for activation, cancellation, payment confirmation and payment failure and are queued. M10 provides provider-mail health and alerting; it is a transport signal, not a billing-state authority.
- `JobQueue` checks subscription service eligibility both at enqueue and delivery. Entitlement decisions are not yet expressed through a generalized feature-key/limit evaluator.
- Scheduler uses UTC-compatible Laravel scheduling conventions, `withoutOverlapping()` and `onOneServer()`. Existing schedules include platform/customer backup and internal plans; no subscription reconciliation/dunning schedule was found in the reviewed console file.
- Tests cover checkout, webhooks/lifecycle, promotions, price isolation, concurrency on independent MySQL processes, invoice-intent and invoice-issuance concurrency, and Stripe test-mode E2E. `phpunit.xml` forces Stripe keys/price IDs empty in tests.

## Proposed authority boundaries

| Concern | Authority | Local representation |
|---|---|---|
| Published offer and new-sale price | WP CareGrid versioned catalog | Immutable catalog/price version, referenced by new checkout |
| Payment collection, subscription billing period, provider subscription status, paid invoice facts | Stripe, verified account + mode | Reconciled local mirror; never inferred from redirect |
| Customer-facing service state and grace policy | WP CareGrid policy | Derived state plus persisted grace deadlines/decision evidence |
| Feature and numeric limits | WP CareGrid entitlement evaluator | Derived from plan version + lifecycle policy, with explainable snapshot/cache version |
| Legal invoice issuance and accounting disposition | Approved Számlázz configuration and accountant-approved rules | Immutable invoice intent/snapshot/attempt and provider invoice reference |
| Email delivery | Queued notification outbox/provider | Independent delivery state; never changes financial entitlement |
| Displayed current state | Local projection after verified provider event/reconciliation | UI read model; visibly stale/degraded if sync is stale |

Stripe must not become the public pricing catalog or direct entitlement API. Customer profile edits create new billing identity snapshots; issued invoice snapshots remain unchanged. Internal plans (such as PROBONO) remain a distinct non-Stripe path.

## Target lifecycle design

Keep Stripe's status as `provider_status`; map it through an explicit transition policy to an internal lifecycle state, then evaluate entitlements. Keep separate `cancel_at_period_end`, `grace_until`, pending plan change and reconciliation timestamps. A transition is a transaction: lock subscription/operation row, compare provider event order/version, persist provider facts and internal transition/audit, create idempotent outbox records, commit; workers deliver invoice/email side effects after commit. Do not call Stripe or Számlázz while holding a DB row lock.

Use the existing CheckoutIntent and verified Stripe webhook path. Extend webhook durability with a unique receipt/inbox state machine, not a second parallel webhook mechanism. Reconciliation reads canonical provider objects and invokes the same idempotent projection service. UI success redirects poll the local intent and remain pending until authoritative webhook/reconciliation evidence.

## Pricing and plan versioning

Published catalog edits must create immutable versions. Each version records product/tier/interval/market/currency, minor-unit amount, Stripe Price ID, annual discount basis, features/limits, effective time, author and verification evidence. Existing subscriptions retain their Stripe Price ID and a price/catalog snapshot; new checkout selects only currently saleable versions. Stripe Price IDs are immutable references; retire a price from new sales instead of rewriting it. Annual discount copy (“two months free”) must be computed from the approved annual/monthly comparison for the same market and currency and must not imply a tax treatment.

## Migration principles

Additive, expand/backfill/contract. First add nullable lifecycle/version/operation/inbox/outbox fields and indexes; deploy code that reads both legacy and new forms; backfill only when facts are derivable from existing snapshots/provider IDs; reconcile Stripe test/live mode and all local subscriptions before declaring rows clean; retain `unknown_needs_reconciliation` for unsupported history instead of fabricating dates/amounts; then make constraints required only after a measured clean backfill. Never reset customer, subscription, invoice or event data. Every backfill is resumable, bounded, observable and dry-runnable; rollback must preserve already-issued financial records.

## Recommended initial policy (requires Owner approval)

- Monthly and annual plans, with explicit versioned Price IDs; annual is prepaid for the year. Publish annual savings only where the catalog confirms it.
- Upgrade takes effect after Stripe confirms payment/updated subscription; no expanded access on an unpaid invoice. Default invoice behavior/proration must be selected before implementation.
- Downgrade is scheduled for next renewal; no mid-period feature/data deletion. Over-limit websites remain visible and operational, but adding new websites/paid expansion is blocked with a clear remediation path.
- Cancellation defaults to period end; access remains through paid-through time. Reactivation before period end cancels pending cancellation after Stripe confirms. No website deletion or disconnect due to cancellation/payment failure.
- Payment failure: proposed 7-day grace from first failed renewal, keep read access, heartbeat/monitoring and security alerts, continue at most already-scheduled backups subject to storage policy; block new paid expansion/updates after grace. Emergency restore remains Owner-reviewed and never becomes unavailable solely from a billing delinquency without a separate safety policy. No destructive site action.
- Full/partial refunds and invoice correction/storno remain manual Owner/accountant-controlled in M11. No automatic refund or tax correction.
- Customer subscription management: custom UI backed by narrow server commands; Billing Portal may be used only for payment method management initially, not plan/cancel actions, to avoid dual control surfaces.
- Require explicit Owner/accountant signoff on tax/Számlázz issuance rules before live automatic invoice issuance.

## Dependencies and operating boundaries

Stripe Checkout/Billing, signed webhook endpoint and test-mode credentials; database unique constraints/transactions; queue and scheduler; cache locks; M10 `provider.mail` monitoring; Service Credentials; immutable billing identity and seller/tax profile snapshots; Számlázz Agent credentials and permitted environment gates; customer language; audit system; platform DR/APP_KEY recovery. If any external provider is unavailable, preserve a pending/reconciliation-required state; do not invent success or duplicate charge/invoice.

## Definition of ready

Execution starts only after a clean checkout at current primary, fresh billing-source review, Owner decisions in `owner-decisions.md`, accountant-approved invoice/tax boundary, Stripe test/live environment map, and a specific migration/backfill plan. M11 is not implemented or closed by this document.
