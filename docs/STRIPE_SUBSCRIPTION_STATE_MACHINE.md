# Stripe subscription state machine: immediate (paid) plan changes

Scope: an **upgrade** or a **billing-period switch** that is charged now (`SubscriptionManagement::changePlan`, operations `upgrade` and `billing_period_switch`), and how Stripe events, reconciliation and the customer's retries settle it. A downgrade is a Stripe schedule and is settled by `SubscriptionSync` / `ReconcileStripeSubscriptions::hasConfirmedPendingSchedule`; cancel/reactivate are in `SubscriptionManagement::changeCancellation`. Neither is changed by this document.

Tests: `tests/Feature/SubscriptionUpgradeStateMachineTest.php` (this machine), `SubscriptionManagementTest`, `SubscriptionLifecycleTest`, `StripeSubscriptionReconciliationTest`, `StripeSubscriptionConcurrencyTest` (MySQL).

## Invariants

1. **A plan change that is charged never grants the higher plan before an authentic paid invoice for exactly the pending target price.** Authentic = a signed Stripe webhook, or an object the reconciliation job pulled from the Stripe API. A subscription event that merely shows the new price is not that proof (`SubscriptionSync` keeps the paid plan while the change is `awaiting_payment` **or** `reconciliation_required`).
2. **A paid change is never lost.** A paid invoice settles the change in every non-final state, including when the Stripe response to the request was lost and when a failed attempt is paid later inside Stripe's pending-update window. Outside that window the payment is not an upgrade at Stripe either, so it is not one here.
3. **A failed or expired payment never grants the plan and never blocks the account.** The subscription keeps the plan it has paid for, starts no payment grace (it is not unpaid), and the customer can ask again.
4. **An unknown outcome is neither paid nor failed.** It is replayed to Stripe with the same idempotency key, a bounded number of times; after that it waits for an operator and is surfaced. It is closed automatically only on Stripe's own evidence (below).
5. **Every transition is idempotent.** A repeated or redelivered event, a retry, or a late response changes nothing further. An event older than a confirmed paid change cannot take the plan back.
6. Cancel and reactivate are independent of this machine and are never undone by it. A subscription that is ending (`cancel_at_period_end`) cannot start an upgrade.

## States

Subscription row (`pending_change_status`) for an immediate change, and the operation row (`subscription_operations.status`):

| Subscription pending | Operation | Meaning |
|---|---|---|
| none | none | nothing pending |
| `awaiting_payment` | `requested` → `processing` → `awaiting_confirmation` | Stripe accepted the request (`payment_behavior=pending_if_incomplete`, `always_invoice`); the proration invoice is unpaid |
| `reconciliation_required` | `reconciliation_required` | the Stripe call failed or its response was lost: outcome unknown |
| none (cleared) | `confirmed` | paid and applied (final) |
| none (cleared) | `payment_failed` | the proration invoice failed (closed; a payment inside the 23-hour window still settles it) |
| none (cleared) | `expired` | Stripe's pending-update window passed with nothing applied (closed) |

The operation key of an immediate change has no clock value: the same request repeated while an attempt is open is the same operation (and Stripe idempotency key `subscription-change-{uuid}`); once an earlier identical attempt is `confirmed`, `payment_failed` or `expired`, the same request is a new attempt with a new operation.

## Transitions

| # | Trigger | Precondition | Stripe evidence | Local change | Entitlement | Retry | Audit / log |
|---|---|---|---|---|---|---|---|
| 1 | Customer requests the change | eligible subscription, no pending change, target price resolved on the server | none yet | operation `requested`; pending `awaiting_payment` (set **before** the Stripe call) | unchanged | the same request is idempotent | `billing.subscription_change_requested` |
| 2 | Stripe call returns | claimed operation | request id, proration invoice id | operation `awaiting_confirmation`; invoice id stored | unchanged | — | — |
| 3 | Stripe call fails / response lost | claimed operation | none | operation + pending `reconciliation_required` | unchanged | see 4 | `billing.subscription_change_failed` |
| 4 | Customer retries an unknown outcome | operation `reconciliation_required`, fewer than 3 replays | Stripe returns the original result for the same key, or applies it now | replay counted; on success operation `awaiting_confirmation` and pending back to `awaiting_payment` | unchanged | up to 3 replays | `billing.subscription_change_requested` |
| 5 | Retry after the replay limit | replays exhausted | — | `operator_required_at` set once; no Stripe call; the request is refused with an "operator needed" message | unchanged | **none**, operator settles | `billing.subscription_change_operator_required`; the operation stays `reconciliation_required` and is counted by the billing monitoring (`operations_needing_reconciliation`) |
| 6 | `invoice.paid` (or reconciliation of the latest invoice) | billing reason `subscription_update`; invoice lines contain the pending target price; a recorded invoice id matches; pending is `awaiting_payment` or `reconciliation_required` | signed webhook / API object | plan, interval, price, amount applied from the operation snapshot; pending cleared; operation `confirmed`; provider paid time stored | **higher plan granted here** | idempotent: a second delivery finds nothing pending | `billing.subscription_change_confirmed`; `upgrade_completed` notice (once per invoice) |
| 7 | `invoice.payment_failed` | same matching rule as 6 | signed webhook / API object | operation `payment_failed`; pending cleared; **no grace**, `last_payment_status` untouched | unchanged (paid plan) | the customer may request again (new operation) | `billing.subscription_change_payment_failed`; `upgrade_payment_failed` notice (once per invoice); the generic "Stripe will retry" notice is not sent |
| 8 | Same failure delivered again | a closed (`payment_failed` / `expired`) operation recorded exactly this invoice, also while a newer attempt for the same price is open | — | none: no grace, no notice | unchanged | — | none |
| 9 | Payment of the same invoice after 7, inside Stripe's 23-hour pending window | pending cleared; operation `payment_failed` matches the invoice; provider paid time within 23 h of the request | paid invoice | as 6 | higher plan granted | — | as 6 |
| 9b | Payment of the same invoice after the window, after 10, or while another change is pending | operation `expired`; or paid more than 23 h after the request; or another pending change exists (it is never cleared by this one) | paid invoice | none: paying the invoice after Stripe discarded the pending update does not change the Stripe subscription | unchanged; the plan moves only if Stripe's subscription object shows the new price (rows 11 and 12) | — | `billing.subscription_change_late_payment_unapplied` (once per invoice, with the reason): a real payment an operator must look at |
| 10 | Reconciliation: change gone | pending open; operation older than 25 hours; Stripe shows **no pending update** and the **old price**; a paid latest invoice has already been applied first | API subscription object | operation `expired`; pending cleared | unchanged | new request allowed | `billing.subscription_change_expired` |
| 11 | `customer.subscription.updated` showing the new price | pending `awaiting_payment` or `reconciliation_required` | event only | price/plan/amount fields **deferred**; other fields (period, cancel flag, status) follow the event | unchanged until 6 | — | — |
| 12 | `customer.subscription.updated` older than a confirmed paid change | event time before the provider paid time of a `confirmed` operation, price differs from its target | event only | price/plan/amount fields deferred | the paid plan stays | — | — |
| 13 | Webhook settles the change while a request/replay is in flight (the request then succeeds **or fails**) | operation `confirmed` / `payment_failed` / `expired` | — | neither the late response nor the late failure takes the operation back (`awaiting_confirmation` / `reconciliation_required`); a failed request whose outcome a webhook already settled returns the settled operation | as the webhook decided | — | — |
| 14 | Invoice for a different price or another billing reason | no match | — | none (an ordinary renewal failure is an ordinary payment failure with grace) | unchanged | — | — |

Locks: every writer locks the subscription row first, then the operation row (`lockForUpdate` in a transaction: `changePlan` request, success and failure transactions, confirm, fail, expire, replay counter); `SubscriptionSync` only reads immediate-change operations for its plan decisions (it still settles scheduled-downgrade and cancel/reactivate operations as before). Run on MySQL 8.4 (`StripeSubscriptionConcurrencyTest` and the new suite).

## What is deliberately not done

- An unknown outcome is not closed on a timer alone: only transition 10 (Stripe's own evidence, after the window) closes it without a payment.
- A subscription event is not treated as proof of payment; the paid invoice, or reconciliation of it, is. If the paid invoice event is lost and the latest invoice has moved on, the change stays `awaiting_payment`/`reconciliation_required`; the pending state is shown on the owner customer page and an operation in `reconciliation_required` is counted by the billing monitoring (`operations_needing_reconciliation`), and it is settled by transition 6 on a later reconcile or by an operator. There is no in-app screen to settle an operation yet: an operator uses Stripe's dashboard as evidence and engineering/support acts on the rows (a known gap, P2).
- A paid invoice is matched to its operation by signed source, subscription, billing reason, the target price in its lines and, when the operation recorded one, the invoice id. When the Stripe response was lost no invoice id is recorded, so price and billing reason are the match; a $0 proration (credit) is a legitimate paid invoice, so a positive amount is not required.
- The amount of the paid invoice is not compared to the expected proration (Stripe computes it; the price match and the signed source are the guard). Hardening idea for later.
- Downgrades, cancellation, dispute/refund policy, invoicing (Számlázz) are out of scope.

## Staging / deploy

No migration. Staging validation needs a Stripe **test-mode** run of: upgrade with a declined card, 3DS failure, a lost response (simulated), a late payment, and the reconciliation job (`stripe:subscriptions:reconcile`) with the scheduler. Not yet run on staging.
