# Coupons (owner-managed)

Discount codes for the subscription checkout, created and maintained in the owner area (**Owner → Coupons**, ability `owner.coupons.manage`). There is **one coupon engine**: the `promotions` table and `Promotion::ineligibilityReason()`, which `StartCheckout` already used before the owner area could edit them. The owner area only creates and maintains those rows (and the matching Stripe coupon and promotion code). Checkout, redemption counting and the webhook are unchanged and stay authoritative.

## What a coupon is

| Field | Rule |
| --- | --- |
| Code | 3–32 letters, digits, `-` or `_`; stored upper-case, matched case-insensitively; unique. **Fixed once created.** |
| Discount | A percentage typed by the owner: above 0 and up to **100**, at most two decimals (`10`, `12.5`, `100`). Parsed from digits, never as a float (`Promotion::parsePercent`). Stored as `decimal(5,2)`. **Fixed once created.** |
| Duration | `once` (first payment), `repeating` (1–36 months) or `forever` (every payment). Default `once`. **Fixed once created.** |
| Valid from / until | Optional. Checked on our side at checkout. |
| Maximum total uses | Optional. Counted when Stripe confirms the payment (webhook), once per completed checkout. Cannot be set below the uses so far. |
| Maximum uses per customer | Optional. A customer account or an email address; counted from completed checkouts. |
| Restrictions | Optional product types, plans (Basic / Business / Secure) and billing periods. Empty = everything. |
| Internal note | Owner only. Never shown to customers, never sent to Stripe, never in the audit log. |
| Active | Switching it also switches the Stripe promotion code. |

0% is refused: Stripe cannot create a zero-percent coupon and it would change nothing. Values above 100, negative values, more than two decimals and non-numbers are refused before anything is sent to Stripe.

**Why code, percentage and duration are fixed.** A Stripe coupon cannot be changed after it is created. Everything else (validity, limits, restrictions, note, active) is a rule we check before creating a Checkout Session, so it can be edited at any time. To change a fixed value, create a new coupon; an unused coupon can be deleted first.

## Owner actions

- **Create**: validates, creates the Stripe coupon and promotion code (`StripePromotionGateway`), then the local row. If the row cannot be saved afterwards, the Stripe objects are removed again. If Stripe fails, nothing is created locally.
- **Edit rules**: validity, limits, restrictions, note. Audited by field name.
- **Activate / deactivate**: Stripe first, then locally; if Stripe fails, nothing changes. A deactivated coupon is refused by checkout (`inactive`).
- **Delete**: only a coupon nobody has used (no checkout intent references it). One with history can only be deactivated.
- The list and the detail page show usage count, remaining uses, created / updated times and the 10 latest checkouts.

Audit events (`admin_audit_events`): `coupon.created`, `coupon.updated`, `coupon.activated`, `coupon.deactivated`, `coupon.deleted`, and `coupon.redeemed` (written by the webhook when a checkout completes; system actor, intent UUID only). No secret, no value of the note, no Stripe key is ever logged. Creating, activating, deactivating and deleting use the `owner-coupons` rate limit (10/minute, 60/hour per owner) on top of `owner-sensitive`.

## Checkout rules (unchanged)

The browser never chooses the amount, the currency, the Stripe Price ID or the discount. The server resolves billing country → market → catalogue price, validates the code (`Promotion::ineligibilityReason`, plus the per-customer limit), and only then passes `discounts[promotion_code]` to Stripe. Codes are accepted only through our form; Stripe's own code field stays off.

## 100% discount (zero-charge checkout)

A 100% coupon is an ordinary coupon, not a special code. Verified against real Stripe in test mode:

| Coupon | Stripe total today | Payment method asked for |
| --- | --- | --- |
| 10%, once | 17,091 Ft (90% of 18,990) | yes |
| 12.5%, 3 months | 16,616.25 → 1,661,625 minor units | yes |
| 100%, once or for some months | 0 | **yes**: the subscription is charged afterwards |
| 100%, **forever** | 0 | **no** (`payment_method_collection = if_required`) |

Only `percent = 100` **and** `duration = forever` makes every invoice zero (`Promotion::makesEveryInvoiceFree()`); only then does the Checkout Session skip the payment method, and Stripe's page shows "Start for free", the email, "Total due today HUF 0.00" and a "Pay and subscribe" button. Everything else about the session is unchanged.

After the customer completes it, Stripe reports `payment_status = no_payment_required`. The webhook already treated that as paid (`StripeWebhookHandler`), so the flow is the normal one: signed `checkout.session.completed` → customer, login and subscription created **once** (event IDs are recorded, replays and the `async_payment_succeeded` sibling change nothing, `times_redeemed` counts once) → `customer.subscription.created` (active) → `invoice.paid` with a $0 invoice. A zero invoice sends **no** "payment received" email (`InvoiceEvents::paid`). The browser reaching the success page provisions nothing. No amount is ever negative: the intent keeps the list price and Stripe applies the discount.

A 100% coupon that applies "for ever" gives the plan away permanently; for a free plan that is not sold, use PROBONO instead ([PROBONO.md](PROBONO.md)).

## Tests

`CouponManagementTest` (18), `ZeroChargeCheckoutTest` (5); real Stripe test mode: `tests/e2e/owner-commercial.spec.ts` (see STRIPE_TEST_MODE.md).

## Limits

- Usage limits are enforced by us and counted at confirmation, as before: two checkouts started before the first completes can both pass a limit of 1 (Stripe's own `max_redemptions` is not used, because it cannot be edited).
- Only percentage coupons can be created in the owner area; the model still supports fixed amounts for coupons made another way.
