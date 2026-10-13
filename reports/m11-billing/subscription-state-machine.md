# Subscription state machine

## Three independent state dimensions

1. **Provider state**: exact status/period/cancel fields last confirmed by Stripe; nullable/unknown when not yet received.
2. **Business lifecycle**: CareGrid's normalized contract state, including pending, active, grace, suspended, canceled-at-period-end, ended and reconciliation-required. Do not mutate provider facts to fit policy.
3. **Entitlement decision**: per feature, `allow`, `allow_until`, `read_only`, or `deny`, with policy/catalog version and reason. Never derive it from a browser-supplied plan name.

`cancel_at_period_end` is a scheduled action flag, not a terminal status. `grace` is a local policy state with a deadline; Stripe remains `past_due`. `suspended` is an operational restriction, not deletion or disconnection.

## Transition table (recommended policy; grace and change timing need Owner approval)

| Current | Evidence/event | Next business state | Entitlement behavior |
|---|---|---|---|
| none | Checkout intent created | pending_checkout | No paid entitlement |
| pending_checkout | signed checkout complete but payment pending | payment_pending | No paid entitlement |
| pending_checkout/payment_pending | verified provider subscription active/trialing + required payment evidence | active/trialing | Catalog features active |
| pending_checkout | Checkout expired/failed | incomplete/expired | None; release promotion reservation idempotently |
| active/trialing | verified cancel-at-period-end update | cancel_scheduled | Continue through paid period; record exact end |
| cancel_scheduled | verified cancellation cleared before period end | active/trialing | Continue; audit reactivation |
| active/trialing | invoice.payment_failed / provider past_due | grace_pending or grace | Apply approved limited grace policy; deadline fixed from first qualifying failure, not extended by duplicate events |
| grace | verified invoice.paid + provider state recovered | active/trialing | Restore allowed features; clear failure window |
| grace | grace deadline passes and provider remains delinquent/unpaid | restricted/suspended | Reversible restrictions only; no WP data/site deletion |
| restricted | verified payment recovery | active | Recompute entitlements; preserve prior audit |
| active/cancel_scheduled | provider canceled at period end | ended | Remove paid entitlements, retain account/invoice access per policy |
| any | unknown status, identity mismatch, mode mismatch, conflicting provider snapshot | reconciliation_required | Fail closed for privilege increases; preserve safe access only as already-granted contract requires; alert Owner |
| any | refund event | unchanged + refund_review | Do not infer cancellation or issue correction invoice automatically |

## Ordering and race policy

Persist Stripe event `created`, provider object `updated`/period timestamps where available, event ID, provider/account/mode identity, and local projection version. A monotonic provider snapshot wins over an older event. Equal-time conflicts are resolved by retrieving the current Stripe object; event ID lexical ordering is not a business ordering guarantee. A cancellation is never resurrected by an older active event. Reconciliation and webhook processing lock the same subscription row and use one projection method. Provider fetch occurs outside the lock; apply only if the fetched object/version is not older than the already committed projection. An event received but not applied remains pending/retryable, not “processed.”

## Allowed and forbidden transitions

- Access increases only with verified provider evidence and an approved local policy transition.
- A checkout redirect alone cannot move pending to active.
- Payment failure alone does not cancel, delete a website, remove data, or create invoices.
- Local Owner edits cannot directly set `active`, `paid`, plan, billing period or provider IDs. Owner actions create a command/operation and call Stripe with idempotency; read model updates only from provider confirmation/reconciliation.
- A stale event cannot revert a terminal provider state. `canceled` can change only through a later explicit new provider subscription identity, not by replaying an old event.
- Internal comped plans remain governed by their own audited internal-plan service and do not masquerade as Stripe subscriptions.

## Entitlement evaluator

Input tuple: subscription business state, current period/grace end, catalog version, internal-plan exception, website usage and any explicit legacy grandfather rule. Output: feature key, typed value/limit, status, reason, effective-from/until, source version. Cache keys include subscription/catalog projection version; updates invalidate only after transaction commit. Every enforcement endpoint re-evaluates authorization server-side.

## Observability/audit

Transitions persist from/to, cause class, provider event/reference, policy version, actor (if a user command), effective timestamp UTC and correlation/operation ID. Avoid card data, auth headers, full provider payloads and customer tax identifiers in audit. Unknown/conflicting transitions emit `billing.subscription_inconsistent`; stale event inbox emits `billing.webhook_stale`.
