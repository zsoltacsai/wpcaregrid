# Local Connector E2E environment (Windows)

This setup runs a local Laravel application and an isolated WordPress site on loopback. It is for Connector investigation only; it does not pair the site automatically or contact staging, production, customer sites, Stripe, or Számlázz.hu.

## Requirements discovered on this machine

- Laragon 8.7 supplies PHP 8.3.33, Composer 2.10.2, MySQL 8.4.3, Apache 2.4.68, and PHP’s Apache module. These binaries are used by absolute path; no PATH or system-wide setting is changed.
- The Laravel lockfile requires PHP 8.4.1 or later. `Initialize-Local.ps1` therefore downloads the PHP 8.4.26 x64 Thread Safe archive from the [official PHP Windows downloads](https://www.php.net/downloads.php?os=windows&version=8.4) into ignored `storage/e2e/runtime/`, checks its published SHA-256 (`6e56f0e932e92bfdce208d3a7e6068f6e2f7a19fc2922a5f856fb085f61673f3`), and uses it for Laravel and Composer.
- Docker/Docker Desktop and LocalWP were not found. WSL is installed but has no Linux distribution. Node is 24.15.0 and npm is 11.14.1.

## Initialize, run smoke checks, stop

From either approved Connector E2E worktree:

```powershell
.\scripts\e2e\windows\Initialize-Local.ps1
.\scripts\e2e\windows\Start-Local.ps1
.\scripts\e2e\windows\Stop-Local.ps1
```

Initialization is guarded to the exact approved path/branch pairs `D:\-=AI=-\-=Git=-\wpcaregrid-connector` + `feature/connector-e2e` and `D:\-=AI=-\-=Git=-\wpcaregrid-combined` + `integration/connector-seo`. The second pair is an integration-only allowance in this worktree; path and branch validation remains mandatory. It uses Composer lockfiles with scripts disabled, then runs Laravel package discovery directly with the local PHP executable. Composer home/cache, app environment, generated credentials, runtime files, databases, server logs, Apache config, and the copied WordPress site are under ignored `storage/e2e/` (except its tracked `.gitignore`). Composer packages live in each worktree’s ignored `vendor/` directory. `.env.e2e` is ignored by the repository’s `.env.*` rule. The initializer refuses to overwrite an unrelated `.env.e2e` or unmarked WordPress site directory and validates the required local-only app and Apache settings before startup.

## Local topology and safeguards

| Component | Address / storage | Runtime controls |
| --- | --- | --- |
| Laravel | `http://127.0.0.1:8317`; `storage/e2e/laravel.sqlite` | PHP 8.4.26; array cache/mail; database sessions in the dedicated SQLite file; synchronous queue; local-only URL; Stripe and provider credentials blank; Számlázz issuance and synthetic-test flags off |
| WordPress 7.1.2 | `http://127.0.0.1:8318`; `storage/e2e/wordpress-site/database/wp.sqlite` | Laragon Apache on a private config and PHP 8.3.33; connector 0.4.1 copied and activated; outbound WordPress HTTP blocked except loopback ports 8317/8318; WordPress mail is a no-op |
| Connector API | `http://127.0.0.1:8317/api/v1/connector` | Plugin’s insecure-HTTP exception is enabled only for localhost in this isolated WordPress config |

Both servers bind to `127.0.0.1`. Apache uses a configuration under `storage/e2e`, never Laragon’s shared Apache configuration. It uses a private access-file name so Apache does not read a `.htaccess` file at the drive root. The start script refuses occupied service ports and a busy local egress-sink port. For PHP HTTP clients, non-loopback HTTP(S) uses a closed loopback proxy; WordPress also has `WP_HTTP_BLOCK_EXTERNAL` and an explicit local-only HTTP filter.

The databases are two separate SQLite files inside the E2E directory. Laravel browser sessions are database-backed in its dedicated SQLite file so login persists between HTTP requests; browser fixture cleanup clears that disposable session table. No Laragon MySQL service is started. `WP_CAREGRID_TEST_MYSQL` is forced empty in setup and startup; do not set it for the WordPress test suite because its MySQL bootstrap drops every table in the configured schema. The generated WordPress admin is `e2e-admin` with an `.invalid` email; its random password is held only in ignored `storage/e2e/credentials.json`.

## Smoke assertions

`Start-Local.ps1` checks all of the following before reporting success:

- Laravel `/up` returns HTTP 200.
- Unauthenticated `GET /api/v1/connector/jobs` returns HTTP 401, proving the Connector API route is active and protected.
- WordPress homepage and `/wp-json/` return HTTP 200.
- The Connector plugin is active at version 0.4.1.
- Laravel and WordPress SQLite files are distinct and both remain under `storage/e2e`.

The smoke check does not pair the site. The existing full WordPress plugin test suite and future cross-process Connector scenarios are not run by this script. No shared CI workflow is changed.

## Connector HTTP pairing/auth smoke

With the two local services started, run:

```powershell
.\scripts\e2e\windows\Invoke-ConnectorE2E.ps1
```

The runner rechecks the worktree, branch, loopback listener owners, both SQLite paths, local-only mail/provider settings, WordPress outbound guard, and empty `WP_CAREGRID_TEST_MYSQL` before it creates data. It seeds one uniquely tagged synthetic customer, active plan, website, and one-time code in the local Laravel database. A separate PHP CLI process boots the isolated WordPress site and invokes the Connector plugin's pairing service; the plugin crosses real HTTP to Laravel on port 8317. The runner checks consumed/reused, malformed, expired, and wrong-site pairing requests; absent and malformed credentials; tenant-header scope; revoked credentials; plugin-side plain-HTTP URL policy; credential hash/encryption; and log redaction.

The transient pairing code is kept only in ignored `storage/e2e/connector-e2e-state.json`, never printed, and is removed in the runner's `finally` cleanup. Cleanup removes rows only for the tagged synthetic website and resets the isolated plugin options. If cleanup fails, the runner warns and leaves the state file for inspection; it will refuse to seed over an existing state file. It does not reset either database.

The E2E runner also exercises heartbeat and version persistence, synthetic plugin/theme/core inventory, idempotency and removal, deterministic offered/current/unknown update metadata, health payload validation and persistence, repeated heartbeats, outage/timeout/invalid-response handling, service recovery, and credential preservation. Local transient fixtures make no update-provider requests. Malformed health, inventory, and update data, missing credentials, wrong tenant scope, and log redaction are verified across the HTTP boundary. This local runner is intentionally not wired into shared CI.

The WordPress connector tests use their own fresh SQLite site. Always leave `WP_CAREGRID_TEST_MYSQL` empty: its non-empty MySQL bootstrap drops all tables in the selected schema. On Windows, run the wrapper below. It uses the isolated PHP 8.4 CLI (PDO SQLite and ZIP enabled), waits for PHP to exit, then removes only the exact temporary directory whose random marker matches the current run. This avoids deleting an open SQLite file during PHP shutdown.

```powershell
.\scripts\e2e\windows\Invoke-WordPressTests.ps1
```

Ordinary PHPUnit arguments and test paths can follow the wrapper. It does not modify Laragon's PHP configuration. If cleanup is denied, it prints the exact path and leaves the directory for review.

## Batch 4 — browser pairing, reconnect, and recovery

Run `scripts/e2e/windows/Invoke-ConnectorBrowserE2E.ps1` for the browser lifecycle. It drives the actual Laravel customer pages and WordPress Connector admin through Playwright and Chrome, with two reserved `.example.com` synthetic sites and `.invalid` accounts. Pairing codes and credentials stay in ignored local files and are not printed into test artifacts.

The three browser scenarios passed:

1. Customer login and website registration, tenant access isolation, expired and wrong-site code rejection through the WordPress admin form, successful pairing, connected status on both sides, authenticated heartbeat/inventory/health persistence, disconnect, consumed-code replay rejection, and authenticated fresh re-pair. The form was checked at a 375-pixel viewport and with keyboard navigation.
2. WordPress Apache restart retained the encrypted connector credential; the real admin sync restored heartbeat, inventory, and health state.
3. Laravel outage displayed the WordPress connection error while preserving credentials. Restart then exposed stale state to the customer, queued local health/inventory jobs, and recovered the connection through the WordPress admin sync. The final browser assertions verified the active credential, heartbeat, inventory continuity, and newer health timestamp.

The browser flow checked pairing guidance, success/error/disconnected/connected notices, keyboard focus, and mobile input width. It did not assert loading indicators. Temporary outage retained the credential; explicit disconnect removed it and required a fresh authenticated pairing code. The Batch 2 HTTP contract runner separately verified revoked credentials are rejected.

The restart helper now suppresses inherited child-process output handles and has a 30-second timeout. A direct Laravel restart-helper check completed in 8.6 seconds. The browser runner cleans the two tagged accounts, resets only the isolated Connector settings, and stops its local listeners in `finally`.

### Batch 4 verification record

- Browser pairing/reconnect/recovery: 3 passed.
- Batch 2/3 real HTTP Connector E2E runner: passed, including auth/pairing, heartbeat, inventory, health, updates, outage/timeout handling, recovery, and revocation.
- Laravel Connector and ProductionHardening regression suites: 87 passed, 533 assertions, with a one-run random `APP_KEY` and in-memory PHPUnit SQLite.
- WordPress BackupTest: 17 tests completed, 121 assertions, 4 environment skips.
- Full WordPress suite: 169 tests completed, 1,239 assertions, 11 environment skips, no failures and no unexpected process exit. Runtime was 2m13s; measured peak was 234 MiB.
- PHP lint: the two browser PHP fixture helpers and both changed WordPress test PHP files passed.
- PowerShell parsing: all 7 E2E PowerShell scripts passed.
- Laravel Pint: the E2E PHP helpers passed.
- `git diff --check`: passed.

### Batch 4B — PHPUnit process-exit diagnosis

The pinned local PHP 8.4.26 CLI ini sets `memory_limit=128M`. With that limit, a class run passed its first backup tests and then exhausted memory at `wordpress/wp-caregrid-connector/includes/Backups/Uploader.php:49`, while requesting a 4,194,336-byte allocation for a chunk. A shutdown callback recorded `Allowed memory size of 134217728 bytes exhausted`; peak usage was exactly 128 MiB. The failing retry test alone passed at 8.86 seconds and 92 MiB peak. Windows Application Event Log had no corresponding PHP crash event. It contained one unrelated configuration-parse entry from an initial diagnostic setup attempt; the runtime ini was restored before test runs.

At 256 MiB, the 17-test BackupTest class passed with a 112 MiB peak, but the complete suite later exhausted 256 MiB while building a request chunk in `wordpress/tests/Support/FakeBackupServer.php:69`. At 512 MiB, the full suite completed with a 234 MiB peak. This identifies the cause as an undersized **local PHPUnit process memory limit**, not a backup integrity defect, filesystem failure, antivirus interference, or PHPUnit isolation failure. The process-local runtime ini remains unchanged at 128M. `Invoke-WordPressTests.ps1` now passes `memory_limit=512M` and `display_errors=stderr` only to the test process, leaving production PHP settings and backup behavior unchanged.

These are test-runner measurements for a synthetic suite, not a proven hard minimum for production WordPress backups. The hosting guidance recommends `memory_limit` ≥ 512M for WordPress sites using WP CareGrid, while requiring compatibility verification for lower values; see [Deployment — WordPress PHP memory recommendation](DEPLOYMENT.md#wordpress-php-memory-recommendation). A future Connector health check should report the effective WordPress `memory_limit` and warn below 512M. That check has not been implemented.

`BackupTest.php` also has an uncommitted cleanup-order change that predates Batch 4B and was introduced during Batch 4: its existing tracked fixture removals now run in reverse order. The change was preserved as requested. The captured failure is a memory-limit fatal during chunk allocation; there is no evidence that cleanup order causes that fatal.

The 11 legitimate full-suite skips were 4 Windows symlink-permission cases, 2 Unix-mode-permission cases, and 5 MySQL-only cases. `WP_CAREGRID_TEST_MYSQL` remained empty; the disposable test site used SQLite. Do not enable the destructive shared-MySQL bootstrap to remove these skips.

### Remaining work and scope

Milestone 7 remains active. Batch 4B resolved the local PHPUnit process exit by raising only the wrapper-launched test process limit to 512 MiB; both the focused BackupTest class and the complete runnable WordPress suite now complete. No shared CI workflow was changed. Any later E2E scenario set remains a separately authorized batch.

This local setup remains loopback-only and synthetic. Batch 4 did not contact staging, production, customer sites, Stripe, or Számlázz.hu. No merge, push, or deployment was performed.
