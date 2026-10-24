# PHP Runtime Lifecycle and Security Policy

**Status:** PHP 8.5 server compatibility is under validation; staging adoption requires a separate approved release window.  
**Scope:** WP CareGrid Laravel server runtimes. Connector PHP compatibility is governed by its own WordPress CI matrix.

## Supported runtime contract

- The Laravel application declares PHP `^8.3` in `composer.json`. This is a dependency constraint, not proof that every PHP minor is operationally supported.
- The required server CI baseline remains PHP 8.4 across SQLite, MySQL, and MariaDB. The added PHP 8.5 server lanes run the same tests and become compatibility evidence only after all jobs pass on the exact reviewed commit.
- A PHP minor is approved for staging only after dependency/platform checks, the full server matrix, the MySQL process-concurrency gate, and a protected staging release all pass. Production requires its own decision and migration; staging evidence never authorizes production.
- Keep the active PHP-FPM, CLI, Composer, scheduler, queue-worker, and protected deployment-controller interpreters on a known, patched runtime. Record each independently; a CLI version alone does not identify the web runtime.

## Security patch policy

- Monitor official PHP security advisories and the operating-system package security tracker. Subscribe to the relevant vendor notices and review them when published; do not assume security updates arrive on a fixed weekly schedule.
- For every exposed PHP SAPI and worker, verify the installed package provenance and vendor patch status. Distribution backports may retain an older upstream version string, so compare the exact package build with the distribution advisory rather than relying only on `php -v`.
- Prioritize critical remotely exploitable issues immediately. Inventory CLI, FPM/mod_php/CGI, inactive installed versions, workers and scheduled jobs; patch every serving/long-lived runtime and restart/reload affected workers so they stop executing old code.
- Temporary configuration mitigations are not a replacement for a complete vendor patch unless the vendor explicitly says otherwise. Preserve sanitized advisory, package and restart evidence.
- If a supported runtime reaches end of security support within 12 months, create a dated upgrade plan. At 6 months, treat the upgrade as a release blocker for new production launch or runtime expansion unless the Owner records an exception and compensating controls.

## Staging security inventory — Owner and Integration & QA reports, 2026-10-24

The Owner reports the following read-only package inventory:

- PHP CLI: 8.4.27. Installed PHP 8.4 package build: `8.4.27-1+ubuntu24.04.1+deb.sury.org+1`, from `ppa.launchpadcontent.net/ondrej/php` for Ubuntu 24.04 Noble.
- PHP-FPM package: 8.4.27. PHP 8.5 CLI/FPM are not installed; APT offers PHP 8.5.12 from the same configured repository.
- CLI modules include PDO MySQL, mysqli, cURL, intl, mbstring, XML, ZIP, sodium, and OPcache. SQLite, GD, and bcmath do not appear in the CLI module inventory.
- PHP 8.4 SQLite is removed (`rc` package state).

Integration & QA separately reports a read-only staging preflight:

- `/usr/bin/php8.4` and the active `php8.4-fpm.service` use PHP 8.4.27. The service has been running since 2026-10-24 06:15 CEST; the sampled FPM status showed three idle workers, 1,605 requests, and zero slow requests.
- Nginx configuration includes both PHP 7.4 and PHP 8.4 sockets. The effective upstream for the staging vhost, whether PHP 7.4 is actively used, the running FPM executable identity, Supervisor workers, and scheduler interpreter remain unverified.
- `/up` returned HTTP 200; this does not identify which runtime serves the request.
- APT reports the installed PHP 8.4.27 build and PHP 8.5.12 candidate from the `ondrej/php` PPA. This report does not independently validate that repository's signing-key fingerprint.

PHP 8.4.27 is the upstream fixed release for CVE-2026-109618 and CVE-2026-109619. Its reported version is at the fixed upstream release level, but the PPA signature/provenance and every active PHP process have not been independently verified. Record the active-runtime patch state as **partially verified**, not fully closed.

The production `composer.json` and `composer.lock` do not declare GD, bcmath, or PDO SQLite as hard Composer platform requirements. The lock metadata mentions GD for Laravel's HTTP test image helper and optional image support; repository validation/test scripts and CI use SQLite, and historical M12 staging evidence omitted two SQLite-only restore tests while documenting CI/MySQL coverage. These facts suggest the absent modules are not baseline production requirements, but do not prove that no application-specific runtime path uses them. Before migration, confirm the actual database driver and inspect application/Connector code paths and scheduled workloads for GD, BCMath, or SQLite use; install only extensions shown to be required.

No PHP 8.5 installation, runtime switch, or service restart is authorized by these inventories. The cutover preflight remains: validate the PPA signing key against an independent publisher source; map the staging Nginx vhost to its actual FPM socket/pool; verify the active FPM executable; identify Supervisor and scheduler PHP executables/versions without exposing full command arguments; resolve the PHP 7.4 socket usage; confirm required extensions and Composer platform; test rollback to a patched runtime; and obtain protected integration approval plus an exclusive staging window.

## Staging-first runtime changes

1. Confirm the advisory, affected runtime packages, current staging release SHA, active deployment state and exact target package source.
2. Confirm a recent verified backup where application/data risk warrants it, sufficient disk, required extensions, compatible Composer platform, and a tested rollback to a still-patched runtime.
3. Coordinate an exclusive UTC staging window in the integration ledger. Do not overlap deployments, configuration changes or PHP-FPM/worker restarts.
4. Install only signed/trusted packages from the approved operating-system source. Preserve the prior patched runtime for rollback until acceptance completes.
5. Validate PHP-FPM configuration before cutover. Switch only the intended staging site and synchronize the CLI, Composer, Artisan, queue and scheduler paths; do not make unrelated hosted applications change versions.
6. Verify actual web PHP version through an authenticated non-public diagnostic, FPM service/pool and socket, required extensions, worker and scheduler interpreter, Laravel bootstrap, database/queue operation, `/up`, protected paths, logs and application data invariants.
7. Capture the approved controller receipt, package provenance, pre/post versions, UTC window, health results and rollback status. Release the staging reservation only after the integration owner records completion.

No runtime migration in this policy authorizes production access, a production change, live customer billing, destructive restore, or bypass of PR/CI/deployment protections.

## Rollback and incident response

- Roll back promptly on a fatal/deprecation regression affecting operation, failed health checks, worker/scheduler mismatch, or integrity warning. Revert FPM, CLI, worker and scheduler configuration using the documented protected procedure.
- Roll back only to a version with the applicable security fix. Never return to a known-vulnerable build to restore availability.
- Do not restore the application database as part of a runtime rollback. Preserve sanitized evidence, verify app and worker health, and open a compatibility fix before another attempt.
- Treat suspected exploitation as an incident: preserve evidence, isolate affected runtimes under the incident plan, rotate credentials only through the established owner-controlled procedure, and assess process-readable data and neighboring sites.

## Evidence and ownership

For each runtime upgrade, record the source advisory, package versions/builds, PHP/FPM/CLI/worker/scheduler versions, exact application SHA, PR-head and merge-SHA CI, MySQL concurrency result, UTC window, pre/post health, rollback evidence, and deployment receipt in the integration ledger. Unknown values remain unknown; CI is not evidence of staging runtime state.

The integration owner arbitrates CI workflow, Composer lockfile, and deployment-controller edits. Runtime changes are serialized and use the protected PR, exact-SHA CI, protected merge, merge-SHA CI and staging controller. Production requires an independent, separately approved release plan.
