# M13 Current-State Audit

**Audit source:** primary `b62db67ff95ec7640a68eabafd6e9fbb8f657ec0`; no runtime code changed.

| Area | Evidence | Current finding | Status |
|---|---|---|---|
| Framework/build | `composer.json`, `package.json`, `vite.config.js` | Laravel 13, PHP 8.3+, Blade, Vite 8, Tailwind 4, Alpine 3 | Verified |
| Public routes | `routes/web.php` | Only home/pricing localized marketing routes, plus legal, checkout, auth and crawler/system endpoints | Verified |
| Localization | `config/localization.php`, `config/content.php`, `SetLocale`, `MarketingRoutes` | Stable `/en` and `/hu` URLs for home/pricing; language switcher links equivalent URL; cookie is not required for marketing URL locale | Strong foundation |
| Canonical | `CanonicalUrl`, marketing layout | Built from configured `APP_URL`, not Host header; strips query from canonical; owner override constrained to exact canonical | Verified/tested |
| Index gate | `config/seo.php`, `IndexingPolicy` | Off by default; requires production environment + matching configured host and request host | Fail-safe/tested |
| hreflang | `MarketingTranslationPolicy` | Emits en/hu/x-default only when all relevant sections are native-locale content; otherwise emits none | Good fail-safe; native-content criterion may be overly broad/strict and needs route QA |
| Robots | `RobotsController` | Disallows nothing (correctly leaves noindex pages crawlable); advertises sitemap only when production indexing gate passes; response itself noindex | Verified/tested |
| Sitemap | `SitemapController`, `seo.sitemap` | Four fixed home/pricing URLs; localized reciprocal XHTML links; gated 404 outside approved production host; no lastmod; no cache | Correct for current route set; expansion requires source-of-truth updates |
| Metadata | marketing Blade layout, `ContentSchema` SEO sections | Title, description, robots, canonical, hreflang, OG type/site/title/description/url/locale; Owner can manage home/pricing values | Partial: no OG image, Twitter/X metadata, general route registry |
| Structured data | Views/app search | No JSON-LD or schema output found | Missing |
| Content model | `ContentSchema`, `ContentRepository`, Owner content editor | Version-like draft/publish states and per-locale fallback; typed fields; not a CMS | Reuse |
| Marketing page breadth | `routes/web.php`, `resources/views/pages` | Home is long-form sections with FAQ; pricing has WordPress/WooCommerce plan tabs. No standalone feature/content pages | Limited but not inherently deficient |
| Pricing authority | `PricingCatalog`, `config/pricing.php`, Owner pricing and checkout services | Display and checkout resolve from shared catalogue; checkout re-resolves on server; marketing copy/feature strings are separately translated | Strong amount authority; content parity tests desirable |
| Analytics | Exact search for GA IDs, GTM, gtag/dataLayer, known analytics vendors | No analytics integration/event layer detected | Missing |
| Consent | Consent manager/banner/storage/integration search; M12 cookie handoff | No consent implementation detected; M12 handoff says cookie/legal classification remains open | Missing; legal decision boundary |
| Funnel | Checkout controller/webhook code | Browser success route is informational and does not prove payment; subscription state is webhook-driven | Preserve this boundary in measurement |
| Performance | Vite entry points and media inventory | Small Alpine entry; only favicon assets found under public; no lab/field CWV evidence in repo | Baseline unmeasured |
| Security / indexation | account, Owner and Operator layouts; SEO feature tests | Authenticated layouts emit noindex; selected auth/checkout routes are feature-tested | Good base; extend route-wide assertions as inventory grows |
| Owner tooling | `Owner\SeoController`, `Owner\ContentController`, views | Owner can edit only typed content/SEO fields for known pages; no analytics/consent configuration UI | Keep static engineering config version-controlled |
| External setup | `.env.example`, SEO config | Production domain is represented by test/domain configuration as `wpcaregrid.com`; `SEO_INDEXING_ENABLED` defaults false. GA4/GSC credentials absent | Setup not evidenced |
| Staging baseline | M12 Wave 7 report in repository | Last recorded executable staging SHA is `19e31d7204a59e08211c4dd9e313b9cccbb01611`; current staging was not queried in this planning pass | Historical evidence only; recheck at implementation start |

## Detailed route behavior found

- Legacy `/` and `/pricing` route to configured canonical English pages with a 301 and an allowlist of non-sensitive query parameters. They are redirects, not indexable copies.
- `/en/`, `/hu/`, `/en/pricing`, `/hu/pricing` are the only marketing views passed `indexable=true`.
- Checkout, login, reset/activation and error/preview contexts rely on the marketing layout's default `indexable=false` or explicit preview noindex. Customer, Owner and Operator layouts explicitly emit noindex; Owner/Operator also set `X-Robots-Tag`.
- `/terms`, `/privacy`, `/cookie-policy`, `/dpa`, and `/legal/history/{uuid}` are public published-document endpoints, not marketing content. Indexing policy should be explicitly decided and tested per legal-document state; historic/legal availability is not automatically approval to index.
- `robots.txt` is not an access control. Protected pages use authentication and noindex; staging sitemap is gated off.

## Evidence limitations

This was a source audit, not a live crawl, analytics-account inspection, Search Console inspection, Lighthouse run, or staging health check. No GA4/GSC account access was used. The SSH host-key scan tool was unavailable in the local environment; therefore the reported staging SHA is retained as the latest repository evidence only and must be reverified at M13 implementation start. Existing M12 reports say staging had a visible `no completed security scan` warning; M13 must preserve it for M14.
