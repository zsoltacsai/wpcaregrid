# M13.1 Technical SEO Foundation Report

**Status:** M13.1 technical implementation and staging validation complete. M13.2 analytics remains a separate reviewed phase. This report does not claim compliance certification or approve unsupported product claims.

## Release lineage

- Planning PR #76 merge: `8d3604099361bca074dfc46fd6bb7a857f588906`.
- Implementation baseline: `8d3604099361bca074dfc46fd6bb7a857f588906`.
- Implementation candidate: `1228ee3f0ed0e9b1ff6f31c438ea2ad3a480efa1`.
- Implementation PR #77: merged by GitHub Actions run `38862713926` (five required jobs passed).
- Implementation merge / executable staging SHA: `9431db502105b51102905aefe60faee2efbcc021`.
- Final primary at validation: `9431db502105b51102905aefe60faee2efbcc021`.
- Pre-deploy staging SHA: `19e31d7204a59e08211c4dd9e313b9cccbb01611`; checkout was clean.
- Guarded staging deployment used the repository deployment script, retained its protected pre-deploy SQL backup, restarted both workers, resumed the guarded scheduler, and passed route and health checks.

## Routes, indexing, and metadata

Four routes are intentionally indexable in production: `/hu/`, `/en/`, `/hu/pricing`, and `/en/pricing`. Each now renders a distinct localized title and description, one clear H1, locale-appropriate `html lang`, route-derived canonical, reciprocal HU/EN `hreflang`, `x-default` to the English counterpart, Open Graph title/description/URL/type/site/locale, and Twitter card/title/description. Canonicals are derived from configured application URL, not the request Host header. Untrusted or route-mismatched Owner canonical overrides are ignored.

An approved social image was not present. Image metadata is optional and constrained to the configured own host; no final artwork or social handle was invented. Pages therefore render a `summary` Twitter card and omit OG/Twitter image tags.

Staging was served in production application mode but remained non-indexable: all four pages returned HTTP 200 with `noindex, nofollow`; canonicals remained on the staging host; `/robots.txt` advertised no sitemap; `/sitemap.xml` returned 404. Production sitemap generation remains limited to the four canonical marketing URLs. Login returned noindex metadata; Owner/Operator and checkout unauthenticated paths returned noindex response headers/redirects; health and 404 responses were not sitemap entries.

## Structured data and pricing

JSON-LD uses safe structural serialization and an explicit entity builder. Rendered home pages contain `Organization`, `WebSite`, `WebPage`, and visible-content `FAQPage`; pricing pages contain `Organization`, `WebSite`, and `WebPage`. Staging rendered valid JSON-LD for HU and EN routes with the corresponding locale and canonical. No Offer/Product prices, ratings, reviews, availability, private business identifiers, or search action were emitted. The current pricing page exposes multiple products/intervals and configured commercial values; omitting Offer markup avoids encoding ambiguous tax/interval semantics or a second price source. Visible pricing and checkout continue to use the shared server-side pricing catalogue, covered by the existing catalogue/checkout tests.

## Content and claims

Homepage and pricing metadata/copy now state the current maintenance scope more clearly in HU and EN, distinguish plan-specific WooCommerce coverage, and qualify separate storage, backup verification, update recovery, and activity-history statements. They do not claim guaranteed restore, universal one-click recovery, GDPR compliance, permanent physical deletion, complete malware protection, or autonomous AI remediation. The future GFS backup schedules remain unpublished as current entitlements.

The read-only staging `marketing:claims-audit` on this SHA reported no review-pattern matches in built-in or currently published marketing content. The command reports bounded candidate patterns and does not establish legal or technical approval. The existing “No completed security scan yet” warning remains visible as an M14 residual.

## Tests and release checks

Local validation:

- Focused SEO/indexing suite: 14 tests, 281 assertions, passed.
- Broader route/localization/pricing/checkout focused set: 168 tests, 7,969 assertions, passed.
- Frontend production build, Pint (938 PHP files plus changed tests), Composer validation/audit, npm audit, and `git diff --check` passed.

Exact candidate CI run `38862713926` passed all five required jobs:

- Server SQLite full suite: 1,291 tests, 18,639 assertions, 19 documented skips.
- Server MySQL full suite: 1,291 tests, 18,837 assertions.
- MySQL process/concurrency tests: 15 tests across required process-gate invocations, 155 assertions.
- Connector SQLite: 202 tests, 1,401 assertions, 24 documented skips.
- Connector MySQL: 202 tests, 1,775 assertions, 3 documented skips.
- Quality/security/build passed, including PHP syntax, Pint, dependency advisories, frontend build and secret scan.

The exact deployed SHA passed the guarded deployment smoke checks. Post-deploy `system:check` was OK for application, database, queue, scheduler, Connector API, Stripe, Cloudflare, backup storage, mail, safety, privacy retention, restore safety, and privacy exports. Both workers were RUNNING. The security scanner remains WARNING: no completed scan yet. The staging checkout was clean. The six privacy/destructive configuration values remained false, including `M12_DESTRUCTIVE_RETENTION_ENABLED` and normal-staging synthetic profile anonymization.

## Residuals and boundary

- No approved social artwork exists; image tags are safely omitted until supplied.
- M13.1 did not implement analytics, consent, Search Console account setup, CWV optimization, or new feature landing pages.
- Staging crawl validation proves staging output and its noindex posture; it does not prove Google indexing or production-host output.
- M12 `WAIT_LEGAL`, `WAIT_ACCOUNTING`, and `WAIT_FACT` remain unresolved. No legal, accounting, or provider conclusion is inferred.
- Production destructive execution remains disabled. The M14 security scan warning remains visible.

M13.1 technical SEO foundation is ready for closure. M13.2 may begin only after this phase is reviewed. This report does not certify GDPR compliance.
