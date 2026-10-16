# M13.2 Consent and Measurement

## Implemented technical behavior

- `MARKETING_ANALYTICS_ENABLED` and the separate `MARKETING_ANALYTICS_POLICY_AUTHORIZED` gate both default to false. An unset or invalid GA4 Measurement ID disables external collection. Configuration alone therefore cannot turn on collection without the separately reviewed policy gate.
- Server eligibility requires `APP_ENV=production`, enabled configuration, a valid `G-...` ID, and exact agreement among request host, `APP_URL` host, and the configured production SEO host. Only the four indexable marketing pages receive an enabled client context. Staging/local/testing are ineligible.
- The marketing preference UI is localized in HU/EN, has equally prominent allow/reject choices, a persistent preferences entry point, and a later withdrawal action. Copy explicitly remains pending qualified privacy/legal review; this is not a compliance claim.
- Consent is stored in local storage as only `{state, version}`. Missing, malformed, blocked, or stale-version state resolves to UNKNOWN and sends nothing. Choice persistence failure fails closed.
- Google’s library is not requested until a valid explicit grant and eligible production configuration. Before script load the adapter queues denied Google consent defaults; after grant it enables only `analytics_storage`. Advertising categories remain denied. Automatic page views, Google signals, ad personalization, URL passthrough, and referrer data are disabled.
- Withdrawal stops subsequent app events and updates loaded Google consent to denied. Re-grant updates analytics consent without loading a duplicate script. Previously collected vendor data is outside this control and is not promised to be deleted.
- The preferences controls and analytics adapter do not gate or alter checkout, authentication, billing, SEO, or other application behavior.

## Legal and external boundaries

- The implementation uses explicit opt-in as the fail-closed engineering default; it does not determine legal requirements, jurisdictional behavior, cookie classifications, or legal basis.
- **WAIT_LEGAL / qualified review:** final localized wording, purpose/legal basis, regions/policy, analytics data retention, cookie/privacy notice alignment, and whether future server-side aggregate conversion export is permitted.
- **OWNER_ACTION / EXTERNAL_SETUP:** choose/create the GA4 property and web stream; provide the production Measurement ID through deployment configuration; confirm account ownership/access and production hostname. Without those actions, production collection remains disabled.
- Google Search Console verification and sitemap submission remain separate external actions. No account or API credential is stored in source.
- No UTM values, arbitrary query strings, full referrers, click IDs, or checkout identity are persisted or transmitted in M13.2. Attribution is deferred pending review.

## Verification

Node fake-sink tests cover event validation, consent states/versioning, one page view, pricing events, withdrawal/re-grant, disabled configuration, and late script-load cancellation without network access. Laravel feature tests cover production host/config eligibility, staging and invalid-ID fail-closed behavior, localized copy, and query-free canonical context. The staging deployment has no analytics configuration enabled; final staging verification must confirm that the rendered context and network remain disabled.
