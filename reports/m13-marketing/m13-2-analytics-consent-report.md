# M13.2 Analytics, Consent, and Funnel Implementation Report

## Release lineage

- M13.1 planning merge: `8d3604099361bca074dfc46fd6bb7a857f588906`.
- M13.2 implementation baseline: `eaa8f0b27bd020084216d42367cc1d86980b8e76`.
- Implementation branch: `codex/m13-analytics-consent-funnel`.
- Implementation candidate: `bed531a2b8209557072d118e855efe3d54cd2d41` (PR #79).
- Exact-head GitHub Actions run: `38869056939`, all 5 required jobs passed.
- Implementation merge and deployed primary: `cc8acede6faea076b14a264dccabca75df15a76e`.
- M13.1 executable staging baseline observed before this implementation: `9431db502105b51102905aefe60faee2efbcc021`.
- Staging deployed SHA: `cc8acede6faea076b14a264dccabca75df15a76e`; checkout is clean.
- The post-deploy closure report will be merged separately; it is documentation-only and does not require another deployment.

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
- M13.1 SEO plus checkout, Stripe isolation, and subscription lifecycle regression: 109 passed, 1 existing MySQL-connection isolation test skipped, 820 assertions. The skip is scoped to independent MySQL connections and is covered by the required MySQL CI gate.
- Both Composer validations/audits passed; npm audit reported 0 vulnerabilities; `git diff --check` passed.

## Exact-head CI evidence

GitHub Actions run `38869056939` passed all required jobs on candidate `bed531a2…`:

- Server SQLite: 1,296 tests, 18,668 assertions, 19 documented engine/environment skips.
- Server MySQL 8.4: 1,296 tests, 18,866 assertions, 0 skips; all nine required MySQL process/concurrency test files passed.
- Connector SQLite: 202 tests, 1,401 assertions, 24 MySQL-specific skips.
- Connector MySQL: 202 tests, 1,775 assertions, 3 documented skips.
- Quality/security/build: Pint, PHP syntax, Composer validation/audits, frontend build, Node analytics contract (9/9), npm audit (0 vulnerabilities), and full-history secret scan passed.
- Both Server jobs passed the production-cache HTTP smoke suite (77 checks).

## External setup and residuals

- **OWNER_ACTION / EXTERNAL_SETUP:** select/create GA4 property and web stream, provide the production Measurement ID through secret-safe deployment config, and confirm property ownership/access. No ID is configured in this candidate or staging.
- **WAIT_LEGAL:** review final HU/EN consent wording, purpose and applicable policy, retention, cookie/privacy notice alignment, and any future server-side aggregate conversion export. This implementation uses explicit opt-in as its fail-closed technical default and does not infer legal requirements.
- Search Console verification/submission remains a separate external action; M13.1 technical sitemap readiness is unaffected.
- The existing staging `security_scanner` warning (“No completed security scan yet.”) remains an M14 security audit carry-forward.

## Staging deployment and validation evidence

- Strict SSH host-key verification used the approved Ed25519 fingerprint `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`. The guarded staging script pinned baseline `9431db502105b51102905aefe60faee2efbcc021`, created its protected pre-deploy database backup, deployed exact primary SHA `cc8acede…`, found no pending migrations, rebuilt dependencies/assets/caches, restarted both workers, observed a healthy scheduler heartbeat, and passed all final health and HTTP smoke checks.
- Post-deploy `system:check`: application, MySQL, queue, scheduler, Connector, Stripe TEST, Cloudflare, backup storage, mail, safety, privacy retention, restore readiness, and privacy exports are OK. Both workers are RUNNING; failed jobs are zero. Existing `security_scanner` WARNING (“No completed security scan yet.”) remains visible as an M14 carry-forward.
- Staging analytics config readback: `enabled=false`, `policy_authorized=false`, Measurement ID empty, technical version `technical-v1`.
- All six M12 privacy/destructive gates read false after deployment: destructive retention, profile anonymization, backup deletion, provider deletion, bulk execution, and synthetic profile anonymization.
- In a disposable headless Edge context, HU and EN pages rendered `enabled=false` and empty Measurement ID. Reject, grant, withdrawal, reload, and locale switch persisted the expected states. Requests to `googletagmanager.com`, `google-analytics.com`, and `analytics.google.com`: **0**.
- Four staging marketing routes (`/en/`, `/hu/`, `/en/pricing`, `/hu/pricing`) returned 200 with localized titles/descriptions, one H1, parseable JSON-LD, and `noindex, nofollow`. Staging sitemap returned 404 and contained no staging hostname. The live application continues to serve public canonical paths for its own noindex staging responses.
- No paid checkout or billing mutation was performed. Client fake-sink tests exercise funnel event order; authoritative checkout/payment outcomes remain internal and unchanged.

## Closure disposition

- M13.2 implementation is merged and deployed to staging at cc8acede6faea076b14a264dccabca75df15a76e; staging validation passed. This report-only closure PR records evidence and does not require redeployment.
- GA4 property/stream ownership and Measurement ID remain external setup. Collection remains disabled until separately configured and authorized.
- Consent wording, purpose, applicable legal policy, and analytics retention remain WAIT_LEGAL; no legal conclusion is claimed.
- The staging security-scanner warning remains an M14 carry-forward. M13.3 and M14 have not started.
- M12_DESTRUCTIVE_RETENTION_ENABLED=false; no destructive M12 behavior was enabled or changed.
