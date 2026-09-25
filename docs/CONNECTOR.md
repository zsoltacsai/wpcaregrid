# WP CareGrid Connector

The connector is a small WordPress plugin (`wordpress/wp-caregrid-connector`, slug `wp-caregrid-connector`, text domain `wp-caregrid`) that links one WordPress site to WP CareGrid. It is distributed privately as a ZIP from the customer area.

Design rule: **a small, explicit, auditable attack surface.** The connector runs a fixed list of six jobs. It cannot run PHP, shell commands, WordPress hooks or anything else the server asks for.

## Architecture

```
WordPress site                                   WP CareGrid (Laravel)
┌──────────────────────────────┐   HTTPS, outbound only   ┌───────────────────────────────┐
│ WP-Cron: heartbeat (15 min)  │ ───────────────────────▶ │ /api/v1/connector/*           │
│ WP-Cron: poll jobs (5 min)   │ ◀── jobs / results ───── │ AuthenticateConnector         │
│ Settings → WP CareGrid       │                          │ JobQueue, RecordJobResult     │
│ JobRunner → 6 handlers       │                          │ InventoryIngest, HealthIngest │
└──────────────────────────────┘                          └───────────────────────────────┘
```

- **Outbound only.** The site always starts the connection. WP CareGrid never calls in, so it works behind NAT and firewalls, and the site has no endpoint to attack.
- **Entry points in the plugin:** two WP-Cron events (`wp_caregrid_heartbeat`, `wp_caregrid_poll`) and one admin page with three `admin-post` actions. There are no REST routes, no public or logged-in AJAX, and no `init`/`admin_init` handlers.

| Plugin part | Where |
| --- | --- |
| Bootstrap, autoloader, activation hooks | `wp-caregrid-connector.php`, `includes/Core/` |
| HTTPS client | `includes/Api/Client.php` |
| Credential storage, installation ID | `includes/Auth/` |
| Pairing | `includes/Pairing/PairingService.php` |
| Heartbeat | `includes/Heartbeat/HeartbeatService.php` |
| Inventory and licenses | `includes/Inventory/`, `includes/Licenses/` |
| Health checks | `includes/Health/` |
| Jobs: whitelist, runner, local store, handlers | `includes/Jobs/` |
| Upgrader loading, update guard | `includes/Updates/` |
| Log with redaction | `includes/Security/` |
| Admin page | `includes/Admin/SettingsPage.php` |

| Server part | Where |
| --- | --- |
| Domain (pairing, job queue, results, ingest, enums) | `app/Connector/` |
| API | `routes/api.php`, `app/Http/Controllers/Api/V1/ConnectorController.php` |
| Connector authentication | `app/Http/Middleware/AuthenticateConnector.php` |
| Models | `Connector`, `ConnectorPairingCode`, `WebsiteJob`, `WebsiteInventory` |
| Customer area (pairing code, status, health, inventory, disconnect) | `app/Http/Controllers/Account/WebsiteController.php`, `resources/views/account/websites/show.blade.php` |
| Settings | `config/connector.php` (`CONNECTOR_*` in `.env`) |
| Commands | `connector:package`, `connector:queue-job`, `connector:update-presence` (scheduled every 10 min) |

## Authentication

Every call after pairing carries two headers:

```
Authorization: Bearer cgc_<48 random characters>
X-CareGrid-Website: <website UUID>
```

- The **credential** is `cgc_` + 48 characters from `Str::random` (a CSPRNG, ~285 bits). It is created by the server at pairing time and returned **once**.
- The server stores only its **SHA-256 hash** (`connectors.credential_hash`) and a short non-secret hint. A database leak does not reveal usable credentials.
- The website header must match the connector's website (`hash_equals`), so a credential can never act for another website.
- A revoked connector gets `401 CONNECTOR_REVOKED`; an unknown credential `401 UNAUTHENTICATED`. In production (`CONNECTOR_REQUIRE_HTTPS`), plain HTTP is refused.
- `connectors.public_key` is reserved for request signing in a later version.

### Credential storage in WordPress

- Kept in the non-autoloaded option `wp_caregrid_credential`, **encrypted with libsodium** (`crypto_secretbox`, XSalsa20-Poly1305). The key is derived from the site's `SECURE_AUTH_SALT` via HMAC-SHA256. A database dump alone does not reveal it; tampered ciphertext is rejected. If the salts change, the site must be paired again.
- Never printed (not even partially), never logged, never exposed through the front end, REST, JavaScript or the admin page. Sent only to the WP CareGrid API URL, only over HTTPS (TLS verified, redirects not followed).
- For development only, `WP_CAREGRID_ALLOW_INSECURE_LOCALHOST` allows `http://localhost` / `http://127.0.0.1`.

## Pairing flow

No WordPress, hosting, FTP or database password is asked for or stored at any point.

1. The customer opens their website in the WP CareGrid account and clicks **Generate pairing code** (needs an active subscription).
2. The server creates a code of 12 characters from a 31-character alphabet without look-alikes (`XXXX-XXXX-XXXX`, ~59 bits), stores only its SHA-256 hash, and shows it once. It is flashed through the session, never put in a URL.
   - Valid for 30 minutes (`CONNECTOR_PAIRING_CODE_TTL`), single use, bound to one website. A new code invalidates older ones; disconnecting invalidates open codes.
3. In WordPress: **Settings → WP CareGrid**, enter the code. The plugin posts it with its installation ID, site URL, versions and capabilities to `POST /pair` (the only unauthenticated call).
4. The server checks, under a row lock: code exists, unused, not expired or invalidated; the subscription allows service; the reported site URL has the same host as the registered website (scheme and `www.` ignored). Then it marks the code used, revokes any earlier connector of that website (`re_paired`), creates the connector, and marks the website **Connected**.
5. The response carries the credential (once), website and connector UUIDs, accepted capabilities and intervals. The plugin validates the format of every field before storing anything, then immediately sends a heartbeat and polls. The server has already queued the initial check: `GET_INVENTORY`, `SITE_HEALTH`, `LICENSE_CHECK`.

A website is only "connected" after this real confirmation from the connector.

## API

Base: `/api/v1/connector`. JSON in, JSON out. Errors are always `{"error": {"code": "...", "message": "..."}}` (validation errors add `fields`), never stack traces.

| Method, path | Auth | Purpose | Rate limit |
| --- | --- | --- | --- |
| `POST /pair` | pairing code | Exchange a code for a credential. `201` | 5/min and 20/hour per IP |
| `POST /heartbeat` | credential | Versions, capabilities, latest health summary. Returns intervals and latest connector version. | 30/min per connector (shared) |
| `POST /inventory` | credential | Full inventory. `202` | ″ |
| `GET /jobs` | credential | Up to 5 queued jobs, marked delivered. | ″ |
| `POST /jobs/{uuid}/result` | credential | Result of a delivered job. `202`, or `200` with `duplicate: true` if already recorded. | ″ |
| `POST /disconnect` | credential | The site disconnects itself; the credential is revoked. | ″ |

Error codes include `INVALID_REQUEST`, `RATE_LIMITED`, `UNAUTHENTICATED`, `CONNECTOR_REVOKED`, `WEBSITE_MISMATCH`, `HTTPS_REQUIRED`, `INVALID_PAIRING_CODE`, `SITE_URL_MISMATCH`, `SERVICE_INACTIVE`, `JOB_NOT_FOUND`, `JOB_NOT_DELIVERED`, `JOB_NOT_ALLOWED`.

**Multi-tenant isolation:** every lookup after authentication is scoped to the authenticated connector's website. A job UUID of another website is `404 JOB_NOT_FOUND`, exactly like an unknown one. Customer pages go through `WebsitePolicy`. Cross-tenant cases are covered by tests on both the API and the account side.

**URL safety:** customer-entered website URLs must be public `http(s)` addresses: no credentials in the URL, no IP literals, no ports other than 80/443, no internal names (`localhost`, `.local`, `.internal`…) (`App\Rules\PublicWebsiteUrl`). The server never fetches these URLs; they are only compared with what the connector reports.

## Jobs

| Type | Capability | Payload (only these keys) | Does |
| --- | --- | --- | --- |
| `GET_INVENTORY` | inventory | – | Collects the inventory |
| `SITE_HEALTH` | health | – | Runs the health checks |
| `LICENSE_CHECK` | license_check | – | Reports license states |
| `UPDATE_PLUGIN` | updates | `plugin` (basename, e.g. `akismet/akismet.php`), optional `target_version`, `backup_uuid` | Updates one plugin |
| `UPDATE_THEME` | updates | `stylesheet` (e.g. `twentytwentyfive`), optional `target_version`, `backup_uuid` | Updates one theme |
| `UPDATE_CORE` | updates | `policy` (`minor`/`major`), optional `target_version`, `backup_uuid` | Updates WordPress |
| `BACKUP_FILES` / `BACKUP_DATABASE` / `BACKUP_FULL` | backup_files / backup_database / backup_full | `backup_uuid`, `reason`, `chunk_size` | Builds a backup locally and uploads it to WP CareGrid ([BACKUP_ENGINE.md](BACKUP_ENGINE.md)) |
| `RESTORE` | restore_files / restore_database / restore_full (by `scope`) | `restore_uuid`, `backup_uuid`, `scope`, `objects` (name, size, sha256) | Restores one of this site's own backups |

`backup_uuid` on updates is set by the server only after it verified a pre-update backup. Database jobs and capabilities exist only on MySQL/MariaDB sites; capabilities are sent with every heartbeat, so the server always knows what a site can do.

There is no `RUN_PHP`, `RUN_COMMAND`, `EXECUTE_CODE` or `EVAL`, and no way to add a type: the list is a constant on both sides (`App\Connector\JobType`, `JobRegistry::TYPES`), not a filter.

**Authorisation (server, when queueing and again when delivering):** the website exists and has an active connector; the subscription allows service; the job type is known; the connector advertised the capability; the payload passes strict validation (unknown keys rejected, identifiers by regex, no paths, `..`, URLs or code). `policy: major` is refused unless `CONNECTOR_ALLOW_MAJOR_CORE_UPDATES=true`. A job that is no longer allowed at delivery time is cancelled, not sent.

**Validation again in the plugin (defence in depth):** unknown type → `UNSUPPORTED_JOB_TYPE`; bad payload → `INVALID_PAYLOAD`. Nothing runs in either case.

**Statuses:** `queued → delivered → success | failed | partial | skipped`, plus `expired` (not delivered successfully after 5 deliveries) and `cancelled`.

**Idempotency and retries.** Running a job and delivering its result are separate:

- Before a job runs, the plugin records its UUID as `running` in `wp_caregrid_jobs`. A UUID it has seen is **never executed again**: a completed one has its stored result re-sent; one left `running` (the process died) is reported as `JOB_INTERRUPTED`, not re-run.
- Results that cannot be delivered (network error, timeout, 429, 5xx) stay in a local outbox and are sent on the next poll, without re-running the job. Permanent refusals (4xx) are dropped after logging.
- The server accepts one result per job; repeats get `duplicate: true` and change nothing.
- One poll at a time per site (option lock, taken over after 15 minutes if a process died; long backups and restores keep it fresh).
- A backup interrupted while uploading resumes its upload at the next poll instead of being reported as interrupted; it is never built twice.

**Updates** use WordPress's own `Plugin_Upgrader`, `Theme_Upgrader` and `Core_Upgrader` with the update WordPress itself offers (the package URL is never taken from WP CareGrid). The handler:

1. reports `skipped` if nothing is offered or the offer differs from `target_version`; for core, `MAJOR_UPDATE_NOT_ALLOWED` when the policy is `minor` and the offer is on another `x.y` branch;
2. records the backup state: `{status: verified_by_server, backup_uuid}` when a verified backup protects the update, `{status: none}` otherwise (see pre-update backups in [BACKUP_ENGINE.md](BACKUP_ENGINE.md));
3. runs the upgrader with its output buffered;
4. re-reads the version **from disk** and only reports success when it actually increased; reactivates a plugin WordPress deactivated during the update;
5. runs the health checks and a fresh inventory: `partial` (`UPDATED_HEALTH_CRITICAL`) if the site is critical afterwards.

Results carry `previous_version`, `new_version`, `backup`, `health` and `inventory`.

## Health

Checks: home page and REST API (loopback requests), active theme present, plugins loading (none paused after a fatal error), WordPress's Site Health summary, PHP version, error display. Each finding keeps its status (`pass`, `warning`, `fail`, `unknown`) and message.

Summary: `critical` if a critical check fails (home page 5xx, theme missing, plugins paused), `attention` for any other failure or warning, `healthy` if something passed and nothing failed, `unknown` if nothing could be verified. A blocked loopback request is `unknown`, not critical.

## Licenses

Per plugin: `valid`, `expired`, `unknown` or `not_available`.

- `valid` / `expired` only with evidence from a license provider (filter `wp_caregrid_license_providers`); providers cannot report anything else.
- `unknown`: the plugin looks commercial (updates from somewhere other than WordPress.org) but its license cannot be read.
- `not_available`: no license concept detected.

"Could not check" is never reported as expired or valid. The customer area says so next to the table.

## Transmitted data

| Sent | When | Content |
| --- | --- | --- |
| Pairing | once | pairing code, installation ID (random UUID), home URL, connector/WordPress/PHP versions, capabilities |
| Heartbeat | every 15 min | connector/WordPress/PHP versions, home URL, WooCommerce installed/active/version, last poll time, capabilities, latest health summary and findings |
| Inventory | on request | WordPress version and whether an update is offered (`null` = not checked, never guessed as "up to date"); PHP version; per plugin: file, slug, name, version, active, update offered, new version, update source **host**, license state; per theme: stylesheet, name, version, active, update offered, new version |
| Job results | per job | status, result code, versions before/after, health findings, inventory, structured error (code, message, safe details) |
| Backups | on request | the backup objects and manifest described in [BACKUP_ENGINE.md](BACKUP_ENGINE.md): site files (`wp-config.php` with database credentials, keys and salts removed) and the database without transients and without the connector's state, only when a backup job asks for them |

**Never sent outside a backup:** post or page content, users or user data, passwords, e-mail addresses, payment data, `wp_options` values, plugin settings, license keys, package URLs, files, media, database contents or credentials, salts, the WP CareGrid credential, stack traces. The server also drops unexpected fields from inventory payloads.

**Logs** (last 100 entries in `wp_caregrid_log`, shown to administrators; the PHP error log with `WP_DEBUG_LOG`): events and error codes only. Credentials, bearer tokens, pairing codes and values under keys like `password`, `token`, `secret`, `auth`, `cookie`, `salt` are redacted.

## Admin page

**Settings → WP CareGrid** (`manage_options` only): connection status, connected website UUID, connector version (and newer version if any), last successful communication, next poll, capabilities, recent log events; the pairing form when not connected, otherwise **Check connection now** and **Disconnect**. Every action checks the capability and a nonce; other users get a 403 and nothing happens.

## Disconnect and revocation

- **From WordPress** (Disconnect button): the plugin calls `POST /disconnect` (server revokes, marks the website disconnected, cancels open jobs), then deletes the credential and the schedule. The local reset happens even if WP CareGrid is unreachable.
- **From the customer area** (Disconnect website): the server revokes all connectors of the website, invalidates open pairing codes and cancels open jobs. The site's next heartbeat or poll gets `CONNECTOR_REVOKED`; the plugin deletes its credential, stops its schedule and shows "Disconnected by WP CareGrid – pair again".
- **Re-pairing** revokes the previous connector.
- A revoked connector **never reconnects on its own**: only a new pairing code does. Other errors (network, 5xx, a proxy's plain 401) never drop the credential.
- **Deactivating** the plugin stops the schedule and keeps the pairing; **uninstalling** tells WP CareGrid and deletes every option.

Presence (`connector:update-presence`): connected, `stale` after 45 minutes without contact, `disconnected` after 24 hours. A disconnected site that reports in again becomes connected.

## Development

```sh
php artisan connector:package                           # builds storage/app/private/connector/wp-caregrid-connector-<version>.zip
php artisan connector:queue-job <website-uuid> UPDATE_PLUGIN --payload='{"plugin":"akismet/akismet.php"}'
```

The plugin header version must match `CONNECTOR_LATEST_VERSION`, or packaging fails.

Plugin tests run inside a real WordPress (from Composer) on SQLite; only outbound HTTP is faked:

```sh
cd wordpress && composer install && vendor/bin/phpunit
```

Local install against a local WP CareGrid: add to the site's `wp-config.php`

```php
define('WP_CAREGRID_API_URL', 'http://127.0.0.1:8000/api/v1/connector');
define('WP_CAREGRID_ALLOW_INSECURE_LOCALHOST', true); // development only
```

then upload the ZIP (Plugins → Add New → Upload Plugin), activate, and pair from Settings → WP CareGrid with a code from the website's page in the account. Note: `php artisan serve` and `php -S` handle one request at a time unless `PHP_CLI_SERVER_WORKERS` is set; the site's health checks make loopback requests, so give the WordPress server a few workers.

## Future capabilities

Not in this version, and not advertised: malware scanning, request signing with a per-connector key pair (`public_key`), self-update of the connector, automated disaster recovery. Backups and restores are in [BACKUP_ENGINE.md](BACKUP_ENGINE.md). A new capability is only advertised once its jobs exist on both sides and are covered by tests.
