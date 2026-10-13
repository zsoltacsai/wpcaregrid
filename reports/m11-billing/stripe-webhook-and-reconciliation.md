# Stripe checkout, webhook inbox and reconciliation

## Existing path

`StartCheckout` resolves price server-side into `CheckoutIntent`; `StripeCheckoutGateway` creates a subscription-mode Checkout Session with idempotency key `checkout-intent-{uuid}`. `StripeWebhookController` validates Stripe signature. `StripeWebhookHandler` currently applies a selected event set inside one DB transaction and inserts unique `stripe_events` receipt with effects. Checkout completes/provisions only on verified paid/no-payment-required event; delayed payment stays pending. `SubscriptionSync` rejects older events/identity conflicts. This is a useful base and should be evolved, not replaced.

## Authoritative event set for M11 v1

Required: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `customer.subscription.created`, `.updated`, `.deleted`, `invoice.paid`, `invoice.payment_failed`, and `invoice.finalized` if needed to maintain invoice projection. Keep `charge.refunded` as a review marker only while refund handling is manual. Add `customer.updated` only if it is the selected source for syncing Stripe customer contact; never make email identity. Ignore unknown event types after recording a safe audit receipt/metric and acknowledge them.

## Durable inbox design

1. Verify signature against the environment-specific webhook secret and expected Stripe mode/account.
2. In short DB transaction, insert unique event ID, type, object ID, provider-created time, received time, mode/account, payload schema version, and status `pending`; retain only encrypted/minimized payload needed for deterministic processing, with a separately approved retention rule. A unique collision acknowledges the duplicate.
3. Dispatch process job after commit. A scheduler drains pending receipts so a lost queue dispatch cannot lose an event. Use atomic claim/lease, bounded attempt count, exponential jitter/backoff and poison state requiring review.
4. Handler locks receipt and affected aggregate; applies event + state projection + audit + outbox rows in transaction; marks applied atomically. External Stripe/Számlázz/mail calls happen after commit.
5. Failed transient DB transaction leaves receipt retryable. Invalid signature is never stored as trusted input. Authentication/config failure blocks processing and raises M10 actionable health signal.

If project prefers synchronous handling, it must retain equivalent durable receipt/retry behavior; acknowledge 2xx only after durable receipt. Do not store raw unencrypted payload indefinitely. Payload minimization/encryption and retention require a privacy review under M12.

## Out-of-order and replay

Keep event ID uniqueness, but do not confuse event-ID idempotency with subscription state ordering. For a stale/ambiguous subscription event, retrieve the current subscription from Stripe outside the DB lock and feed one idempotent projection. Validate Stripe Customer/subscription/Checkout intent relationships and live/test mode before any customer provisioning or access grant. Unknown-price subscriptions without a matching verified intent remain quarantined for reconciliation. Never accept browser price IDs or status.

## Reconciliation

Scheduled bounded batches select local Stripe subscriptions whose `stripe_synced_at` exceeds a configurable stale threshold, plus records with pending/failed inbox receipts; fetch canonical Stripe state using the same environment account; apply using aggregate lock/version check; record diff category and cursor. Add periodic bounded discovery only by known Customer IDs; do not sweep an entire Stripe account and attach unknown subscriptions to users. Provider outage marks `degraded`, retries with backoff, and leaves last-known state with a visible age. Reconciliation never issues invoices or sends email directly; it emits the same idempotent domain transition/outbox entries as webhook processing.

## Outgoing operations and idempotency

Persist operation UUID/command for plan switch, cancel, reactivate, Checkout, and any approved refund. Derive Stripe idempotency key from immutable operation UUID + operation type + version; store request hash and target subscription/price. On timeout/unknown result, retrieve provider state or retry with the same key; never mint a new key for an ambiguous operation. Serialize commands per subscription with DB lock/unique active operation. Do not call Stripe within a DB transaction. Coupon reservation/consumption remains idempotent and one-time.

## Test and environment separation

Unit/feature: valid/invalid signatures, duplicate concurrent receipt, queue dispatch loss, out-of-order same-second events, delayed checkout, poison failure, stale reconciliation, provider outage, identity mismatch, test/live mode mismatch, retry bound and command timeout. MySQL independent-process races required for receipt, subscription command and invoice dispatch. Stripe CLI/test mode plus signed fixtures; test clocks only for lifecycle time acceleration after confirming feature support. No live Stripe IDs in staging/test; test suite already forces keys/price IDs empty. Staging E2E must use dedicated test-mode endpoint/secrets and synthetic test customers, never real money.
