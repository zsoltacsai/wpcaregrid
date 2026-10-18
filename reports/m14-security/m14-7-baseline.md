# M14.7 M147-A Source Baseline

**Baseline commit:** `0a0d25c2da256015d5cffad5f419603593ed72b5` — merge commit for planning PR #119 on `claude/ecstatic-wozniak-oemyuf`.

**Evidence boundary:** repository source and committed lockfiles only. No live environment secrets, staging configuration, production state, customer data, M12 runtime values, scanner execution, Wordfence feed, or browser test was read or run for this baseline.

## Versions and provenance

| Component | Baseline evidence |
|---|---|
| Laravel application | `laravel/framework` `v13.33.0` in root `composer.lock`; application release SHA is `0a0d25c2da256015d5cffad5f419603593ed72b5`. |
| WordPress Core | Locked package `johnpbloch/wordpress-core` `7.1.3` in `wordpress/composer.lock`. |
| WP CareGrid Connector | `0.4.7` in `wordpress/wp-caregrid-connector/release.json` and plugin header. The immutable Connector source revision carried by the M14.6 staging artifact is `9e330854f0e7d5ef073db846245f7008d7ef6ec5`; the Connector tree at that revision matches the tree at this baseline (`af37410e8fbcc561762aceb87467800869e0606f`). The last path-specific source commit currently recorded by Git is `f565e49b3b955c48ccf65b344576e0657842cbee`. These are Connector artifact provenance facts and are not the Laravel application release SHA. |
| Connector source-tree digest | SHA-256 over sorted relative file names and file content digests at baseline: `07eef2dc36c1bd6e22fd37806b4555fc56fb7c47580ebc74de588ef2ee3b2f98`. |
| Primary CI | Planning merge primary CI run `39098281556` completed all seven required jobs; Server MySQL process/concurrency gate passed. This is release-integrity context, not scanner evidence. |

## Security source/configuration inventory

- `config/security.php`: scanner version `1.0.0`; vulnerability source defaults to `none`; feed freshness default is 72 hours; scheduled scans default disabled. Source defaults for incident auto-open and backup-request are true. Remediation and plugin deactivation defaults are false. These are source defaults only; environment overrides were not read.
- `app/Security/SecurityScanService.php` coordinates scan lifecycle and recorded scanner/Connector versions. Vulnerability feed parsing/matching is in `app/Security/Vulnerabilities/WordfenceFeed.php` and `VulnerabilityScanner.php`; `app/Console/Commands/Security/SyncVulnerabilities.php` downloads only an explicitly configured HTTPS source or imports a supplied file. It has no M147-A runtime invocation.
- Security scheduled commands are `security:monitor`, `security:schedule`, and conditionally `security:vulnerabilities:sync` in `routes/console.php`. Source configuration conditions the feed refresh on `source=wordfence` and a configured URL. No live feed state was read.
- Connector API v1 pair/heartbeat/inventory/jobs routes are under `routes/api.php`; paired requests use `connector.auth` and endpoint-specific throttles. Operator routes are defined in `routes/operator.php`: security overview requires `operator.security.view`; finding status changes require `operator.security.manage`; remediation requires `operator.security.remediate` and throttling; incident backup requests require `operator.backups.run`. Route scoping is used for nested website/finding/incident resources. Controllers are `app/Http/Controllers/Operator/Security*Controller.php`; role/capability boundaries also appear in `tests/Feature/AuthorizationMatrixTest.php`. Scheduled commands are `security:monitor`, `security:schedule`, and conditionally `security:vulnerabilities:sync`. The synthetic M147-A scaffold does not pair a Connector or call these routes/commands.
- `config/privacy.php` has six independent M12 destructive capability keys whose code defaults are false. This report records key names only, not runtime values. A fresh secret-safe read-only read of all six is still mandatory before any separately authorized staging scanner validation.

## Existing test/CI coverage

Security feature coverage includes `SecurityScanTest`, `VulnerabilityAndPostureTest`, `IncidentLifecycleTest`, `RemediationTest`, and `AuthorizationMatrixTest`. The CI mandatory suite remains seven jobs: Quality; Server SQLite; Server MySQL; Server MariaDB; Connector WordPress SQLite; Connector WordPress MySQL; Connector WordPress MariaDB. MySQL process/concurrency is part of Server MySQL. Existing unit/feature fixtures do not establish live feed freshness, full scanner effectiveness, or browser execution.

## Carry-forward findings and scope

- AS-16 remains open: no completed scanner coverage evidence is asserted here.
- M146-F08 was open at the M147-A baseline and pending adversarial log-integrity evidence then. Subsequent M147-B review closed it narrowly for the tracked secret-path checker output path; see the [M147-B closure report](m14-7-b-closure-report.md). Broader runner-command parsing is not claimed tested.
- M14.6 single-maintainer independent-review residual remains.
- M12 fresh post-deployment gate readback remains missing; source defaults do not substitute for it.
- M14.6 reported one pre-existing security scanner warning. This baseline does not claim the warning was cleared.

M147-A establishes only a source inventory and local fixture/evidence-harness groundwork. No vulnerability feed was configured, no scanner ran, and no vulnerability-clean claim is made.
