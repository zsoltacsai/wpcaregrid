# M14 Compatibility Fixture Plan

## Principles

- Use disposable synthetic WordPress installations, isolated MySQL/SQLite databases and fake providers. Never use an ordinary customer website for destructive or compatibility edge cases.
- Version every fixture and expected capability result. Capture PHP/WordPress/database/plugin/Connector versions, extensions, filesystem semantics and feature flags.
- Avoid test-specific exceptions in production code. Capability unknown or fixture mismatch must fail closed.
- Keep staging tests under normal staging host identity and scoped synthetic-site UUID; never enable M12 production switches.

## Fixture matrix

| Fixture | Variation | Required behavior |
|---|---|---|
| Host baseline | supported PHP/WordPress, MySQL, HTTPS, writable content | all relevant preflight checks pass and benign backup/restore lifecycle works |
| Minimum PHP / missing extensions | each required extension absent independently (curl, zip, sodium, openssl, fileinfo as applicable) | capability reports unsupported with actionable code; no fatal partial write |
| Read-only and limited permissions | WordPress root, content, temp, rename denied | backup coverage is partial/truthful; restore/update/remediation block before mutation |
| Disk pressure | below reserve, near object size and concurrent transfer limit | reject safely before extraction/write; leave existing site and retry state truthful |
| Database variants | supported MySQL/MariaDB versions, charset/collation, table prefix | backup verification/restore result matches explicit support matrix; unsupported engine blocks |
| Network | no outbound HTTPS, proxy, DNS timeout, TLS invalid, service timeout | bounded retries, no leaked secret, state becomes unavailable/partial not success |
| Multisite | network root plus subsite tables and network plugins | OQ-02 approved as unsupported for initial launch: detection/status must be explicit; restore, destructive, filesystem-wide and network-wide operations block when scope is unproven; limited diagnostics only if demonstrated safe. Future support requires dedicated network-level backup, restore, scope/tenant, plugin/theme, users/uploads and recovery fixtures. |
| Bedrock/custom root | Bedrock, Composer-managed layout, custom docroot, symlinked content/config | OQ-03 approved as unsupported for initial launch: detect/report non-standard layout where possible; unresolved canonical root or symlink boundary blocks mutation/restore; never guess. Future support requires canonical-root discovery and fixture-based backup/restore/filesystem evidence. |
| Archive adversarial | `../`, absolute, Windows separators, NUL, duplicate/case-colliding names, symlink, huge member count, expansion ratio | reject before mutation; bounded CPU/memory/disk; cleanup verified |
| Concurrency | two backup/restore/update/remediation operations; permission revoked mid-job | locks and fresh authorization prevent duplicate or stale writes |
| Provider outage | fake S3/SFTP provider timeout, permission denied, checksum mismatch | no success state; retries bounded; no credential-bearing diagnostic |

## Test layers

Unit capability mapping; feature-level preflight against synthetic fixture; Connector integration across SQLite/MySQL harnesses; process/concurrency tests for restore and duplicate jobs; isolated staging smoke; manual operator walkthrough for diagnostics. OQ-01–OQ-03 are approved boundaries, not passing compatibility evidence. No real destructive provider APIs or production secrets.
# M14.3 planning reconciliation — 2026-10-16

M14.2 now supplies adversarial archive, payload, redirect/egress, path containment and restore race/failure evidence. M14.3 should reuse those lower-level tests and add capability classification/effect assertions; do not duplicate M14.2 security tests as if they were new compatibility coverage.

The current staging synthetic fixture is paired at Connector 0.4.5 while source release is 0.4.6. Do not re-pair/upgrade it during planning. Add an isolated 0.4.5 vs 0.4.6 per-capability fixture; high-risk operations requiring protections introduced in 0.4.6 remain UNKNOWN/BLOCK absent equivalence proof, while unrelated read/monitor capabilities can be supported-old if evidence permits.

Use the versioned, operation-specific fixture inventory in [M14.3 fixture matrix](m14-3-fixture-matrix.md). Numeric WordPress/PHP/MySQL/MariaDB bounds are TBD from actual fixtures. The expected baseline is standard single-site layout only. Multisite and Bedrock/custom/unresolved symlink roots are expected unsupported initial-launch conditions, not security vulnerabilities. Low disk tests use a quota/fault abstraction, never host exhaustion. Fake network tests distinguish DNS/TLS/timeout/403/406/413/429 and preserve existing M14.2 egress policy.

Every fixture must record exact fact set, policy/evaluator version, expected capability/reason vector, mutation boundary, cleanup method and cleanup verification. CI green is not host-support evidence; staging fixture success is not M15 pilot proof.

## M14.3 fixture execution reconciliation — 2026-10-17

The 16-fixture proposal above is not a claim that each host variant ran. Executed/reused evidence and explicit untested rows are in [M14.3 fixture results](m14-3-fixture-matrix.md). CI passed actual Connector 0.4.7 WordPress jobs on SQLite/MySQL/MariaDB and server capability tests; staging kept the pre-existing synthetic Connector 0.4.5 unchanged. Declared PHP 8.1/WP 6.4 minima, dedicated missing-extension/read-only/rename, Bedrock/root-symlink, cron replacement, and select network/cache fixtures remain UNKNOWN/NOT TESTED. They stay outside the claimed supported envelope; affected mutation/restore capability stays fail-closed.
