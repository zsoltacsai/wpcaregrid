# Coupons (owner-managed)

Discount codes for the subscription checkout, created and maintained in the owner area (**Owner → Coupons**, ability `owner.coupons.manage`). There is **one coupon engine**: the `promotions` table and `Promotion::ineligibilityReason()`. The owner area creates and maintains those rows and matching Stripe objects; checkout reserves a usage slot transactionally, and signed Stripe webhooks consume or release it.

## What a coupon is

| Field | Rule |
| --- | --- |
| Code | 3–32 letters, digits, `-` or `_`; stored upper-case, matched case-insensitively; unique. **Fixed once created.** |
| Discount | A percentage typed by the owner: above 0 and up to **100**, at most two decimals (`10`, `12.5`, `100`). Parsed from digits, never as a float (`Promotion::parsePercent`). Stored as `decimal(5,2)`. **Fixed once created.** |
| Duration | New coupons use `once` (first payment) or `forever` (every payment). Existing Stripe `repeating` subscriptions remain readable. Default `once`. **Fixed once created.** |
| Valid from / until | Optional. Checked on our side at checkout. |
| Maximum total uses | Optional. Includes open checkout reservations; consumed once when Stripe confirms payment. Cannot be set below used plus reserved uses. |
| Maximum uses per customer | Optional. A customer account or an email address; counted from completed checkouts. |
| Restrictions | Optional market (All, Hungary/HUF, International), product type, plan and billing period. Empty restrictions allow everything. |
| Internal note | Owner only. Never shown to customers, never sent to Stripe, never in the audit log. |
| Active | Switching it also switches the Stripe promotion code. |

0% is refused: Stripe cannot create a zero-percent coupon and it would change nothing. Values above 100, negative values, more than two decimals and non-numbers are refused before anything is sent to Stripe.

**Why code, percentage and duration are fixed.** A Stripe coupon cannot be changed after it is created. Everything else (validity, limits, restrictions, note, active) is a rule we check before creating a Checkout Session, so it can be edited at any time. To change a fixed value, create a new coupon; an unused coupon can be deleted first.

## Owner actions

- **Create**: validates, creates the Stripe coupon and promotion code (`StripePromotionGateway`), then the local row. If the row cannot be saved afterwards, the Stripe objects are removed again. If Stripe fails, nothing is created locally.
- **Edit rules**: validity, limits, restrictions, note. Audited by field name.
- **Activate / deactivate**: Stripe first, then locally; if Stripe fails, nothing changes. A deactivated coupon is refused by checkout (`inactive`).
- **Delete**: only a coupon with no checkout or reservation history. One with history can only be deactivated.
- The list and the detail page show usage count, remaining uses after reservations, created / updated times and the latest checkouts.

Audit events (`admin_audit_events`): `coupon.created`, `coupon.updated`, `coupon.activated`, `coupon.deactivated`, `coupon.deleted`, and `coupon.redeemed` (written by the webhook when a checkout completes; system actor, intent UUID only). No secret, no value of the note, no Stripe key is ever logged. Creating, activating, deactivating and deleting use the `owner-coupons` rate limit (10/minute, 60/hour per owner) on top of `owner-sensitive`.

## Checkout rules (unchanged)

The browser never chooses the amount, the currency, the Stripe Price ID or the discount. The server resolves billing country → market → catalogue price, validates the code (`Promotion::ineligibilityReason`, plus the per-customer limit), and only then passes `discounts[promotion_code]` to Stripe. Codes are accepted only through our form; Stripe's own code field stays off.

## Currency and stacking policy

- The owner interface creates **percentage coupons only**. A percentage is currency-agnostic; Stripe applies it to the configured recurring Price selected for the customer's currency, including the configured annual Price. We do not calculate a second discount total locally.
- Stripe fixed-amount coupons are currency-specific. The local eligibility check rejects a fixed coupon unless its currency exactly matches the selected Price. The owner interface does not create or edit fixed-amount coupons; any future fixed-coupon workflow must create a separate Stripe coupon for each supported currency. No FX conversion is performed.
- Annual `2 months free` pricing is already built into the annual catalogue Price (10 × monthly). A coupon applies to that annual Price, not to 12 months of monthly pricing.
- Checkout accepts at most one promotion code. Recovery Offers are separate one-time payments and do not accept subscription coupons. Recovery Offers remain limited to the HU billing market and HUF; they cannot be combined with coupons. PROBONO is a separate Owner-assigned internal plan without a Stripe subscription, public price or checkout; it is not a 100% coupon.
- Percentage codes may be restricted by market, product type, plan, interval, dates and usage. The Owner form offers only All, Hungary/HUF, or International; existing custom legacy market restrictions remain preserved and cannot be replaced with arbitrary input.

New repeating-duration coupons are disabled because Stripe has deprecated that duration for newly created coupons. Existing repeating coupons and subscriptions continue to synchronize from Stripe. No Stripe object is deleted or changed by this compatibility policy.

The application reserves a usage slot at checkout creation under a lock on the promotion row. This serializes global and per-customer limits in the production database. It consumes the reservation only after a signed success webhook, and releases it after checkout failure or expiry. Expired reservations are ignored immediately and marked released when the next reservation is attempted.

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

- Stripe's own `max_redemptions` is not used because its limits cannot be edited. Local reservations serialize claims for each promotion, including global and per-customer limits.
- Only percentage coupons can be created in the owner area; the model still supports fixed amounts for coupons made another way.
- Reservation locking relies on the production database honoring row locks (MySQL/InnoDB). The feature suite exercises competing claims sequentially; a parallel database stress test remains a useful follow-up before live checkout acceptance.
