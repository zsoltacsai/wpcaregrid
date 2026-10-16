# M14 Hosting Compatibility Audit — Planning Baseline

**Status:** `PARTIAL`; initial support boundaries are Owner-approved, but capability implementation, fixtures and cross-host compatibility audit remain incomplete.

## Source facts observed

- Server deploy and release docs target PHP 8.4 and MySQL 8.4 CI; current staging is PHP 8.4.26, Laravel 13.33.0, MySQL, HTTPS and zero pending migrations.
- The server uses Laravel queues and scheduler; staging uses database queue and a guarded scheduler wrapper. Required CI covers SQLite and MySQL and Connector SQLite/MySQL.
- WordPress feature execution occurs in the site Connector; server uses an outbound-only polling protocol (`routes/api.php`). Monitoring has pinned public DNS resolution and redirect checks (`WebsiteProbe`, `PublicOrigin`, `SafeRedirect`).
- `config/backup.php` exposes S3 and SFTP storage, chunk/object/staging bounds and restore flags. Restore preflight and Connector filesystem checks are documented in `docs/BACKUP_ENGINE.md`.
- System health checks disk availability and storage, but there is not yet a single preflight report that establishes all environment capabilities per site/host before promising each action.
- Backup manifest has a `site.multisite` boolean, but that serialization field is not proof of safe Multisite scheduling/restore across network sites.
- No Bedrock support contract or dedicated Bedrock fixture was identified in the reviewed docs/tests.

## Compatibility boundary recommendation

OQ-01 approved a capability-specific support model on 2026-10-16. A host/site is supported for a specific operation only when versioned compatibility/preflight fixtures and runtime checks prove that capability; unknown restore/write compatibility fails closed. Begin from the tested server baseline (PHP 8.4/Laravel 13, CI-exercised SQL drivers and Connector capability level), but do not infer universal provider, shell, WP-CLI, filesystem or network behavior.

OQ-02 and OQ-03 approved the following initial-launch boundary: standard WordPress layouts are the support baseline; Multisite/network installations, Bedrock, Composer-managed layouts, arbitrary custom document roots and unresolved symlinked/custom-root installations are not supported. Detect and report these configurations where possible. Block restore, destructive, filesystem-wide, network-wide or other mutating operations whenever scope/canonical root cannot be proven. Safe, limited read-only diagnostics may remain available only when demonstrated not to imply support. Reconsider excluded layouts only in a future milestone after the dedicated fixture and recovery evidence described in the Owner decision register passes.

## Required capability areas

| Area | Current evidence | M14 required evidence / risk |
|---|---|---|
| PHP/server runtime | PHP 8.4 CI and staging; supported extensions enumerated in CI | define minimum PHP/extensions from dependency/platform metadata; test missing extension and PHP limits; distinguish server runtime from customer host |
| Database | MySQL 8.4 and SQLite CI; MariaDB appears in backup fixtures/docs | identify supported MySQL/MariaDB versions/engines/charset/size; test import/transaction/locking differences; PostgreSQL not assumed |
| WordPress | Connector package supports capability exchange, inventory, backups, restore, updates, security workflows | define versioned per-capability tested matrix; standard single-site baseline only; no blanket “all hosts” claim |
| Filesystem | connector path guard, hashes, staged restore, permissions preflight | writable root/content paths, symlinks, ownership, case-sensitive paths, atomic rename and open_basedir fixtures |
| Disk/resources | system check measures staging free space; Connector scanner time/memory budgets | compute per-operation headroom for largest archive + parallel jobs; memory/time/process ceilings; disk-full recovery |
| HTTPS/network | production Connector HTTPS default; monitoring pinned DNS; WordPress site initiates polling | TLS chain, outbound 443 to fixed service/providers, restricted outbound support, proxy behavior, DNS changes and timeout tests |
| Cron/queue | scheduler wrapper and database queues documented | WP-Cron/real cron and missed schedules; duplicate polling lease; disabled cron and delayed queue diagnostics |
| PHP functions/extensions | CI requires curl, zip, sodium, pdo_mysql, intl, gd, openssl, fileinfo, pcntl and others | map runtime vs test-only requirements; avoid unsupported shell functions; `disable_functions` and extension failure fixtures |
| Security plugins/WAF | no universal compatibility matrix located | document WAF/proxy/plugin blocks, request/body caps, REST restrictions and allowlist diagnostics using fake hosts |
| Reverse proxy/TLS | trusted proxy and HTTPS config exist | validate correct client IP and scheme behind known proxy; never trust wildcard unless proxy-only topology is proven |
| Multisite | manifest boolean exists; does not prove safe network operation | `UNSUPPORTED` for initial launch; detect and report; block restore, destructive, filesystem-wide and network-wide operations with unproven scope; limited diagnostics only if demonstrably safe |
| Bedrock/custom docroot | no support contract or fixture found | `UNSUPPORTED` for initial launch; detect non-standard layout; block mutation/restore unless canonical root boundaries are proven; never guess roots or follow symlinks outside the allowed root |

## Owner decision application and remaining external gates

The initial support policy is approved under OQ-01–OQ-03; it is not a claim that compatibility is already implemented or that any untested host is supported. Engineering must prove each reported capability with runtime checks and versioned fixtures. No manual exception may force a destructive/restore action past an unknown capability. Qualified legal/accounting minima and physical provider deletion semantics remain separate `WAIT_LEGAL`, `WAIT_ACCOUNTING` and `WAIT_FACT` gates where applicable.

## M14 deliverables

Versioned capability registry; harmless preflight with per-capability `SUPPORTED`, `UNSUPPORTED`, `UNKNOWN`, `BLOCKED`; customer-safe actionable wording; Operator diagnostics with private internals redacted to customer; reproducible compatibility fixtures; no false “all hosts” restore promise. M15 DR audit must independently verify live restore/recovery across the actual pilot host set.
