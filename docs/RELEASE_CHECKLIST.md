# Release checklist

Gates before the controlled pilot and before production use. Each item is **READY** (done and verified in the repository or by tests), **NOT READY** (work or a rehearsal is still needed) or **NOT CONFIGURED** (needs production configuration or an external account). The detailed legacy rows below include historical configuration states and must be refreshed against the current exact release before use. See [PROJECT_STATUS.md](PROJECT_STATUS.md) for the current status register and evidence boundaries.

How to check most items: `php artisan system:check` (OK / WARNING / ERROR / NOT CONFIGURED, never prints secrets) and the owner System page. Deployment steps: [DEPLOYMENT.md](DEPLOYMENT.md); pilot: [PILOT.md](PILOT.md).

## Owner release-sequence decisions — 2026-11-04

The first production target is the **HU/EN marketing-only MVP**. Customer registration, Stripe Checkout/webhook provisioning, subscription activation, Connector onboarding, paid services and live payments are out of scope and must remain unavailable. Paid service activation follows separate service, financial and operational readiness gates. Full M15 customer-facing Backup & Restore is a later milestone; its deferral does not waive minimum infrastructure backup, isolated restore, migration rehearsal or rollback requirements for this broad application release.

### Initial release gates for the enabled scope

| Gate | Current evidence/state | Release acceptance |
| --- | --- | --- |
| Exact source and CI | Primary `7355788882e026db7c4a6d37db2b957740b5f385`; merge CI `41747134447` — 38/38 PASS. | Recheck exact target SHA and all currently required contexts immediately before each release decision. |
| Marketing-only application QA | Marketing E2E `41748568634` — PASS on the recorded exact source. | Revalidate HU/EN routes, informational pricing, navigation, CTA/contact, responsive layouts, SEO and negative access checks for registration, checkout, payment, service provisioning and SaaS/background actions on the deployed exact SHA. |
| Server PHP policy | PHP 8.4 is the staging/rollback baseline. Server CI covers PHP 8.4 and 8.5. | Keep staging baseline on 8.4. Complete 8.5 compatibility validation in CI or an approved isolated environment; this is not a PHP 8.5 cutover authorization. Connector PHP matrix remains separate and unchanged. |
| Pre-deployment backup | Fresh, mutually associated application/configuration/database backup is not currently evidenced. | Capture immediately before a state-changing deployment; verify identity, completeness and hashes; protect `.env`/`APP_KEY` through approved secret recovery without exposing values. |
| Actual recovery proof | Isolated restore is blocked by the unavailable officially approved transfer/restore path. Synthetic MySQL migration passes are not actual backup restore evidence. | Restore the fresh backup or approved equivalent into an isolated disposable MySQL 8.4.11 environment; verify application/config/key pairing; run release migrations on a restored clone; verify cleanup. |
| Rollback/recovery | Exact-SHA controller and PHP 8.4 rollback baseline exist; an actual current backup restore remains unproven. | Demonstrate code/config rollback and database recovery path without automatic destructive migration rollback. Keep the public gate closed on migration failure. |
| Runtime and background fencing | PHP 8.4 preflight, Nginx check and scheduler-lock evidence PASS; worker phase classification is accepted. | Recheck the current host before release. For marketing-only scope, workers and scheduler must remain persistently fenced and pending jobs retained; strict worker RUNNING contract applies after recovery starts workers. |
| Production readiness | Must be evaluated against the explicitly enabled production scope. | Complete applicable security, legal/accounting, monitoring, access/secrets and release-authorization gates. Deferred M15 product gates are not silently enabled or claimed complete. |
| Scope isolation | Owner has approved marketing-only first release; no actual production/staging sync yet. | Prove public marketing routes work and registration, checkout, Stripe webhook provisioning, Connector onboarding, customer service and SaaS background activity remain blocked. Do not accept a source-only claim instead of exact staging/browser checks. |

**Current decision:** `BACKUP_TRANSFER=BLOCKED_EXTERNAL_AUTHORITY`; `ISOLATED_RESTORE=BLOCKED`; `STAGING_DEPLOY=NO-GO`. The preferred option is an administrator-provided isolated restore target within the staging security boundary, if already supported and isolatable without changing the live database or weakening AppArmor/policy. Authorized encrypted transfer to WSL is the fallback only after the platform provides an officially approved channel. Do not substitute another transfer mechanism.

### MVP scope and staging sync — current evidence 2026-11-04

| Item | Current state | Acceptance / limitation |
|---|---|---|
| Authoritative primary | `7355788882e026db7c4a6d37db2b957740b5f385`; `origin/claude/ecstatic-wozniak-oemyuf` read-only check matched. | Recheck exact current target and all mandatory jobs immediately before staging or production release. |
| Staging application SHA | `761e0e5d37558da808ac961ca2d2a68b4ffab0db`, read through existing `caregrid-staging` SSH alias on 2026-11-04 08:55 UTC; deployed worktree status was clean. | GitHub compare identifies the staging SHA as base and primary 545 commits ahead. This target includes application, billing, Connector, backup, migrations, deployment/runtime, localization and marketing changes. Use the full protected exact-SHA controller release; do not cherry-pick marketing files. |
| Exact-source acceptance | CI `41747134447` = 38/38 PASS; Marketing E2E `41748568634` = PASS on the recorded source. | Accepted repository evidence only. It does not establish deployed staging behavior; actual browser QA follows a successful exact-SHA deploy. |
| Staging deploy | **NO-GO** | Actual backup/restore remains blocked by external authority; do not deploy until the minimum recovery gate and all runtime, fencing, migration, controller and post-deploy acceptance gates pass. |
| PHP policy | PHP 8.4 is staging/rollback baseline; PHP 8.5 is planned compatibility validation only. | Keep server CI on 8.4/8.5. No PHP 8.5 cutover; Connector matrix remains unchanged. |
| Paid plan candidate | `wordpress.basic`, 8,990 HUF/month, is a later candidate, not part of this MVP and not approved for sale. | It includes plugin/theme/core updates, remote backup, uptime monitoring, monthly security checks and activity log; catalogue marks it restore-eligible. Verify capability, operator coverage, entitlement, financial readiness and recovery before later activation. HU/EN labels are Basic/Business/Secure; 39,900 HUF is `woocommerce.secure`. |
| Registration and payment | Disabled for marketing-only MVP; real payment is prohibited. | Verify account creation, Checkout, webhook provisioning and service activation are inaccessible. Sandbox customer-journey verification belongs to the later paid-service gate; live mode always needs separate explicit Owner GO. |
| Deferred product scope | Full customer-facing M15 Backup & Restore is first post-production milestone. | Keep customer browse/download/restore/retention capabilities unavailable until M15 acceptance. The current plan catalogue carries backup/restore entitlements; reconcile runtime and offer visibility before launch. This does not waive minimum infrastructure backup, recoverability or rollback requirements. |

### MVP customer acceptance after staging synchronization

The deployed exact SHA must pass actual staging browser tests for the HU/EN homepage, informational pricing, navigation/language/currency, mobile/desktop layout, CTAs/contact, SEO and console/network errors. Negative checks must prove registration, account creation, checkout, payment, webhook provisioning, Connector onboarding, customer service and SaaS/background actions stay inaccessible. Do not create a customer/site or submit a payment in this phase. The paid-service journey (sandbox success/decline, signed webhook/replay, subscription consistency, activation, onboarding, service delivery and operator support) is a later acceptance gate after the package and operational conditions pass. Source tests and CI do not substitute for staging acceptance.

Production remains a separate decision after the enabled package and operational scope are supportable and the applicable legal/accounting, security, access/secrets, monitoring, incident-response, recovery and exact-release gates pass. No production or live-payment operation is authorized by this checklist.

### Minimum infrastructure backup and recovery requirements

These remain mandatory before any state-changing staging or production release, independently of M15 product completion:

1. Fresh verified application, configuration and database backup bound to the exact source SHA and timestamp.
2. `.env` and original `APP_KEY` preserved using the approved secret-management/recovery route; never print, commit or attach secret values.
3. Actual restore proof in a disposable isolated MySQL 8.4.11 environment, with networking disabled or otherwise proven inaccessible to shared/live services.
4. Structural/data-integrity checks and target release migrations tested on a separate restored clone; no `migrate:rollback` used as restore proof.
5. Exact application/config rollback plan and database recovery decision, with the HTTP gate kept closed if migration or health checks fail.
6. Verified cleanup of only the disposable restore artifacts and environment.

## Application

| Item | State | Check |
| --- | --- | --- |
| PHP 8.4 with required extensions | READY | CI runs PHP 8.4 on Ubuntu 24.04; Ubuntu ships 8.3, so the server needs `ppa:ondrej/php`; exact extension list in DEPLOYMENT.md; server: `composer check-platform-reqs --no-dev` |
| PHP 8.5 backend compatibility | READY IN SERVER CI / STAGING CUTOVER NO-GO | Preserve Laravel CI coverage on PHP 8.4 and 8.5. Complete any required isolated compatibility validation before release; PHP 8.5 staging/production cutover needs separate approval. Connector matrix is separate and unchanged. |
| English and Hungarian for the public site, customer login and portal; HU → HUF; no fake international prices | READY | LOCALIZATION.md; CustomerLocalizationTest, HungarianContentTest, Playwright localization spec |
| Customer emails in Hungarian | NOT READY | English only; needs the language stored at checkout (LOCALIZATION.md) |
| Fresh install without dev packages | READY | `composer install --no-dev`, build, migrate, `connector:package`, all caches, rollback and re-migrate verified on an exported copy of the repository (2026-10-04) |
| Staging server `stg1.wpcaregrid.com` | READY — M14 staging deployment reported | Protected deployment of M14 SHA `b152fcfc53543938c27d820d966ca99bd56e22d6` is Owner-reported under receipt `release-20261024T165817.155049Z-b152fcfc53543938c27d820d966ca99bd56e22d6.json`; later dependency release deployment through current HEAD `761e0e5d37558da808ac961ca2d2a68b4ffab0db` is also Owner-reported, separate receipt not archived. Not production readiness. |
| Rollback procedure | READY (documented) | DEPLOYMENT.md "Rollback"; to be rehearsed once on staging |
| `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` set | NOT CONFIGURED | `system:check` Application = OK (ERROR if debug in production) |
| `APP_URL` is the public `https://` domain | NOT CONFIGURED | `system:check` Application (ERROR if http or local in production) |
| HTTPS redirect, HSTS, secure cookies | READY | defaults when `APP_URL` is https (tests: ProductionHardeningTest) |
| Security headers on every response | READY | `curl -I https://<domain>/`; HTTP smoke test |
| Content-Security-Policy | NOT READY | set `HTTP_CSP=report-only` on staging, check the console, then `enforce` |
| Trusted proxies (if behind a load balancer) | NOT CONFIGURED | `TRUSTED_PROXIES` |
| Production caches build | READY | CI runs config/route/view/event cache; deploy runs them |
| Frontend build | READY | CI `npm run build`; deploy runs it |

## Database

| Item | State | Check |
| --- | --- | --- |
| Migrations run from an empty database, roll back fully, run again | READY | Full Laravel CI on MySQL 8.4 for PHP 8.4 and 8.5; targeted SQLite regressions separately |
| Indexes and foreign keys | READY | verified on MySQL 8.4 (every table has a primary key; foreign keys on all relations) |
| Server-side suite on MySQL | READY | CI server job (mysql); found and fixed: reason codes longer than their columns, JSON key order in tests |
| No seed or demo data in production | READY | `migrate --force` only; no seeders run |
| Production MySQL database and user | NOT CONFIGURED | MySQL 8.4 LTS from the MySQL APT repository (Ubuntu 24.04 ships 8.0); `system:check` Database = OK, no pending migrations |
| Backup of WP CareGrid's own database | NOT CONFIGURED | nightly `mysqldump` to separate storage (DEPLOYMENT.md) |

## Queue

| Item | State | Check |
| --- | --- | --- |
| Two workers (`default`, `backups`) under a supervisor | NOT CONFIGURED | supervisor config in DEPLOYMENT.md; `supervisorctl status` |
| Backup transfers cannot run twice | READY | own connection with retry_after > timeout, unique job; `system:check` Queue reports ERROR otherwise |
| Abandoned transfers recover | READY | `backup:monitor` hands them over (test) |
| Failed jobs visible | READY | System page / `system:check`; `php artisan queue:failed` |
| Workers restarted on deploy | NOT CONFIGURED | `php artisan queue:restart` in the deploy procedure |

## Scheduler

| Item | State | Check |
| --- | --- | --- |
| One cron entry `schedule:run` every minute | NOT CONFIGURED | `system:check` Scheduler = OK (heartbeat younger than 5 minutes) |
| `internal-plans:sync` and `offers:expire` registered with the scheduler (hourly) | NOT CONFIGURED | `php artisan schedule:list` lists both (checked locally; to be repeated on staging) |
| `internal-plans:sync` and `offers:expire` execute successfully on staging | NOT CONFIGURED | run both by hand (exit 0), then `php artisan schedule:run` in the first minute of an hour; DEPLOYMENT.md "Staging checklist" item 12 |
| No duplicate task runs | READY | every task `withoutOverlapping()` + `onOneServer()` |

## Mail

| Item | State | Check |
| --- | --- | --- |
| Real mailer and sender address | NOT CONFIGURED | `system:check` Mail = OK (log driver = NOT CONFIGURED) |
| Mail queued, links from `APP_URL` | READY | invitation link (pilot E2E) and activation link (Stripe E2E through an https tunnel) used `APP_URL` |
| Delivery tested (invitation, password reset, activation) | NOT READY | activation, payment and subscription emails verified through SMTP into Mailpit (test catcher, 2026-10-04); a real provider on staging is still open (PILOT.md) |

## Stripe

| Item | State | Check |
| --- | --- | --- |
| Test-mode keys (`STRIPE_KEY`, `STRIPE_SECRET`) | READY (local) | test sandbox keys in the local `.env`; staging needs its own `.env` (STRIPE_TEST_MODE.md) |
| 2 products and 48 recurring TEST prices configured (HUF/EUR/USD/GBP) | PENDING | `php artisan pricing:verify-stripe` must validate all 48 prices before checkout acceptance |
| Webhook endpoint + signing secret, 9 events | READY (local) | registered for the temporary tunnel and verified; staging needs its own endpoint for its `APP_URL` |
| Tax setting for Hungary (final price, no VAT added) | READY | Stripe Tax off, no tax rates, `amount_tax` 0 on every session (STRIPE_TEST_MODE.md) |
| Promotion codes (local rules + Stripe discount) | READY | real checkout with `PILOT10`: 8 990 Ft → 8 091 Ft charged; unknown and not-applicable codes refused locally |
| Webhook signature, idempotency, transaction, retries | READY | tests (SubscriptionLifecycleTest, CheckoutTest) |
| Browser success page never activates service | READY | tests |
| Test purchase end to end | READY (local) | Playwright against real Stripe Checkout through an https tunnel (STRIPE_TEST_MODE.md); repeat on staging (PILOT.md step 4) |
| Failed payment, cancellation, webhook replay, delayed webhook | READY | real test-mode runs 2026-10-04 (PROJECT_STATUS.md) |
| Live mode | NOT READY | separate decision after the test-mode pilot (`STRIPE_ALLOW_LIVE_MODE`) |

## Backup product and infrastructure recovery

| Item | State | Check |
| --- | --- | --- |
| Fresh pre-deployment application/configuration/database backup | BLOCKED — NOT CURRENTLY EVIDENCED | Required immediately before a state-changing release; bind the artifacts to exact source SHA/time and preserve secret/key material through approved recovery. |
| Actual isolated restore of the release backup | BLOCKED_EXTERNAL_AUTHORITY | Minimum infrastructure release gate. Current approved transfer is unavailable; prefer an admin-provided isolated restore target within staging infrastructure if supported. |
| Restore structure/data integrity and release migrations | NOT VERIFIED ON A RESTORED COPY | Run on an isolated restore and a separate clone; synthetic empty-database migration PASS is not a substitute. No automatic migration rollback. |
| Exact application/config rollback and gate recovery | DOCUMENTED; ACTUAL DATABASE RESTORE UNPROVEN | Keep the public HTTP gate closed on migration/health failure; use reviewed recovery steps, not `down()` migrations as restore proof. |
| Customer-facing backup/restore product | DEFERRED — FIRST POST-PRODUCTION M15 | Keep customer backup/restore, retention and restore-provider workflows unavailable/disabled until M15 completion and their own security/release gates. |
| Storage provider configuration and real-provider E2E | POST-PRODUCTION M15, unless required by the explicitly enabled release scope | Do not make this a blanket prerequisite while the customer Backup & Restore product is deferred. If enabled, verify provider behavior and credentials through approved controls. |
| S3 and SFTP integration contracts | READY IN TESTS | CI uses synthetic/controlled providers (moto, pinned-host-key OpenSSH); this is not evidence of a production provider configuration. |
| Storage secrets never leave the server or reach logs | READY IN TESTS | StorageProviderTest and CredentialExposureTest; retain this security invariant. |
| Retention per customer plan | POST-PRODUCTION M15 | Commercial/legal decision and deletion controls remain required before enabling customer retention or destructive expiry. |
| Backup and verification end to end | READY LOCALLY / PRODUCTION STATE UNKNOWN | Local evidence does not prove production storage configuration or current backup recoverability. |

## Connector

| Item | State | Check |
| --- | --- | --- |
| Connector 0.4.2 package built and verified | READY | `php artisan connector:package`; CI checks release metadata, runtime manifest, and controlled runner membership |
| Tested in real WordPress (SQLite and MySQL) | READY | CI wordpress job; locally 169 tests on both |
| Symlink containment, path traversal, archive extraction | READY | strict mode in CI: never skipped |
| HTTPS-only connector API in production | READY | `CONNECTOR_REQUIRE_HTTPS` defaults on in production (also when left empty) |
| Pairing over HTTPS on a real host | NOT READY | PILOT.md gates |

## Security

| Item | State | Check |
| --- | --- | --- |
| `SECURITY_REMEDIATION_ENABLED=false` | READY | default; `system:check` Safety switches |
| Vulnerability feed decision | NOT CONFIGURED | licensed feed configured, or customers told it is not included |
| Rule set reviewed on real sites | NOT READY | pilot scans, medium/high findings reviewed (SECURITY_INCIDENTS.md checklist) |
| Quarantine outside the web root on pilot sites | NOT READY | `WP_CAREGRID_QUARANTINE_DIR` in each pilot site's wp-config.php |
| Quarantine write, undo, permissions, deny rules | READY | WordPress tests (Linux CI checks permissions) |
| Who may hold `security.remediate` | NOT READY | operator procedure written |
| Secret scanning | READY | gitleaks in CI over the full history: clean (CI run #2); the allowlist holds only named dummy test values |
| Dependency advisories | READY | `composer audit` (app and connector tests) and `npm audit` clean |
| Role isolation (customer / operator / owner) | READY | AuthorizationMatrixTest covers every route of every area |

## Admin Access

| Item | State | Check |
| --- | --- | --- |
| Maintenance access works and is restricted | READY (local) | local E2E: users, plugin install, editor, settings refused |
| Revocation ends the WordPress session | READY (local) | local E2E |
| `ADMIN_ACCESS_OPERATOR_FULL_ADMIN=false` | READY | default; `system:check` Safety switches |
| Tested on a real host over HTTPS | NOT READY | PILOT.md |

## Commercial operations (coupons, PROBONO, recovery offers)

| Item | State | Check |
| --- | --- | --- |
| Owner coupons: percentage 0 < p <= 100, one engine, 100% supported | READY | COUPONS.md; CouponManagementTest, ZeroChargeCheckoutTest |
| 100% coupon against real Stripe test mode (browser) | READY | docs/STRIPE_TEST_MODE.md (2026-10-05) |
| PROBONO internal, not public, no backups | READY | PROBONO.md; ProbonoPlanTest |
| Recovery offers: one-time payment, webhook-authoritative | READY | RECOVERY_OFFERS.md; RecoveryOffersTest, real Stripe test mode |
| Hourly `internal-plans:sync` and `offers:expire` registered in the scheduler | READY | routes/console.php; `php artisan schedule:list`. Running on staging: see "Scheduler" above |
| Invoices for offers and coupons' sales | NOT READY | invoice provider milestone (CLAUDE.md section 23) |

## Owner Admin

| Item | State | Check |
| --- | --- | --- |
| First owner created by invitation | NOT CONFIGURED | `php artisan owner:user` |
| Drafts never public, preview signed and expiring | READY | tests |
| Published changes invalidate caches | READY | tests |
| Unsafe URLs refused, output escaped | READY | tests |
| Hungarian content written | NOT READY | an editorial task; English is the fallback per section |

## Monitoring

| Item | State | Check |
| --- | --- | --- |
| Uptime monitor on `/up` | NOT CONFIGURED | external monitor |
| `system:check --json` monitored | NOT CONFIGURED | cron or monitoring agent; alert on exit code 1 |
| Failed jobs, scheduler heartbeat, connectors offline, failed backups watched | NOT CONFIGURED | owner dashboard / System page |

## Logging

| Item | State | Check |
| --- | --- | --- |
| `LOG_LEVEL=info`, daily rotation | NOT CONFIGURED | `.env`, logrotate or log shipping |
| Important events logged, no secrets | READY | tests check secrets never reach logs |

## CI

| Item | State | Check |
| --- | --- | --- |
| Workflow: quality, Laravel PHP 8.4/8.5 on MySQL 8.4, targeted SQLite regressions, Connector matrix | READY | CI workflow; Connector MariaDB coverage unchanged |
| First green run on GitHub | READY | run #2 (commit `3f01f5d`, 2026-10-04): all 5 jobs green; server 593 tests × 2 with real S3 (moto) and SFTP (OpenSSH), 0 skips; connector 169 tests × 2; gitleaks clean over full history; audits clean. Run #1 failed on CI configuration only (moto path, two test fixtures not allowlisted) |
| Required status checks on protected primary | READY | Ruleset 25300175 requires Quality, Laravel MySQL 8.4/PHP 8.4+8.5, SQLite regression, and three Connector PHP 8.4 contexts |
