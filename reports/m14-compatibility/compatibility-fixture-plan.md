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
