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
| OS | Linux (Ubuntu 24.04 LTS is what CI runs) |
| PHP | 8.4 (CLI and FPM; `composer.json` allows 8.3, CI tests 8.4) |
| PHP extensions | ctype, curl, dom, fileinfo, filter, intl, mbstring, openssl, pcntl, pdo_mysql, posix, session, sodium, tokenizer, xml, zip |
| Database | MySQL 8.4 (tested in CI). SQLite is for development and tests only |
| Web server | nginx (or Apache) with TLS |
| Process supervisor | supervisor or systemd, for the two queue workers |
| Build tools | Composer 2, Node.js 22 + npm (only to build assets) |
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

Permissions: the web and worker users write to `storage/` and `bootstrap/cache/` only. The document root is `public/`; `storage/app/private` (connector package, backup staging) is never served (the `local` disk's HTTP serving is switched off).

No seeders run in production: `migrate --force` creates empty tables only. Demo data exists only in development databases.

### Production `.env` (the values that differ from `.env.example`)

| Setting | Production value |
| --- | --- |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | `https://<your domain>` (links in emails, redirects and signed URLs use it) |
| `APP_KEY` | generated once (`php artisan key:generate`), kept secret, never reused |
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
* * * * * cd /var/www/wpcaregrid && php artisan schedule:run >> /dev/null 2>&1
```

(`php artisan schedule:work` in the foreground is for development.) Every task is `withoutOverlapping()` and `onOneServer()`.

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
| `system:scheduler-heartbeat` | every minute | proof for the System page that the scheduler runs |

## Mail

Transactional only (activation, welcome, payment confirmation and failure, subscription changes, operator/owner invitations). All mail is queued (`default` worker) and sent after the database transaction that triggered it commits. Links are built from `APP_URL` (also in the worker), so production mail never contains `localhost` links: `system:check` reports a local `APP_URL` in production as an error. Mail contains links, never passwords or tokens other than the single-use link itself. A failure is a failed job (visible on the System page) and a log entry. `MAIL_MAILER=log` writes mail to the log file and is reported as "not configured".

## Stripe (test mode first)

Keys stay in the environment; the owner area never shows, creates or rotates them. Live keys are refused unless `STRIPE_ALLOW_LIVE_MODE=true`.

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
2. Put the `price_…` IDs into `STRIPE_PRICE_<PRODUCT>_<PLAN>_<INTERVAL>_HUF`, the product IDs into `STRIPE_PRODUCT_WORDPRESS` / `STRIPE_PRODUCT_WOOCOMMERCE`, the secret key into `STRIPE_SECRET` (`sk_test_…`), the publishable key into `STRIPE_KEY` (`pk_test_…`).
3. Add a webhook endpoint `https://<APP_URL>/stripe/webhook` with these events: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.paid`, `invoice.payment_failed`. Put its signing secret into `STRIPE_WEBHOOK_SECRET`.
4. `php artisan config:cache && php artisan pricing:verify-stripe` must print `ok` for every price (exists, active, recurring, same amount, currency and interval, attached to the configured product). The owner area's "Check prices in Stripe" runs the same check.
5. Test purchase with Stripe's test card. The account is created only when the signed webhook arrives; the success page alone never activates anything.

Without `STRIPE_SECRET` checkout refuses to start, `system:check` reports Stripe as NOT CONFIGURED, and the webhook answers 500 "not configured" (Stripe will retry).

Prices changed later in the owner area affect new checkouts only (docs/PRICING_ADMIN.md).

## Backup storage

Set `BACKUP_STORAGE=s3` or `sftp` (empty = backups unavailable, `local` = development only and reported as such).

**S3-compatible:** `BACKUP_S3_ENDPOINT` (empty for AWS), `BACKUP_S3_REGION`, `BACKUP_S3_BUCKET`, `BACKUP_S3_KEY`, `BACKUP_S3_SECRET`, optional `BACKUP_S3_PREFIX`, `BACKUP_S3_SSE` (`AES256` by default), `BACKUP_S3_PATH_STYLE` for MinIO-style endpoints. Use a private bucket and a key limited to that bucket (put, get, head, list, delete). Where the service returns a native SHA-256 checksum it is used; otherwise objects are read back and hashed (`BACKUP_VERIFY_READBACK`).

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

```sh
php artisan down --retry=60          # connectors and browsers get 503 and retry
git pull
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build
php artisan migrate --force
php artisan connector:package        # when CONNECTOR_LATEST_VERSION changed
php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan queue:restart
php artisan up
php artisan system:check
```

Run the HTTP smoke test against the result: `php tests/smoke/http-smoke.php https://<APP_URL>` (read-only: public pages, sign-in pages, area isolation, headers).

## Continuous integration

`.github/workflows/ci.yml` runs on pushes to the development branch and on pull requests (never deploys):

- **quality:** no tracked `.env`/keys/databases, `composer validate`, PHP lint, Pint, `composer audit` (app and connector tests), `npm ci && npm run build`, `npm audit` (high and critical), gitleaks over the full history (`.gitleaks.toml`).
- **server (PHP 8.4 × SQLite, MySQL 8.4):** migrations from an empty database, full rollback and again; production caches; the full test suite with real S3 (moto) and SFTP (OpenSSH, key authentication, pinned host key) services, `--fail-on-skipped`; `system:check`; the HTTP smoke test against `php artisan serve`.
- **wordpress (PHP 8.4 × SQLite, MySQL 8.4):** the connector inside real WordPress 7.1, strict mode (symlink containment and permission tests may not be skipped).

The Linux CI run is the authoritative result for filesystem behaviour (permissions, symlinks); on Windows those few assertions are skipped with an explicit reason.
