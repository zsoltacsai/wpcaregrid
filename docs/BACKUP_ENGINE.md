# Backup Engine

Backups of WordPress sites, stored off-site, verified before they count, with retention, backups required before updates, and the foundation for restores. It builds on the connector (see [CONNECTOR.md](CONNECTOR.md)): backups and restores are ordinary whitelisted connector jobs.

Scope: files, database and full backups; S3-compatible and SFTP storage; verification; retention; pre-update backups; restore with safety backup. Not included: malware scanning or removal, automated disaster recovery, browser-based functional tests.

## Architecture

```
WordPress (connector)                     WP CareGrid (Laravel)                         Storage
┌─────────────────────────┐   HTTPS   ┌──────────────────────────────────┐          ┌───────────────┐
│ BACKUP_* job            │──chunks──▶│ /api/v1/connector/backups/…      │          │ S3-compatible │
│  build locally (tmp)    │           │  BackupUploads → staging (0700)  │          │  or SFTP      │
│  tar.gz + sql.gz        │──finalize▶│  BackupManifest (strict)         │          │               │
│  SHA-256 while writing  │           │  queue: TransferBackup ──────────┼── put ──▶│ {site}/{bkp}/ │
│                         │           │   verify size + SHA-256 ◀────────┼─ check ──│               │
│ RESTORE job             │◀─ranges───│  content endpoint (restore only) │◀─ read ──│               │
└─────────────────────────┘           └──────────────────────────────────┘          └───────────────┘
```

- **The connector never sees storage credentials.** It uploads to WP CareGrid, which moves the data to storage. Storage credentials live in the server's `.env` only.
- **The server never sees database credentials.** The connector dumps the database locally. The `wp-config.php` copy in the files backup has the database user and password, and all keys and salts, removed.
- The backup domain only knows the `BackupStorage` interface.

| Server part | Where |
| --- | --- |
| Models | `Backup`, `BackupObject`, `BackupRestore`, `WebsiteEvent` (audit log) |
| States and types | `app/Backups/BackupStatus.php`, `RestoreStatus.php`, `BackupType.php`, `BackupReason.php`, `BackupObjectName.php` |
| Starting and settling backups | `app/Backups/BackupService.php` |
| Upload ingestion and manifest | `BackupUploads.php`, `BackupManifest.php`, `app/Http/Controllers/Api/V1/BackupController.php` |
| Store and verify | `app/Jobs/TransferBackup.php` |
| Storage | `app/Backups/Storage/` (`BackupStorage`, `S3Storage`, `SftpStorage`, `LocalStorage`, `StorageManager`, `ObjectKey`) |
| Policy, retention, deletion | `BackupPolicy.php`, `Retention.php`, `BackupDeletion.php` |
| Pre-update backups | `PreUpdateBackups.php` |
| Restores | `RestoreService.php` |
| Customer area | `app/Http/Controllers/Account/BackupController.php`, `resources/views/account/websites/{backups,restore}.blade.php`, `components/backups/summary.blade.php` |
| Commands | `backup:schedule` (hourly), `backup:monitor` (10 min), `backup:retention` (daily), `backup:admin` |
| Settings | `config/backup.php` |

| Connector part | Where |
| --- | --- |
| Backup job | `includes/Jobs/Handlers/BackupHandler.php` |
| Restore job | `includes/Jobs/Handlers/RestoreHandler.php` |
| Files archive | `Backups/FilesArchiver.php`, `FilePolicy.php`, `TarWriter.php`, `WpConfigRedactor.php` |
| Database dump | `Backups/DatabaseDumper.php` |
| Upload and download | `Backups/Uploader.php`, `Downloader.php` |
| Restore safety | `Backups/BackupIndex.php`, `SqlImporter.php`, `TarReader.php`, `FileRestorer.php`, `PathGuard.php`, `Maintenance.php` |
| Local files | `Backups/TempDir.php`, `Sink.php`, `DiskSpace.php` |

## Backup types

| Type | Job | Objects |
| --- | --- | --- |
| `files` | `BACKUP_FILES` | `files.tar.gz` |
| `database` | `BACKUP_DATABASE` | `database.sql.gz` |
| `full` | `BACKUP_FULL` | both |

Every backup also has a `manifest.json`. Every backup belongs to exactly one website.

### What a files backup contains (policy `standard-1`)

- `wp-content/…`: all of `WP_CONTENT_DIR`, except:
  - caches: `cache`, `et-cache`, `litespeed`, `uploads/cache`;
  - upgrade leftovers: `upgrade`, `upgrade-temp-backup`;
  - other backup plugins' archives: `backup(s)`, `backup-db`, `updraft`, `ai1wm-backups`, `backups-dup-*`, `wpvividbackups`, `wp-snapshots`, `uploads/backwpup-*`;
  - logs: `*.log`, `error_log`, `wflogs`, `uploads/wc-logs`;
  - temporary and editor files: `*.tmp`, `*.temp`, `*.swp`, `.DS_Store`, `Thumbs.db`, `core.NNN`;
  - `.git`, `.svn`, `.hg` and `node_modules` folders;
  - the connector's own temporary directory;
  - files over 1 GiB (`wp_caregrid_backup_max_file_size`, a local filter).
- `root/.htaccess`, `root/.user.ini`, `root/web.config` and `root/robots.txt`, taken from the WordPress root.
- `root/wp-config.php`, with the database user and password, and the 8 keys and salts, replaced by `removed-by-wp-caregrid`. If any secret value would still be in the file (unusual syntax), the file is left out. The manifest records `wp_config: redacted | excluded | missing`.
- **Not included:** WordPress core (`wp-admin`, `wp-includes`, `wp-*.php`). Core is reinstalled for the version recorded in the manifest. Nothing outside the installation is included.
- **Symlinks are never followed.** They are counted as skipped (`skipped.symlinks`), like excluded, too-large and unreadable files.

### Database backup

- Made locally, from a second connection that uses the credentials in `wp-config.php`, inside one consistent snapshot (`START TRANSACTION WITH CONSISTENT SNAPSHOT`).
- Covers tables with the site's table prefix only.
- Rows are read in batches of 500, so memory does not grow with table size.
- The output has one statement per line. INSERT values are only `NULL` or quoted strings. This is the grammar the restore accepts, nothing more.
- **Left out:** transients, and the connector's own options (credential, job records).
- Recorded in the manifest: engine (`mysql`/`mariadb`), server version, charset, table prefix, table and row counts, compression, size and SHA-256. No credentials.
- Needs MySQL or MariaDB. Without it, the connector does not advertise `backup_database`, `backup_full`, `restore_database` or `restore_full`.

## Format

Objects are stored under deterministic keys, `{prefix}/{website_uuid}/{backup_uuid}/`:

```
manifest.json
files.tar.gz      POSIX ustar (+ pax headers for long names), gzip
database.sql.gz   SQL as described above, gzip
```

- Gzip, not Zstandard: zlib is on practically every PHP host, and zstd is not. Without zlib, the connector writes uncompressed files and records `compression: none`.
- No shell tools are used. Archives are plain tar, readable with `tar -xzf`.
- Keys never contain customer names, URLs or internal IDs (`ObjectKey` refuses any other key).

### Manifest

```json
{
  "format": "wp-caregrid-backup/1",
  "backup_uuid": "…", "website_uuid": "…", "type": "full", "reason": "manual",
  "installation_id": "…", "connector_version": "0.2.0", "wordpress_version": "7.1.2", "php_version": "8.4.19",
  "created_at": "…", "completed_at": "…",
  "site": {"multisite": false, "table_prefix": "wp_"},
  "files": {"object": "files.tar.gz", "size": 16368941, "sha256": "…", "compression": "gzip", "format": "ustar",
            "file_count": 512, "uncompressed_size": 18489344, "policy": "standard-1", "wp_config": "redacted",
            "skipped": {"symlinks": 0, "too_large": 0, "unreadable": 0, "excluded": 3}},
  "database": {"object": "database.sql.gz", "size": 8775, "sha256": "…", "compression": "gzip", "engine": "mariadb",
               "server_version": "10.11.14-MariaDB", "charset": "utf8mb4", "table_prefix": "wp_", "tables": 12, "rows": 154,
               "excluded": ["transients", "connector_state"]},
  "total_size": 16377716,
  "checksums": {"algorithm": "sha256", "files.tar.gz": "…", "database.sql.gz": "…"}
}
```

The connector's manifest is validated key by key (`array:` whitelists, formats, enums). Unknown keys are refused, so no password, token, license key or site data can end up in it. Sizes and checksums must match the objects actually received.

## Upload

| Request | Purpose |
| --- | --- |
| `GET /api/v1/connector/backups/{uuid}/objects/{files\|database}` | Resume point: `received_bytes`. |
| `PUT …/objects/{name}?offset=N` | One chunk as the raw body, with `X-Chunk-SHA256`. At most 8 MiB (4 MiB by default). |
| `POST …/objects/{name}/complete` | `{size, sha256}`. The server hashes the staged file and compares. On mismatch it discards the file (`CHECKSUM_MISMATCH`) and the connector uploads it once more. |
| `POST /api/v1/connector/backups/{uuid}/finalize` | `{manifest}`. The backup becomes `uploaded` and `TransferBackup` is queued. |

- **Resuming:** a chunk must start at `received_bytes` (`409 OFFSET_MISMATCH` returns `expected_offset`), and offset 0 restarts the object.
  - The connector retries temporary failures 6 times with backoff, then fails the job (no endless loops).
  - If the process dies while uploading, the next poll resumes the upload from the server's offset. It never builds the backup again: the job's payload and local files are kept for this (`ResumableJob`), and the files are re-hashed first.
- **Scope and rate limit:** every request is scoped to the connector's website (another website's backup is `404`), and limited to 600 per minute per connector.
- **Memory:** nothing is ever loaded whole. Chunks are streamed to disk and hashed as they arrive. Staged files are `0600` in `0700` directories under `storage/app/private/backup-staging`.

A 2 GB site needs neither 2 GB of PHP memory nor a 2 GB request.

## Verification

`TransferBackup` stores every object, then checks every object again in storage:

| Storage | Check |
| --- | --- |
| S3, objects ≤ `multipart_threshold` | Uploaded in one `PutObject` with `ChecksumSHA256`: S3 recomputes it and rejects a mismatch (`BadDigest` → corrupt). Verification reads the checksum back (`HeadObject`, `ChecksumMode=ENABLED`) and compares; nothing is downloaded. |
| S3, larger (multipart) | S3 only has a checksum of part checksums, so the object is streamed back and hashed. |
| SFTP | SFTP has no checksum command: size check (`stat`), then streamed read-back in 8 MiB pieces. |
| Any S3 service that does not return `ChecksumSHA256` | Read-back. |

- **Status:** a backup is `verified` only when every object passed. The customer only sees "Verified" in that case; every other state is neutral or red.
- **Read-back switch:** `BACKUP_VERIFY_READBACK=false` turns read-back off. Objects that then cannot be checked fail as `VERIFICATION_UNAVAILABLE`, never as verified.
- **Mismatch:** a wrong size or checksum marks the backup `corrupt`. Its unusable objects are deleted and the record is kept.
- **Re-check later:** `backup:admin verify {backup}` re-checks a stored backup.

## States

```
Backup:  queued → running → uploading → uploaded → verifying → verified → expired | deleted
                  └──────────────┴──────────┴──────────┴→ failed      verifying → corrupt
         verifying → uploaded (storage unavailable, retry)             verified → verifying (re-check)

Restore: awaiting_safety_backup → queued → restoring → restored | needs_attention | failed
         awaiting_safety_backup → failed (safety backup not verified) | cancelled
```

- **Transitions:** `Backup::transitionTo()` and `BackupRestore::transitionTo()` refuse any transition not listed above.
- **Monitoring:** `backup:monitor` fails backups stuck unfinished for 6 hours (`BACKUP_TIMED_OUT`), re-dispatches lost transfers, hands over transfers abandoned in `verifying` by a dead worker (after the transfer timeout + 15 minutes; stored objects are not uploaded again, everything is verified again), and removes staged data of failed backups after 24 hours.
- **Transfer queue:** `TransferBackup` runs on its own queue connection `backups` (config/queue.php) whose `retry_after` is `BACKUP_TRANSFER_TIMEOUT` + 600 s, so a transfer that is still running is never picked up by a second worker. At most one transfer per backup is queued (`ShouldBeUnique`). Worker: `php artisan queue:work backups --queue=backups --timeout=3660` (docs/DEPLOYMENT.md). With the `sync` queue (tests, local development) it runs inline.
- **Storage outages:** 3 attempts (retries after 1 and 5 minutes), then the backup fails as `STORAGE_UNAVAILABLE` ("Remote storage unavailable" for the customer).
- **Connector-side files:** temporary files are removed after upload or failure. Leftovers of jobs that are not running are removed after 6 hours.

## Policy, schedule, retention

`BackupPolicy::for($website)` decides:
- eligibility (plan, active subscription, storage configured);
- frequency (from the plan in `config/pricing.php`);
- type;
- retention;
- storage;
- whether manual backups, pre-update backups and restores are allowed.

Business rules never live in the connector.

| Plan | Frequency |
| --- | --- |
| WordPress Basic / Business / Secure | monthly / weekly / daily |
| WooCommerce Basic / Business / Secure | weekly / daily / daily |

- **Scheduling:** `backup:schedule` runs hourly and queues one scheduled backup per website and window (`daily:2026-09-25`, `weekly:2026-W39`, `monthly:2026-09`, UTC). A unique `(website_id, schedule_window)` index makes duplicates impossible. Websites whose connector is not reporting in are skipped.
- **Retention** is set per plan through `BACKUP_RETENTION_{PRODUCT}_{TIER}_COUNT` / `_DAYS`. **No values are set: until they are, nothing is deleted.** When set:
  - `retention_count` keeps the newest N verified backups;
  - `retention_days` expires verified backups older than N days;
  - the newest verified backup is never expired, nor one a restore or a waiting update depends on;
  - expiring deletes the stored objects, keeps the record (`expired`) and writes an audit event.
- **Manual backups** ("Backup now"): only for the website owner with an active plan and a connected site. At most one in progress, and one per 60 minutes (`BACKUP_MANUAL_COOLDOWN_MINUTES`). Always queued; never run inside the web request.

## Pre-update backups

```
update requested → plan requires a backup (BACKUP_PRE_UPDATE_REQUIRED, default on)
  → full backup (reason pre_update) → update job "waiting" (never delivered)
  → backup verified → update queued with backup_uuid → update → health check
  → backup failed/corrupt → update failed: BACKUP_REQUIRED_FAILED
```

- **Link to the update:** the backup records `update_job_id` and `update_target` (kind, identifier, previous version). The update job records `backup_id`.
- **What the customer sees:** "Protected by backup" in the website's activity.
- **What the connector reports:** `backup: {status: verified_by_server, backup_uuid}` when a backup protects the update, or `{status: none}` when none was required.
- **Unavailable backup:** if a backup cannot even start (no storage, old connector), the update is not run.

## Restore

A restore always names one verified, unexpired backup of the same website, and a scope: `files`, `database` or `full`. A `files` backup can only restore files, and a `database` backup only the database. The newest backup is never picked implicitly.

1. **Request** (customer confirmation page, or `RestoreService`). The server checks:
   - the backup belongs to the website, and the website to the customer;
   - the plan allows restores and the backup is verified and not expired;
   - the connector is active and reporting in now (not stale), and advertises `restore_{scope}` and `backup_full`;
   - no other restore is running;
   - the customer typed the website address and ticked "I understand".
2. **Safety backup:** a full backup of the current site (reason `pre_restore`). Nothing is restored unless it is verified; there is no override.
3. **`RESTORE` job:** `{restore_uuid, backup_uuid, scope, objects: [{name, size, sha256}]}`. The connector:
   - refuses backups it did not make: the UUID and checksums must be in its local `BackupIndex`, so a compromised server cannot push data;
   - downloads each object in ranges through WP CareGrid. Each range is checked against `X-Chunk-SHA256` and the whole file against its SHA-256. Downloads are only allowed while this connector's restore of that backup runs, and only for objects in its scope;
   - **files:** unpacks the whole archive into a private staging directory first. Only regular files and directories are accepted; links, devices, `..` or absolute paths reject the archive before anything live is touched;
   - **database:** validates every line against the dump grammar and loads it into temporary tables (`cgr…_`). Anything else is refused (`SELECT`, `LOAD_FILE`, `FEDERATED`/`CONNECTION`, `DATA DIRECTORY`, other databases, tables without the site prefix, `GRANT`, `SET GLOBAL`, …). The live site keeps running meanwhile;
   - **maintenance mode:** WordPress's `.maintenance` is turned on for the critical part only;
   - **swap and copy:** one atomic `RENAME TABLE` for all tables, then the connector's own options (credential, jobs, backup index) are put back, so a restore never disconnects the site. Files are copied into place, never through a symlink or outside `wp-content`/the root. `wp-config.php` and the connector plugin are never overwritten;
   - **turning maintenance off,** in four ways: a `finally` block, a shutdown function, WordPress's own 10-minute expiry, and a cleanup at the next poll. Only a `.maintenance` file the connector created is removed;
   - **post-restore checks:** WordPress files are present, the database answers and has `siteurl`, the WordPress version is readable, and the active theme exists. Then the health checks run (home page, REST API, theme, plugin loading).
4. **Result:**
   - `restored` only if the post-checks passed and health is not critical;
   - `needs_attention` if the restore ran but the checks found problems;
   - `failed` otherwise, with its error code.

   Every step is in the audit log.

- **Feature flag:** live restores are off by default (`BACKUP_RESTORE_ENABLED`). While they are off, the customer area says restores are handled on request.
- **Known limits:**
  - files added after the backup stay in place;
  - if copying files fails after the database swap, the site is partly restored. The verified safety backup exists for exactly this case.

## Security

- **Storage credentials** are in the server's `.env` only.
  - They never appear in job payloads, responses, logs, customer pages or exceptions: provider errors carry an error code only.
  - SFTP requires a pinned host-key fingerprint.
  - S3 objects use server-side encryption (`BACKUP_S3_SSE`, default `AES256`).
- **Connector jobs carry logical identifiers only:** backup UUID, reason and chunk size. There are no paths, URLs or credentials, and unknown payload keys are refused on both sides.
- **Tenant isolation:**
  - backups, uploads, downloads and restores are scoped to the authenticated connector's website, and to the customer's own websites through `WebsitePolicy` and scoped route bindings;
  - another tenant's backup is "not found";
  - a revoked connector cannot upload (`401`).
- **Deletion** exists only through retention and `backup:admin delete`. Deleting the only verified backup needs `--confirm-only-backup`, a backup a restore depends on cannot be deleted, and metadata is always kept. Customers have no delete route.
- **Audit log** (`website_events`):
  - backup events: requested, started, uploaded, verified, failed, corrupt, expired, deleted;
  - restore events: requested, started, completed, failed;
  - `update.blocked`.

  Entries carry codes and sizes only.
- **Connector side:**
  - `PathGuard` refuses absolute paths, `..`, backslashes, NUL and drive letters;
  - `realpath` containment is checked before any write;
  - temporary files are `0600` in `0700` directories outside the web root when possible. The connector uses `WP_CAREGRID_BACKUP_DIR`, then the system temp directory, then a randomly named `wp-content` folder with deny rules;
  - logs never contain credentials.

## Operations

| Command | |
| --- | --- |
| `backup:admin list {website}` | Backups of a website |
| `backup:admin show {backup}` | Details, objects, checksums and the audit log |
| `backup:admin run {website} [--type=]` | Request a backup (reason `recovery`) |
| `backup:admin retry {backup}` | Replace a failed or corrupt backup |
| `backup:admin verify {backup}` | Re-check the stored objects |
| `backup:admin delete {backup} [--confirm-only-backup]` | Remove the stored objects, keep the record |

Run the scheduler (`php artisan schedule:work`, or cron with `schedule:run`) and two queue workers: `php artisan queue:work database --queue=default` and `php artisan queue:work backups --queue=backups --timeout=3660` (docs/DEPLOYMENT.md).

## Configuration

All settings are in `config/backup.php`, read from `.env` (see `.env.example`):

- **storage:** `BACKUP_STORAGE` (`s3`, `sftp`, or `local` for development only; empty means backups are unavailable), with `BACKUP_S3_*` and `BACKUP_SFTP_*`;
- **uploads and staging:** chunk size, maximum object size, staging path;
- **transfer:** tries, backoff and timeout;
- **verification:** read-back;
- **timeouts and cooldowns:** stale-after hours, staging retention, manual cooldown;
- **per plan:** retention, whether pre-update backups are required, restore allowed;
- **restores:** the restore feature flag.

## Testing

- **Server:** `php artisan test`.
  - `tests/Feature/Backups` covers the whole flow through the real API and the storage contract.
  - Storage runs against real protocol servers when available: moto for S3 and OpenSSH for SFTP. The test harness starts both; the tests are skipped if the binaries are missing.
- **Connector:** `cd wordpress && vendor/bin/phpunit`, in a real WordPress. Set `WP_CAREGRID_TEST_MYSQL=user:password@host/database` to run on MySQL/MariaDB. Database backups and restores need it; on SQLite those tests are skipped.

Note: moto does not return or validate `x-amz-checksum-sha256`, so against moto S3 verification uses read-back. The native checksum path is covered with the AWS SDK's mock handler.
