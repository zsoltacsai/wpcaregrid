# M13 Engineering Closure Matrix

Evidence baseline: primary M13.2 closure SHA `b72a705619ddf5ad3045ae486959e9587ba8b9cb`; M13.3 candidate `6e8b8015ab363b615728b2fed6b4bf49221a9337`, PR #81, exact-head CI run `38879714980`; merged/deployed executable SHA `a335162936afa2933e348fe7b04956e98e45c5a3`.

| Area | Status | Evidence / disposition |
|---|---|---|
| Technical SEO routes, metadata, robots and sitemap | PASS | M13.1 route tests; staging crawl of four HU/EN routes returned 200, localized title/description/H1/lang, canonical and reciprocal alternates. Staging pages are `noindex, nofollow`; staging sitemap is 404. |
| Structured data and AEO | PASS | Staging JSON-LD parsed on all four routes; home FAQ schema matches visible localized FAQ. Pricing schema contains no duplicated Offer price. Existing SEO feature tests pass. |
| Marketing claims | PASS | Staging `php artisan marketing:claims-audit` reported no review-pattern matches. This is a bounded copy review, not legal or technical certification. |
| Analytics event contract and PII protection | PASS | Nine fake-sink Node tests pass; allowlisted payload and no-PII contracts are covered. No external analytics requests during staging checks. |
| Consent and withdrawal | PASS | Staging denial, grant and withdrawal states persisted with version `technical-v1`; staging config `enabled=false`. Grant/withdraw generated zero Google requests because analytics is disabled. Policy wording remains external review. |
| Environment isolation | PASS | Staging config reports analytics disabled and unauthorized, with no measurement ID. Four public routes carried `data-enabled=false`; no Google tag/analytics request occurred. |
| Funnel events and billing authority | PASS | Client funnel contract is tested with fake transport. Existing checkout, catalogue and subscription suites pass. Stripe return is not used as payment authority; no checkout/charge was initiated during staging validation. |
| Pricing catalogue / checkout consistency | PASS | PricingCatalog and Checkout feature tests passed; existing SEO tests verify schema does not become a second price source. No price or billing code changed. |
| CWV laboratory baseline | PASS | Reproducible five-cold/five-warm sample matrix for each of four routes on desktop and throttled mobile is documented in `cwv-performance-baseline.md`. |
| Targeted performance fix | PASS | Mobile EN pricing cold CLS median improved from 0.21089 to 0.03220 (~84.7%) after narrow viewport stacking; exact merged SHA remeasured on staging. No broad speculative optimization was added. |
| Field CWV | M17_RECHECK | No Search Console/CrUX data or reliable field dataset was available. This is explicit; no lab value is represented as field CWV. |
| Staging integration and health | PASS | Exact SHA deployed through guarded script; checkout clean, migrations current, app/DB/queue/scheduler/Connector/Stripe TEST/backup/mail/privacy checks OK; workers RUNNING; failed jobs 0. Existing scanner warning is retained. |
| M12 destructive safety | PASS | Before and after deployment readback: global, profile, backup, provider, bulk and synthetic anonymization gates all false. |
| GA4 property / production stream | EXTERNAL_SETUP | No measurement ID is configured; analytics remains disabled and unauthorized. Owner must complete Google property/access/configuration separately before collection. |
| Search Console verification / submission | EXTERNAL_SETUP | Technical route/sitemap architecture exists; no Google account access or submission is claimed. |
| Consent purpose, policy and retention review | WAIT_LEGAL | Qualified review of notice, purpose and retention remains open. Explicit technical consent stays fail-closed; engineering does not infer legal requirements. |
| Security scanner / AppSec | M14_CARRY_FORWARD | Staging reports “No completed security scan yet.” Warning remains visible; general AppSec is outside M13.3. |
| Full CRO/accessibility polish | M16_CARRY_FORWARD | No broad UX redesign was undertaken. The narrow layout fix preserves controls and semantics; wider CRO/accessibility review remains M16. |
| Production crawl, field CWV and final release recheck | M17_RECHECK | Repeat on the exact production release SHA after launch setup; production deployment is not part of M13. |

No M13 engineering `MISSING` or `STALE` item remains in this matrix. External setup/review and later audit work are explicitly classified rather than hidden as engineering partials.
