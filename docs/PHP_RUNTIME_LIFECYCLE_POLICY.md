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

- PHP CLI and installed PHP 8.4 packages: `8.4.27-1+ubuntu24.04.1+deb.sury.org+1`; PHP-FPM 8.4.27 is installed.
- MySQL, cURL, intl, mbstring, OPcache, XML, and ZIP extensions are installed at PHP 8.4.27.
- PHP 8.5 is not installed. PHP 8.4 SQLite is removed (`rc` package state).

Integration & QA separately reports a read-only staging preflight:

- CLI binary `/usr/bin/php8.4` and running `php8.4-fpm` unit are PHP 8.4.27; FPM process count is four.
- Nginx configuration includes both PHP 7.4 and 8.4 sockets. The effective upstream for the staging vhost, and whether PHP 7.4 is actively used, remain unverified.
- `/up` returned HTTP 200; this does not identify the runtime serving the route.
- APT reports PHP 8.4.27 installed and PHP 8.5.12 as a candidate from the `ondrej/php` PPA; PHP 8.5 is not installed.

PHP 8.4.27 is the upstream fixed release for CVE-2026-109618 and CVE-2026-109619. The package version identifies a deb.sury.org build, but the repository signing-key fingerprint and package provenance have not yet been independently validated. Active vhost-to-FPM routing, queue-worker PHP executable/version, scheduler PHP executable/version, and possible PHP 7.4 use also remain unknown. Record the overall active-runtime patch state as **partially verified**, not fully closed. Do not infer PHP 8.5 readiness from the candidate package or the HTTP health check.

No PHP 8.5 installation, runtime switch, or service restart is authorized by these inventories. Continue to require verified package signing provenance, effective vhost/pool mapping, worker and scheduler runtime checks, package/extension compatibility, a tested rollback, protected integration, and an exclusive staging window before any runtime change.

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
