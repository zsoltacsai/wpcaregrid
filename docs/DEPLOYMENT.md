# Deployment

A clean deployment of WP CareGrid from this repository to a Linux server, and how to run it: web server, database, queue workers, scheduler, storage, mail, Stripe and security settings. Release gates are in [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md), the pilot in [PILOT.md](PILOT.md). No real credentials belong in this file or in the repository.

## What runs

```
                 HTTPS (443)
 browsers ──────────────┐        ┌───────── WordPress sites (connector, outbound HTTPS only)
                        ▼        ▼
                  nginx ─▶ PHP-FPM 8.4 (Laravel)
                                 │
         ┌───────────────────────┼─────────────────────────────┐
         ▼                       ▼                             ▼
   MySQL 8.4              queue workers (2)              scheduler (cron, every minute)
   (app data, jobs,       default: mail, owner           presence, backups, retention,
    sessions, cache)      backups: backup transfers      admin access expiry, security
                                 │
                                 ▼
               backup storage (S3-compatible or SFTP), mail (SMTP), Stripe
```

One application server is assumed. Everything below works with more than one (shared database, `onOneServer()` on every scheduled task), but then sessions, cache and the staging directory must be shared or sticky; not covered here.

## Server requirements

| | |
| --- | --- |
| OS | Linux, x86_64 (Ubuntu 24.04 LTS is what CI runs) |
| PHP | **8.4** (CLI and FPM). `composer.json` allows `^8.3`, but CI tests 8.4 only. Ubuntu 24.04 ships 8.3, so install 8.4 from the `ppa:ondrej/php` repository |
| PHP extensions | Required by the locked packages (`composer check-platform-reqs --no-dev --lock`): ctype, curl, dom, fileinfo, filter, hash, iconv, json, libxml, mbstring, openssl, pcre, session, simplexml, tokenizer. Required by the application: **intl** (`Number::fileSize`), **zip** (`connector:package`), **pdo_mysql** (MySQL), **pcntl** (CLI only: queue worker `--timeout`). Ubuntu packages: `php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-intl` (the others are built in) |
| Database | **MySQL 8.4 LTS** (tested in CI, migrations roll back fully). Ubuntu 24.04 ships 8.0 (end of life April 2026); install 8.4 from the MySQL APT repository. MariaDB is not tested. SQLite is for development and tests only |
| Web server | nginx with TLS (Let's Encrypt / certbot) |
| Process supervisor | supervisor (or systemd), for the two queue workers |
| Build tools | Composer 2; Node.js **22** + npm to build assets (Vite 8 needs Node ≥ 20.19 or ≥ 22.12; Ubuntu 24.04 ships 18, so use the NodeSource 22.x repository) |
| Disk | Application + logs, plus the backup **staging** space (see Capacity) |

PHP settings for the web (FPM) pool: `upload_max_filesize` and `post_max_size` ≥ 16M (backup chunks are 4 MiB by default, 8 MiB at most), `memory_limit` 256M, `max_execution_time` 60. The CLI (workers) uses no time limit; the workers' own `--timeout` applies.

## First installation

```sh
# as the deploy user, e.g. in /var/www/wpcaregrid
git clone <repository> /var/www/wpcaregrid && cd /var/www/wpcaregrid
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build            # public/build; node_modules can be removed afterwards
cp .env.example .env               # then fill in the production values (next section)
php artisan key:generate
php artisan migrate --force
php artisan connector:package      # the plugin ZIP customers download (version = CONNECTOR_LATEST_VERSION)
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan system:check           # must not report ERROR; see below
php artisan owner:user owner@your-domain --name="Owner"   # prints a one-time invitation link
```

Permissions: the web and worker users write to `storage/` and `bootstrap/cache/` only; nothing else needs to be writable, and `storage:link` is not needed (no public disk is used). The document root is `public/` and only `public/`; `storage/app/private` (connector package, backup staging, vulnerability feed) is never served (the `local` disk's HTTP serving is switched off).

Files are owned by the deploy user; the web, worker and cron processes run as `www-data`. Give both users write access to the two writable trees with default ACLs, so files created by either stay writable by the other (otherwise a log file created by a deploy command can lock out the web server):

```sh
sudo setfacl -R  -m u:www-data:rwX -m u:deploy:rwX storage bootstrap/cache
sudo setfacl -dR -m u:www-data:rwX -m u:deploy:rwX storage bootstrap/cache
```

No seeders run in production: `migrate --force` creates empty tables only. Demo data exists only in development databases.

### Production `.env` (the values that differ from `.env.example`)

| Setting | Production value |
| --- | --- |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | `https://<your domain>` (links in emails, redirects and signed URLs use it) |
| `APP_KEY` | generated once (`php artisan key:generate`), kept secret, never reused; also encrypts Owner-managed integration credentials stored in the database. Preserve a secure recovery copy with the deployment secrets. Losing it makes those credentials undecryptable. |
| `LOG_LEVEL` | `info` (or `warning`) |
| `DB_*` | MySQL connection, a dedicated database user with rights on this database only |
| `QUEUE_CONNECTION` | `database` |
| `MAIL_*` | a real mailer (SMTP), `MAIL_FROM_ADDRESS` on your domain |
| `STRIPE_*` | see Stripe below |
| `BACKUP_*` | see Backup storage below |
| `TRUSTED_PROXIES` | only behind a load balancer / reverse proxy: its address(es) |
| `HTTP_CSP` | `report-only` first, `enforce` after checking (see HTTP security) |

Defaults that must **stay** as shipped until their documented approval: `SECURITY_REMEDIATION_ENABLED=false`, `ADMIN_ACCESS_OPERATOR_FULL_ADMIN=false`, `BACKUP_RESTORE_ENABLED=false`, `SECURITY_REMEDIATION_ALLOW_DISABLE_PLUGIN=false`. `php artisan system:check` shows any of them that is on ("Safety switches").

Leave optional switches with secure defaults unset rather than empty: an empty `HTTP_FORCE_HTTPS=`, `HTTP_HSTS=`, `SESSION_SECURE_COOKIE=` or `CONNECTOR_REQUIRE_HTTPS=` counts as unset, so the secure default applies.

## Web server

nginx, PHP-FPM socket, TLS from Let's Encrypt (certbot) or your provider:

```nginx
server {
    listen 80;
    server_name app.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name app.example.com;
    root /var/www/wpcaregrid/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/app.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/app.example.com/privkey.pem;

    client_max_body_size 16m;          # backup chunks
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 120;
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

### HTTP security

`App\Http\Middleware\SecurityHeaders` adds to every response: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN` (`DENY` on the admin access hand-over page), `Referrer-Policy: strict-origin-when-cross-origin` (`no-referrer` where a token is involved), `Permissions-Policy`, `Cross-Origin-Opener-Policy`. With `APP_URL=https://…`:

- plain HTTP is redirected to HTTPS (except `/up`) and all generated links are `https://` (`HTTP_FORCE_HTTPS`);
- session cookies are `Secure`, `HttpOnly`, `SameSite=Lax` (`SESSION_SECURE_COOKIE`);
- in production, `Strict-Transport-Security` is sent (`HTTP_HSTS`, 180 days, no subdomains unless `HTTP_HSTS_INCLUDE_SUBDOMAINS=true`);
- the connector API refuses plain HTTP (`CONNECTOR_REQUIRE_HTTPS`).

Content-Security-Policy is staged: `HTTP_CSP=off` (default) → `report-only` on staging (browser console shows what would be blocked) → `enforce`. The policy allows the app's own scripts and styles, Alpine.js (`'unsafe-eval'`), the one inline script by nonce, and form posts to `https:` (admin access hands a token to the customer's site).

Behind a proxy that terminates TLS, set `TRUSTED_PROXIES`, or Laravel sees plain HTTP and redirects in a loop.

The customer, operator and owner areas use separate guards; a session is regenerated at every sign-in and invalidated at sign-out.

## Queue workers

Two workers, both under a process supervisor. The backup worker has its own queue connection whose `retry_after` (transfer timeout + 600 s) is longer than any transfer, so a running transfer is never picked up a second time.

```ini
; /etc/supervisor/conf.d/wpcaregrid.conf
[program:wpcaregrid-default]
command=php /var/www/wpcaregrid/artisan queue:work database --queue=default --sleep=3 --tries=3 --backoff=60 --timeout=120 --max-time=3600 --memory=256
user=www-data
autostart=true
autorestart=true
stopwaitsecs=150
stdout_logfile=/var/www/wpcaregrid/storage/logs/worker-default.log

[program:wpcaregrid-backups]
command=php /var/www/wpcaregrid/artisan queue:work backups --queue=backups --sleep=3 --timeout=3660 --max-time=14400 --memory=512
user=www-data
autostart=true
autorestart=true
stopwaitsecs=3700
stdout_logfile=/var/www/wpcaregrid/storage/logs/worker-backups.log
```

| Queue | Jobs | Retry behaviour |
| --- | --- | --- |
| `default` | emails (activation, payment, invitations…) | up to 3 tries, 60 s apart; a duplicate email is the worst case |
| `backups` | `TransferBackup`: store a staged backup in storage and verify it | its own tries (`BACKUP_TRANSFER_TRIES`, back-off 60/300/900 s); idempotent (status guard; stored objects are not uploaded twice); at most one queued per backup; a transfer abandoned by a dead worker is handed over again by `backup:monitor`; a checksum mismatch marks the backup corrupt, never "verified" |

Connector jobs (backups, updates, restores, scans, admin access) are **not** queue jobs: the sites fetch them. They run once (UUID), are never replayed blindly, and stuck ones time out (`backup:monitor`, `security:monitor`, `admin-access:expire`). Stripe webhooks are processed in the request (signature, idempotent by event ID, in a transaction; Stripe retries on failure).

One `backups` worker transfers one backup at a time. Add workers only with enough staging disk and storage bandwidth.

Failed jobs: `php artisan queue:failed` (also counted on the owner System page and in `system:check`). Retrying a `TransferBackup` is safe (`php artisan queue:retry <id>`). Deploys must restart workers: `php artisan queue:restart` (they finish the current job first, which is why `stopwaitsecs` is longer than `--timeout`).

## Scheduler

Exactly one cron entry, on one server:

```cron
# crontab -u www-data -e   (the same user as the workers)
* * * * * cd /var/www/wpcaregrid && php artisan schedule:run >> /dev/null 2>&1
```

(`php artisan schedule:work` in the foreground is for development.) Every task is `withoutOverlapping()` and `onOneServer()`; the locks use the cache store (`CACHE_STORE=database`). Times are UTC (`config/app.php`), so "03:30" is 03:30 UTC. `system:check` reports the scheduler as NOT CONFIGURED until the first run records its heartbeat.

| Task | When | Purpose |
| --- | --- | --- |
| `connector:update-presence` | every 10 min | mark sites that stopped reporting in |
| `backup:schedule` | hourly | queue due scheduled backups per plan |
| `backup:monitor` | every 10 min | time out stuck uploads, hand over lost/abandoned transfers, clean staging |
| `backup:retention` | daily 03:30 | apply per-plan retention (nothing is deleted until retention is configured) |
| `admin-access:expire` | every minute | expire unused tokens and ended sessions |
| `security:monitor` | every 10 min | time out stuck scans |
| `security:schedule` | hourly | scheduled scans (only with `SECURITY_SCHEDULE_ENABLED=true`) |
| `security:vulnerabilities:sync` | daily 04:10 | refresh the vulnerability feed (only when configured) |
| `internal-plans:sync` | hourly | start PROBONO plans at their start date, end them at their end date |
| `offers:expire` | hourly | expire recovery offers whose validity passed, stop their unpaid Checkout Sessions |
| `system:scheduler-heartbeat` | every minute | proof for the System page that the scheduler runs |

## Mail

Transactional only (activation, welcome, payment confirmation and failure, subscription changes, operator/owner invitations).

Transports that work with the installed packages: **SMTP** (any provider's SMTP relay: `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT=587` with STARTTLS or `465` with `MAIL_SCHEME=smtps`, `MAIL_USERNAME`, `MAIL_PASSWORD`) and **Amazon SES** (`MAIL_MAILER=ses` with the `AWS_*` keys; the AWS SDK is installed). SMTP password and SES access/secret values may be entered under Owner → Integrations; mailer selection and non-secret server settings remain in environment configuration. Laravel's `postmark` and `resend` API transports need packages that are not installed: the Owner page can store those API keys but they are not operationally consumed; use those providers through their SMTP relay. `MAIL_FROM_ADDRESS` must be on a domain verified at the provider with SPF and DKIM (and a DMARC record), or mail lands in spam or is rejected. Mailpit and `log` are development tools only. All mail is queued (`default` worker) and sent after the database transaction that triggered it commits. Links are built from `APP_URL` (also in the worker), so production mail never contains `localhost` links: `system:check` reports a local `APP_URL` in production as an error. Mail contains links, never passwords or tokens other than the single-use link itself. A failure is a failed job (visible on the System page) and a log entry. `MAIL_MAILER=log` writes mail to the log file and is reported as "not configured".

## Stripe (test mode first)

Keys may be supplied through the environment or replaced in Owner → Integrations. Owner-managed values are encrypted with `APP_KEY`, applied to the Stripe config at runtime, and never displayed after saving. `STRIPE_ALLOW_LIVE_MODE` remains a server-side safety switch; live keys are refused unless it is deliberately true.

Full procedure, price mapping, tax setting and troubleshooting: [STRIPE_TEST_MODE.md](STRIPE_TEST_MODE.md).

1. In the Stripe dashboard (test mode) create two products, **WordPress** and **WooCommerce**, and **12 recurring prices in HUF**, exactly matching `config/pricing.php`:

   | Plan | Monthly | Annual |
   | --- | ---: | ---: |
   | WordPress Basic | 8 990 Ft | 89 900 Ft |
   | WordPress Business | 18 990 Ft | 189 900 Ft |
   | WordPress Secure | 28 990 Ft | 289 900 Ft |
   | WooCommerce Basic | 14 900 Ft | 149 000 Ft |
   | WooCommerce Business | 24 900 Ft | 249 000 Ft |
   | WooCommerce Secure | 39 900 Ft | 399 000 Ft |

   Monthly prices recur every month, annual every year (interval count 1).
2. Put the `price_…` IDs into `STRIPE_PRICE_<PRODUCT>_<PLAN>_<INTERVAL>_HUF`, the product IDs into `STRIPE_PRODUCT_WORDPRESS` / `STRIPE_PRODUCT_WOOCOMMERCE`; set `STRIPE_SECRET` (`sk_test_…`) and `STRIPE_KEY` (`pk_test_…`) in the environment or Owner → Integrations.
3. Add a webhook endpoint `https://<APP_URL>/stripe/webhook` with these events: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.paid`, `invoice.payment_failed`. Set `STRIPE_WEBHOOK_SECRET` in the environment or Owner → Integrations.
4. `php artisan config:cache && php artisan pricing:verify-stripe` must print `ok` for every price (exists, active, recurring, same amount, currency and interval, attached to the configured product). The owner area's "Check prices in Stripe" runs the same check.
5. Test purchase with Stripe's test card. The account is created only when the signed webhook arrives; the success page alone never activates anything.

Without `STRIPE_SECRET` checkout refuses to start, `system:check` reports Stripe as NOT CONFIGURED, and the webhook answers 500 "not configured" (Stripe will retry).

Prices changed later in the owner area affect new checkouts only (docs/PRICING_ADMIN.md).

## Backup storage

Set `BACKUP_STORAGE=s3` or `sftp` (empty = backups unavailable, `local` = development only and reported as such).

**S3-compatible:** `BACKUP_S3_ENDPOINT` (empty for AWS), `BACKUP_S3_REGION`, `BACKUP_S3_BUCKET`, `BACKUP_S3_KEY`, `BACKUP_S3_SECRET`, optional `BACKUP_S3_PREFIX`, `BACKUP_S3_PATH_STYLE` as the provider requires, and `BACKUP_S3_ENCRYPTION_MODE`. The access/secret pair and SSE-C key can be set in the environment or Owner → Integrations; endpoint, region, bucket, prefix, path-style and encryption mode remain server configuration. The default `legacy` preserves `BACKUP_S3_SSE` (`AES256` by default). Explicit `sse-s3` uses `BACKUP_S3_SSE`; `sse-c` selects customer-provided-key encryption and ignores `BACKUP_S3_SSE` for encryption-header selection. `BACKUP_S3_SSE=AES256` does not select SSE-C. `none` sends no encryption header and is allowed in production only with `BACKUP_S3_ALLOW_UNENCRYPTED=true`, an explicit operator confirmation that the provider supplies at-rest encryption. For `sse-c`, the key must be exactly 64 hexadecimal characters encoding 32 random bytes. The key is not stored by the object-storage provider; losing it makes those backups unrecoverable. If Owner-managed, its database copy is encrypted with `APP_KEY`; keep a separate recoverable escrow copy. Never place it in source control, logs, or `.env.example`. Existing deployments keep their `BACKUP_S3_SSE` behavior in `legacy` mode. Use a private bucket and restrict credentials to the configured backup object prefix. The application needs object-level put, get, and delete; `HeadObject` is authorized by get. It does not list buckets or objects, create/delete buckets, copy, or tag objects. Where the service returns a native SHA-256 checksum it is used; otherwise objects are read back and hashed (`BACKUP_VERIFY_READBACK`).

SSE-C support is implemented in the current local code changes. CI validation for these changes is pending, and no real Hetzner Object Storage E2E validation has been performed. In particular, Hetzner `HeadObject` checksum behavior remains unverified; the existing SHA-256/readback verification contract has not been weakened.

**SFTP:** `BACKUP_SFTP_HOST`, `BACKUP_SFTP_PORT`, `BACKUP_SFTP_USERNAME`, a private key file (`BACKUP_SFTP_PRIVATE_KEY`, preferred) or password, `BACKUP_SFTP_ROOT`, and the **required** host key fingerprint `BACKUP_SFTP_HOST_FINGERPRINT` (`ssh-keyscan -t ed25519 host | ssh-keygen -lf -`, the `SHA256:…` part). An unknown host key is refused.

Storage credentials stay on this server: they are never sent to WordPress, never logged, never shown (the System page shows only "Configured"). Object names are `{website_uuid}/{backup_uuid}/…`, never customer names or URLs.

### Capacity

- **Staging:** every upload is written to `BACKUP_STAGING_PATH` (default `storage/app/private/backup-staging`) and removed once stored and verified; staged data of failed backups is removed after `BACKUP_STAGING_RETENTION_HOURS` (24) by `backup:monitor`. Size this disk for the largest site's full backup times the number of uploads that can run at once, with margin. `system:check` and the System page warn below `BACKUP_STAGING_MIN_FREE_MB` (20 GiB by default).
- **Object size:** `BACKUP_MAX_OBJECT_SIZE` (50 GiB default) caps one archive; larger uploads are refused.
- **Memory:** uploads, transfers and restores are streamed in chunks; a backup is never loaded into memory whole.
- **Large sites:** the connector splits the archive into chunks (`BACKUP_CHUNK_SIZE`, 4 MiB) and resumes interrupted uploads; the transfer worker's timeout (`BACKUP_TRANSFER_TIMEOUT`, 1 h) must cover storing and verifying the largest site.

### Backups of WP CareGrid itself

The application database (customers, subscriptions, audit logs, backup records) needs its own nightly backup (e.g. `mysqldump --single-transaction`) to storage separate from the server, plus `.env` kept in a password manager. Not done by the application.

## Restore

Live restore is off (`BACKUP_RESTORE_ENABLED=false`) until the staging rehearsal in RELEASE_CHECKLIST.md is done. When on, it still requires a verified backup of the same website, an active connector with the restore capability, the typed confirmation, a verified safety backup of the current site first, and post-restore health checks (BACKUP_ENGINE.md). Restores can be partial if a site fails midway; the safety backup is the way back.

## Security configuration

- Malware rules ship with the connector (rule set `2026.10.1`, connector 0.4.1); severity and confidence are separate; scanners never confirm malware.
- Vulnerability data: `SECURITY_VULNERABILITY_SOURCE=wordfence` with a licensed feed (`SECURITY_VULNERABILITY_FEED_URL`, `…_KEY`), or leave `none` and tell customers vulnerability checks are not included; the UI then says "not configured", never "no vulnerabilities".
- Scheduled scans: `SECURITY_SCHEDULE_ENABLED=true` when wanted (frequency from the plan).
- Remediation stays off (`SECURITY_REMEDIATION_ENABLED=false`); the checklist before turning it on is in SECURITY_INCIDENTS.md.
- On customer sites: `define('WP_CAREGRID_QUARANTINE_DIR', '/path/outside/the/web/root');` in `wp-config.php` where the host allows it. The default location inside `wp-content` relies on deny files (Apache, IIS) and an unguessable directory name; nginx ignores `.htaccess`.

## Logging and monitoring

- `LOG_CHANNEL=daily` (or `stack` with `daily`), `LOG_LEVEL=info`; rotate or ship `storage/logs`. Logged: checkout and subscription changes, connector pairing and failures, every website event (backup, restore, update, security scan, remediation, admin access), owner changes (content, prices, operators, customers), sign-ins refused. Never logged: passwords, tokens, connector credentials, storage or Stripe secrets, pairing codes (tests check this).
- Health endpoint for the load balancer / uptime monitor: `GET /up`.
- `php artisan system:check --json` for monitoring (exit 1 on any ERROR, and in production on NOT CONFIGURED). It reads configuration and the database only; it never contacts Stripe or storage, so it is cheap to run every few minutes.
- Watch: failed jobs, the scheduler heartbeat, connectors not reporting in, failed backups (owner dashboard / System page).

## Updating a running installation

### Staging deployment

Use the repository-controlled `scripts/deploy-staging.sh` on the staging VPS only. Run it as `deploy`, from `/var/www/wpcaregrid`, with the exact full commit SHA to deploy:

```sh
cd /var/www/wpcaregrid
bash scripts/deploy-staging.sh <full-40-character-commit-sha>
```

With no argument, the script deploys the current tip of `claude/ecstatic-wozniak-oemyuf`. A supplied SHA must exist in that branch's fetched history; moving refs and short SHAs are rejected. The script serializes concurrent deploys, rejects dirty or untracked repository state, and never runs `git clean` or resets local changes. `.env` and `storage/` are deployment data, not release files.

Before the first use, the staging HTTPS server block in `/etc/nginx/sites-available/wpcaregrid` must contain this gate inside the `staging.wpcaregrid.com` TLS server only:

```nginx
    if (-f /var/lib/wpcaregrid/deploy-maintenance) {
        return 503 "Staging deployment in progress.\n";
    }
```

Validate the Nginx configuration and reload it after adding the gate. Do not add this to a production server block. The script verifies the active configuration and probes that the gate returns HTTP 503 before it changes the release. The marker is stored under `/var/lib`, outside the web root, and persists through a reboot so an interrupted deployment remains closed rather than falling back to PHP-FPM.

The script then follows this order:

1. Verify PHP 8.4, Composer 2, Node.js 22, the protected `.env`, writable application directories, cron, the active config cache, baseline HTTP health and both Supervisor workers.
2. Fetch the staging branch, resolve one immutable commit SHA and create a new gzip-verified MySQL backup in `/home/deploy/private-backups`. Existing backups are never overwritten or removed. Automated snapshot pruning is intentionally disabled; define and approve a separate retention policy before enabling any cleanup.
3. Enable the Nginx 503 gate, then Laravel maintenance mode. The Nginx gate runs before PHP-FPM, so a request cannot boot Laravel while its configuration cache is absent.
4. Stop `wpcaregrid-default` and `wpcaregrid-backups` through Supervisor. Supervisor waits for each worker's configured graceful stop interval, allowing an in-progress job to finish before application files change.
5. Check out the exact SHA, install Composer dependencies with `--no-dev`, verify PHP-FPM can read the Composer autoloader and PHP dependency files, build Vite assets, remove build-only `node_modules`, and run migrations with `--force`.
6. Run `optimize:clear` and rebuild config, route, view and event caches while the Nginx gate is still active. `optimize:clear` also clears the cached scheduler heartbeat; the script waits for the normal cron tick to restore it before opening HTTP. Bootstrap config cache contains secrets, so bootstrap cache files are private to `deploy` and readable by `www-data` through per-file ACLs; group and other access is removed and checked. Compiled Blade views must be readable by PHP-FPM; the script resets prior default ACLs, reapplies the documented deploy/`www-data` entries, and verifies readability before and after the HTTP smoke. `.env` remains `deploy:deploy` mode `0600` throughout. Rebuild the private connector ZIP only when `CONNECTOR_LATEST_VERSION` changes.
7. Bring Laravel out of maintenance mode while the Nginx gate remains active. Signal `queue:restart`, start both named Supervisor programs, and require each to be `RUNNING` with a new PID. This lets workers boot normally without exposing requests during the transition.
8. Verify migration status, scheduler availability and `system:check`. `application`, `database`, `queue`, `scheduler` and `safety` must be `OK`; any `ERROR` fails the deploy. Expected staging `WARNING` and `NOT CONFIGURED` integrations (for example Stripe, mail, connectors or backup storage) are reported but do not block deployment.
9. Remove the Nginx gate and test the HTTPS homepage, `/up`, the guest Owner authentication redirect, `/.env` and `/.git/config`; recheck worker states before marking the gate released. The script keeps its recovery flag active through these checks and reinstalls the gate before recovery if interrupted. Any failed HTTP, permission or worker check re-engages the gate and Laravel maintenance mode.

The deployment is an in-place, single-node update. It provides a controlled 503 during code, migration and cache transitions, not zero downtime. The one-minute cron continues to invoke `schedule:run`; Laravel maintenance mode suppresses ordinary scheduled tasks during the critical section. Queue workers are stopped so jobs cannot execute against mixed code. The approach does not provide multi-node coordination or blue/green rollback.

The script exits non-zero on any failed command. Before a code switch, its exit handler tries to restore the still-current healthy release. After a code switch, it only reopens traffic if Composer, asset build, migration and any required connector package build all completed and caches, critical health checks, scheduler and workers can be verified. If that is not safe, it deliberately leaves the Nginx gate active and attempts to leave Laravel in maintenance mode; if Laravel cannot boot, the Nginx gate still returns the controlled 503. It prints the recovery marker path. It never runs an automatic migration rollback.

For a failed deployment, inspect the exact phase and logs first. Keep `/var/lib/wpcaregrid/deploy-maintenance` in place until the code, production dependencies, database and caches agree. Restore a known commit and its production dependencies, rebuild all four caches, verify critical `system:check` components and both workers, then run `php artisan up` and remove the gate. Do not remove the gate merely to test whether the site has recovered.

This script is staging-specific. Production deployment remains a separately reviewed operation. Run the full read-only HTTP smoke test against the result when required: `php tests/smoke/http-smoke.php https://<APP_URL>`.

### Manual production update reference

For a separately approved production release, preserve the existing production procedure and take a protected database backup before migration. Run Artisan as the deployment user with the documented ACLs; keep `.env` mode `0600`, rebuild the config cache before reopening traffic, restart queue workers, and perform the health checks. The staging deployment script must not be pointed at production.

## Rollback

1. `php artisan down --retry=60`.
2. If the release added migrations and they must be undone: `php artisan migrate:rollback --step=<number of new migrations> --pretend` first, then without `--pretend`. Every migration has a working `down()` (CI migrates, rolls everything back and migrates again on SQLite and MySQL), but rolling back drops columns and tables with their data. When in doubt, restore the dump taken before the update instead.

   **Data loss in owner-managed migrations.** Rolling back a release that contains these migrations destroys data that cannot be rebuilt from Stripe or from the code. The commercial migrations are documented in COUPONS.md, PROBONO.md and RECOVERY_OFFERS.md:

   | Migration | What its `down()` removes |
   | --- | --- |
   | `add_internal_plans_to_subscriptions` | **deletes every PROBONO / internal-plan subscription row** (they have no Stripe counterpart), then drops the internal-plan columns (`internal_plan`, dates, internal note, assigned-by) |
   | `create_recovery_offers_table` | **drops the `recovery_offers` table** with every offer, its scope, price, status and payment reference |
   | `add_owner_management_to_promotions` | drops the coupon duration, per-customer limit, internal note, Stripe coupon ID and creator columns, and turns `percent_off` back into a whole number (a fractional percentage such as 12.5 is rounded) |
   | `create_integration_credentials_table` | drops every Owner-managed encrypted integration credential; recover them only from a protected database backup plus the matching `APP_KEY` |

   A database backup is therefore **required before any rollback that includes these migrations**: the `mysqldump` from "Updating a running installation" taken immediately before the update is the one to keep. Do not run `migrate:rollback` for them without it. A payment already confirmed by Stripe for an offer is not undone by dropping the table; the offer record would be gone, so restore the dump if the offers must survive.
3. For staging, keep the Nginx maintenance gate active while restoring a known-good full commit SHA with `scripts/deploy-staging.sh <sha>`. The script uses the same guarded dependency, migration, cache and worker sequence. Do not manually remove the gate during recovery. For production, use a separately reviewed recovery procedure that keeps requests gated while dependencies and all four caches are restored.
4. Restore database state only when required by the failed migration and only after reviewing the migration's `down()` behavior and the protected pre-deploy dump. Never run an automatic destructive rollback as part of deployment recovery.
5. Verify `system:check`, both queue workers, scheduler and the HTTP/security smoke checks before reopening traffic.

Stripe events that arrived during the downtime are retried by Stripe; connectors retry on their own.

## Staging: staging.wpcaregrid.com

Staging runs the same code and configuration as production would, against Stripe **test mode**, a real mail provider and real S3-compatible storage, with non-production WordPress sites. It is where PILOT.md's end-to-end test and the restore rehearsal run. It is **not** production: no real customers, no live payments.

### Server

Ubuntu 24.04 x86_64, 2 vCPU, 4 GB RAM, 80 GB SSD, public IPv4. DNS: an `A` record for `staging.wpcaregrid.com`. Firewall: only 22, 80 and 443 open (`ufw allow OpenSSH`, `ufw allow 'Nginx Full'`); MySQL listens on 127.0.0.1 only.

```sh
sudo add-apt-repository ppa:ondrej/php
# MySQL 8.4 LTS: add the MySQL APT repository (dev.mysql.com/downloads/repo/apt), select "mysql-8.4-lts"
# Node.js 22: add the NodeSource 22.x repository
sudo apt update
sudo apt install nginx mysql-server supervisor git unzip acl certbot python3-certbot-nginx nodejs \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-intl
# Composer 2: getcomposer.org/download (verify the installer checksum)
php -v && mysql --version && node -v && php -m | grep -Ei 'intl|zip|pdo_mysql|pcntl'
```

On 4 GB: `innodb_buffer_pool_size = 1G`, PHP-FPM `pm.max_children = 10`; the PHP settings from Server requirements above.

Database: `CREATE DATABASE wpcaregrid CHARACTER SET utf8mb4 COLLATION utf8mb4_unicode_ci; CREATE USER 'wpcaregrid'@'localhost' IDENTIFIED BY '<generated>'; GRANT ALL ON wpcaregrid.* TO 'wpcaregrid'@'localhost';`

Code: the repository is private; give the server a read-only GitHub **deploy key**, clone into `/var/www/wpcaregrid` as the deploy user, then follow First installation and the ACLs above.

### Staging `.env`

Production values from the table above, and:

| Setting | Staging value |
| --- | --- |
| `APP_ENV` | `production`. The application's production safeguards are tied to this value: `system:check` fails on missing configuration, debug / non-https `APP_URL` are errors, HSTS and connector HTTPS are on by default, admin access is handed over to `https` sites only. Staging must rehearse them; it is told apart by `APP_URL` and `APP_NAME` |
| `APP_NAME` | `"WP CareGrid Staging"` (shown in page titles and as the mail sender name) |
| `APP_URL` | `https://staging.wpcaregrid.com` |
| `APP_DEBUG` | `false` |
| `APP_LOCALE` | the language for visitors who have not chosen: `hu` or `en` (docs/LOCALIZATION.md). Everyone can switch; the choice is remembered in a cookie and, for customers, on their login |
| `LOG_CHANNEL` / `LOG_LEVEL` | `daily` / `info` |
| `MAIL_*` | the provider's SMTP relay; `MAIL_FROM_ADDRESS` on a verified domain (for example `staging@wpcaregrid.com`) |
| `STRIPE_*` | test-mode keys (`sk_test_…`, `pk_test_…`), the 12 price IDs and 2 product IDs (STRIPE_TEST_MODE.md), the staging endpoint's `whsec_…`, `STRIPE_ALLOW_LIVE_MODE=false` |
| `BACKUP_STORAGE` | `s3`, with endpoint, region, private staging-only bucket, credentials scoped to the backup object prefix (put, get, delete; no bucket listing required), prefix and provider-specific path style. Select `BACKUP_S3_ENCRYPTION_MODE` explicitly when changing from the legacy `BACKUP_S3_SSE=AES256` behavior. In `sse-c` mode the legacy `BACKUP_S3_SSE` setting is ignored; `BACKUP_S3_SSE=AES256` does not activate SSE-C. For Hetzner Object Storage use `sse-c` and a securely escrowed `BACKUP_S3_SSE_C_KEY`; no real Hetzner E2E has been performed and `HeadObject` checksum compatibility remains unverified. |
| `BACKUP_RETENTION_*` | set per plan before scheduled backups run for long (no default: nothing is deleted while unset) |
| `HTTP_CSP` | `report-only` |
| Safety switches | unchanged: `SECURITY_REMEDIATION_ENABLED=false`, `ADMIN_ACCESS_OPERATOR_FULL_ADMIN=false`, `BACKUP_RESTORE_ENABLED=false` (until the restore rehearsal) |

There is no application-level backup encryption key: archives are protected in transit by TLS and at rest by the selected storage encryption. With SSE-C, the key is not stored by the provider and is required for every read/restore; losing it makes associated backups unrecoverable. The key may be supplied through `BACKUP_S3_SSE_C_KEY` or Owner → Integrations, where the database copy is encrypted with `APP_KEY`. Escrow the SSE-C key independently and securely. `APP_KEY` signs sessions, cookies and activation tokens and encrypts Owner-managed integration credentials; keep a secure recovery copy with deployment secrets. Losing `APP_KEY` prevents decryption of those credentials, and changing it without re-encrypting the stored values makes them unusable. Recovery of Owner-managed values requires both the protected database backup and the matching `APP_KEY`; disaster recovery must preserve that pairing. Backups containing the `integration_credentials` table must be protected to the same standard as the key.

### nginx

The server block from Web server above with `server_name staging.wpcaregrid.com`, `root /var/www/wpcaregrid/public`, the certificate from `sudo certbot --nginx -d staging.wpcaregrid.com`, and one addition so staging is never indexed (the marketing pages say `index,follow`):

```nginx
    add_header X-Robots-Tag "noindex, nofollow" always;
```

Do not put HTTP basic authentication in front of the whole site: Stripe webhooks (`/stripe/webhook`) and connectors (`/api/v1/connector/…`) must reach it.

### Stripe (test mode)

Use the existing test sandbox (STRIPE_TEST_MODE.md): the same products and prices. **Coupons are not carried over.** They are managed in the Owner Admin (Owner → Coupons, [COUPONS.md](COUPONS.md)), and staging does not depend on a promotion code created by hand in the Stripe dashboard: the staging `promotions` table starts empty, and when the owner creates a coupon in the WP CareGrid Owner interface the application creates the matching Stripe coupon and promotion code itself (`PromotionGateway`, with the staging `STRIPE_SECRET`). A code that exists only in Stripe, or only from an earlier local test, is not accepted by the staging checkout; there is nothing to copy or sync. Create a **new** webhook endpoint `https://staging.wpcaregrid.com/stripe/webhook` with the nine events and put its signing secret into the staging `.env`. Delete the temporary endpoint left from the local tunnel test. Every endpoint of a sandbox receives all of its events, so keep only staging's endpoint enabled while staging is in use (events for checkouts it did not create are ignored, but they clutter its logs).

### Staging checklist

1. Packages installed; `php -v` 8.4, `mysql --version` 8.4, `node -v` 22; `composer check-platform-reqs --no-dev`.
2. First installation done; `.env` per the table; caches built; ACLs set.
3. nginx + certificate; `https://staging.wpcaregrid.com/up` answers 200; `http://` redirects; `X-Robots-Tag` present.
4. Supervisor running both workers (`supervisorctl status`); cron as `www-data`.
5. `php artisan system:check` exits 0: every part OK (scheduler after its first minute).
6. `php artisan pricing:verify-stripe`: ok for all 12.
7. Smoke test: `php tests/smoke/http-smoke.php https://staging.wpcaregrid.com`.
8. Owner created (`php artisan owner:user <email> --name="…"`); invitation email received through the real provider (not spam); link is `https://staging.wpcaregrid.com/…`.
9. PILOT.md end-to-end run, including the Stripe test purchase from `/pricing` with the webhook arriving at staging.
10. A manual FULL backup of a test site reaches the bucket and is **verified**; then the restore rehearsal (RELEASE_CHECKLIST.md) before `BACKUP_RESTORE_ENABLED` is reconsidered.
11. Nightly `mysqldump` of the staging database to storage off the server.
12. Scheduled commands of the owner commercial tools (`internal-plans:sync`, `offers:expire`):
    1. Registered: `sudo -u www-data php artisan schedule:list` shows both as `0 * * * *` (hourly) with a "Next Due" time.
    2. Scheduler heartbeat running: `php artisan system:check` shows Scheduler OK (heartbeat younger than 5 minutes), and the owner System page agrees.
    3. They run successfully: `sudo -u www-data php artisan internal-plans:sync` prints "Started 0, ended 0 internal plan(s)." and `sudo -u www-data php artisan offers:expire` prints "Expired 0 recovery offer(s).", both exit 0 (the numbers differ when a PROBONO plan or an offer is due). Then, once, during the first minute of an hour, `sudo -u www-data php artisan schedule:run`: both commands are listed as running and finish without an error. This is the same path cron uses.

## Continuous integration

`.github/workflows/ci.yml` runs on pushes to the development branch and on pull requests (never deploys):

- **quality:** no tracked `.env`/keys/databases, `composer validate`, PHP lint, Pint, `composer audit` (app and connector tests), `npm ci && npm run build`, `npm audit` (high and critical), gitleaks over the full history (`.gitleaks.toml`).
- **server (PHP 8.4 × SQLite, MySQL 8.4):** migrations from an empty database, full rollback and again; production caches; the full test suite with real S3 (moto) and SFTP (OpenSSH, key authentication, pinned host key) services, `--fail-on-skipped`; `system:check`; the HTTP smoke test against `php artisan serve`.
- **wordpress (PHP 8.4 × SQLite, MySQL 8.4):** the connector inside real WordPress 7.1, strict mode (symlink containment and permission tests may not be skipped).

The Linux CI run is the authoritative result for filesystem behaviour (permissions, symlinks); on Windows those few assertions are skipped with an explicit reason.
