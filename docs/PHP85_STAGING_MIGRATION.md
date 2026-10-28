# Staging PHP Runtime Migration Procedure

**Status:** tooling proposal; staging PHP 8.5 cutover remains NO-GO until every Owner precondition is verified and separately approved. This document does not authorize package, service, backup, or runtime changes.

## Scope and invariants

The migration uses only the fixed profiles `/usr/bin/php8.4` and `/usr/bin/php8.5`. It does not use `/usr/bin/php`, PATH lookup, or `update-alternatives` for application PHP commands. PHP 8.4 remains installed and its shared FPM service and socket remain available to sibling vhosts. The Nginx selector is scoped to the single `staging.wpcaregrid.com` vhost; the helper refuses a selector referenced by any other vhost. Queue and scheduler commands use the root-owned `/usr/local/bin/wpcaregrid-php` dispatcher, which accepts only the application Artisan entry point and resolves the same profile as the Nginx selector.

The existing exact-SHA CI verifier, branch-tip check, deployment lock, protected DB backup, maintenance gate, scheduler lock/pause, queue drain, application health checks, and fail-closed recovery remain required. The profile switch is attempted only after the gate and scheduler pause are active and both queue workers are stopped. The helper rejects arbitrary profile names, paths, commands, Nginx changes, and Supervisor commands.

## Required host preparation (ordered gates; each write needs separate Owner approval)

This section is a preparation plan, not authorization to act. Complete Gate 1 and verify it before requesting a separate Gate 2 package-install GO. PHP 8.5 installation, staging cutover, production release and infrastructure changes remain NO-GO absent their own approvals.

### Gate 1 — pin application consumers to PHP 8.4 first

Before any PHP 8.5 package is installed:

1. Read-only record the current PHP 8.4 CLI/FPM versions, shared 8.4 FPM service/socket state, the global `php` alternatives mode and selection, and the exact Supervisor and scheduler consumer commands. The current alternatives mode is auto; do not change it as part of this runbook step.
2. Obtain a separate Owner GO for the PHP 8.4-only protected deployment that prepares the root-owned `/usr/local/bin/wpcaregrid-php` dispatcher and the fixed scheduler wrapper. Its exact source and hashes must come from the reviewed primary commit. Keep the dispatcher's active/default profile on `/usr/bin/php8.4`.
3. Under that approved deployment, first verify the Supervisor file is root-owned mode 0644, has no named/default ACLs, and contains only the two documented worker commands. Pin both commands and the scheduler entry to the protected dispatcher/wrapper. Drain workers safely and follow the protected controller; do not edit Supervisor or scheduler files manually. Keep PHP 8.4 FPM and its shared socket available to sibling vhosts.
4. Before Gate 1 is accepted, read-only verify both live workers resolve to `/usr/bin/php8.4`, their command lines use the dispatcher, and the scheduler invokes the fixed wrapper/dispatcher with the 8.4 profile. Confirm no application worker or scheduler command relies on unversioned `php` or PATH lookup. Record sanitized evidence only.
5. Keep the existing deploy-only sudo policy. Install the root helper only from its reviewed merge commit and source hash; do not grant general sudo, root SSH, package-manager access, or direct service commands to deploy.
6. If any consumer cannot be pinned and verified, stop. Do not proceed to PHP 8.5 package installation. Do not alter global alternatives, Nginx, FPM configuration, or services during this gate unless a separate Owner GO explicitly covers that exact change.

### Gate 2 — separately approved PHP 8.5 side-by-side package installation

Only after Gate 1 is complete and independently verified, prepare a distinct package-install request:

1. Revalidate the Ondřej PHP PPA signer, signed repository metadata, candidate package hashes, host architecture, required extensions and exact CLI/FPM package versions. The supplied 2026-10-28 evidence records the signer and inspected candidate integrity as verified; refresh that evidence immediately before package use.
2. Inspect the exact package maintainer scripts and a fresh APT simulation. The PHP 8.5 CLI package can register priority-85 `php` alternatives while global alternatives are in auto mode. Do not run `update-alternatives` or change its mode under this plan. The dispatcher/consumer pinning in Gate 1 is a prerequisite, not proof that unrelated host consumers are unaffected; account for and verify the package-script effect within the separately approved installation plan.
3. Review and explicitly approve the PHP 8.5 FPM package's service-start behavior before installation. It may attempt to start its versioned FPM service. If the effect cannot be safely controlled and verified without changing Nginx or disturbing PHP 8.4/shared services, stop and revise the installation plan before requesting GO.
4. After a distinct Owner GO, install only the reviewed PHP 8.5 CLI/FPM packages and required extensions side-by-side. Do not remove or upgrade the PHP 8.4 rollback packages. Keep PHP 8.4 FPM and its shared socket available; do not change Nginx selectors or any vhost in this installation-only step.
5. After installation, perform read-only checks of `/usr/bin/php8.5`, package versions, both FPM services and sockets, `[www]` worker UID/GID (`www-data`), and socket owner/mode (`www-data:www-data`, `0660`). Confirm the effective PHP 8.5 FPM settings, including Zend OPcache availability and `opcache.enable`, `opcache.validate_timestamps`, and `opcache.revalidate_freq`; PHP 8.5 OPcache is statically built in, so a separate OPcache package or `.so` is not expected. Keep the shared PHP 8.4 FPM pool/socket available to sibling vhosts. Unknown or out-of-contract values block any later cutover.

### Later cutover-only Nginx preparation (separate Owner GO)

Nginx configuration is outside both preparation gates and remains unchanged during package installation. Only after Gate 2, backup/drain readiness, exact-SHA CI and a separate cutover GO may an administrator prepare root-owned mode-0644 snippets with exactly one directive each:

- `wpcaregrid-staging-php84.conf`: `fastcgi_pass unix:/run/php/php8.4-fpm.sock;`
- `wpcaregrid-staging-php85.conf`: `fastcgi_pass unix:/run/php/php8.5-fpm.sock;`

Set `wpcaregrid-staging-runtime.conf` to a root-owned symlink to the 8.4 profile initially. Change only the `staging.wpcaregrid.com` vhost to include this selector at its PHP FastCGI location. Do not change other vhosts. The helper verifies expanded Nginx configuration and fails unless that exact include is used once and only in the staging vhost. PHP 8.4 FPM/socket remains shared with other vhosts and must not be repointed.
The protected controller must not be used for these preparation steps until its reviewed PHP 8.4 dispatcher path and consumer pinning have passed Gate 1. No staging package or configuration operation was performed for this PR.
## Protected cutover

After the prerequisites, backup, idle/drain plan, exact-SHA CI, and exclusive staging window have separate Owner approval, execute the existing approved protected controller as `deploy` from `/var/www/wpcaregrid`:

```bash
scripts/deploy-staging.sh <exact-approved-primary-merge-sha> --php-runtime 8.5
```

The command still requires current branch-tip equality and exact successful push CI. It verifies the current and target PHP profiles, checks the selected runtime’s Composer platform requirements, creates the existing protected pre-deploy database backup, activates the Nginx gate and scheduler pause, and gracefully stops both workers before selecting PHP 8.5. It then rebuilds dependencies/caches under the selected interpreter, updates only the two fixed Supervisor programs, verifies their live `/proc/<pid>/exe` and command lines, checks the Laravel scheduler/health and HTTP smoke contract, and only then reopens traffic. The deployment receipt records the exact selected PHP executable and version.

Do not manually switch the selector, edit Supervisor, run Artisan, install packages, or invoke the root helper outside this protected workflow. A failure before verified recovery leaves the Nginx gate and scheduler pause in place for reviewed recovery. Never remove a gate or pause marker merely to retry.

## Rollback

Rollback is a full code-and-runtime rollback, not only a Git checkout. Use the separately Owner-approved baseline commit and the administrator-installed rollback authorization, with the same protected controller:

```bash
scripts/deploy-staging.sh --rollback <administrator-approved-baseline-sha> --php-runtime 8.4
```

The helper switches the staging selector to the dedicated 8.4 socket; workers and scheduler resolve the same 8.4 dispatcher profile. The 8.4 FPM service/socket must remain installed and active throughout cutover. The controller rebuilds the baseline Composer dependencies and Laravel caches using `/usr/bin/php8.4`, verifies both workers’ actual executable paths, confirms health, and reopens traffic only after all gates pass. Do not remove or downgrade the 8.4 security-fixed packages.

If target migrations may have changed data, automatic code/runtime rollback is refused and the gate stays active. Database restore or migration rollback requires a separate reviewed decision and verified backup; it is not part of runtime switching.

## Stop conditions and evidence

Stop and retain the gate if any binary, version, service, socket owner/mode, Nginx include scope, Supervisor command, worker executable, Composer platform requirement, cache build, scheduler heartbeat, health check, or HTTP smoke test differs from the fixed contract. Record only sanitized command classifications and the release receipt; never report `.env`, configuration cache contents, customer data, or secrets.

Required evidence before the Owner can approve cutover:

- Verified PPA signing fingerprint and signed repository metadata.
- PHP 8.5 CLI/FPM package versions and required extension compatibility.
- Read-only verification of the exact staging vhost, FPM pools/sockets, Supervisor commands, scheduler path, and absence of stale PHP 7.4 dependencies.
- Fresh staging backup created through the documented mechanism, integrity-checked, with recoverability evidence; backup-worker idle/drain procedure reviewed so active backup work is not interrupted.
- Exact target merge SHA and all required CI jobs green.
- Exclusive staging window, rollback SHA, operator, stop conditions, and post-cutover health checklist.

Until these are independently verified and approved, staging cutover is **NO-GO**. Production remains out of scope.

## Compatibility and release-gate matrix (reviewed 2026-10-28)

This is a CI/source snapshot, not host evidence. Recheck the primary branch, ruleset and exact-SHA push run immediately before any later cutover.

| Layer | Current automated coverage | Limit |
| --- | --- | --- |
| Laravel server | PHP 8.4 and 8.5, each against MySQL 8.4; full server test suite and that PHP lane's MySQL process-concurrency gate | No Laravel PHP 8.5 SQLite or MariaDB lane |
| Laravel SQLite regression | PHP 8.4 targeted SQLite regression gate | Does not establish PHP 8.5 SQLite compatibility |
| WordPress Connector | PHP 8.2–8.5 across WordPress 6.8/7.1 with MySQL 5.7, MySQL 8.0, MySQL 8.4 and MariaDB 10.11; the protected PHP 8.4 / WordPress 7.1 SQLite, MySQL 8.4 and MariaDB 10.11 contexts | Connector compatibility is separate from Laravel server runtime coverage |

The seven protected contexts at review time are Quality; Server PHP 8.4/MySQL; Server PHP 8.5/MySQL; Server SQLite/PHP 8.4; and Connector PHP 8.4/WordPress 7.1 SQLite, MySQL and MariaDB. Both server MySQL jobs include their own process-concurrency gate. Do not remove the PHP 8.4 rollback lane, change the workflow, or infer host readiness from these tests.

At this snapshot, the application declares PHP `^8.3`, Laravel `^13.17`, and Composer's lock platform is PHP `^8.3`; the resolved Laravel lock version is 13.33.0. These constraints admit PHP 8.5 but are not a substitute for runtime validation. Laravel 13's documented minimum is PHP 8.3 and its documented extension set includes Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, Session, Tokenizer and XML. The deployment contract additionally requires `intl`, `zip`, `pdo_mysql`, and `pcntl` for CLI queue workers. Validate the real PHP 8.5 CLI and FPM runtimes with the protected `composer check-platform-reqs --no-dev --lock` gate plus the application-specific extension checks; do not use `--ignore-platform-reqs`. The Composer command checks locked package requirements, so it does not replace checks for application-specific extensions.

## PHP security advisory preflight

The PHP project lists PHP 8.4.27 and 8.5.12 as the October 2026 releases fixing CVE-2026-109618 and CVE-2026-109619. The version alone does not prove that a distro package came from the expected signer; the Ondřej PHP PPA signer and cached InRelease signature must be independently verified before package use.

A separate PHP notice dated 2026-10-23 recommends setting `session.upload_progress.enabled=0` until a later regular bugfix release, for an unusual session-serialization configuration that can expose an RCE path. Before cutover, recheck the current PHP advisory and the effective setting for both CLI and FPM profiles, and verify the application does not switch `session.serialize_handler` at runtime before session start. If the notice still applies and the effective setting or application behavior is unknown, STOP; any configuration change needs its own Owner approval and verification. Do not expose a public phpinfo page.

## Time-bounded rehearsal and acceptance

Proposed window: one exclusive 60-minute staging window, with its calendar start approved by the Owner and Integration & QA. If the window starts without all gates green, or a required step cannot complete before expiry, retain the maintenance gate and stop for review. This proposal does not authorize a backup, package change, service restart or cutover.

1. **Before the window:** capture a same-day sanitized read-only host inventory; verify PPA key fingerprint and signed InRelease; record exact primary release SHA and all seven protected checks; verify PHP 8.5 CLI/FPM package versions, required extensions, FPM pool UID/GID, socket owner/mode, selector scope, Supervisor commands and live worker binaries; resolve the PHP 7.4 inventory; verify current PHP advisories and effective session settings.
2. **Backup and drain gate:** obtain a separate Owner GO for a fresh staging backup using the documented protected backup mechanism. Confirm the backup worker is idle and no backup transfer/job is active; never interrupt a running backup. Drain queue work gracefully under the controller's pause/lock procedure. Verify the backup manifest and integrity, confirm the recorded database recovery point, and obtain recoverability evidence from an approved isolated verification. If drain or integrity verification fails, do not switch PHP.
3. **Cutover:** only after the exact branch-tip / CI gate, host preparation and approvals pass, run the protected controller with the exact approved SHA and `--php-runtime 8.5`. Do not manually edit the Nginx selector, Supervisor, scheduler, alternatives or service state.
4. **Post-switch checks:** while the maintenance gate remains active, verify the selected FPM socket/pool and worker identity, run `composer check-platform-reqs --no-dev --lock` under PHP 8.5, verify both Supervisor workers' live executable and version, scheduler heartbeat/interpreter, Laravel system health, HTTPS smoke checks, and unchanged behavior for sibling vhosts using PHP 8.4. Compare against the recorded PHP 8.4 baseline (including its existing Connector and backup-storage warnings); any new error, 5xx, missing heartbeat, wrong executable/socket, extension mismatch or unexplained warning is STOP.
5. **Rollback:** use the controller's separately approved PHP 8.4 baseline SHA with `--rollback ... --php-runtime 8.4`. Keep PHP 8.4.27 CLI/FPM packages, shared FPM service/socket and worker/scheduler profile intact. Confirm PHP 8.4.27 is selected, both workers and scheduler resolve to the 8.4 profile, platform requirements and health pass, sibling vhosts are healthy, and only then clear the gate. Do not automatically roll back or restore database state; if the release applied migrations or changed data, keep the gate and obtain a separate database recovery decision.
6. **Integration & QA acceptance:** accept only after reviewing sanitized pre/post receipts, the exact SHA and CI, package-signature evidence, backup integrity/recoverability evidence, selected PHP versions for FPM/CLI/workers/scheduler, smoke/health results, and rollback readiness. Owner cutover approval remains a separate decision.

