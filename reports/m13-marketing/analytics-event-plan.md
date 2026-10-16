# M13.2 Analytics Event Contract

The client analytics boundary is implemented by `MarketingAnalytics`, `validateMarketingEvent`, and `GoogleAnalyticsTransport`. Collection requires all of: `MARKETING_ANALYTICS_ENABLED=true`, the separately reviewed `MARKETING_ANALYTICS_POLICY_AUTHORIZED=true` gate, valid GA4 measurement ID, exact production host, and a current-version explicit analytics grant. Unknown events, parameters, enums, URLs, and query-bearing page context are rejected. Tests use an in-memory fake transport and never contact Google.

| Event | Trigger | Source | Permitted parameters | Conversion class | Consent / environment | PII review | M13.2 disposition |
|---|---|---|---|---|---|---|---|
| `page_view` | One approved canonical marketing page view per page load after grant | Client | `page_family`, `locale`, `canonical_path` | None | Explicit analytics grant; production-only | Path restricted to the four current public pages; no query or referrer | IMPLEMENTED |
| `pricing_view` | Once when the pricing page initializes after grant | Client | `locale` | Micro conversion | Explicit grant; production-only | No identity, currency, or query data | IMPLEMENTED |
| `plan_selected` | Visitor activates a rendered plan CTA | Client | `product_type`, `tier`, `billing_interval` | Intent / micro conversion | Explicit grant; production-only | Canonical enums only; this is not a successful checkout claim | IMPLEMENTED |
| `billing_cycle_selected` | Visitor deliberately changes the monthly/annual selector | Client | `product_type`, `billing_interval` | None | Explicit grant; production-only | Canonical enums only; initial render emits nothing | IMPLEMENTED |
| `checkout_started` | Would require a server-confirmed accepted checkout boundary | Server/client | None currently exported | Primary conversion candidate | Separate consent/legal and identity-correlation review | Not sent: current POST redirects directly to Stripe and no approved consent linkage exists | DEFERRED |
| `checkout_redirected` | Server successfully creates/obtains Stripe redirect | Server/client | None currently exported | Funnel diagnostic | Separate consent/legal review | Not sent; no browser return inference or Stripe session/URL data | DEFERRED |
| `checkout_completed` | Stripe webhook confirms completion | Internal billing lifecycle | Internal domain fields only | Authoritative conversion internally | Not exported to GA4 | Never use browser success page as truth; no identifiers exported | INTERNAL ONLY |
| `subscription_activated` | Local server lifecycle activates subscription | Internal lifecycle | Existing domain state | Product/billing outcome | Not exported to GA4 | No identity stitching | INTERNAL ONLY |
| `connector_pairing_started` / `connector_pairing_completed` | Pairing lifecycle | Internal product/security flow | Existing internal event fields | Product activation | Not exported to GA4 | Pairing code, token, site identity excluded | INTERNAL ONLY |
| `first_site_connected` | First site association | Internal product lifecycle | Existing internal event fields | Product activation | Not exported to GA4 | Site URL/domain and identity excluded | INTERNAL ONLY |
| `first_backup_verified` | Backup verification lifecycle | Operational monitoring | Existing operational metrics only | Operations | Not exported to GA4 | No backup key/name, site identity, or security details | INTERNAL ONLY |
| `registration_started` | No public registration step exists | N/A | None | N/A | N/A | Would misdescribe checkout lifecycle | NOT APPLICABLE |

## Global payload and transport rules

- The contract accepts only the exact event-specific parameter set and enum values. Extra keys cause rejection rather than being stripped and forwarded.
- Never send email, name, phone, billing/tax/invoice data, user/customer IDs, Stripe identifiers, site URLs/domains, backup/storage identifiers, malware/security findings, privacy case/request IDs, credentials, free text, or full query strings.
- The GA4 adapter is not initialized until the visitor grants analytics and production configuration/host checks pass. No GTM, ads, server-side Measurement Protocol, or custom warehouse is included.
- Google advertising consent categories stay denied. Automatic page views are disabled; the application emits one canonical page view itself.
- Checkout and lifecycle outcomes remain internal. Analytics must not alter checkout, Stripe, billing, subscription, or activation behavior.
- Campaign attribution is not captured or persisted in M13.2 pending qualified privacy/legal policy review.
