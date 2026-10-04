# Release checklist

Gates before the controlled pilot and before production use. Each item is **READY** (done and verified in the repository or by tests), **NOT READY** (work or a rehearsal is still needed) or **NOT CONFIGURED** (needs production configuration or an external account). States are as of the "Production Hardening, CI & Release Readiness" commit; update them as items are completed (and the summary in PROJECT_STATUS.md).

How to check most items: `php artisan system:check` (OK / WARNING / ERROR / NOT CONFIGURED, never prints secrets) and the owner System page. Deployment steps: [DEPLOYMENT.md](DEPLOYMENT.md); pilot: [PILOT.md](PILOT.md).

## Application

| Item | State | Check |
| --- | --- | --- |
| PHP 8.4 with required extensions | READY | CI runs PHP 8.4 on Ubuntu 24.04; Ubuntu ships 8.3, so the server needs `ppa:ondrej/php`; exact extension list in DEPLOYMENT.md; server: `composer check-platform-reqs --no-dev` |
| Fresh install without dev packages | READY | `composer install --no-dev`, build, migrate, `connector:package`, all caches, rollback and re-migrate verified on an exported copy of the repository (2026-10-04) |
| Staging server `staging.wpcaregrid.com` | NOT CONFIGURED | DEPLOYMENT.md "Staging" (server, `.env`, nginx, Stripe endpoint, checklist) |
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
| Migrations run from an empty database, roll back fully, run again | READY | CI (SQLite and MySQL 8.4); verified locally on MySQL 8.4 |
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
| 2 products and 12 recurring HUF prices created and their IDs set | READY | `php artisan pricing:verify-stripe`: ok for all 12, including the product (2026-10-04) |
| Webhook endpoint + signing secret, 9 events | READY (local) | registered for the temporary tunnel and verified; staging needs its own endpoint for its `APP_URL` |
| Tax setting for Hungary (final price, no VAT added) | READY | Stripe Tax off, no tax rates, `amount_tax` 0 on every session (STRIPE_TEST_MODE.md) |
| Promotion codes (local rules + Stripe discount) | READY | real checkout with `PILOT10`: 8 990 Ft → 8 091 Ft charged; unknown and not-applicable codes refused locally |
| Webhook signature, idempotency, transaction, retries | READY | tests (SubscriptionLifecycleTest, CheckoutTest) |
| Browser success page never activates service | READY | tests |
| Test purchase end to end | READY (local) | Playwright against real Stripe Checkout through an https tunnel (STRIPE_TEST_MODE.md); repeat on staging (PILOT.md step 4) |
| Failed payment, cancellation, webhook replay, delayed webhook | READY | real test-mode runs 2026-10-04 (PROJECT_STATUS.md) |
| Live mode | NOT READY | separate decision after the test-mode pilot (`STRIPE_ALLOW_LIVE_MODE`) |

## Backups

| Item | State | Check |
| --- | --- | --- |
| Storage provider configured (S3 or SFTP) | NOT CONFIGURED | `system:check` Backup storage = OK after a verified backup |
| S3 and SFTP integrations | READY | CI against real services (moto, OpenSSH with pinned host key) |
| Storage secrets never leave the server or reach logs | READY | tests (StorageProviderTest, CredentialExposureTest) |
| Staging disk sized | NOT CONFIGURED | `system:check` warns below `BACKUP_STAGING_MIN_FREE_MB` |
| Retention per plan | NOT CONFIGURED | a commercial decision; nothing is deleted until set |
| Backup and verification end to end | READY (local) / NOT READY (production) | local E2E run (PILOT.md); repeat on production storage |
| Live restore | NOT READY | `BACKUP_RESTORE_ENABLED=false` until the staging rehearsal is signed off |
| Restore rehearsal on staging | NOT READY | PILOT.md "Restore rehearsal" (rehearsed locally on a throw-away site) |

## Connector

| Item | State | Check |
| --- | --- | --- |
| Connector 0.4.1 package built | NOT CONFIGURED | `php artisan connector:package` on the server |
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
| Workflow: quality, server (SQLite, MySQL), WordPress (SQLite, MySQL) | READY | `.github/workflows/ci.yml` |
| First green run on GitHub | READY | run #2 (commit `3f01f5d`, 2026-10-04): all 5 jobs green; server 593 tests × 2 with real S3 (moto) and SFTP (OpenSSH), 0 skips; connector 169 tests × 2; gitleaks clean over full history; audits clean. Run #1 failed on CI configuration only (moto path, two test fixtures not allowlisted) |
| Required status checks on the main branch | NOT CONFIGURED | GitHub branch protection |
