# Public Route Inventory

Baseline: route source at `routes/web.php`, `routes/owner.php`, `routes/operator.php`; view and test inspection at primary `b62db67ff95ec7640a68eabafd6e9fbb8f657ec0`. “Public route pattern” means a GET URL template; dynamic instances do not count separately. The four indexable marketing documents are the current intended indexable route count.

| Route / pattern | Locale | Indexable? | Current title/description | H1 | Search intent | Main CTA | Schema | Analytics | Status |
|---|---|---:|---|---|---|---|---|---|---|
| `/en/` | EN | Yes, only approved production host/config | `home.meta.title` / `home.meta.description`; Owner SEO may override | `home.hero.title` | broad WordPress operations/maintenance | pricing section / checkout | none | none | implemented; metadata/index gate tests exist |
| `/hu/` | HU | Yes, same gate | HU home metadata; Owner SEO may override | HU `home.hero.title` | WordPress karbantartás | pricing section / checkout | none | none | implemented; native content required for hreflang |
| `/en/pricing` | EN | Yes, same gate | `checkout.meta.pricing_*`; Owner SEO may override | pricing section heading | commercial plan/price comparison | choose plan → `/checkout` | none | none | implemented; prices from catalogue |
| `/hu/pricing` | HU | Yes, same gate | HU pricing metadata; Owner SEO may override | pricing section heading | WordPress/WooCommerce csomagok és árak | choose plan → `/checkout` | none | none | implemented; prices from catalogue |
| `/`, `/pricing` | default English redirect | No document; 301 | N/A | N/A | canonical aliases | redirect | none | none | implemented; query allowlist |
| `/terms`, `/privacy`, `/cookie-policy`, `/dpa`, `/legal/history/{uuid}` | current request locale; URLs unprefixed | Not currently in sitemap; explicit policy needs validation | document title/content from approved published version; no marketing metadata registry | document title | navigational/legal | none | none | none | public legal infrastructure; legal text/index policy remains review-bound |
| `/checkout` | current app locale | Noindex | checkout title; general home description currently supplied | checkout title | transactional | submit checkout | none | none | POST resolves actual price; tracking absent |
| `/checkout/success`, `/checkout/cancel/{intent}` | current app locale | Noindex | status title; general description | status H1 | transactional utility | continue/retry | none | none | browser return is not payment proof |
| `/login`, `/forgot-password`, `/reset-password/{token}`, `/account/activate/{token}`, `/staff/activate/{token}` | default/display locale | Noindex | auth-specific or layout title | auth-specific H1 | navigational/utility | authenticate/activate | none | none | default layout noindex; route-specific assertions partly covered |
| `/owner/login`, `/operator/login` and authenticated consoles | EN | Noindex + auth | console-specific title | console H1 | system | login | none | none | auth required; layouts noindex and security header |
| `/robots.txt` | N/A | system response, noindex | N/A | N/A | crawler directive | N/A | N/A | none | production gated sitemap line; does not block routes |
| `/sitemap.xml` | HU/EN URLs | system XML, not a page | N/A | N/A | crawler discovery | N/A | sitemap XHTML alternates | none | production-only, currently 4 canonical URLs |
| `/locale`, `/currency` (POST); `/stripe/webhook` (POST) | N/A | Not indexable | N/A | N/A | system | N/A | none | none | no public GET document; webhook authenticated by Stripe signature |
| `/contact` (configured link target only) | unspecified | N/A | N/A | N/A | contact | contact | none | none | no route found; current footer can link to absent destination unless externally configured |

## Counts

- Intended indexable marketing pages: **4** (2 page families × 2 locales).
- Distinct public marketing page families: **2** (home and pricing).
- Public GET route templates beyond those documents: redirects, legal, transaction, authentication, and crawler/system routes listed above; do not label them all marketing pages.
- No routed FAQ, feature, backup/recovery, security, monitoring, documentation, contact, blog, or per-product landing page was found.

## Launch implementation checks

1. Crawl these exact canonical URLs and all declared links in HU and EN.
2. Assert title/description/H1 presence and uniqueness, one canonical, correct language, and reciprocal alternates when emitted.
3. Assert the sitemap exactly matches the indexable route registry and contains no legal history, auth, checkout, dashboard, callbacks, or staging hostname.
4. Explicitly decide legal-page robots behavior with legal review; keep unpublished/missing documents unavailable and do not use noindex in place of access control.
5. Treat `/contact` route absence as a content/CTA implementation item (either provide an actual page or use an approved real contact destination).
