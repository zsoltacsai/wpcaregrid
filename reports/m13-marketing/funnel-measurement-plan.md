# M13.2 Funnel Measurement

## Client marketing sequence

1. Canonical public page view: `page_view` with page family, locale, and query-free canonical path.
2. Pricing page exposure: one `pricing_view` per page load.
3. Plan CTA intent: `plan_selected` with product type, tier, and current billing interval. This records selection intent only; it does not claim that server checkout validation succeeded.
4. Deliberate interval changes: `billing_cycle_selected`; initial default selection emits nothing.
5. Checkout: no client event is emitted for checkout creation or Stripe redirect. Current checkout POST is server-validated and redirects directly to Stripe, while the only implemented external adapter is client-side and there is no approved consent/anonymous-state linkage to authoritative server outcomes. Do not infer success from a click or return page.
6. Paid completion and subscription activation: authoritative webhook/server lifecycle events stay internal.
7. Pairing, connected sites, and first verified backups: remain internal product/operations signals; do not export identity, site, pairing, backup, or security data to GA4.

## Attribution and reporting

- M13.2 does not persist UTMs, click IDs, arbitrary query strings, or referrers. Attribution persistence is deferred to qualified privacy/legal review.
- No cross-domain Stripe attribution or identity stitching is implemented.
- If external setup and policy review later authorize collection, start with GA4 standard reports. Search Console covers search performance. Do not build a custom warehouse or Owner analytics dashboard without demonstrated need.
- Keep marketing intent, authoritative billing conversion, product activation, and operational health as separate measures.

## Acceptance evidence

- Fake-sink contract tests verify allowlisted event names/parameters, consent gating, page-view deduplication, pricing event order, and withdrawal.
- Laravel tests verify that the eligible host/configuration is required to render an enabled context, while staging/invalid configuration remain disabled.
- Checkout code, payment truth, Stripe webhook handling, and subscription lifecycle are not modified by analytics instrumentation.
- A future server-confirmed `checkout_started`/`checkout_redirected` export requires a reviewed architecture for consent continuity and deduplication before implementation.
