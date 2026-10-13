# Customer, Owner and Operator billing UX

## Customer surfaces already present

Account dashboard, subscription list/detail, billing identity/profile, billing page and Stripe invoice history exist. The subscription management action is visibly disabled, and `BillingController` provides account billing read state. Plan selection/checkout is a separate localized flow. These are foundations, not full self-service management.

## Customer UX target

Show current plan/product, monthly/annual cadence, localized currency/amount, paid-through/next renewal in local timezone, provider-confirmed status translated to HU/EN, scheduled plan/cancellation change, recent payment/invoice events, and a clear pending/sync-stale indicator. Provide: update billing identity (new immutable snapshot); update payment method via restricted Stripe Billing Portal or hosted Stripe flow; request upgrade; schedule/cancel downgrade; cancel-at-period-end; reactivate before period end; invoice list/PDF access. Every action is server-authorized against the authenticated Customer/subscription; UUIDs do not replace ownership checks.

**Recommended separation:** custom CareGrid UI owns plan changes, cancel/reactivate and entitlement explanation. Initially allow Stripe Billing Portal only for payment method update (and optionally invoice viewing if the same invoice source is authoritative). Disable portal plan switching/cancellation to prevent divergent policy. If portal cannot be configured narrowly, build a hosted payment method update flow instead. No unrestricted production restore or billing mutation button.

On Checkout return, display pending confirmation and poll local intent boundedly; only verified webhook/reconciliation makes “active.” On provider delay/outage, show “processing” with support path and no duplicate checkout/charge. Grace and restrictions must state exact dates and preserve data/connection. No customer-facing raw Stripe error.

## Owner Console

Single billing customer/subscription view: local business state and age of last Stripe sync; Stripe customer/subscription/price/invoice IDs; provider mode/account identity; plan/catalog version; cadence/period/cancel date; last webhook; pending/failed event count; reconciliation result; payment failure/grace deadline; invoice intent/tax/issuance state; last notification state; safe error category. Mask IDs where role does not need them; never show keys, card data, full webhook payload or unredacted provider errors.

Allowed audited actions: request reconciliation; retry a known-safe pending internal processing step; review/approve a configured invoice policy; issue a single provider submission only under independent gates; schedule a refund review (manual accounting). Forbidden: direct status/amount/entitlement edits, arbitrary Stripe ID assignment, repeated invoice submission, live account switching from UI. Owner commands use CSRF, permission abilities, throttling, confirmation, operation UUID/idempotency, and audit.

## Operator visibility

Operators need only service-operational status (`active`, `grace`, `restricted`, `ended`) and safe next action. No amount, tax ID, payment method, invoice PDF, card/billing contact, or Stripe IDs unless a separately approved support capability exists. “Billing hold” must not expose financial details and must never authorize destructive site work.

## Accessibility and localization

Localize all labels, statuses, validation, notification copy and dates. Use accessible state badges plus explanatory text, not color only. Distinguish “change requested,” “provider pending,” “scheduled for renewal,” “applied,” and “failed/reconciliation required.”
