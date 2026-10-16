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
