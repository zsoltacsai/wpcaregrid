# M13 Closure Criteria

M13 closure means the planned marketing engineering and measurement foundation is complete, truthfully represented, and verified in staging. It does not mean public launch readiness or legal approval.

## Required engineering evidence

- Every current/new indexable HU/EN marketing page has correct HTTP status, unique title/description/H1, canonical, reciprocal language alternate where a true translation exists, robots, OG metadata and internal links.
- Production indexation remains explicitly deployment-gated; staging/local/CI are noindex, sitemap unavailable or empty on staging; robots is not treated as access control.
- Sitemap includes only canonical indexable pages; legal/history/transaction/auth/private/system/temporary routes are handled by explicit policy and tests.
- Structured data parses, matches visible content and canonical URLs, uses catalogue-backed prices only, and contains no invented reviews, availability, or unsupported claims.
- Claim register is reviewed against actual published Owner content and product evidence. M12 legal/accounting/provider waits remain visible; no GDPR or physical-erasure guarantee.
- Analytics uses stable allowlisted event names/parameters, blocks PII/secrets, is consent-aware per approved policy, does not load in staging by default, and does not alter billing or treat Stripe browser return as payment confirmation.
- Pricing shown in page/schema matches the live `PricingCatalog`/server checkout resolution for available products, currencies, intervals, tax treatment and plan features.
- Consent withdrawal, event dedupe, no duplicate pageviews, environment separation and zero production traffic from CI are verified with a fake analytics sink/network assertions.
- CWV baseline is recorded using a reproducible setup; evidence-backed regression/bottleneck fixes are applied; no invented numeric baseline or target is represented as measured fact.
- Core existing CI gates pass exact candidate SHA: Quality/security/build, Server SQLite, Server MySQL, Connector SQLite, Connector MySQL; MySQL process/concurrency gate remains required.
- Staging validation verifies exact deployed executable SHA, HU/EN route crawl, private noindex, sitemap, schema, analytics isolation, consent states, pricing and funnel behavior. No production deployment is implied.
- Closure report PR is merged; external GA4/GSC setup and legal review are explicit actions if not yet complete.

## Release gates

Stop closure for production-noindex misconfiguration, staging canonical/index exposure, private route indexing, canonical conflict/loop, HU/EN alternate corruption, PII analytics leakage, consent bypass, pricing mismatch, billing mutation from analytics, false structured-data claims, or a serious measured performance regression caused by implementation.

An absent external property ID may remain an Owner action if the repository safely stays disabled and all fake-sink/integration behavior is tested. Search Console submission is external evidence and is not fabricated by CI.

## Explicit non-claims and carry-forwards

- No GDPR compliance certification or legal conclusion.
- M12 `WAIT_LEGAL`, `WAIT_ACCOUNTING`, `WAIT_FACT` remain as applicable; they block affected claims/actions, not safe repository plumbing.
- No production destructive execution; `M12_DESTRUCTIVE_RETENTION_ENABLED=false` remains mandatory.
- M14 carries the open security scanner/AppSec and hosting compatibility audit.
- M15 carries DR, billing, privacy-control and operational safety audits.
- M17 carries final production readiness, final crawl, production analytics IDs, Search Console/sitemap submission and release-SHA CWV recheck.
- Commercial backup GFS redesign belongs to M14 backend and M16 frontend/pricing.
