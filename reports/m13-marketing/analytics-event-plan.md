# Analytics Event Plan

No GA4/GTM/provider client or consent implementation was found in the audited source. This is a proposed taxonomy, not current tracking behavior. External collection stays disabled when configuration/consent is absent.

| Event | Description / trigger | Source | Parameters (allowlist) | Conversion? | Consent dependency | Environment | PII risk | Implementation / test | Status |
|---|---|---|---|---|---|---|---|---|---|
| `page_view` | canonical public page rendered | client/router/page load | page family, locale, canonical path (no query) | no | analytics consent if required by approved policy | production property only; staging off | low if query stripped | centralized layout; single emission test | absent |
| `pricing_view` | pricing page visible | client | locale, product tab (if selected), currency code | micro conversion | same | production only | low | no raw query/identity; event test | absent |
| `plan_selected` | user proceeds with a plan selection | client | product type, tier, billing interval, currency | micro conversion | same | production only | low | checkout URL selection; payload allowlist | absent |
| `billing_cycle_selected` | customer changes monthly/annual option | client | interval, product type | no | same | production only | low | pricing UI event test | absent |
| `registration_started` | **not currently applicable**; purchase checkout is registration/activation path | n/a | none | no | n/a | n/a | n/a | do not fabricate an event | not applicable |
| `checkout_started` | checkout form submitted successfully to server / intent created | server or client intent acknowledgement | plan, interval, currency, market; no intent ID/email | primary marketing conversion candidate | consent/policy review; do not transmit billing identity | production only | medium | emit only after server accepted state; unit/payload test | absent |
| `checkout_redirected` | server returns redirect to Stripe Checkout | server/client | plan, interval, currency | funnel diagnostic | same | production only | low | exclude Stripe session ID | absent |
| `checkout_completed` | authoritative Stripe webhook confirms paid/completed state | internal lifecycle by default; optional approved aggregate conversion | plan, interval, currency, event class | primary conversion | separate legal/consent decision before export | production only; deduplicated | high if identifiers leak | webhook idempotency test, no email/session/customer ID | internal billing event exists; GA absent |
| `subscription_activated` | authoritative server lifecycle activation | internal lifecycle; optional aggregate export | plan, interval, currency | primary conversion | legal/consent approval | production only | high | webhook/server state, dedupe, no account identifiers | internal state; GA absent |
| `connector_pairing_started` | customer starts pairing after activation | internal product activation preferred | product category only | product activation, not marketing conversion | separate review | product analytics only if approved | high | no site/domain/pairing code | absent |
| `connector_pairing_completed` | pairing verified | internal product activation preferred | no site identity | activation | separate review | product analytics only if approved | high | no domain/secrets | absent |
| `first_site_connected` | first successful website link | internal product activation preferred | product type only | activation | separate review | product analytics only if approved | high | aggregate/non-identifying only | absent |
| `first_backup_verified` | first verified backup | operations/product metric, not GA4 | none to GA4 | operations | do not send operational details to marketing analytics | internal aggregate only | very high | use existing operational monitoring; no filename/domain | absent |

## Global payload rules

Never send email, name, phone, billing address, invoice/tax values, Stripe customer/session/intent IDs, website URL/domain, credentials, backup filenames, malware/security details, privacy case/request data, free-form text, or full query strings. Allowlist every parameter at the event boundary. Do not add server-side analytics until the consent/legal and duplicate-delivery design is approved. Browser return URL is never a conversion source of truth.
