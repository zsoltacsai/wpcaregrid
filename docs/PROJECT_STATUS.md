# WP CareGrid — Project Status

> Kept up to date at the end of every milestone (see CLAUDE.md §27).
> A new session should read CLAUDE.md, this file and the relevant docs, then run `git status` and `git log --oneline -12`.

Last updated: 2026-10-04

## Branch and HEAD

- Branch: `claude/ecstatic-wozniak-oemyuf` (also the main branch used for PRs)
- Latest commit: **Real Stripe Test Mode Validation** (local, not pushed; `git log --oneline -3` shows its hash)
- Before it: `9171262` status update, `3f01f5d` CI configuration fix, `050afe0` **Production Hardening, CI & Release Readiness** (pushed)
- Earlier: `2f7d0b4` Owner Admin & Content Management, `4076684` Security & Malware Engine, `ba2d1db` Internal Operator Console
- `origin` (github.com/zsoltacsai/wpcaregrid) has everything up to `9171262`; push only when requested, never force.

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

## Stripe test mode — VALIDATED (local, through an https tunnel)

Validated 2026-10-04 against a Stripe test sandbox, from the local installation exposed through a temporary Cloudflare tunnel (Stripe needs an https webhook URL), with Mailpit as the SMTP catcher. Procedure and configuration: docs/STRIPE_TEST_MODE.md. **This is test-mode validation only; the platform is not PRODUCTION_READY.**

- Stripe: products WordPress and WooCommerce, 12 recurring HUF prices (lookup keys `caregrid_<product>_<plan>_<interval>_huf`), webhook endpoint with the 9 events, coupon + promotion code `PILOT10` (10 % once). `pricing:verify-stripe`: ok for all 12, including the product (new check).
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
2. Production/staging server per DEPLOYMENT.md: HTTPS domain, MySQL, workers, cron, a real mail provider, backup storage.
3. Restore rehearsal on a staging copy, then decide on `BACKUP_RESTORE_ENABLED`.
4. First-customer safety gates in PILOT.md on the real installation.
5. GitHub branch protection with the CI checks required.

## Test baseline (2026-10-04)

Linux CI (authoritative): see "CI validation" above.

Windows development machine:

- Server suite on SQLite: 599 tests, all pass except 7 explicit skips (6 S3/SFTP tests without local services, 1 Windows-only permission assertion).
- Server suite on MySQL 8.4 (local): all pass with the same explicit skips.
- WordPress connector suite (169 tests) on SQLite and MySQL 8.4: all pass; skips are MySQL-only/SQLite-only tests and explicit Windows symlink/permission limitations (strict in CI).
- Local end-to-end run with a real WordPress and connector 0.4.1: PILOT.md.
- Stripe test-mode browser E2E (Playwright, real Stripe Checkout): 6 passed, plus the delayed-webhook test (runs only with the endpoint paused): STRIPE_TEST_MODE.md.
- Run with `vendor/bin/phpunit -c phpunit.xml` (`php artisan test` breaks when the path contains `=`). Linux CI is authoritative for filesystem behaviour.

## Next milestone

Finish this one (list above; the next blocker is a staging server with HTTPS, a real mail provider and backup storage). After that, per CLAUDE.md §29: real backup storage, transactional email, controlled pilot onboarding.

## Blockers

- External accounts and configuration: backup storage, mail provider, a staging/production server, optionally a vulnerability feed licence. Stripe test mode is set up (sandbox keys only in the local `.env`).

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
- Stripe: one Product per product type (WordPress, WooCommerce), one Price per plan × interval × market; `pricing:verify-stripe` checks amount, currency, interval and product. Promotion codes need a local rule row and a Stripe promotion code; local rules decide eligibility, Stripe applies the discount.
- Tests never use the developer's Stripe keys (`phpunit.xml` pins them empty); the real-Stripe browser E2E is opt-in and outside CI.
