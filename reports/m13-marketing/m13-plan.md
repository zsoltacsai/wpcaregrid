# M13 Marketing, SEO, AEO, Analytics & Measurement Plan

**Planning baseline:** `claude/ecstatic-wozniak-oemyuf` at `b62db67ff95ec7640a68eabafd6e9fbb8f657ec0`
**Scope:** repository and read-only evidence audit; documentation only.
**Planning branch:** `codex/m13-marketing-seo-analytics-plan`

## Recommendation

Proceed with M13 implementation after this plan is reviewed. The repository already has a well-separated technical SEO base for four localized marketing documents: home and pricing in English and Hungarian. It also has gated indexation, deployment-owned canonical origin, conditional reciprocal language alternates, robots and XML sitemap endpoints, metadata editing for those pages, and targeted feature tests. It does not currently show analytics or consent instrumentation, JSON-LD, a dedicated feature/content page family, or measured Core Web Vitals evidence.

Keep M13 implementation incremental. First close metadata/crawler regression gaps and protect the existing SEO safeguards. Then add evidence-backed content and structured facts, and only then add consent-aware analytics/funnel events. Configure Search Console and analytics properties as external Owner actions; repository work must remain safe when IDs and approvals are absent.

## Verified source facts

- Current remote primary at planning start: `claude/ecstatic-wozniak-oemyuf`, `b62db67ff95ec7640a68eabafd6e9fbb8f657ec0`; a fresh clean worktree was created at that SHA.
- Laravel 13 (`^13.17`), PHP `^8.3`, Vite 8, Tailwind 4, Alpine 3; public marketing views are Blade.
- Public crawlable marketing content currently has two page families, each in `/en` and `/hu`: home and pricing. `/` and `/pricing` issue 301 redirects to English routes.
- Host/canonical tests use `https://wpcaregrid.com`; indexing requires `APP_ENV=production`, an explicit `SEO_INDEXING_ENABLED=true`, matching configured production host, `APP_URL` host and request host. Staging and mismatched hosts are noindex. No live staging query was made in this planning audit; latest reported executable staging SHA is `19e31d7204a59e08211c4dd9e313b9cccbb01611`, recorded in the M12 Wave 7 report, and is not asserted current.
- Sitemap lists four canonical localized URLs and their language alternates. Current matching tests validate the sitemap/robots gate and metadata basics.
- Content is in HU/EN language files and an Owner-managed, typed/published content repository. Owner editing is not a general CMS. Pricing amounts/availability come from `PricingCatalog` and the Owner pricing configuration; translated copy is separate.
- Source searches found no GA4/GTM ID, analytics client, consent manager/banner, Consent Mode integration, JSON-LD/schema output, or analytics event taxonomy.
- M12 final engineering closure is documented. Its legal/accounting/provider gates and the M14 security-scan warning remain carry-forward items; marketing must not convert them into claims of legal approval or completed security audit.

## M13 phases

1. **SEO core and crawl QA (P0):** preserve the current deployment gate, URLs, canonical, hreflang, robots and sitemap; inventory generated pages; extend deterministic checks before adding routes; assess legal-page indexability with counsel-approved policy. Keep staging noindex.
2. **Metadata and social sharing (P0):** retain centralized Blade metadata; set page/locale-specific title and description, language, canonical, robots, OG; add social image/card metadata only when approved assets exist. Add no duplicate per-view head logic.
3. **Claim-safe content architecture (P1):** use existing section schema and translation files; improve only pages with a distinct customer question and evidence. Keep one canonical landing page per intent cluster; avoid thin feature pages. No backup entitlement promises before M14/M16.
4. **Structured data and AEO (P1):** emit only validated Organization/WebSite/WebPage/BreadcrumbList facts where the page semantics support them. Derive values from the visible, canonical page and pricing catalogue. Do not add ratings, reviews, invented availability, or FAQ rich-result promises.
5. **Consent-aware measurement (P0):** no vendor script or event transmission by default. Add an adapter/config boundary and an agreed consent state model; do not decide jurisdictional consent rules in code. External property IDs remain unset until Owner setup and legal review are complete.
6. **Funnel events and attribution (P1):** record minimal marketing events only; server/webhook remains authoritative for payment and activation. Do not export operational/security/privacy data to GA4. Preserve only an allowlisted set of campaign fields, not arbitrary query strings.
7. **Performance baseline and fixes (P1):** measure before setting budgets. Gather mobile/desktop lab data and field data once available; optimize the measured bottleneck, with third-party analytics loading behind the approved consent gate.
8. **Staging validation and closure (P0/P1):** HU/EN crawler checks, schema/pricing parity, consent and payload tests, funnel/Stripe non-interference, guarded staging validation, then report-only closure PR.

## Current route inventory summary

There are four intended indexable marketing URLs: `/en/`, `/hu/`, `/en/pricing`, `/hu/pricing`. Current public GET route patterns also include legacy redirects, five public legal-document patterns, checkout pages, customer/Owner/Operator authentication pages, and robots/sitemap. They are not all marketing documents. See [public-route-inventory.md](public-route-inventory.md).

No standalone features, backup, security, monitoring, FAQ, contact, documentation, blog, WooCommerce pricing landing page, or WordPress pricing landing page currently exists as a distinct routed marketing document. The home page contains sections and FAQ; pricing contains product tabs/plans. Login exists; registration is intentionally purchase/activation based. `/contact` is a configured link destination but no matching route was found.

## Search and positioning

Keep “intelligent WordPress Operations Platform” as the supplied product direction, while current published copy describes WordPress maintenance and related tasks. Explain the current capability set concretely: managed updates, remote backups, security/vulnerability checks, uptime monitoring, and post-update checks as actually available by plan. Do not claim autonomous AI execution, universal recovery, absolute security, legal compliance, guaranteed physical deletion, or future GFS retention.

Map intent clusters in [search-intent-map.md](search-intent-map.md). Home remains the broad informational/commercial entry; pricing owns plan and price intent. Create additional landing pages only after a distinct customer need, current capability evidence, and translated content are ready.

## Owner and external decisions

**NO NEW OWNER DECISIONS REQUIRED FOR M13 IMPLEMENTATION.** The Owner has already set product positioning and launch-roadmap boundaries in this request. Engineering can implement fail-safe configuration with analytics disabled by default and external IDs unset. Before external collection starts, Owner must provide the GA4 property/stream and Search Console access; legal review must approve applicable consent text/settings and analytics retention policy. These are setup/review gates, not blockers to building a safe disabled-by-default adapter.

## Non-goals and handoffs

- No runtime or production changes in this planning pass; no deployment.
- No public legal copy publication or consent-law conclusion.
- No future GFS commercial entitlement redesign: M14 domain/entitlement, M16 pricing/UX presentation.
- No M14 security hardening, no M15 pilot, no M16 CRO redesign, no M17 public-launch audit.
- Keep `M12_DESTRUCTIVE_RETENTION_ENABLED=false` and all action gates off.

## Success and release evidence

Implementation acceptance is defined in [closure-criteria.md](closure-criteria.md). The key release evidence is: all indexable HU/EN pages have one correct canonical, reciprocal alternates, truthful metadata and schema; private/transactional routes remain noindex; sitemap and robots match deployment state; analytics is consent-gated, PII-free and isolated from staging; the pricing display agrees with the actual catalogue/checkout; all existing M12 CI gates and regressions remain green; staged crawl and funnel tests pass. Search Console submission and production GA4 activation are external tasks, recorded separately.

Google implementation references: [international sites](https://developers.google.com/search/docs/specialty/international/managing-multi-regional-sites), [localized versions](https://developers.google.com/search/docs/specialty/international/localized-versions), [canonicalization](https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls), [sitemaps](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap), [robots/noindex basics](https://developers.google.com/search/docs/fundamentals/get-started-developers), [GA4 consent types](https://support.google.com/analytics/answer/12334711?hl=en). These explain technical behavior, not legal requirements.
