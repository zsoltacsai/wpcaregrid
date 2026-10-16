# M13 Implementation Backlog

This backlog starts only after planning approval. All runtime work must be on a fresh branch from the then-current authoritative primary. No packages are approved by this plan.

## P0 — crawl and measurement safety

| ID | Dependencies | Files / systems | Type | Owner action? | Google action? | Legal dependency? | Tests | Staging validation | Acceptance |
|---|---|---|---|---|---|---|---|---|---|
| M13-01 | current primary audit | routes, `app/Seo`, marketing layout, sitemap/robots | runtime + tests | no | no | no | indexability, canonical, host, redirects, route inventory, noindex for private/system/preview | HU/EN crawl; staging noindex + sitemap hidden | only approved four canonical docs indexable until new content passes review |
| M13-02 | M13-01 | metadata schema and marketing layout | runtime/front-end/content | Owner reviews text | no | claims/legal review for legal/privacy | title/description/H1/canonical/OG/robots per locale; missing metadata and duplicate checks | all current public routes and newly created routes | centralized defaults; no per-view metadata drift; query-free canonical |
| M13-03 | consent policy boundary | `app/Marketing/Analytics`, `config/marketing_analytics.php`, marketing layout, localized preferences, JS adapter | runtime/front-end/tests | choose property before activation | Owner config later | final wording/retention/region remains WAIT_LEGAL; explicit opt-in is the fail-closed technical default | Node fake sink, Laravel host/config/UI tests, PII and withdrawal tests | verify staging disabled and no external requests | implemented in M13.2 candidate; no vendor call unless consent and exact production configuration |
| M13-04 | M13-01 | existing localized marketing content | translation/content | approve claims | no | legal/security review for risky claims | HU/EN completeness and claim-register linked tests | inspect actual published Owner content | every indexed promise has current evidence/qualifier |
| M13-05 | M13-01 | SEO route/content registry | runtime/tests | no | no | legal review for legal-document index policy | sitemap parse/exact URLs, reciprocal hreflang, status/canonical/noindex crawl | deploy config check | sitemap is canonical and private/temporary URLs excluded |

## P1 — structured facts, conversion, content and performance

| ID | Dependencies | Files / systems | Type | Owner action? | Google action? | Legal dependency? | Tests | Staging validation | Acceptance |
|---|---|---|---|---|---|---|---|---|---|
| M13-06 | claim register and entity facts | Blade/schema helper, content/catalogue | runtime + content | approve organization/entity details | no | no unless claim | JSON-LD parse and visible parity; prices from catalogue | inspect rendered HU/EN structured data | only meaningful, true schema; no false Offer/review data |
| M13-07 | external property and reviewed server-event design | marketing event contract and pricing interaction | runtime/front-end | provide ID/launch choice | property/stream setup | YES before collection; server outcome export requires further review | allowlist, consent, dedupe, PII, no-network fake sink | separate non-production behavior | client marketing events implemented; checkout/server outcomes remain internal/deferred |
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

## M13.2 execution status

M13-03 client-side consent and marketing analytics foundation is implemented in the M13.2 candidate. The GA4 Measurement ID remains unset and the feature flag defaults off. Checkout accepted/redirected, paid completion, subscription activation, product activation, and operations events are not exported. Owner property setup and qualified review remain external follow-up; they do not require enabling collection to validate the technical foundation.

Do not implement GFS backup tier content until M14 entitlements and M16 pricing presentation are complete.

## M13.3 and final M13 disposition — 2026-10-16

M13-01 through M13-10 are engineering-complete. M13.3 established a repeatable desktop/mobile lab baseline, corrected the measured mobile EN pricing control-row layout shift, and remeasured the deployed exact SHA. The complete evidence and exact-head CI/staging lineage are in `cwv-performance-baseline.md` and `m13-final-engineering-closure.md`.

| Backlog item | Final status | Evidence / boundary |
|---|---|---|
| M13-01 to M13-06 | CLOSED | SEO routes, localized metadata, claims, sitemap/robots, schema, pricing catalogue alignment and staging crawl remain green. |
| M13-07 | CLOSED (engineering) | Consent-aware client funnel contract and staging isolation are tested. GA4 property/ID and legal review remain external; collection stays disabled. |
| M13-08 | CLOSED for current P0 scope | HU/EN home/pricing explain current scope. No thin feature pages or future backup schedules were published. Optional content requires later evidence and prioritization. |
| M13-09 | CLOSED | Lab baseline recorded; measured EN pricing CLS issue fixed and remeasured on staging. Field CWV is unavailable and remains an M17 recheck. |
| M13-10 | CLOSED for deterministic regression | Route, localized metadata, sitemap, noindex, schema and pricing invariants are covered by existing tests and staging checks. |
| M13-11 | OPTIONAL / FUTURE | Add informational pages only when real customer/search evidence supports ongoing HU/EN maintenance. |
| M13-12 | EXTERNAL_SETUP / FUTURE | GA4 property/access and any reporting setup remain external, gated by authorization and qualified retention/purpose review. No custom warehouse is planned. |

M13 is engineering-closed once the report-only closure PR merges. This does not mean GDPR compliance certification, production analytics activation, public launch, or completion of M14/M15/M16/M17.
