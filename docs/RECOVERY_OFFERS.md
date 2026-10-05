# Recovery offers (custom one-off offers)

A separate commercial offer for security recovery work after a compromise: **not** Basic / Business / Secure, **not** a subscription. The owner writes exactly what is included and the price; the customer accepts and pays once on Stripe.

An offer describes work to be done. It is not a security finding and never claims that a website is infected; findings live in the security tools.

## Entity

`recovery_offers` (model `RecoveryOffer`, UUID in URLs): exactly one customer and exactly one **website of that customer** (checked on the server when creating and when editing a draft; the browser's IDs are never trusted), title, scope (free text written by the owner), internal notes (owner only), price in minor units, currency, validity, status and the timestamps of each step, the Stripe Checkout Session and the payment reference, and two "notified" timestamps so each email is sent once.

**Price and currency.** The owner types the price in the main unit (`45000`); the server converts it (`PriceEditor::minor`) and stores it. That stored value is the only amount ever charged. The currency must be one the catalogue sells in (`PricingCatalog::sellableCurrencies()`): **today HUF only**. No conversion, no exchange rates, no invented currencies. Validity is at most 180 days ahead.

## States

```
draft ──send──▶ offered ──accept──▶ accepted ──webhook──▶ paid ──▶ completed
  │                │  │                 │
  │                │  └─decline─▶ declined
  └──cancel──▶ cancelled ◀─────────────┘     offered/accepted ──validity passes──▶ expired
```

- Only the **signed Stripe webhook** moves an offer to `paid`. Reaching the success page changes nothing (the page says "waiting for Stripe's confirmation").
- A draft and an offered offer can be edited by the owner (the customer sees changes of an offered one at once). **An accepted offer is frozen.**
- An open offer expires at its validity: shown as expired immediately, recorded by the hourly `offers:expire`, which also stops an unpaid Checkout Session.
- Cancelling stops an unpaid Checkout Session. Money that still arrives (a payment already in flight) is recorded as paid; it is never lost from view.
- The owner marks a paid offer `completed` when the work is done.

## Payment

`OfferPaymentGateway` / `StripeOfferPaymentGateway`: a one-time Checkout Session, `mode = payment`, one inline line item (`price_data` with the stored amount and currency, the offer title as product name). No Stripe Price object exists for an offer and none of the catalogue prices can be used; no coupons. `client_reference_id` and metadata name the offer. The session expires with the offer's validity (Stripe: 30 minutes to 24 hours). The idempotency key includes the session being replaced, so a retry never creates a second session, and a second click reuses the open one.

Webhook (`checkout.session.completed` and `async_payment_succeeded`, same endpoint and events as subscriptions): the event is routed by `metadata.kind = recovery_offer` to `RecoveryOffers::markPaid`, which requires the session to be the one saved on the offer, `payment_status = paid`, and Stripe's `amount_total` and currency to equal the stored price; anything else is logged and changes nothing. Event IDs are recorded once; a replay, a second event for the same session, or `async_payment_succeeded` after `completed` changes nothing and sends nothing twice. A payment for an offer provisions no customer and no subscription.

## Customer portal

**Offers** (menu entry only for a customer who has been sent one, plus a notice on the dashboard): the list, and a page per offer with the website, the scope, the price ("one-time payment"), the validity and the status, and the actions that fit the state: *Accept and pay*, *Decline*, *Continue to payment*. The customer cannot change the price, currency, scope or website (no such form field exists), cannot see internal notes, drafts or other customers' offers (`RecoveryOfferPolicy`: own offers, never a draft; anything else is 403). English and Hungarian (the language saved on the customer's login).

## Emails

`RecoveryOfferSent` (when the owner sends) and `RecoveryOfferPaid` (when payment is confirmed) are sent **once** each, queued after commit, in the customer's saved language (`Customer::mailLocale()`: `users.locale`, else the site default). The offer email carries title, website, price and validity and a link to the signed-in portal; it contains no scope, no notes and no payment link. These are the first localized customer emails; the older ones (activation, welcome, payment, subscription) are still English (LOCALIZATION.md).

## Owner area

**Recovery offers** (ability `owner.offers.manage`): list with status filter, create (customer → website → title, scope, price, currency, validity, internal notes), detail with timeline, send (with a confirmation), edit while draft or offered, cancel, mark completed. A "New offer" button sits on each customer page.

Audit: `offer.created`, `offer.updated` (field names), `offer.sent`, `offer.accepted`, `offer.declined`, `offer.paid` (system actor), `offer.cancelled`, `offer.expired` (system), `offer.completed`. Identifiers only; never the scope, the notes or any secret.

## Tests

`RecoveryOffersTest` (30): ownership and cross-tenant, validation, states, emails, the stored price, the webhook (replay, mismatches, late money), expiry, authorization, CSRF, rate limits. Real Stripe test mode: `owner-commercial.spec.ts` (English and Hungarian end to end).

## Limits

- One currency (HUF) because nothing else has prices.
- No invoice is created for an offer: the invoice provider is a separate milestone (CLAUDE.md §23).
- No refund or partial payment flow; refunds are done in Stripe.
