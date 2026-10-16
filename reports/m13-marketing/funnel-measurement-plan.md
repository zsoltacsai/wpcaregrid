# Funnel Measurement Plan

## Funnel

1. Landing page (`page_view`, segmented only by locale and page family)
2. Pricing page / plan view (`pricing_view`)
3. Plan and billing interval selection (`plan_selected`, optional `billing_cycle_selected`)
4. Checkout started (`checkout_started`, after server accepts/creates the intent)
5. Redirect to Stripe (`checkout_redirected`)
6. Checkout completed (`checkout_completed`, authoritative Stripe webhook only)
7. Subscription activated (`subscription_activated`, authoritative local lifecycle state)
8. Product activation: pairing, connected site, first verified backup (prefer internal aggregate operations reporting, not GA4)

There is no public self-registration route; the lifecycle starts through purchase and account activation. Do not emit `registration_started` unless product flow changes.

## Attribution handling

- Preserve a bounded allowlist of campaign parameters already accepted by legacy redirects (`utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, click IDs) only after privacy/legal review.
- Do not persist arbitrary query strings or pass campaign values into canonical URLs.
- Avoid carrying attribution into Stripe metadata unless reviewed, minimal and necessary. Do not include PII or custom text.
- Browser Stripe return is not payment confirmation. Server webhook idempotency and subscription state remain authoritative; analytics must never write into billing state.
- Cross-domain attribution is not needed until a measurement property and desired user journey are selected. If later used, configure/test with Stripe domains without exposing session identifiers.

## Reporting

Start with GA4 standard reports/explorations if approved and sufficient. Do not build a custom warehouse or Owner analytics dashboard without demonstrated need. A simple periodic report can show landing→pricing→checkout→activation by locale/product category, with minimum sample/identity protection. Search Console covers search visibility; GA4 is not a substitute for product operations monitoring.

## Acceptance checks

- event sequence corresponds to the actual server state transitions;
- webhook duplicate/replay cannot duplicate counted conversion;
- no event includes payment/session/account/site identifier;
- staging/non-production never contaminates the launch property;
- consent gate prevents optional collection until approved state;
- no pageview duplicates on form validation/redirect/back navigation;
- dashboard/reporting definitions distinguish marketing conversions from activation and operational metrics.
