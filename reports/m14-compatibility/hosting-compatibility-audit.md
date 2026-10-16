# M14 Hosting Compatibility Audit — Planning Baseline

**Status:** `PARTIAL`; documented operational assumptions exist, but user-host support boundaries and cross-host compatibility audit are incomplete.

## Source facts observed

- Server deploy and release docs target PHP 8.4 and MySQL 8.4 CI; current staging is PHP 8.4.26, Laravel 13.33.0, MySQL, HTTPS and zero pending migrations.
- The server uses Laravel queues and scheduler; staging uses database queue and a guarded scheduler wrapper. Required CI covers SQLite and MySQL and Connector SQLite/MySQL.
- WordPress feature execution occurs in the site Connector; server uses an outbound-only polling protocol (`routes/api.php`). Monitoring has pinned public DNS resolution and redirect checks (`WebsiteProbe`, `PublicOrigin`, `SafeRedirect`).
- `config/backup.php` exposes S3 and SFTP storage, chunk/object/staging bounds and restore flags. Restore preflight and Connector filesystem checks are documented in `docs/BACKUP_ENGINE.md`.
- System health checks disk availability and storage, but there is not yet a single preflight report that establishes all environment capabilities per site/host before promising each action.
- Backup manifest has a `site.multisite` boolean, but that serialization field is not proof of safe Multisite scheduling/restore across network sites.
- No Bedrock support contract or dedicated Bedrock fixture was identified in the reviewed docs/tests.

## Compatibility boundary recommendation

Keep support claims capability-based. A host/site is supported for a specific operation only when the corresponding preflight passes; `unknown` must block that operation or return manual review, not imply support. Begin M14 with the tested baseline—PHP 8.4 server/Laravel 13, supported SQL drivers exercised by CI, WordPress Connector capability level—and document that actual WordPress host requirements are per-operation. Do not infer universal provider, shell, WP-CLI, filesystem, or network behavior. Recommend single-site WordPress as initial supported scope; require explicit Owner decision and dedicated network-level tests before declaring Multisite or Bedrock supported.

## Required capability areas

| Area | Current evidence | M14 required evidence / risk |
|---|---|---|
| PHP/server runtime | PHP 8.4 CI and staging; supported extensions enumerated in CI | define minimum PHP/extensions from dependency/platform metadata; test missing extension and PHP limits; distinguish server runtime from customer host |
| Database | MySQL 8.4 and SQLite CI; MariaDB appears in backup fixtures/docs | identify supported MySQL/MariaDB versions/engines/charset/size; test import/transaction/locking differences; PostgreSQL not assumed |
| WordPress | Connector package supports capability exchange, inventory, backups, restore, updates, security workflows | test minimum/latest supported WordPress and PHP, multisite topology and plugin conflicts; no blanket “all hosts” claim |
| Filesystem | connector path guard, hashes, staged restore, permissions preflight | writable root/content paths, symlinks, ownership, case-sensitive paths, atomic rename and open_basedir fixtures |
| Disk/resources | system check measures staging free space; Connector scanner time/memory budgets | compute per-operation headroom for largest archive + parallel jobs; memory/time/process ceilings; disk-full recovery |
| HTTPS/network | production Connector HTTPS default; monitoring pinned DNS; WordPress site initiates polling | TLS chain, outbound 443 to fixed service/providers, restricted outbound support, proxy behavior, DNS changes and timeout tests |
| Cron/queue | scheduler wrapper and database queues documented | WP-Cron/real cron and missed schedules; duplicate polling lease; disabled cron and delayed queue diagnostics |
| PHP functions/extensions | CI requires curl, zip, sodium, pdo_mysql, intl, gd, openssl, fileinfo, pcntl and others | map runtime vs test-only requirements; avoid unsupported shell functions; `disable_functions` and extension failure fixtures |
| Security plugins/WAF | no universal compatibility matrix located | document WAF/proxy/plugin blocks, request/body caps, REST restrictions and allowlist diagnostics using fake hosts |
| Reverse proxy/TLS | trusted proxy and HTTPS config exist | validate correct client IP and scheme behind known proxy; never trust wildcard unless proxy-only topology is proven |
| Multisite | manifest boolean exists | status `NOT VERIFIED`; deny/limit network restore until site/network scope tested, including subsite IDs and network-wide operations |
| Bedrock/custom docroot | no support contract or fixture found | status `NOT VERIFIED`; do not promise; define roots/bootstrap/config discovery and symlink behavior before support |

## Owner decisions and non-decisions

Owner should decide commercial/launch support boundaries for Multisite, Bedrock/custom docroots and allowed hosting profiles after the compatibility evidence is prepared. Engineering sets explicit safe requirements and operation-level preflight. No manual exception should force a destructive/restore action past an unknown capability.

## M14 deliverables

Versioned capability registry; harmless preflight with per-capability `SUPPORTED`, `UNSUPPORTED`, `UNKNOWN`, `BLOCKED`; customer-safe actionable wording; Operator diagnostics with private internals redacted to customer; reproducible compatibility fixtures; no false “all hosts” restore promise. M15 DR audit must independently verify live restore/recovery across the actual pilot host set.
