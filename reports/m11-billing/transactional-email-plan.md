# Transactional lifecycle email plan

## Existing components

Queued mailables currently include activation, cancellation, payment confirmation and payment failure; locale-specific email templates exist. M10 verifies the mail provider and surfaces failures. Email dispatch is already intended to be after-commit in Stripe lifecycle code. M11 should reuse Laravel mail and M10 health, and add an idempotent billing notification outbox so webhook retries cannot duplicate mail.

## Recommended event catalog

| Event | Recipient/timing | Content/state guard |
|---|---|---|
| Subscription activated | Customer after signed provider confirmation | Plan/cadence/paid-through; no card data |
| Payment received | Customer after unique paid invoice event | Amount/currency/date and invoice link; suppress zero-value invoice receipt |
| Invoice ready | Customer only after provider invoice issued and linked | Secure authenticated download/reference; never email sensitive PDF attachment by default |
| Payment failed | Customer after unique failure transition | Recovery action, next attempt/period facts only if provider confirmed |
| Grace warning | Customer at fixed approved offsets from grace deadline | Exact service limits and date in localized timezone |
| Recovered | Customer once after verified payment recovery | Confirm restored status and entitlement |
| Upgrade applied | Customer after provider-confirmed price/state | New cadence/amount/effective time from provider snapshot |
| Downgrade scheduled | Customer after confirmed schedule | Effective date and no immediate data removal |
| Cancellation scheduled/reactivated/ended | Customer after provider-confirmed transition | Clear last paid-through date and operational retention note |
| Billing profile issue | Customer only for actionable validated correction | No tax/legal claim beyond approved copy |

## Delivery model

In the same DB transaction as the domain transition, insert unique notification record keyed by aggregate ID + transition/event ID + template/version + recipient role. Dispatch after commit. The worker claims with lease, renders HU/EN from customer locale, redacts provider details, attempts bounded retry for transient mail failures and records state/error category. Permanent address/config/auth failures stop hot retry and raise M10 `billing.mail_failed` / `provider.mail` correlation. Mail failure cannot roll back a Stripe payment, subscription or invoice status.

Suppress duplicate notices from webhook/reconciliation processing through the unique outbox key. Content correction requires a new template version/event policy, not replaying an already-sent email. Avoid leaking full invoice/tax identity, payment method data or raw provider response. Store recipient snapshot only according to approved M12 retention/minimization policy.

## Localization and time

Use `lang/hu` and `lang/en`; fall back to account locale, then configured default. Store all event/effective timestamps UTC; render dates with explicit timezone and exact dates (“service continues through …”) instead of ambiguous “today”. Use customer-facing internal status copy, never raw Stripe enums.

## Tests

For every template assert locale, escaping, correct provider-confirmed amounts/dates, no secrets, unique outbox on duplicate events, queue retry independent of billing transaction, mail outage does not alter subscription, permanent failure stops bounded retry and produces monitoring evidence, and recovery event sends exactly once. Staging validates at least activation/payment/invoice email using Stripe test mode and a mail sink/test recipient before any approved controlled mailbox test.
