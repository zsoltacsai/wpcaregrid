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
# M14.3 planning reconciliation — 2026-10-16

This addendum supersedes assumptions below that treat the proposed compatibility matrix as implemented behavior. M14.3 is planning-only; no runtime controls are marked PASS by this audit update.

Verified source is primary `94b9fbafe2c2ab89d81b677af82d0d3d956c0175`; latest verified executable staging is `c5e0edabd8bd1661f72eb20330c5ca2f4375b9c5`. Connector source/package is 0.4.6/API v1; the connected synthetic staging fixture reports 0.4.5 and was left untouched. M14.2 now provides payload bounds, safe outbound behavior, archive/path/race guards and staged restore recovery; M14.3 must consume these primitives, not duplicate them.

Current support disposition remains **PARTIAL / not yet implementation evidence**. Existing Connector heartbeat/pairing facts include Connector/WP/PHP versions, advertised job capabilities, health and polling; inventory includes WooCommerce. Backup manifests already carry DB engine/version/charset/prefix, site Multisite bit, artifact sizes and hashes. `JobRegistry` suppresses DB/full backup/restore capabilities when DB dumping is unavailable. `DiskSpace` has a 50 MiB margin and unknown free space does not prove sufficient capacity. These are useful evidence points, but there is no canonical persisted policy-versioned per-operation capability vector or operation-time compatibility decision.

Historical planning note (superseded during M14.3 implementation): source config was reported as defaulting to `0.4.1`. Direct inspection of primary `94b9fbafe2c2ab89d81b677af82d0d3d956c0175` and planning merge `80642e369e86e022f2a2f9d32b6b54d4f2eb28a9` shows `config/connector.php` derives `latest_version` from the canonical `release.json`; no environment override is supported. This was a stale-source observation, not confirmed configuration drift. M14.3 adds a consistency regression test. The 0.4.5 staging fixture remains per-capability evidence and is not upgraded or re-paired.

Evidence-based version envelope is not yet established. Connector header declares WordPress >=6.4 and PHP >=8.1; real WordPress test dependency uses WP 7.1.x and PHP ^8.2, while local E2E exercises WP 7.1.2/PHP 8.3.33. These do not establish complete supported ranges. Laravel server PHP 8.4/MySQL CI is not customer-host compatibility evidence. MySQL/MariaDB are the supported Connector DB paths; exact customer version bounds remain fixture-derived.

Approved OQ-02/OQ-03 boundaries remain: detect Multisite, Bedrock/Composer/custom root and unresolved root symlink where possible; block affected mutation/restore when unsupported or root uncertain. A standard single-site fixture is the only initial launch baseline until fixture results prove a wider envelope. Final detailed plan/matrices: [M14.3 plan](m14-3-plan.md), [capability domain](m14-3-capability-domain.md), [support matrix](m14-3-support-matrix.md), [preflight matrix](m14-3-preflight-test-matrix.md), [restore matrix](m14-3-restore-readiness-matrix.md), [fixture matrix](m14-3-fixture-matrix.md).

No customer host was changed. M12 destructive gates remain false. AS-16 warning remains M14.7 carry-forward. Owner decisions OQ-01–03 are already approved; no new Owner decision is needed for the planned implementation.

## M14.3 final engineering disposition — 2026-10-17

The preceding `PARTIAL / not yet implementation evidence` and planning ranges above are historical and superseded by the merged M14.3 implementation and [closure report](m14-3-closure-report.md). Capability-specific controls are implemented and exercised through exact-head CI and guarded staging validation at executable SHA `9e330854f0e7d5ef073db846245f7008d7ef6ec5`. This is not a universal hosting claim.

| Disposition | Evidence-based scope |
|---|---|
| `SUPPORTED BASELINE` | Tested CI fixture line: Connector 0.4.7, WordPress 7.1.x/7.1.2, customer-host PHP 8.4, MySQL 8.4 or MariaDB 10.11, standard single-site facts. PASS remains capability-specific and does not bypass authorization, entitlement, workflow, or safety checks. |
| `SUPPORTED_WITH_WARNING` | Existing 0.4.5 synthetic staging Connector: connection PASS, monitoring WARN, high-impact operations blocked. This is one version-drift case, not a general host promise. |
| `UNSUPPORTED` | Multisite and known custom/Bedrock-like layouts for affected mutation/restore scope, under approved OQ-02/OQ-03 boundaries. |
| `UNKNOWN / NOT YET TESTED` | Declared PHP 8.1 and WordPress 6.4 minima, other version branches, unresolved roots/symlinks, and unexecuted edge-host fixtures. Unknown mutation/restore remains fail-closed. |

No broad version range is inferred from package metadata. CI exercises PHP 8.4.26, WordPress 7.1.2/7.1.x, MySQL 8.4, and MariaDB 10.11. M143-F01 is closed as a stale-source observation: `config/connector.php` reads canonical package `release.json`, guarded by a regression test. Connector source is now 0.4.7/API v1.

Exact-SHA staging health, migration, package, public-route checks, and all six M12 gates passed. The existing 0.4.5 fixture was not upgraded/re-paired. Its transaction-only evaluator probe rolled back and left no snapshot or transition rows. “No completed security scan yet” remains visible as M14.7_CARRY_FORWARD. M15 pilot-host and DR proof remains separate.
