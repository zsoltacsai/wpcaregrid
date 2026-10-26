# Staging PHP Runtime Migration Procedure

**Status:** tooling proposal; staging PHP 8.5 cutover remains NO-GO until every Owner precondition is verified and separately approved. This document does not authorize package, service, backup, or runtime changes.

## Scope and invariants

The migration uses only the fixed profiles `/usr/bin/php8.4` and `/usr/bin/php8.5`. It does not use `/usr/bin/php`, PATH lookup, or `update-alternatives` for application PHP commands. PHP 8.4 remains installed and its shared FPM service and socket remain available to sibling vhosts. The Nginx selector is scoped to the single `staging.wpcaregrid.com` vhost; the helper refuses a selector referenced by any other vhost. Queue and scheduler commands use the root-owned `/usr/local/bin/wpcaregrid-php` dispatcher, which accepts only the application Artisan entry point and resolves the same profile as the Nginx selector.

The existing exact-SHA CI verifier, branch-tip check, deployment lock, protected DB backup, maintenance gate, scheduler lock/pause, queue drain, application health checks, and fail-closed recovery remain required. The profile switch is attempted only after the gate and scheduler pause are active and both queue workers are stopped. The helper rejects arbitrary profile names, paths, commands, Nginx changes, and Supervisor commands.

## Required host preparation (separate Owner approval)

Before the protected controller can use runtime profiles, an administrator must prepare and verify the following on staging. Do not perform these steps from the development workstation or through the normal deploy controller until separately approved:

1. Verify the package signer and cached repository metadata; confirm PHP 8.5 package provenance, required extensions, and CLI/FPM package versions. The PPA signer/InRelease evidence is currently UNKNOWN.
2. Install PHP 8.5 side-by-side with patched PHP 8.4 only after package provenance is verified. Do not change alternatives or remove PHP 8.4.
3. Confirm `/usr/bin/php8.4`, `/usr/bin/php8.5`, both FPM services, and sockets `/run/php/php8.4-fpm.sock` and `/run/php/php8.5-fpm.sock`. The effective `[www]` pool in each PHP-FPM configuration must run as `user = www-data` and `group = www-data`; the protected preflight validates these worker identities from `php-fpm -tt` output. Both sockets must independently be `www-data:www-data` mode `0660`; socket ownership does not prove the PHP worker UID/GID. Both FPM services remain active. The 8.4 pool/socket is shared by other vhosts and must not be repointed.
4. Install root-owned mode-0644 snippets with exactly one directive each:
   - `wpcaregrid-staging-php84.conf`: `fastcgi_pass unix:/run/php/php8.4-fpm.sock;`
   - `wpcaregrid-staging-php85.conf`: `fastcgi_pass unix:/run/php/php8.5-fpm.sock;`
   Set `wpcaregrid-staging-runtime.conf` to a root-owned symlink to the 8.4 profile initially. Change only the staging vhost to include this selector at its PHP FastCGI location. Do not change other vhosts. The helper verifies Nginx’s expanded config and fails unless that exact include is used once and only in the staging vhost.
5. Confirm `/etc/supervisor/conf.d/wpcaregrid.conf` is root-owned mode 0644 with no named/default ACLs and contains the two documented worker commands. The protected helper converts only those exact commands to `/usr/local/bin/wpcaregrid-php` while the gate is active and workers are stopped. Any differing configuration is a stop condition.
6. Confirm the `www-data` crontab remains the fixed root-installed scheduler wrapper entry. The helper installs the hash-pinned wrapper from the exact deployed commit; it calls the same runtime dispatcher.
7. Keep the existing deploy-only sudo policy. The root helper must be installed from the reviewed merge commit, with its reviewed source hash. No general sudo, root SSH, package-manager access, or direct service command is added for `deploy`.

The current source review cannot prove the live host satisfies these prerequisites. No staging package or configuration operation was performed for this PR.

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
