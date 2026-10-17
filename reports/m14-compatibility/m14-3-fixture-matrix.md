# M14.3 Compatibility Fixture Matrix

All fixtures are disposable and version-tagged. “Destructive” means mutation of fixture-only files/database; no customer or provider resource may be mutated. The standard fixture is single-site, standard root, tested Connector, supported evidence-backed WP/PHP/MySQL or MariaDB, verified HTTPS/polling, writable required paths, sufficient quota and verified same-site backup.

| Fixture | Facts / expected capabilities and reasons | Test layer | Destructive? / cleanup | Supported claim |
|---|---|---|---|---|
| FX-01 Standard baseline | facts above; applicable capabilities PASS, absent scanner result remains separate | Server feature + Connector SQLite/MySQL real WP | disposable backup/restore only; marker cleanup + verify | only exact fixture envelope |
| FX-02 Connector 0.4.5 | current staging synthetic version; supported-old for proven unaffected operations; high-risk capability unknown/block if 0.4.6 hardening required | compatibility evaluator + real WP | no staging upgrade/re-pair; isolated fixture teardown | no general 0.4.5 promise |
| FX-03 minimum/below-minimum PHP and WP | minimum selected from source+fixtures; below blocks dependent operations | Connector matrix | fixture config only | tested bounds only |
| FX-04 extension missing individually | dependency-specific degradation; unrelated monitoring can remain | PHP isolated container/runtime | ephemeral container | operation-specific |
| FX-05 read-only/partial permissions | read diagnostics possible; exact file backup scope; update/restore/remediation block | temp filesystem + Connector | sentinel verification | no partial artifact called complete recovery point |
| FX-06 low/unknown disk quota | near threshold WARN, below or unknown BLOCK/UNKNOWN per operation | quota/fake disk | quota reset; never fill host | measured operation budget only |
| FX-07 MySQL/MariaDB variants | tested engine/version/prefix/charset pass; unknown unsupported variant does not inherit pass | real WordPress DB CI | disposable DB reset | only exercised versions |
| FX-08 network | DNS/TLS/timeout/proxy/403/406/413/429 | fake server/proxy | fake endpoints stopped | protocol behavior only |
| FX-09 scheduler/poll | WP-Cron, equivalent real cron, stale/no poll | WP/process tests | restore scheduler state | effective polling only |
| FX-10 Multisite | detect; unsupported ambiguous/mutating operations BLOCK | disposable network install | destroy marked install and DB after evidence | explicit unsupported initial launch |
| FX-11 Bedrock/custom root | detect where reliable; unknown root BLOCK; outside-root sentinel preserved | disposable filesystem | remove exact fixture | explicit unsupported initial launch |
| FX-12 symlink root | unresolved root symlink BLOCK; contained paths reuse M14.2 guard | filesystem/process | unlink fixture, verify target sentinel | no root guess |
| FX-13 object cache/drop-ins | test stale post-restore state if reproducible; otherwise no blocker | optional disposable cache service | flush/delete fixture namespace | no blanket cache claim |
| FX-14 Basic Auth/maintenance | only impacted probe/status degrades; outbound Connector may remain ready | fake HTTP + WP | restore fixture settings | capability-specific |
| FX-15 state/race | PASS then disk/permission/conflict change; fresh recheck blocks; policy v1→v2 stale | MySQL/process | disposable DB/jobs | stale PASS cannot dispatch unsafe operation |
| FX-16 restore artifact matrix | same-site valid verified backup passes prerequisites; missing/corrupt/wrong-site/incompatible blocks | Laravel + Connector integration | disposable DB/files, no provider deletion | readiness only, not universal success |

## Fixture gates

No version range or host/provider brand is called supported until its exact fixture, CI result, policy version and operation scope are recorded. Reuse M14.2 archive/path/security tests; only add M14.3 capability assertions around those primitives.

## Execution results — 2026-10-17

| Fixture | Final result | Evidence / boundary |
|---|---|---|
| FX-01 Standard baseline | `PASS — evaluator baseline; host-root evidence bounded` | Standard fact vector is exercised in Server feature tests; real WordPress CI runs 7.1.2 against MySQL 8.4/MariaDB 10.11. The real harness’s content directory is intentionally outside the Composer WordPress root and classifies custom; it is not represented as a real standard-root host proof. |
| FX-02 Connector 0.4.5 | `PASS — safe per-capability classification` | Staging synthetic M8 stays 0.4.5; read-only transaction probe yields connection PASS / monitoring WARN / mutation and restore BLOCK / absent-fact UNKNOWN. Snapshot/history rollback verified; no upgrade or re-pair. |
| FX-03 minimum/below PHP and WP | `PARTIAL — exact tested branches only` | PHP 8.4 and WordPress 7.1.x/7.1.2 run in CI. PHP 8.1 and WP 6.4 are not tested; unknown branches do not gain high-impact PASS. |
| FX-04 missing extension | `NOT RUN as dedicated environment fixture` | Bounded extension facts are collected; database capability uses required mysqli fact. Missing-extension permutations remain an explicit support residual. |
| FX-05 read-only/partial permissions | `PARTIAL — fail-closed fact handling` | Evaluator consumes read/write/rename facts; no separate read-only host/container fixture was run. Unknown/denied mutation prerequisites do not pass. |
| FX-06 low/unknown disk | `PASS — evaluator boundary` | Reserve/insufficient and missing disk facts are exercised; no host disk was filled. |
| FX-07 DB variants | `PASS — exact CI branches` | Real WordPress Connector jobs passed MySQL 8.4 and MariaDB 10.11; no broader minimum is claimed. |
| FX-08 network | `REUSED — M14.2 transport evidence plus current heartbeat` | Existing bounded TLS/redirect/request behavior and staging Connector health are preserved; no external host/WAF compatibility claim. |
| FX-09 scheduler/poll | `PARTIAL` | Stale capability evidence fails closed; staging scheduler/Connector health passed. No dedicated WP-Cron-disabled/real-cron host fixture was run. |
| FX-10 Multisite | `PASS — classification/evaluator fixture` | Multisite facts classify and block affected mutation capabilities while retaining connection diagnostics; no live network site was changed. |
| FX-11 Bedrock/custom root | `PARTIAL — custom/unknown covered` | Custom and unknown root facts block; no isolated Bedrock installation fixture was run. |
| FX-12 root symlink | `REUSED / RESIDUAL` | M14.2 path containment/symlink guards remain authoritative; no separate M14.3 unresolved-root-symlink Connector fixture was run. |
| FX-13 object cache/drop-ins | `NOT RUN; no reproduced concrete blocker` | No blanket cache/drop-in incompatibility claim is made. |
| FX-14 Basic Auth/maintenance | `NOT RUN as compatibility fixture` | No host-wide claim; actual Connector polling/health remains independently represented. |
| FX-15 state/race | `PASS — core stale evidence and process gates` | Stale facts/policy reject high-impact queueing with no queue side effect; MySQL process/concurrency tests pass. |
| FX-16 restore artifact/readiness | `REUSED — feature-level restore evidence; no live staging restore` | Existing verified-backup/restore feature tests supply the synthetic positive workflow; readiness blockers and stale facts are fail-closed. No staging restore was dispatched. |

The unrun items above are why the closure disposition is B, not a claim of exhaustive hosting-matrix proof. Their affected high-impact capabilities remain UNKNOWN/BLOCK until a versioned fixture proves otherwise.
