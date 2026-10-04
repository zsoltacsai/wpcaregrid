# WP CareGrid — Project Status

> Kept up to date at the end of every milestone (see CLAUDE.md §27).
> A new session should read CLAUDE.md, this file and the relevant docs, then run `git status` and `git log --oneline -12`.

Last updated: 2026-10-04

## Branch and HEAD

- Branch: `claude/ecstatic-wozniak-oemyuf` (also the main branch used for PRs)
- Latest milestone commit: `050afe0` **Production Hardening, CI & Release Readiness**, followed by `3f01f5d` (CI configuration fix) and this status update
- Earlier: `2f7d0b4` Owner Admin & Content Management, `4076684` Security & Malware Engine, `ba2d1db` Internal Operator Console
- Pushed to `origin` (github.com/zsoltacsai/wpcaregrid); push only when requested, never force.

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

1. Stripe test-mode configuration and an end-to-end test purchase (PILOT.md step 4).
2. Production/staging server per DEPLOYMENT.md: HTTPS domain, MySQL, workers, cron, mail, backup storage.
3. Restore rehearsal on a staging copy, then decide on `BACKUP_RESTORE_ENABLED`.
4. First-customer safety gates in PILOT.md on the real installation.
5. GitHub branch protection with the CI checks required.

## Test baseline (2026-10-04)

Linux CI (authoritative): see "CI validation" above.

Windows development machine:

- Server suite on SQLite: see the final report of this milestone (all pass except explicit skips: 6 S3/SFTP tests without local services, 1 Windows-only permission assertion).
- Server suite on MySQL 8.4 (local): all pass with the same explicit skips.
- WordPress connector suite (169 tests) on SQLite and MySQL 8.4: all pass; skips are MySQL-only/SQLite-only tests and explicit Windows symlink/permission limitations (strict in CI).
- Local end-to-end run with a real WordPress and connector 0.4.1: PILOT.md.
- Run with `vendor/bin/phpunit -c phpunit.xml` (`php artisan test` breaks when the path contains `=`). Linux CI is authoritative for filesystem behaviour.

## Next milestone

Finish this one (list above; the next blocker is Stripe test-mode configuration and a test purchase). After that, per CLAUDE.md §29: real Stripe test-mode configuration, real backup storage, transactional email, controlled pilot onboarding.

## Blockers

- External accounts and configuration: Stripe (test mode), backup storage, mail provider, a staging/production server, optionally a vulnerability feed licence.

## Pending manual configuration

- Production `.env` per DEPLOYMENT.md (`APP_ENV=production`, `APP_DEBUG=false`, https `APP_URL`, MySQL, mail, storage, Stripe); never commit it.
- `php artisan migrate --force`, `php artisan connector:package`, `php artisan owner:user …`.
- Supervisor for the two workers, cron for `schedule:run`, nginx with TLS.
- Stripe: 12 HUF prices, webhook with 9 events, `pricing:verify-stripe`.
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
