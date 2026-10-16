# M14.3 Initial Support Matrix (Evidence-Gated)

This is an engineering target for fixtures, not a public compatibility promise. Numeric support ranges remain `TBD_FROM_FIXTURES` where repository evidence is incomplete.

| Dimension | Source evidence | Initial classification rule | Evidence still required |
|---|---|---|---|
| Connector | source release 0.4.6; API v1; 0.4.5 connected staging fixture | current 0.4.6 expected baseline; older version evaluated per capability; unsupported only where required protocol/security behavior is absent; unknown newer is not optimistic PASS | 0.4.5 vs 0.4.6 operation matrix; reconcile `latest_version` default 0.4.1 |
| WordPress | plugin header minimum 6.4; real test dependency WordPress `~7.1.0`; local E2E 7.1.2 | below actual declared/API floor blocks affected Connector functions; 7.1.x is tested fixture range, not claim that all 7.x is supported; unknown future versions warn until regression evidence | minimum-current-boundary fixtures and API/dependency review |
| PHP on customer site | plugin header PHP 8.1; tests run with PHP 8.2+ dependency constraint; local E2E PHP 8.3.33 | 8.1 declared minimum requires real fixture before `PASS`; tested versions only supported once CI covers; newer unknown warns unless runtime/security fact proves block | PHP 8.1 fixture and tested CI matrix; extension/functions inventory |
| PHP on Laravel server | CI PHP 8.4 | not a customer Connector support dimension | none for customer-host policy |
| DB | Connector uses MySQL/MariaDB and database backup capability only advertised if supported; manifest records engine/version/charset/prefix | MySQL/MariaDB only for DB backup/restore; other engines block those capabilities; connection/monitoring may remain pass | declare versions only after MySQL/MariaDB fixtures and restore proof |
| WooCommerce | inventory currently reports installed/active/version | conditional support for a capability that consumes WooCommerce data; no blanket connector dependency | actual Woo-specific product requirements/tests |
| Site topology | approved OQ-02 | single-site standard layout is launch baseline; Multisite detected and blocks ambiguous/network-wide mutation/restore | isolated Multisite detection and denial fixture |
| Layout | approved OQ-03 | standard supported root/content layout only; Bedrock/Composer/custom root/unresolved symlink is unsupported or UNKNOWN; mutation/restore block | standard, Bedrock, custom-root, contained-symlink and unresolved-symlink fixtures |
| HTTPS/egress | Connector uses verified TLS; API v1 outbound polling | verified HTTPS and protocol reachability required for remote operations; proxy is supported only if existing WP HTTP stack honors it in tests | fake TLS/DNS/timeout/proxy/403/413/429 tests |
| Filesystem | current backup skips symlinks; restore uses staging/path guards and bounded operations | read-only may allow monitoring and defined readable backup scope; update/restore/remediation require fresh operation-specific write/rename/containment PASS | read-only and permission/rename/quota fixtures |
| Resource budget | operation caps and 50 MiB disk margin exist | per-operation estimates + configurable reserve; unknown free space cannot yield restore PASS; never universal fixed threshold | measured fixture-derived requirements, low-disk quota fixtures |
| Scheduler | polling is Connector controlled; server heartbeat/poll thresholds configured | stale status based on observed heartbeat/poll; WP-Cron disabled is not itself unsupported when external real cron works | normal, real-cron replacement and stale poll fixtures |

## Staging 0.4.5 classification

Keep fixture paired; do not upgrade during planning. Evaluate independent non-mutating monitoring/connection paths as `SUPPORTED_OLD` if their v1 behavior is proven. For high-risk operations whose archive/path/response guarantees depend on 0.4.6 changes, classify as `BLOCK` or `UNKNOWN` until equivalent protections are established. Do not equate “older than latest” with globally unsupported, and do not let 0.4.5 assert unsupported facts as false. Reconcile deployed environment’s latest-version setting with source config default before interpreting UI status.

## Claim policy

No provider-brand support list. The product may describe capability-specific current evidence; it may not claim universal host support or guaranteed restore. M15 must independently prove actual pilot-host behavior.
