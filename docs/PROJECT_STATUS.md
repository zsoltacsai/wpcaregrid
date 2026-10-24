# WP CareGrid — Project Status

## M14 final audit execution — 2026-10-24

Latest primary merge SHA `5ea05192581e5ca98945669be6aa2cfbcff7bd86` was
verified with [exact merge CI run 40101414072](https://github.com/zsoltacsai/wpcaregrid/actions/runs/40101414072):
30/30 jobs succeeded, including the Server MySQL process-concurrency gate.
The Owner-approved Integrity scan on `kiszov-szeged.hu` is complete with
Connector job 48 terminal success; exact identity, counters, M12 evidence,
side-effect limitations and the 11-row M14/M15/M17 disposition are recorded in
[`reports/m14/m14-final-audit-2026-10-24.md`](../reports/m14/m14-final-audit-2026-10-24.md).

The audit found that the Operator scan history omitted missing, extra and
unavailable Integrity coverage counts. A focused Blade/localization change and
rendering regressions (including absent-count behavior) are prepared on
isolated branch `codex/m14-final-audit`.
Because this changes runtime UI, M14 remains **BLOCKED pending exact-head CI,
protected merge and protected staging deployment/read-only smoke for that
change**. The prior successful scan is not being repeated. The historic
notification delta and exact paths behind one missing/four unavailable items
remain unknown and are recorded without a zero-side-effect or complete-coverage
claim.

> Kept up to date at the end of every milestone (see CLAUDE.md §27).
> A new session should read CLAUDE.md, this file and the relevant docs, then run `git status` and `git log --oneline -12`.

Last updated: 2026-10-24

## M14.7/M14.8 latest evidence — Owner-approved real-site Integrity acceptance (2026-10-24)

Primary branch `claude/ecstatic-wozniak-oemyuf` and protected staging were verified at merge SHA `8262bb60dccf5bd8681e4a97ac1d8cd56128d603`. Exact-SHA Actions run [40092077613](https://github.com/zsoltacsai/wpcaregrid/actions/runs/40092077613) passed 30/30 jobs, including the MySQL process/concurrency gate. The protected deployment receipt and runtime SHA matched this release. The Owner-approved read-only M12 readback [40095400109](https://github.com/zsoltacsai/wpcaregrid/actions/runs/40095400109) reported all six privacy gates `FALSE`, staging identity `stg1.wpcaregrid.com` / `https://staging.wpcaregrid.com`, and global `security.incidents.auto_open=TRUE` plus `security.incidents.auto_backup=TRUE`; neither global setting was changed.

The Owner approved the existing registered, subscribed `https://kiszov-szeged.hu` site as a real, production-like target for a bounded Integrity scan and formally replaced the requirement to provision another disposable external WordPress site solely for this operational scan criterion. The completed scan is `01a16dfa-afed-735f-a4fd-2eafcd0a8a3d`; Website `01a16409-3537-7061-993d-a18fccd5b945`; active Connector `01a16533-b4a5-7271-89f7-c032bae5b643`, version `0.4.7`; WordPress `7.1.3`; customer-hosted PHP `8.2.35`; Connector job 48 completed once with `SCAN_COMPLETED`. Coverage: 5,930 verified, 0 modified, 0 extra, 1 missing, 4 unavailable; 0 findings. The missing/unavailable items remain a coverage limitation; the result is not a Malware scan or a malware-free claim. Remediation was disabled. The Owner-reported scan window had no incident or backup; a complete notification-channel delta was not retained and remains unverified. No global incident/backup toggle was changed.

The exact scan and the revised acceptance route are recorded in [the real-site acceptance report](../reports/m14/owner-real-site-integrity-acceptance-2026-10-24.md), [the decision register](../reports/m14/owner-questionnaire.md), and [the M14 closure criteria](../reports/m14/m14-closure-criteria.md). The old `NO COMPLETED STAGING SCAN` claim is superseded for this operational Integrity-execution criterion only. The real site remains an ordinary subscribed Website; synthetic fixture policy and entitlements were not applied to it.

**Milestone status: M14 BLOCKED — NOT CLOSED.** The app-security and tenant-isolation audit documents still classify portions of their scope as `PARTIAL`; the complete supply-chain, compatibility, Connector/archive, operational side-effect, and backup-entitlement audit dispositions have not been reconciled in this closeout. The coverage exceptions need triage, notification deltas are incomplete, and the independent-review residual remains open. M14.8 is not ready until these mandatory audit and risk-disposition records are completed. The scan closes the operational Integrity execution evidence gap, not all M14.7/M14.8 requirements. No further live scan is warranted solely for acceptance-path identity.

## Historical M14.7 status snapshot — Owner-reported staging evidence (2026-10-19; superseded above)

Authoritative primary is `5e1e8f68951b2925e57dd406a9b3fcd1a7923799`; merged-primary run [39255687374](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39255687374) passed all seven required jobs, including the explicit Server MySQL process-concurrency gate. PR #131 integrated the M14.7 recovery candidate; PRs #129/#130 are superseded historical candidates and are not to be recreated or remerged.

The Owner reports that a Rackforest-console read-only session on 2026-10-19 observed staging `stg1.wpcaregrid.com`, checkout `/var/www/wpcaregrid`, non-root `deploy` (UID 1000), clean worktree, Laravel Git HEAD `be427a17320ca7aba9ec292d2c288813df5f7c07`, PHP CLI `8.4.26`, and `70,385,975,296` free bytes. The Owner also reports running the merged audit runner whose repository SHA-256 is `7ba37058f137f65294fb0082f83d1d9418ad9e15fd74e40da7cdb2b6f022a73e`. Codex did not access staging. The complete JSON, audit ID, and exact execution time are not archived; the disk figure has not been reconciled against the complete original JSON. Treat these as Owner-provided observations, not independently verified Codex results.

In a separately Owner-authorized read-only Laravel configuration inspection, the Owner reports that `runuser -u deploy -- timeout 20s php /var/www/wpcaregrid/artisan config:show privacy --no-interaction` displayed all six M12 gates as `false`. This is an effective-configuration readback for the reported staging state at that session, not repository-default evidence and not a promise about a later deployment/configuration. The date is known; the exact UTC time and complete captured output are not archived. The earlier `UNKNOWN` results below are preserved as historical and superseded only for this reported observation. M147-F02 remains open pending auditable evidence retention and fresh pre-scan verification.

Staging is 104 commits behind primary (`be427a…` is an ancestor of `5e1e8f…`). The intervening changes include feed download/import bounds and atomic index handling, incomplete-coverage posture logic, scanner evidence changes, and the audit/evidence/test harness. No Laravel migration changed in this interval, and no tracked Connector runtime source file changed (the WordPress manifest/lock and tests did change); the installed staging Connector fingerprint/pairing is still unknown. The current staging scanner therefore does not validate the merged-primary scanner behavior. No deployment or scan was performed.

M147-A/B/C/D remain closed within their recorded scopes; M147-E.1 remains planning-only/closed. E.2a has Owner-reported identity and M12 evidence but incomplete archival; E.2b remains **NO-GO / NOT AUTHORIZED** and no scanner ran. AS-16/M147-F01, M147-F02 through F06, M147-F08, and CI-REL-01 remain open at their stated scopes. M147-F07's previously bounded synthetic closure is reopened for a newly reproduced failed-publication path; a narrow fix and regression test are prepared below, pending exact-head CI/review. M147-C-02 and M146-F08 retain their bounded prior closures. M147-F remains not started. M14.7 remains incomplete, approximately 65% by delivery estimate; M14.8 is **NOT READY**.

Merged-primary local synthetic checks on 2026-10-19 also passed: scanner/posture feature suite after the narrow fix, 15 tests / 170 assertions; full Laravel Security feature directory, 103 tests / 1,783 assertions; staging audit runner suite, 8 tests / 55 assertions; M147-A Node safety suite, 11/11; and guarded selected browser E2E, 14/14 on a tree identical to primary. These do not cover live feed provenance/SSRF variants or operational side effects. The exact CI run's Connector MariaDB passed; CI-REL-01 remains OPEN because no new MariaDB root-cause/fault-injection evidence was established in this pass. Exact-head CI for the unmerged fix is pending.

## Historical M14.7 closure recovery — expanded local evidence snapshot (2026-10-19; superseded below)

This was the then-current candidate snapshot before PR #131 merged. It is retained without rewriting its earlier conclusions.

The current unmerged implementation/evidence candidate is PR [#130](https://github.com/zsoltacsai/wpcaregrid/pull/130), branch `codex/m147-closure-recovery`. Its last code-change commit is `544333f0629c50ac829315698b4a51887563d124`; documentation reconciliation is at `ecfae2b4d65c998f7220c8b2957ac374833e9793`. The protected primary remains `9e11c2035cd343b8cc1615fee4dbcf4a34c16cd9`; PRs #129 and #130 remain open and unmerged. Local isolated Playwright ran the complete selected security-relevant matrix on `5c350b759bed438eb8d967dd329a944a53b91a74`: **14/14 passed**. The separate Laravel Security feature suite passed **102 tests / 1,778 assertions / 0 skips**. The earlier full Laravel strict-MySQL suite passed **1,411 tests / 20,776 assertions / 0 skips**; the supported local Connector suites and prior platform-specific failures are reconciled in the [recovery evidence](../reports/m14-security/m14-7-closure-recovery-evidence.md). These results do not establish staging or live scanner behavior.

Exact-head CI [39233009685](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39233009685) on code-change commit `544333f0629c50ac829315698b4a51887563d124` passed **7/7**. After the documentation reconciliation commit `ecfae2b4d65c998f7220c8b2957ac374833e9793`, exact-head CI [39234984289](https://github.com/zsoltacai/wpcaregrid/actions/runs/39234984289) also passed **7/7**. The required Server MySQL process/concurrency gate passed in both runs, including all nine required files. Connector WordPress MySQL and MariaDB each passed 210 tests / 1,828 assertions / 3 documented skips. The earlier JsonException was a confirmed test-harness JSON signal publication race fixed by atomic temp-file/rename; the formerly failing lock-succession test passed on both engines. **CI-REL-01 remains OPEN pending Owner review/protected merge**, while the identified harness defect is fixed and exact-head validated. The full Stripe-hosted payment browser flows remain unrun because isolated Stripe TEST, public webhook and Mailpit infrastructure was not available; M147-F05 remains OPEN.

Historical run [39230906190](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39230906190) on `512412eba05ef0e521c3365c89e34ab934615c39` completed **6/7**: Connector WordPress MySQL failed in the lock-succession test with `JsonException` after the child observed an incomplete JSON signal; Connector MariaDB passed. Source review confirmed a test-harness publication race (non-atomic final-path write observed by an existence-based reader). The subsequent atomic temp-file/rename correction was validated by the current 7/7 exact-head run above; the 20-second deadline and lock assertions were not weakened. The Server MySQL suite and mandatory process/concurrency gate passed on run 39230906190 as well.

All six live M12 gate values remain **UNKNOWN**. No staging, SSH, Rackforest console, production, customer site, live feed, scanner, queue, external notification, backup/restore, remediation, deployment, Stripe or AI operation occurred. M147-E.2b remains **NO-GO / NOT AUTHORIZED**. AS-16/M147-F01, M147-F02 through F06, M147-F08 and CI-REL-01 retain their open dispositions; no finding is closed by this candidate. M14.7 is **incomplete**, approximately **65%** by delivery estimate, and M14.8 is **NOT READY**.

## M14.7 closure recovery — local engineering candidate (2026-10-19)

Authoritative primary was reverified at `9e11c2035cd343b8cc1615fee4dbcf4a34c16cd9`. PR #129 remains open and unmerged at `0c51d0966bc083c48bbba49ae3314e7968ec2b18`; its exact-head run [39170388169](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39170388169) passed 7/7, including the Server MySQL process/concurrency gate. That CI evidence applies to PR #129 only. The separate `codex/m147-closure-recovery` branch is an implementation/evidence candidate; it is not merged and has no exact-head CI result until its PR is created and checks complete.

This candidate adds a manually invoked, standalone, fail-closed staging identity audit with an M12 mode that returns `UNKNOWN` rather than reading live configuration; it improves the exact-commit-guarded local Connector browser harness; and it adds bounded, redacted diagnostics/worker cleanup to the Connector process-concurrency test. The isolated Connector browser suite passed 3/3 on commit `510aeac849c022786978f6d3c87384dc557afdcc`. This is not staging validation. CI-REL-01 remains open because no local MariaDB process/concurrency reproduction was possible and the timeout root cause remains unproven. See the [recovery evidence](../reports/m14-security/m14-7-closure-recovery-evidence.md).

All six live M12 gates remain **UNKNOWN** and were not read. M147-E.2b remains **NO-GO / NOT AUTHORIZED**; M147-F remains not started, and M14.8 remains not ready. No SSH, staging, production, customer-site, scanner, feed sync, queue, backup, restore, remediation, deployment, or live external service operation occurred. M147-A/B/C/D remain closed within their documented scopes; M147-E.1 remains planning-only. M14.7 remains incomplete (approximately 65% estimate, not a security score). No finding is closed by this candidate.

## Current milestone — M147-E.2a RO-00 blocked; no authenticated access

**Update (2026-10-18):** Owner authorized only RO-00–RO-04 within 18:00–18:30 Europe/Budapest. RO-00 was attempted during that window using unauthenticated SSH key scanning to the approved endpoint. The local OpenSSH client failed KEX negotiation (`sntrup761x25519-sha512@openssh.com` unsupported); its compatibility option was also unsupported. No server host key/fingerprint was obtained or compared, and no authentication or remote command occurred. Per the stop rule, RO-00 is BLOCKED and RO-01–RO-04 were NOT EXECUTED. This was an unauthenticated SSH protocol contact, not authenticated staging access. All six live M12 values and all staging application/runtime state remain UNKNOWN. M147-E.2b remains NO-GO / NOT AUTHORIZED. No scanner, feed, queue, backup, restore, remediation, quarantine, deployment, or configuration operation occurred. See the [E.2a evidence](../reports/m14-security/m14-7-e2a-staging-preflight-evidence.md).

## Current milestone — M147-E.2a preflight blocked before connection

**Current status (2026-10-18):** M147-A/B/C/D remain CLOSED within their documented scopes. M147-E.1 is CLOSED — PLANNING ONLY. An E.2a execution-record draft was supplied but is explicitly NOT APPROVED FOR EXECUTION; it lacks the trusted host identity pin, active time window, authorization reference/final approval, and exact reviewed command list. The mandatory stop rule was applied. **NO LIVE STAGING ACCESS PERFORMED.** All staging identity/runtime/configuration facts and all six live M12 gates remain UNKNOWN. Read-only preflight result: BLOCKED; M147-E.2b: NO-GO / NOT AUTHORIZED. No scan, feed request, queue action, incident, notification, backup, restore, remediation, quarantine, deployment or configuration change occurred. Details: [E.2a preflight evidence](../reports/m14-security/m14-7-e2a-staging-preflight-evidence.md). M147-F remains NOT STARTED; M14.8 remains NOT READY.

The previous E.1 status summary below records the state at that planning closure and is retained as historical evidence; this section is the latest status.

## Historical milestone — M14.7 M147-E.1 closed (planning only)

**Current status (2026-10-18):** M147-A/B/C/D are closed within their documented scopes. M147-E.1 is **CLOSED — PLANNING ONLY** by PR #127, merged at `f0bdbf5ad4c06160613fc5283a779707da28aea0`; exact merged-primary CI run [39131255646](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39131255646) passed all seven required jobs, including the Server MySQL process/concurrency gate. This closure does not validate staging. M147-E.2 is **NOT AUTHORIZED / NOT EXECUTED**, M147-F is NOT STARTED, and M14.8 is NOT READY. All six live M12 gates remain UNKNOWN and were not read or changed. Source review confirms scan completion can write scan/finding/audit/posture state and can open an incident and request a full backup for qualifying findings; M147-F04 remains open. See the [E.1 readiness plan](../reports/m14-security/m14-7-e1-readiness-plan.md), [runbook proposal](../reports/m14-security/m14-7-e1-staging-runbook.md), [risk matrix](../reports/m14-security/m14-7-e1-risk-matrix.md), [Owner checklist](../reports/m14-security/m14-7-e1-owner-approval-checklist.md), [E.1 closure report](../reports/m14-security/m14-7-e1-closure-report.md), and [M147-D closure report](../reports/m14-security/m14-7-d-closure-report.md).

M14.7 estimated implementation progress remains approximately **65%**; this is a delivery estimate, not a security score. The E.1 planning work does not count as operational scanner validation.

The following project snapshot predates the current M14 milestones and is retained as historical context; its staging, branch, and local-work status statements are not current release evidence.

## Historical local milestone — Cloudflare DNS Owner integration

Implementation is in progress on `claude/ecstatic-wozniak-oemyuf` at the unchanged base `dc1409e9d109118d8f5be560c0a346e4eb504bac`. Adds encrypted Owner Integration token mapping, exact-zone allowlisting, a read-only-capable Cloudflare API adapter, validated/idempotent record planning, explicit preview/apply, protected-record safeguards, audit events, Owner UI and mocked tests. **No staging deployment or Cloudflare API call has been made, and no DNS has been changed.** After adversarial review, validation used an isolated checkout and disposable MySQL schema: 784 tests / 12,441 assertions / 6 skipped; 44 focused Cloudflare/security tests / 145 assertions; Pint 554 files; PHP syntax 22 changed/new files. The frontend build, whitespace check and local secret-pattern scan also pass. Gitleaks is unavailable in the local/isolated environment. Keep `.claude/` untouched and uncommitted.

## Historical branch and HEAD snapshot

- Branch: `claude/ecstatic-wozniak-oemyuf` (also the main branch used for PRs)
- Latest commit: **Add owner coupons and custom offers** (local, not pushed; `git log --oneline -3` shows its hash)
- Before it (pushed, CI green): `321bda1` **Upgrade GitHub Actions runtimes**, `c5b60ab` status update, `8afa9d6` **Add bilingual frontend and customer portal**
- Before it (pushed, CI green): `f1f172d` **Fix login configuration**, `6e159e5` **Complete Hungarian public content**, `5231eeb` **Staging deployment preparation**, `7d6327c` status update, `836316f` MySQL test fix, `eeb1ada` **Complete Stripe product validation**, `0b54777` **Real Stripe Test Mode Validation**
- Earlier: `9171262` status update, `3f01f5d` CI configuration fix, `050afe0` **Production Hardening, CI & Release Readiness**
- Earlier: `2f7d0b4` Owner Admin & Content Management, `4076684` Security & Malware Engine, `ba2d1db` Internal Operator Console
- Everything up to `321bda1` is pushed to `origin` (github.com/zsoltacsai/wpcaregrid); the newest commit is local until a push is requested; never force.
- Separate small fix, committed on its own as **Fix login configuration** (after the Hungarian content commit): empty `MARKETING_*_URL` values (as shipped in the old `.env.example`) turned the header and footer Login link into a dead link; `config/marketing.php` now treats empty as unset. Contact, Terms, Privacy and Cookie pages are still not built, so those links point at their default paths and answer 404.

## Completed milestones

| Milestone | Commit | Docs |
| --- | --- | --- |
| Public homepage, pricing engine | earlier | README.md |
| Stripe Checkout MVP, customer accounts, subscription lifecycle | earlier | README.md |
| Customer onboarding, WordPress Connector | earlier | docs/CONNECTOR.md |
| Backup Engine | `7ed8ade` | docs/BACKUP_ENGINE.md |
| Secure WordPress Admin Access | `cac19b1`, `d4dcda0` | docs/ADMIN_ACCESS.md |
| Internal Operator Console | `ba2d1db` | docs/OPERATOR_CONSOLE.md |
| Unified Site Health + Security & Malware Engine | `4076684` | docs/SECURITY_ENGINE.md, MALWARE_SCANNING.md, SECURITY_FINDINGS.md, SECURITY_INCIDENTS.md |
| Owner Admin & Content Management | `2f7d0b4` | docs/OWNER_ADMIN.md, CONTENT_MANAGEMENT.md, PRICING_ADMIN.md |

## Owner commercial operations — COMPLETE: coupons, PROBONO, custom recovery offers

Done 2026-10-05. Descriptions: docs/COUPONS.md, docs/PROBONO.md, docs/RECOVERY_OFFERS.md.

- **Coupons** (Owner → Coupons, ability `owner.coupons.manage`): create, edit rules, activate / deactivate, delete if unused. One coupon engine: the existing `promotions` table and `Promotion::ineligibilityReason()`; checkout, redemption counting and the webhook are unchanged. The owner area creates the Stripe coupon and promotion code through `PromotionGateway` (`StripePromotionGateway`; fake in tests). Percentage: above 0, up to 100, two decimals, parsed from digits (0 is refused: Stripe cannot create it and it would change nothing). Code, percentage and duration are fixed after creation (a Stripe coupon is immutable); validity, limits (total and per customer), restrictions (product, plan, period), note and active are editable. Audit: created, updated, activated, deactivated, deleted, redeemed.
- **100% discount**: an ordinary coupon. Verified against real Stripe test mode: total exactly 0, never negative; `payment_method_collection = if_required` only for 100% **forever** (then the hosted page asks for no card); 100% once / for some months still collects a payment method because the subscription is charged afterwards. Stripe reports `no_payment_required`, which the webhook already treated as paid; provisioning happens once, replays change nothing, a `$0` invoice sends no payment email. Small isolated changes: `StripeCheckoutGateway`, `InvoiceEvents`.
- **PROBONO** (Owner → Customer → Internal plan, ability `owner.plans.assign`): `InternalPlan` enum, deliberately not a `PlanTier` (so it cannot reach the catalogue, pricing page, checkout or Stripe). A normal `subscriptions` row with `internal_plan`, no Stripe subscription, no public tier, no price (those columns became nullable), optional start and end dates (`internal-plans:sync` hourly). **Includes**: updates, health, licence checks. **Excludes**: backups of any kind (scheduled, manual, pre-update, retention, restore), scheduled security scans, customer WordPress admin access. Backups are refused in `BackupPolicy` (`BACKUPS_NOT_INCLUDED`, decided before any config) and again in `JobQueue::refusal` at queue and delivery time.
- **Recovery offers** (Owner → Recovery offers, ability `owner.offers.manage`): `recovery_offers` table; one customer, one website of that customer; stored price and currency (HUF only); states draft, offered, accepted, declined, expired, paid, cancelled, completed. One-time Stripe Checkout (`mode = payment`, inline line item, no Stripe Price), paid only by the signed webhook (`RecoveryOffers::markPaid`: session, amount and currency must match the stored offer), idempotent. Customer portal: Offers list and page (accept, decline, pay), English and Hungarian. First localized customer emails (`RecoveryOfferSent`, `RecoveryOfferPaid`, in the customer's saved language, once each).
- **Migrations** (three, rolled back and forward on SQLite, on a copy of the real development database with data, and on MySQL 8.4): `add_owner_management_to_promotions`, `add_internal_plans_to_subscriptions`, `create_recovery_offers_table`.
- **Tests**: `CouponManagementTest` (18), `ZeroChargeCheckoutTest` (5), `ProbonoPlanTest` (22), `RecoveryOffersTest` (30). Server suite 726 tests on SQLite and on MySQL 8.4 (719 pass, the same 7 local skips as before, no failures). Real Stripe test mode: API level (21 checks) and browser level (`tests/e2e/owner-commercial.spec.ts`, 8 Playwright tests: public PROBONO absence, owner coupons, a 10% purchase, a 100% forever purchase without a card, PROBONO assignment, a recovery offer paid in English and in Hungarian, 375px mobile layouts): all passed. Results in docs/STRIPE_TEST_MODE.md.
- **Found and fixed while building it**: the Eloquent guardable-column cache is per process, so a MySQL test database that predates a migration silently drops the new attributes (recreate the database; CI always starts empty); the owner coupon actions share the Stripe-bound rate limit, which locked the owner out after five typos (own limiter `owner-coupons`, 10/min); Stripe's zero-total hosted page has no Link checkbox, which the E2E helper waited for.
- **Not done / limits**: no invoice for coupon or offer sales (invoice provider milestone); offers are HUF only; the older customer emails are still English; usage limits are counted at confirmation (two simultaneous checkouts can both pass a limit of 1); no refund flow for offers.

## Bilingual frontend and customer portal — COMPLETE (English and Hungarian)

Done 2026-10-05. Full description: docs/LOCALIZATION.md.

- **Runtime language**: `config/localization.php` (`hu`, `en`; `APP_LOCALE` is only the default, read once so `App::setLocale()` cannot leak into the next request). `SetLocale` middleware: owner / operator / staff paths always English; else the cookie `caregrid_locale` (also carries a pre-login choice into the session), else the signed-in customer's saved preference, else the default; everything validated against the supported list. `POST /locale` (CSRF, throttled) accepts only a supported code; an invalid one is refused and changes nothing.
- **Selector**: Magyar / English in the public header (desktop and mobile menu) and the customer sidebar; a plain server-side form, works without JavaScript; with JavaScript, typed form values (never passwords) come back after a switch (login, activation, reset, checkout, profile, add website). `<html lang>` and `Content-Language` follow the language; unknown addresses (404) too. No `hreflang` (one address per page).
- **Currency**: `CurrencySuggestion`, `DisplayCurrency` + `PricingCatalog`. The fixed catalogue contains HUF/EUR/USD/GBP prices for every plan and interval, with annual prices at 10× monthly and no FX conversion. Browser locale only suggests the display currency; language and currency preferences remain independent. Checkout requires HUF for HU billing and EUR/USD/GBP otherwise, resolves amount and Stripe Price ID on the server, and ignores browser amounts, Price IDs, and market values. Existing subscriptions retain their actual Stripe Price, amount, and currency.
- **Preference**: `users.locale` (nullable, one small migration, rolled back and forward on SQLite and MySQL 8.4). Written only on the signed-in customer's own login; no id is ever read from a request; owner and operator logins store nothing.
- **Translated** (Hungarian, exact keys of the English files): `account`, `backups`, `admin_access`, `security`, `site_health`, `connector` (customer login and portal), plus Laravel's `validation`, `auth`, `passwords`, `pagination` and `lang/hu.json` (404, 403, 419, 429 and other default pages). Customer login, activation (including invalid and used links), reset, the portal (dashboard, websites, onboarding, connection, backups, restore, admin access, security, subscription, billing, profile) and its states.
- **Not translated, on purpose**: Owner Admin, Operator Console, staff invitations (English by design); **emails** (activation, welcome, payment, subscription): they use `emails.php`, and are sent by webhooks where no language exists. Translating them needs the language stored at checkout; a small separate milestone. The restore and WordPress-admin confirmation forms do not carry typed text across a switch (a conscious confirmation should not be restored).
- CI run #9 (`8afa9d6`, https://github.com/zsoltacsai/wpcaregrid/actions/runs/37238619476): all 5 jobs green; server SQLite and MySQL 8.4: 651 tests each, 0 skips (real S3 and SFTP); connector 169 tests each (5 and 1 skips as before); gitleaks clean, audits clean, build clean.
- Tests: `CustomerLocalizationTest` (35): languages, selector, refusal of unsupported codes, tampered cookie, foreign return address, form input, staff areas English, HU → HUF, EN currencies (a euro price fixture proves the selector appears only when prices exist and never in Hungarian), forged currency / amount / Price ID / market, subscription amounts unchanged, prices in both languages, login and auth messages, activation and reset, error pages, every portal page in both languages with no English sentence on the Hungarian page, saved preference and tenant isolation, the default does not leak between requests. Playwright (`tests/e2e/localization.spec.ts`, 8) against the local app: public pages, pricing, checkout, login, 404, mobile selector with the keyboard, customer area and a new browser keeping the saved language. Mutation-checked.
- Found and fixed while building it: `App::setLocale()` rewrites `config('app.locale')`, so a Hungarian request would have made the next request in a long-lived worker Hungarian too (the default now has its own key); a form-associated selector in the header would have taken over the Enter key of the page's form (the selector keeps its own form and carries the values with JavaScript instead).

## Hungarian public content — COMPLETE (built-in copy)

Done 2026-10-04. The English site was complete; Owner Admin showed every Hungarian section as *Missing* (23 of 23: 13 homepage, 2 site (navigation, footer), 6 plan wording, 2 SEO) because `lang/hu` did not exist, and public pages in Hungarian fell back to English section by section.

- Mechanism: the editor state comes from `ContentState::of()` (`Section::builtIn($locale)` = `lang/{locale}`), so the warning is real, not a display switch. It is gone because Hungarian built-in copy now exists, the same way the English content is stored (no database rows, nothing to seed or publish per environment; the owner's published text still wins).
- Added: `lang/hu/{home,pricing,checkout,dashboard,status,countries,account}.php`: 375 Hungarian texts, with exactly the keys of the English files (account: only the 7 keys the public order pages use). 7 FAQ items, 5 navigation items, 17 plan feature labels, homepage and pricing SEO titles and descriptions, footer and legal link labels. Beyond the 23 content sections, the homepage demo dashboard, the pricing table and the order pages are Hungarian too, so a Hungarian page shows no English.
- Not changed: `lang/en`, `config/pricing.php`, Stripe, pricing engine, subscription logic, content architecture, design, routes. Prices on Hungarian pages are the configured ones ("8 990 Ft / hó", "189 900 Ft / év"; annual = ten monthly payments, "két hónap ingyenes"; "végleges árak: áfát nem számítunk fel").
- Left in English on purpose: customer portal, owner and operator areas, emails (not public content; Laravel falls back key by key). `account.php` in Hungarian therefore holds only the public order-page keys. Brand and product names and the plan names Basic, Business, Secure stay as they are. The English WooCommerce plan descriptions still say "smaller WordPress sites" (an English copy quirk, translated faithfully, not altered).
- Found in the browser check (mobile 375, tablet 768, desktop 1280): the compact dashboard title was truncated in Hungarian ("Weboldalak áttekintése" → "Weboldalak"); the demo log lines are single-line by design in both languages.
- Tests (`HungarianContentTest`, 9): every section built-in in both languages and not *Missing*; Hungarian files have exactly the English keys; no Hungarian text identical to English (except named brand/identifier texts), none empty, no lost `:placeholder`; Hungarian pages render without fallback (`lang="en"` absent, no English left on `/`, `/pricing`, checkout); prices are the configured ones; English rendering unchanged; Hungarian drafts do not leak and published text wins; Owner Admin lists show no *Missing*; a deliberately missing language (`de`) is still reported. The fallback tests in `ContentManagementTest` now use that `de` fixture instead of Hungarian. Mutation-checked (an English text, a removed key, a lost placeholder each fail).

## Staging — PREPARED (documented and verified locally), NOT DEPLOYED

Target: `staging.wpcaregrid.com`, Ubuntu 24.04 x86_64, 2 vCPU, 4 GB RAM, 80 GB SSD. Nothing is deployed; no server access has been given. The procedure is docs/DEPLOYMENT.md "Staging" (server packages, `.env`, nginx, Stripe endpoint, checklist) plus "Rollback".

Audit (2026-10-04) against the code, not memory:

- **No application change is needed for staging.** A fresh copy of the repository installed with `composer install --no-dev` built, migrated, packaged the connector, built all four caches, rolled back and re-migrated, and ran `system:check`; with the staging values (`APP_ENV=production`, `APP_DEBUG=false`, https `APP_URL`) secure cookies, forced HTTPS, HSTS and connector HTTPS were on, Stripe live mode, remediation, operator full admin and live restore off, and `system:check` exited 1 until the scheduler, Stripe, storage and mail are configured (the intended gate).
- Versions: PHP 8.4 and MySQL 8.4 LTS are what CI tests, but Ubuntu 24.04 ships PHP 8.3, MySQL 8.0 (end of life) and Node.js 18 (Vite 8 needs ≥ 20.19 / 22.12): the server needs `ppa:ondrej/php`, the MySQL APT repository (8.4 LTS) and NodeSource 22.x.
- Extensions: from `composer check-platform-reqs --no-dev --lock` plus the application's own use (intl for `Number::fileSize`, zip for `connector:package`, pdo_mysql, pcntl for worker timeouts); `sodium` and `posix`, listed before, are not required.
- Staging runs `APP_ENV=production` (the production safeguards are tied to that value) and is told apart by `APP_URL` and `APP_NAME`; nginx adds `X-Robots-Tag: noindex` (the marketing pages say `index,follow`).
- Mail: SMTP or Amazon SES work with the installed packages; Laravel's postmark/resend API transports do not (their packages are not installed). The sender domain needs SPF, DKIM and DMARC.
- Backups: S3 settings `BACKUP_S3_*`; no application-level encryption key (TLS in transit, `BACKUP_S3_SSE=AES256` at rest); retention has no default (nothing deleted until `BACKUP_RETENTION_*` is set).
- Docs corrected: cron runs as `www-data`, artisan commands as `www-data` or with default ACLs on `storage/` and `bootstrap/cache/`, a database dump before every migration, a rollback procedure.

External prerequisites (owner actions, not code): the server and SSH access, the DNS record, a read-only GitHub deploy key (private repository), an SMTP provider with a verified sender domain, an S3-compatible bucket with a bucket-limited key, a staging Stripe webhook endpoint (and deleting the old local tunnel endpoint).

## Stripe test mode — VALIDATED (local, through an https tunnel; CI green)

Validated 2026-10-04 against a Stripe test sandbox, from the local installation exposed through a temporary Cloudflare tunnel (Stripe needs an https webhook URL), with Mailpit as the SMTP catcher. Procedure and configuration: docs/STRIPE_TEST_MODE.md. **This is test-mode validation only; the platform is not PRODUCTION_READY.**

- Stripe: products WordPress and WooCommerce, 48 recurring TEST prices (12 HUF retained + 36 EUR/USD/GBP), webhook endpoint with the 9 events, coupon + promotion code `PILOT10` (10 % once). Run `pricing:verify-stripe` for all 48 before enabling checkout.
- Tax: Stripe Tax off, no tax rates, no tax behaviour on the prices; every session `amount_tax` 0 = the listed final price (Hungarian rule).
- Real purchases through `/pricing` and Stripe Checkout (test card): WordPress Business monthly (several runs), WooCommerce Business annual, WordPress Basic monthly with `PILOT10` (Stripe charged 809100 = 8 091 Ft). Each: one intent → one customer (local UUID, Stripe customer linked) → one login → one active subscription with the right plan, interval and HUF amount; activation, payment and "subscription active" emails with https `APP_URL` links; activation sets the password, verifies the email, single-use link; login; dashboard (plan, Active, "Connect your first website"); subscription page; first website entered onboarding.
- Refused / safe: international billing country (before Stripe), unknown and not-applicable promotion codes (local rule; the Stripe code itself is unrestricted), declined card (nothing created, no email), abandoned checkout (intent cancelled, session expired), success and cancel URLs from another browser and a forged session ID (no change), unsigned and forged webhooks (400).
- Webhook: every processed event redelivered twice with a valid signature → 2xx, no new rows or emails. Browser back before the webhook (endpoint paused): "Your payment is being confirmed", no account, no email; delivering the missed events later, out of order, provisioned exactly once.
- Failed payment (`pm_card_chargeCustomerFail`, plan change billed at once): Stripe `past_due` → local `past_due` with the new plan, `invoice.payment_failed` recorded, "Your payment did not go through" email, dashboard shows the payment problem and pauses onboarding.
- Cancellation: at period end → still active, "Does not renew"; immediately → `canceled`, cancellation email, "This subscription has ended".
- Formatting: "18,990 Ft" (en), "8 990 Ft" (hu, narrow no-break space); never smallest units.
- No Stripe secret, webhook secret or card data in pages, logs, the database, queued jobs or emails.

Found and fixed:

- Promotion redemptions were never counted, so `max_redemptions` never applied: now counted once when a paid checkout completes (regression test).
- The test suite read `APP_URL`, Stripe keys and Price IDs from the developer's `.env` (a tunnel URL broke a test; a real key could have reached Stripe): `phpunit.xml` now pins them.
- `pricing:verify-stripe` did not check the product a Price belongs to: `STRIPE_PRODUCT_WORDPRESS` / `STRIPE_PRODUCT_WOOCOMMERCE` added and checked.

Added: Playwright (`tests/e2e/stripe-purchase.spec.ts`, opt-in, not in CI), docs/STRIPE_TEST_MODE.md, a test that a failure while processing a webhook leaves nothing behind (no email for a rolled-back login) and the redelivery succeeds once.

Not done here: a real mail provider (Mailpit is a local catcher), a permanent webhook endpoint (staging needs its own for its `APP_URL`), Stripe live mode, invoicing.

CI for these commits (2026-10-04):

- Run #4 (`eeb1ada`, https://github.com/zsoltacsai/wpcaregrid/actions/runs/37229524459) failed on MySQL only: a new test compared the price draft's JSON keys in insertion order, and MySQL returns JSON object keys in its own order. Every other job passed.
- Fix `836316f`: the assertion compares the key set (what it checks: no browser-supplied field enters the draft). Test-only; reproduced and verified on a local MySQL 8.4 first.
- Run #5 (`836316f`, https://github.com/zsoltacsai/wpcaregrid/actions/runs/37230005110): **all 5 jobs green**. Quality: Pint, composer audit (app and connector harness) and npm audit clean, build OK, gitleaks over 23 commits: no leaks. Server SQLite and MySQL 8.4: migrations, caches, 605 tests each with 0 skips (`--fail-on-skipped`, real S3 via moto and SFTP via OpenSSH), `system:check`, HTTP smoke test (55 checks). Connector in WordPress 7.1.2: 169 tests each; SQLite skips the 5 MySQL-only tests, MySQL the 1 SQLite-only test (unchanged).

Completed afterwards (**Complete Stripe product validation**): the owner area enforces the same Product rule. `StripePriceVerifier::verify()` (the Price check, then the Product configured for the price's product type) is the one check behind `pricing:verify-stripe`, publishing a price and "Check prices in Stripe". The product type comes from the price being edited and the expected Product from `STRIPE_PRODUCT_*`, never from the form; a Price of the other product, or a missing `STRIPE_PRODUCT_*`, blocks publishing. Inactive prices publish without a Stripe check, as before. Checked against Stripe test mode: all 12 prices ok; a WordPress Price checked as WooCommerce (and the reverse) is rejected with its real Product. `phpunit.xml` now forces the Stripe and `APP_URL` variables in both `$_ENV` and `$_SERVER`, so neither `.env` nor an exported shell variable reaches the tests (StripeTestIsolationTest).

## In progress: Production Hardening, CI & Release Readiness — IN_PROGRESS (CI validation complete)

The code, tests, CI workflow and documentation of this milestone are committed and pushed, and **CI validation is complete** (first green run below). The milestone stays **IN_PROGRESS** and the platform is **not PRODUCTION_READY** until the remaining items are done; their current state is tracked in docs/RELEASE_CHECKLIST.md.

Done in the commit:

- CI on Linux (`.github/workflows/ci.yml`): quality (lint, Pint, composer/npm audits, build, gitleaks), server suite on PHP 8.4 × SQLite/MySQL 8.4 with real S3 (moto) and SFTP (OpenSSH) services, connector suite in real WordPress × SQLite/MySQL; missing services fail instead of skipping.
- `php artisan system:check`, security headers / HTTPS / secure cookies / CSP (staged), backup transfer queue safety, rate limits on every signed-in write route, scheduler `onOneServer()`.
- Bugs found and fixed: customer disconnect failed on MySQL (reason column too short; now widened and transactional), security scans and incidents stored non-existent user IDs (failed on MySQL), connector file restore refused every file on Windows hosts (connector 0.4.1), backup transfers could be picked up by a second worker, empty `.env` values switched off secure defaults (`CONNECTOR_REQUIRE_HTTPS=`), customer website page overflowed on phones.
- Docs: DEPLOYMENT.md, RELEASE_CHECKLIST.md, PILOT.md (with the local end-to-end run).

CI validation (2026-10-04):

- Run #1 (`050afe0`, https://github.com/zsoltacsai/wpcaregrid/actions/runs/37200352447) failed on CI configuration only: pipx links `moto_server` into `PIPX_BIN_DIR` (`/opt/pipx_bin` on GitHub runners), not `~/.local/bin`, so both server jobs stopped before the tests; gitleaks reported the fixed pairing credential and admin-access token of the connector test harness. Both WordPress jobs passed.
- Fix `3f01f5d`: moto path resolved from pipx; exactly those two dummy values allowlisted in `.gitleaks.toml`. No application code, test or assertion changed; no check disabled.
- Run #2 (`3f01f5d`, https://github.com/zsoltacsai/wpcaregrid/actions/runs/37215778931): **all 5 jobs green**.
  - Quality: lint, Pint, composer audit (app and connector harness) and npm audit clean, build OK, gitleaks over the full history: no leaks.
  - Server SQLite and MySQL 8.4: migrate / rollback / migrate, production caches, 593 tests each with 0 skips (`--fail-on-skipped`, real S3 via moto and SFTP via OpenSSH), `system:check`, HTTP smoke test (55 checks).
  - Connector in WordPress 7.1.2: 169 tests each; SQLite skips 5 MySQL-only tests, MySQL skips 1 SQLite-only test; symlink and permission tests ran in strict mode.

Still needed before the milestone is complete:

1. ~~Stripe test-mode configuration and an end-to-end test purchase~~ — validated locally (above); repeat PILOT.md step 4 on staging with its own webhook endpoint.
2. Staging server per DEPLOYMENT.md "Staging" (prepared, not deployed): HTTPS domain, MySQL 8.4, workers, cron, a real mail provider, S3 backup storage.
3. Restore rehearsal on a staging copy, then decide on `BACKUP_RESTORE_ENABLED`.
4. First-customer safety gates in PILOT.md on the real installation.
5. GitHub branch protection with the CI checks required.

## Test baseline (2026-10-05)

Linux CI (authoritative): see "CI validation" above.

Windows development machine:

- Server suite on SQLite: 726 tests, all pass except 7 explicit skips (6 S3/SFTP tests without local services, 1 Windows-only permission assertion).
- Server suite on MySQL 8.4 (local, a fresh database): 726 tests, all pass with the same explicit skips.
- WordPress connector suite (169 tests) on SQLite and MySQL 8.4: all pass; skips are MySQL-only/SQLite-only tests and explicit Windows symlink/permission limitations (strict in CI).
- Local end-to-end run with a real WordPress and connector 0.4.1: PILOT.md.
- Stripe test-mode browser E2E (Playwright, real Stripe Checkout): 6 passed, plus the delayed-webhook test (runs only with the endpoint paused): STRIPE_TEST_MODE.md.
- Run with `vendor/bin/phpunit -c phpunit.xml` (`php artisan test` breaks when the path contains `=`). Linux CI is authoritative for filesystem behaviour.

## Next milestone

Finish this one (list above). Next: deploy staging per DEPLOYMENT.md "Staging" once the server, DNS, deploy key, mail provider and bucket exist, then run its checklist and PILOT.md there. After that, per CLAUDE.md §29: real backup storage, transactional email, controlled pilot onboarding.

## Blockers

- Staging cannot be deployed until these exist (owner actions): the server with SSH access, the `staging.wpcaregrid.com` DNS record, a read-only GitHub deploy key, an SMTP provider with SPF/DKIM on the sender domain, an S3-compatible bucket and key. Optionally a vulnerability feed licence. Stripe test mode is set up (sandbox keys only in the local `.env`).

## Pending manual configuration

- Production `.env` per DEPLOYMENT.md (`APP_ENV=production`, `APP_DEBUG=false`, https `APP_URL`, MySQL, mail, storage, Stripe); never commit it.
- `php artisan migrate --force`, `php artisan connector:package`, `php artisan owner:user …`.
- Supervisor for the two workers, cron for `schedule:run`, nginx with TLS.
- Stripe (staging): copy the test keys and the product/price IDs into the staging `.env`, create a webhook endpoint for the staging `APP_URL` (9 events) and set its secret, run `pricing:verify-stripe` (STRIPE_TEST_MODE.md). The temporary local tunnel endpoint is disabled in Stripe; delete it when no longer needed.
- Backup storage and staging disk; nightly backup of WP CareGrid's own database.
- Pilot sites: `WP_CAREGRID_QUARANTINE_DIR` outside the web root.
- GitHub: branch protection with the CI checks required.

## Important decisions

- Conservative defaults stay: `SECURITY_REMEDIATION_ENABLED=false`, `ADMIN_ACCESS_OPERATOR_FULL_ADMIN=false`, `BACKUP_RESTORE_ENABLED=false`; `system:check` shows any that is on.
- Unified Site Health is the only health calculation; unknown is never healthy.
- Customer, operator and owner areas use separate guards; one login never opens another area (AuthorizationMatrixTest covers every route).
- Backup transfers run on their own queue connection (`backups`) whose retry_after exceeds the transfer timeout.
- CSP is staged (off → report-only → enforce) so it cannot break the application unnoticed.
- Windows-only filesystem limitations skip with an explicit reason locally and fail in CI (strict mode); production code is never weakened for them.
- Prices: config/pricing.php is the baseline; owner-published prices override it for future checkouts only.
- Coupons, PROBONO and recovery offers are owner-only commercial tools. PROBONO is not a `PlanTier` and has no price; its backup capability is explicitly off. A recovery offer is a one-time payment from a stored price and is never a subscription or a security finding.
- Stripe: one Product per product type (WordPress, WooCommerce), one Price per plan × interval × market; one check (`StripePriceVerifier::verify()`: amount, currency, interval, active, Product) for the command, owner publishing and the owner's Stripe check. Promotion codes need a local rule row and a Stripe promotion code; local rules decide eligibility, Stripe applies the discount.
- Staging runs `APP_ENV=production` (the production safeguards are tied to that exact value); it is identified by `APP_URL`, `APP_NAME` and Stripe test mode, never by a weaker environment name.
- Tests never use the developer's Stripe keys (`phpunit.xml` pins them empty); the real-Stripe browser E2E is opt-in and outside CI.

## Számlázz.hu Milestone 6 — controlled synthetic TEST E2E (2026-10-08)

**Current status: multi-run authorization fix in local implementation; no provider POST performed.** The initial global one-shot unique slot made the run table unusable after run1 was rejected. The change preserves that immutable historical row, assigns each newly authorized run a fresh unique execution slot and external reference, records history in Owner UI, and keeps at most one unresolved run active behind a DB-locked control row. Each run still has its own fresh Owner account/seller attestations and separate single-submission confirmation; rejected/issued runs remain visible and permit a later independently authorized run. An ambiguous/submission-started run blocks new preparation pending read-only reconciliation. The additive control migration has not been applied; no staging deployment or new provider request has occurred.

Before calling the provider, complete CI, review the staged-only configuration and protected backup, deploy the exact green SHA, apply the additive migration, and have the Owner verify the account is still in TEST mode and that the stored key is associated with that TEST account immediately before confirming. Számlázz.hu documents test-account setup but no independent non-mutating API mode check; this status must not be presented as provider-verified. After the single test, retain the audit row and restore `SZAMLAZZ_SYNTHETIC_TEST_ENABLED=false`, `SZAMLAZZ_PROVIDER_ENABLED=false`, `SZAMLAZZ_DRY_RUN=true`, and `SZAMLAZZ_ISSUANCE_ENABLED=false`. Normal tax approval remains unresolved and production issuance stays blocked.

## M147-B status — 2026-10-18

M147-A is merged at `a84d03146ac3ba0ba8c47301f61be41052903905`; merged-primary CI run `39105703168` passed 7/7. M147-B PR #121 merged at `0699374432afeb26e036a87477c9a0cad5c5518b`; final-head CI `39109648655` and merged-primary CI `39110282933` both passed 7/7. The tracked secret-path checker was re-audited and a disposable Git subprocess regression was added. Five filesystem-safe hostile path cases passed locally on Windows; the full ten-name newline/control corpus passed on test/source head `a3080a7af802f0dd96f1f6b548233b555f989399` in run `39107517613`. The Owner-approved M146-F08 disposition is closed only for this checker output path; live GitHub command parsing remains untested. No staging/production, live scan/feed, secret, customer or M12 gate access occurred. AS-16 remains open. M147-C and later stages are not started. The six M147 stages and readiness conditions are in the [closure report](../reports/m14-security/m14-7-b-closure-report.md).

## M147-C candidate status — 2026-10-18 (historical checkpoint before closure)

M147-C had separate Owner Level 2 authorization for local synthetic security-engine validation. Work was on `codex/m147-c-security-engine-validation`, based on authoritative primary `d863da22d3439f2fa52a9988297e7b19a4c31f36`. Focused Laravel security suites passed 98 tests / 1,124 assertions; the disposable WordPress 7.1.3 + SQLite Connector security integration suite passed 22 tests / 159 assertions. Candidate `1dc8c93a96640a251d0744927ff963e01a6544f9` passed exact-head CI run `39116313678` 7/7, including the Server MySQL process/concurrency gate. At this M147-C candidate checkpoint, a subsequent evidence-status documentation update was awaiting its own exact-head run and Owner review; PRs #123/#124 later closed that stage, as recorded below. The candidate added bounded and redirect-disabled feed sync, strict feed parsing and atomic index replacement; preserved partial status for unknown component versions; and prevented incomplete scanner coverage from producing Healthy posture. Details, test limits and findings are in [M147-C execution evidence](../reports/m14-security/m14-7-c-execution-evidence.md). No live feed, external scan service, browser test, staging, production, customer site, real credential, remote backup, M12 gate read or Level 3 activity occurred. AS-16 remains open; the six live M12 values remain unverified. At this M147-C checkpoint, M147-D and later stages had not started.

## M147-C formal closure — 2026-10-18

PR #123 merged through the active primary ruleset at `5ec7ac4d2449d4eb95d98b108210607c40c847ce`. Exact PR-head CI run 39117328446 and merged-primary CI run 39118627595 passed 7/7; Server MySQL process/concurrency passed in both. The earlier M147-C status paragraph above records the candidate-time state and is historical; this section is the current status. See the [formal closure report](../reports/m14-security/m14-7-c-closure-report.md) for the full coverage matrix and residuals.

M147-C is closed for bounded local synthetic validation. At this M147-C closure checkpoint, M147-A/B/C were closed and M147-D/E/F had not started. M14.7 is approximately 50% complete by stage count, not by security assurance. M14.8 is not ready. No staging, production, customer, live-feed, real-secret, backup, restore, remediation, browser, or destructive operation occurred. The six live M12 gate values remain UNKNOWN and unchanged. AS-16 remains open.

## M147-D authorization checkpoint — 2026-10-18 (historical)

M147-D Level 2 local synthetic attack-path validation is in progress on a dedicated branch from verified primary `b392c5c3f4a934886b574e59ceb67d1dfe04c894`. The planning-first inventory is recorded in `reports/m14-security/m14-7-d-execution-evidence.md`. No M147-D outcome or finding closure is claimed yet. M147-A/B/C remain closed; M147-E/F and M14.8 have not started. No staging, production, customer-site, live feed/service, real backup/restore/remediation, or deployment action is authorized or reported. The six live M12 gate values remain UNKNOWN.

## M147-D local validation candidate checkpoint — 2026-10-18 (historical)

M147-D used a source-grounded inventory followed by existing synthetic Laravel feature suites (no duplicated test harness). The local focused runs completed 471 tests, 5,635 assertions, 12 skips and zero failures after the frontend manifest was built; nine local backup cases and three Stripe-focused cases were skipped, so those cases are not claimed. No new Critical/High defect was reproduced and no runtime code was changed. Cross-tenant website/backup, role checks, Connector site binding/job result idempotency, synthetic signed Stripe cases, CSRF/route policy, selected XSS escaping, selected incident/remediation flows, fake feed transport and Owner AI configuration tests passed within their explicit assertions. Browser E2E, full SSRF/DNS rebinding, exhaustive XSS surfaces, every incident/backup side-effect combination and live operational state remain untested or partial. M147-F04 remains open; AS-16 and other existing findings retain their prior dispositions. Exact-head CI and Owner review were pending at this candidate checkpoint. See [execution evidence](../reports/m14-security/m14-7-d-execution-evidence.md). No staging, production, customer-site, external service, real credential, real backup/restore/remediation, M12 gate or deployment action occurred.

## M147-D formal closure — 2026-10-18

PR #125 was merged through the active authoritative-primary ruleset at `bc0a4e536abfd573b5b34d5e2152dcf15ad80c80`. Exact PR-head CI run [39122868939](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39122868939) passed 7/7 on `28f92ee4238efad64345eba944531d652e194963`. Exact merged-primary CI run [39124920641](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39124920641) passed 7/7 on the merge SHA. The required Server MySQL process/concurrency step passed in both runs. The formal status is **M147-D CLOSED for bounded local synthetic validation only**; it does not mean the broader attack-path campaign or operational scanner validation is complete. See [M147-D closure report](../reports/m14-security/m14-7-d-closure-report.md) and [M147-D execution evidence](../reports/m14-security/m14-7-d-execution-evidence.md).

M147-A/B/C/D are closed within their documented scopes. M147-E and M147-F have not started. M14.7 is estimated at approximately 65% implementation progress, not a security-assurance score. M14.8 is not ready. AS-16 and the open findings listed in the closure report remain open. The six live M12 gate values remain UNKNOWN and were not read or changed during M147-D closure. No staging, production, customer-site, live-service, real-secret, payment, backup/restore, remediation, destructive, or deployment operation occurred.
Local-only follow-up: the Windows OpenSSH build lacks the KEX named in the failed RO-00 attempt; a Git-bundled OpenSSH candidate advertises it but requires package-provenance verification. No retry or network contact occurred. See [local SSH diagnostics](../reports/m14-security/m14-7-e2a-local-ssh-diagnostics.md).


## M147-E.2a RSA reconciliation — 2026-10-18 19:16 UTC

A helyi publikus kulcs friss ellenőrzése `SHA256:Cy8+tFoYq7gsUPjpPBCqnNOvdzksvB6VsLE4AwdunkU` értéket adott; ez pontosan egyezik az Owner legutóbbi, teljes értékű Rackforest-console megerősítésével. A lokális adatot a helyi `id_rsa.pub` nyilvános fájlból, a provenance-ellenőrzött Git for Windows `ssh-keygen.exe -lf … -E sha256` kapcsolóival számoltuk. Az Owner külön forrásként közölte, hogy a szerveren a root és deploy `authorized_keys` ugyanazt az ujjlenyomatot adta. **PUBLIC KEY FINGERPRINT MATCH: PASS**; ez nem Codex által végzett szerverellenőrzés.

A korábbi malformed `SHA256+tFo...` érték és az akkori UNKNOWN/mismatch eredmény történeti bejegyzés marad, nem írjuk át. Privátkulcs-elérhetőség, SSH-hitelesítés, `deploy` account-jogosultság, RO-01–RO-04 futás és minden M12 gate továbbra is UNKNOWN / NOT EXECUTED. M147-E.2b NO-GO / NOT AUTHORIZED. Nincs staging kapcsolat vagy remote parancs ebben az utólagos lokális ellenőrzésben.

## M14.7 full-closure assessment reconciliation — 2026-10-18

**Current disposition: PARTIAL / OWNER ACTION REQUIRED.** Earlier snapshots remain historical. Authoritative primary is 9e11c2035cd343b8cc1615fee4dbcf4a34c16cd9. PR #129 remains open and unmerged at reviewed head 3a525fdfe548b0b24915a0bb6424bba8a6d9264e; exact-head run [39158228230](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39158228230) passed 7/7. The Server MySQL Required MySQL process-concurrency gate step passed.

The local public id_rsa.pub fingerprint was freshly recalculated as SHA256:Cy8+tFoYq7gsUPjpPBCqnNOvdzksvB6VsLE4AwdunkU, exactly matching the complete value in the Owner's latest closure record. The Owner reports the same fingerprint from the Rackforest console for both root and deploy authorized_keys. This is public-key identity evidence only. A local private-key file exists by metadata, but its contents were not read; usability, SSH authentication and account authorization remain UNKNOWN.

The Owner independently confirmed ED25519 host fingerprint SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ from the Rackforest console and stated that it matches the previously observed keyscan result. The original RO-00 attempt failed against its then-supplied, mistranscribed expected value; later Owner confirmation reconciles the identity but does not rewrite that historical run as passing its original gate. No new network request occurred.

RO-01–RO-04 were not run; their authorization record remains draft because the SSH account, credential-use mechanism and a new execution window are not approved. RO-05–RO-09 were not run. The six live M12 values remain UNKNOWN. No live scanner/feed validation or operational side-effect check occurred. M147-A/B/C/D remain closed within their documented scopes; M147-E.1 remains planning-only closed; M147-E.2a is PARTIAL/BLOCKED; M147-E.2b is NOT AUTHORIZED; M147-F has not started; M14.8 is NOT READY. M14.7 estimated implementation progress remains approximately 65%, not a security-assurance score.


## M14.7 current evidence update — 2026-10-18

PR #129 remains open and unmerged. Its code head `049a08bdd2377d82e7f29bce7a319c0c3768dc4c` passed exact-head run [39160827416](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39160827416), 7/7. The explicit Server MySQL process/concurrency gate passed. Connector MariaDB completed 211 tests with 1,834 assertions, 3 skipped and no failures. The test-harness-only CI-REL-01 diagnostic patch reports worker exit/running state when synchronization output is missing; it neither changes runtime application code nor relaxes timeout/assertions. Earlier timeout and intermediate correction failures remain in the audit trail. Root cause remains unknown; CI-REL-01 stays OPEN.

M147-A/B/C/D remain closed within their documented scopes; M147-E.1 remains planning-only closed. M147-E.2a is PARTIAL/BLOCKED: RO-00 identity was later reconciled from Owner evidence, but RO-01–RO-04 were not run; RO-05–RO-09 were not authorized. All six live M12 gate values remain UNKNOWN. No operational scanner validation has occurred. M147-E.2b is NOT AUTHORIZED; M147-F is NOT STARTED; M14.8 is NOT READY. Overall implementation estimate remains about 65%, not a security-assurance measure. Full M14.7 closure is not claimed.

## M14.7 integrated local validation candidate — 2026-10-19

PRs #129 and #130 remain open and unmerged. Their current exact-head CI runs 39170388169 and 39237437478 passed 7/7 each, including the Server MySQL process/concurrency gate; these are not runs for the combined candidate. Local candidate `codex/m147-pr-integration-review` combines both PR heads from primary `9e11c2035cd343b8cc1615fee4dbcf4a34c16cd9`, resolves their overlapping evidence/test changes, and includes bounded audit-runner and browser-initialization corrections. The full local browser suite passed 14/14 on candidate ancestor `57ccb42a55c5cbe9220a40530685fa91a2a40173`; the combined Laravel run passed 1,413 tests / 20,548 assertions with 25 environment-dependent skips; audit-runner tests passed 8/55 assertions; npm tests passed 20/20. Stripe-dependent browser flows and unavailable local database/service cases were not executed. Exact CI on the final pushed candidate is required before review.

This is a local validation candidate, not milestone closure. PR #131 exact-head run 39243862900 passed 7/7 on `66060cbdd1acf0379fb63af416001a898832db72`, including the Server MySQL process/concurrency gate; the Quality job's Gitleaks scan also passed. This project-status documentation update requires a new exact-head CI run. No staging connection, RO command, M12 gate read, live feed, scanner, customer/production access, backup/restore, deployment, or M14.8 work occurred. All six live M12 gates remain UNKNOWN. AS-16/M147-F01, M147-F02/F03/F04/F05/F06/F08 and CI-REL-01 remain open at their recorded boundaries. See [the integration candidate review](../reports/m14-security/m14-7-integration-candidate-review.md) for test methods, overlap decisions, skips and residual risks. M14.7 remains PARTIAL; M14.8 is NOT READY.

## M14.7 staging evidence and feed publication review — 2026-10-19

PR #131 is merged on primary `5e1e8f68951b2925e57dd406a9b3fcd1a7923799`; merged-primary run [39255687374](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39255687374) passed 7/7, including the Server MySQL process-concurrency gate. Owner-provided staging observations are appended in the E.2a evidence reconciliation; all six M12 gates were reported FALSE for that one staging configuration/readback only. The original output, exact timestamp and audit identifier are not archived; this does not close M147-F02. The observed staging checkout `be427a17320ca7aba9ec292d2c288813df5f7c07` is 104 commits behind primary, so it cannot validate primary scanner changes. Codex made no staging connection or operational change.

PR #132 adds a focused fix for feed publication integrity. The prior two-rename sequence could leave a new raw cache beside the last-good index if the second rename failed; repository search shows scanner code reads only the index. The corrected candidate keeps the raw download temporary, removes a legacy regular-file cache before publishing, and performs one checked same-filesystem atomic rename for the authoritative index. Injected pre-rename failure preserves the last-good index; a follow-up successful sync recovers. A local two-process publisher test observed only complete JSON documents. WSL PHP 8.4.26/PHPUnit 12.5.36: focused feed/posture 17 tests / 858 assertions; full Security feature suite 105 / 2,503; Pint passed. This is local synthetic evidence, not live-feed or staging validation.

At this status update PR #132 is OPEN and unmerged. Its prior head's 7/7 run does not validate these new changes; current exact-head CI must be verified after push. M147-F07 remains OPEN for command-level ordering/freshness and live-feed/operational gaps. CI-REL-01 remains OPEN because one green CI run does not establish the root cause or reliability acceptance criterion. M147-F02 remains OPEN despite the Owner-reported single-session gate readback. M147-E.2b is NO-GO / NOT AUTHORIZED; no staging scanner, feed download, queue, incident, notification, backup, restore, configuration change or deployment occurred. M14.7 remains incomplete; M14.8 is NOT READY.
