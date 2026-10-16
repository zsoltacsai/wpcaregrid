# M13 Final Engineering Closure

**Verdict: A — M13 ENGINEERING CLOSED, EXTERNAL GOOGLE / LEGAL / PRODUCTION SETUP REMAINS**

M13 engineering delivered technical SEO/AEO, localized metadata, the consent-aware analytics and funnel foundation, and an evidence-based performance baseline with a targeted mobile CLS correction. This closure does not certify legal compliance, complete Google setup, public launch readiness, or field Core Web Vitals.

## Release lineage

- M13.2 baseline / planning closure primary: `b72a705619ddf5ad3045ae486959e9587ba8b9cb`.
- M13.3 branch: `codex/m13-performance-final-closure`.
- M13.3 candidate: `6e8b8015ab363b615728b2fed6b4bf49221a9337`; implementation PR #81.
- Exact-head GitHub Actions run: `38879714980`, all five mandatory jobs passed, including the required MySQL process/concurrency gate.
- Implementation merge SHA and deployed executable staging SHA: `a335162936afa2933e348fe7b04956e98e45c5a3`.
- Planning and M13.1/M13.2 engineering closure lineage is documented in [M13.1 report](m13-1-technical-seo-report.md) and [M13.2 report](m13-2-analytics-consent-report.md).
- Final report-only closure PR/CI/merge SHA: to be recorded by the closure PR itself.

## M13.3 implementation and performance result

The pre-change staging baseline was executable SHA `cc8acede6faea076b14a264dccabca75df15a76e`. Reproducible Edge 155.0.4283.45 / Playwright lab runs covered HU/EN home and pricing, five cold and five warm samples, desktop 1365×900 unthrottled and mobile 390×844 at 150 ms RTT, 1.6 Mbps down and 4× CPU slowdown. The methodology and before/after medians are in [CWV baseline](cwv-performance-baseline.md).

The material issue was mobile English pricing control-row reflow: pre-change cold CLS median 0.21089. A targeted responsive stack in `resources/views/components/pricing/table.blade.php` reduced the post-deploy cold median to 0.03220 (range 0.03220–0.03544), approximately 84.7% lower, across five runs on the deployed SHA. A regression test protects the narrow layout and the two billing periods. Desktop lab CLS remained small; there was no evidence justifying a broad JS, font or image rewrite.

No reliable field Core Web Vitals baseline is available yet. TBT was not measured using Lighthouse traces and INP is not available as field evidence. Browser long-task totals are not substituted for either. Staging route samples recorded zero failed requests and zero desktop external hosts. Post-build bundles were 62.59 KB JS (21.60 KB gzip) and 59.11 KB CSS (11.28 KB gzip).

## Integrated marketing validation

- Four staging routes (`/hu/`, `/en/`, `/hu/pricing`, `/en/pricing`) returned HTTP 200, with localized title, description, H1 and `lang`; canonical and reciprocal HU/EN alternates were correct for the staging host. All four showed `noindex, nofollow`. `/robots.txt` had no sitemap directive; `/sitemap.xml` returned 404. Production indexing behavior remains covered by M13.1 tests, not asserted from staging.
- All four rendered JSON-LD payloads parsed and used their route locale/canonical. Home schema included visible localized FAQ; pricing did not contain duplicate price Offers. No fake review/rating/availability was emitted.
- Staging `marketing:claims-audit` found no bounded high-risk pattern matches in built-in or published marketing content. It is a human-review aid, not approval certification.
- Consent controls stored DENIED, GRANTED and WITHDRAWN states with `technical-v1`. Staging analytics config remained disabled/unauthorized and the Measurement ID was absent. Browser requests to Google Tag Manager/Analytics were zero, including after a grant then withdrawal attempt in staging.
- Funnel events remain bounded client intent events under the M13.2 fake-sink contract. Checkout/payment authority remains server/webhook-driven; no real checkout, charge, billing mutation or production analytics was triggered.
- M13.1/M13.2 route, schema, claim and pricing architecture remained unchanged apart from the responsive pricing presentation; no legal copy was published or altered.

## Validation and CI

Focused local M13 regression: **154 passed, 1,231 assertions, 3 skips**. Skips were MySQL-only process/concurrency cases under local SQLite; they ran in the required MySQL CI gate. Local Pint test, PHP syntax, Windows frontend production build, Node analytics suite (**9 passed**) and `git diff --check` passed. A first WSL build attempt could not load a Linux Rolldown native binding from Windows-installed dependencies; the repository’s native Windows Node build then passed without dependency/lockfile changes.

Exact candidate GitHub Actions run `38879714980`:

| Required gate | Result |
|---|---|
| Quality/security/build | PASS; frontend build, dependency checks, analytics tests, secret scan passed; npm audit found 0 vulnerabilities |
| Server SQLite | 1,297 tests, 18,673 assertions, 19 documented environment skips; PASS |
| Server MySQL | 1,297 tests, 18,871 assertions, no skips; PASS |
| Required MySQL process/concurrency gate | PASS; all constituent process tests passed |
| Connector SQLite | 202 tests, 1,401 assertions, 24 database-specific skips; PASS |
| Connector MySQL | 202 tests, 1,775 assertions, 3 documented skips; PASS |

The local focused suite and CI suites overlap; their totals are presented separately and are not added together as unique test counts.

## Guarded staging deployment and post-check

Strict SSH host-key validation used the approved Ed25519 fingerprint `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`. The guarded deploy script pinned pre-deploy baseline `cc8acede…`, created protected SQL backup `/home/deploy/private-backups/wpcaregrid-pre-deploy-20261016T093341Z.sql.gz`, found no migrations pending, deployed exact SHA `a335162…`, rebuilt production dependencies/assets/caches, restarted both workers, resumed the scheduler and completed HTTP/security smoke checks. The script is invoked through `bash` because the checked-in file lacks executable mode; its own guarded sequence ran to completion.

Post-deploy checkout is clean at `a335162…`. System check reports application, database, queue, scheduler, Connector, Stripe TEST, Cloudflare, backup storage, mail, safety, privacy retention, restore safety and privacy exports OK; failed jobs 0; both workers RUNNING. The existing security scanner warning (“No completed security scan yet.”) remains visible and is carried to M14.

Before and after deployment, readback showed all privacy destructive gates false: global retention, profile anonymization, backup deletion, provider deletion, bulk execution and normal-staging synthetic profile anonymization. Marketing analytics enabled/authorized is false. No destructive M12 behavior was exercised or changed.

## Remaining external and future work

- `EXTERNAL_SETUP`: GA4 property/stream, authorized access and production Measurement ID; collection remains disabled until separately configured and authorized.
- `EXTERNAL_SETUP`: Google Search Console property verification and sitemap submission; no Google account action is claimed complete.
- `WAIT_LEGAL`: review analytics purpose, consent wording/notices and analytics retention. Engineering makes no legal determination.
- `M14`: complete security/AppSec scan and hosting compatibility audit; preserve the visible scanner warning.
- `M15`: production DR/restore, billing/subscription/invoice, technical privacy controls and operational-safety audits.
- `M16`: full CRO/content polish and pricing presentation changes for future backup entitlements; no future GFS schedule is represented as current behavior.
- `M17`: exact production-SHA crawl, analytics IDs, Search Console submission, public-host CWV/CrUX recheck and final production readiness audit.
- M12 `WAIT_LEGAL`, `WAIT_ACCOUNTING`, and `WAIT_FACT` remain as applicable. M12 destructive retention remains disabled.

M13 engineering is closed. **M13 engineering closure does not mean GDPR compliance certification.** No production destructive functionality was enabled. M14 may begin only after this report-only closure is merged.
