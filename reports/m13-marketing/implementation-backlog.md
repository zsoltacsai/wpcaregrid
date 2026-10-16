# M13 Implementation Backlog

This backlog starts only after planning approval. All runtime work must be on a fresh branch from the then-current authoritative primary. No packages are approved by this plan.

## P0 — crawl and measurement safety

| ID | Dependencies | Files / systems | Type | Owner action? | Google action? | Legal dependency? | Tests | Staging validation | Acceptance |
|---|---|---|---|---|---|---|---|---|---|
| M13-01 | current primary audit | routes, `app/Seo`, marketing layout, sitemap/robots | runtime + tests | no | no | no | indexability, canonical, host, redirects, route inventory, noindex for private/system/preview | HU/EN crawl; staging noindex + sitemap hidden | only approved four canonical docs indexable until new content passes review |
| M13-02 | M13-01 | metadata schema and marketing layout | runtime/front-end/content | Owner reviews text | no | claims/legal review for legal/privacy | title/description/H1/canonical/OG/robots per locale; missing metadata and duplicate checks | all current public routes and newly created routes | centralized defaults; no per-view metadata drift; query-free canonical |
| M13-03 | consent policy boundary | analytics adapter/config and client entry | runtime/front-end | choose property before activation | later | YES before any optional collection | disabled-by-default, consent gate, withdrawal, no PII, no duplicate pageview, fake sink | prove no tracking requests on staging | no vendor call with unset config/consent; production property not used off-prod |
| M13-04 | M13-01 | existing localized marketing content | translation/content | approve claims | no | legal/security review for risky claims | HU/EN completeness and claim-register linked tests | inspect actual published Owner content | every indexed promise has current evidence/qualifier |
| M13-05 | M13-01 | SEO route/content registry | runtime/tests | no | no | legal review for legal-document index policy | sitemap parse/exact URLs, reciprocal hreflang, status/canonical/noindex crawl | deploy config check | sitemap is canonical and private/temporary URLs excluded |

## P1 — structured facts, conversion, content and performance

| ID | Dependencies | Files / systems | Type | Owner action? | Google action? | Legal dependency? | Tests | Staging validation | Acceptance |
|---|---|---|---|---|---|---|---|---|---|
| M13-06 | claim register and entity facts | Blade/schema helper, content/catalogue | runtime + content | approve organization/entity details | no | no unless claim | JSON-LD parse and visible parity; prices from catalogue | inspect rendered HU/EN structured data | only meaningful, true schema; no false Offer/review data |
| M13-07 | GA4 ID + consent approved | event adapter, pricing/checkout integration | runtime/front-end | provide ID/launch choice | property/stream setup | YES for collection policy | allowlist, consent, dedupe, PII and webhook authority tests | separate non-production behavior; synthetic funnel | conversion counts originate from server state, not return page |
| M13-08 | Search intent and current capability facts | home/pricing/typed content, perhaps new page | content/translation/front-end | approve claims | no | claim-specific | HU/EN parity, one intent/page, link checks | crawler and visual/CTA review | substantive content only; no thin pages/future entitlement promises |
| M13-09 | baseline measurements | Vite assets, Blade, CSS, image assets | front-end/performance | no | PSI/GSC access helpful | no | bundle budget and layout checks | repeat lab run with identical setup | measured bottleneck fixed without CWV regression |
| M13-10 | final route list | crawler test harness | tests/CI | no | no | no | statuses, titles, links, canonical, hreflang, private noindex | safe staged crawl | deterministic regression; no paid crawler requirement |

## P2 — optional future content/reporting

| ID | Dependencies | Files / systems | Type | Owner action? | Google action? | Legal dependency? | Tests | Staging validation | Acceptance |
|---|---|---|---|---|---|---|---|---|---|
| M13-11 | Search Console query evidence | future resource/help content | content/translation | topic approval | GSC access | claim review | locale/link/content checks | crawl/page smoke | add only when customer/search value exceeds upkeep |
| M13-12 | stable event data and reporting demand | GA4 exploration/Looker Studio | external config | yes | dashboard configuration | data/retention review | definition review | no production data in staging | minimal report supports landing→activation without custom warehouse |

## Change classification

- **Runtime/frontend/tests:** metadata coverage, sitemap registry, schema, consent-gated analytics, event adapter, deterministic crawler and pricing parity checks.
- **Content/translation:** improved pages, answers, localized titles/descriptions and approved claims.
- **Configuration:** canonical host/indexing gate, unset-by-default analytics ID and environment separation.
- **Owner configuration:** reviewed copy and chosen production measurement property; not broad runtime editing.
- **External Google setup:** GA4 property/stream, Search Console verification/submission and monitoring.
- **Legal review:** consent behavior/text/retention and any privacy/legal claims.

Do not implement GFS backup tier content until M14 entitlements and M16 pricing presentation are complete.
