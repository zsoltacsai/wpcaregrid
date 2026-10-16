# M13.2 Analytics, Consent, and Funnel Implementation Report

## Release lineage

- M13.1 planning merge: `8d3604099361bca074dfc46fd6bb7a857f588906`.
- M13.2 implementation baseline: `eaa8f0b27bd020084216d42367cc1d86980b8e76`.
- Implementation branch: `codex/m13-analytics-consent-funnel`.
- M13.1 executable staging baseline observed before this implementation: `9431db502105b51102905aefe60faee2efbcc021`.
- Candidate, CI, merge, and post-deploy evidence will be added after exact-head validation and guarded staging deployment. This report does not claim those steps are complete.

## Architecture

The marketing layout supplies a bounded client context only for the four canonical home/pricing routes. `MarketingAnalyticsPolicy` enables that context only when `APP_ENV=production`, the app/request/configured SEO hosts match exactly, the GA4 Measurement ID is valid, and both `MARKETING_ANALYTICS_ENABLED` and the separate `MARKETING_ANALYTICS_POLICY_AUTHORIZED` configuration gates are true. Both gates default false; the example environment leaves the Measurement ID blank. Staging/local/test contexts are disabled. The same policy controls the conditional Google CSP allowlist.

The browser adapter loads the Google tag only after an explicit analytics grant. Consent is stored in local storage as a state and technical version only. Unknown/malformed/stale consent fails closed. The localized HU/EN preferences offer equal-weight allow/reject choices and persistent access to change the decision. Withdrawal prevents future app events and updates loaded Google consent to denied; re-grant restores analytics consent without adding another script. The text is explicitly marked pending qualified privacy/legal review.

Google Consent Mode-compatible defaults deny analytics and all advertising categories before the tag is loaded. After explicit grant, only `analytics_storage` is granted. Google signals, ad personalization, automatic page views, URL passthrough, and referrer data are disabled. No Google Ads, GTM, server-side Measurement Protocol, or custom warehouse is added.

## Event and billing boundary

- Client events implemented: one canonical `page_view`, one `pricing_view`, plan CTA intent (`plan_selected`), and deliberate billing interval changes (`billing_cycle_selected`). The contract rejects unknown event names, unknown keys, invalid enum values, and noncanonical/query-bearing page paths.
- Checkout accepted/redirected events are deferred because checkout currently validates server-side and redirects directly to Stripe; no approved consent continuity or deduplicated server export is present. A plan CTA event is intent only, not checkout success.
- `checkout_completed` and `subscription_activated` remain authoritative internal webhook/server lifecycle events. Pairing/site/backup activation and operational security signals are not sent to GA4. Browser return pages are not payment truth.
- No campaign query parameters, click IDs, raw URLs, or referrers are persisted/transmitted. Attribution remains deferred pending qualified review.
- Checkout, Stripe, subscription, account activation, product operations, and M12 privacy behavior are unchanged.

## Validation completed locally

- Node fake-sink contract suite: 9 tests passed, including payload allowlisting, PII-key rejection, stale consent, no initialization for unknown/denied/disabled states, page-view dedupe, pricing sequence, withdrawal/re-grant, denied advertising states, and late script cancellation/retry. The suite has no real network adapter invocation.
- Laravel analytics-policy feature suite: 5 tests, 29 assertions passed. It covers explicit reviewed-policy authorization, exact production host/config eligibility, invalid Measurement ID, non-production behavior, localized preferences, and query-free analytics context.
- Frontend production build succeeded.
- Project-wide Pint passed (944 PHP files).
- M13.1 SEO plus checkout, Stripe isolation, and subscription lifecycle regression: 109 passed, 1 existing MySQL-connection isolation test skipped, 820 assertions. The skip is explicitly scoped to independent MySQL connections and is covered by the required MySQL CI gate.

## External setup and residuals

- **OWNER_ACTION / EXTERNAL_SETUP:** select/create GA4 property and web stream, provide the production Measurement ID through secret-safe deployment config, and confirm property ownership/access. No ID is configured in this candidate or staging.
- **WAIT_LEGAL:** review final HU/EN consent wording, purpose and applicable policy, retention, cookie/privacy notice alignment, and any future server-side aggregate conversion export. This implementation uses explicit opt-in as its fail-closed technical default and does not infer legal requirements.
- Search Console verification/submission remains a separate external action; M13.1 technical sitemap readiness is unaffected.
- The existing staging `security_scanner` warning (“No completed security scan yet.”) remains an M14 security audit carry-forward.

## Deployment evidence

Pending implementation exact-head CI, merge, guarded staging deployment, HU/EN preference interaction checks, no-Google-network proof, SEO smoke, health check, and post-deploy M12 gate readback. M12 destructive/privacy gates must remain false throughout. Production analytics is not activated by this implementation.
