# GO-20 — PHP-FPM confinement 1.0.6: production on PHP 8.5

Status: **source preparation only.** This document and `deploy/php-fpm-confinement/1.0.6/` do not authorize any host change. Nothing is loaded, installed, restarted or reloaded by the repository, and no helper binds this package yet. Every host step needs its own exact-scope Owner GO after merge, independent QA and AppSec review, and a fresh read-only inventory, exactly as in `docs/PHP_FPM_CONFINEMENT_GO13.md`.

## Owner decision

WP CareGrid **production runs PHP 8.5** in a dedicated production FPM service. Legacy staging stays on PHP 8.4 (`php8.4-fpm.service`); staging-next stays on PHP 8.5 (`php8.5-fpm.service`). PHP 8.4 is neither removed nor upgraded. There is **no new Linux user, group or FPM UID**: production keeps `www-data` 33/33 under the confinement model, with the same invariant as in GO-13 (Invariant I: no production group-readable secret and no production route until every same-UID peer is enforce-attached and live-accepted).

## What 1.0.6 is

`deploy/php-fpm-confinement/1.0.6/` is the narrowest successor of 1.0.5. It is a copy of 1.0.5 with only the files listed below changed; every other file is byte-identical apart from the version in its header comment (the test suite enforces the exact set). Changed bytes always ship as a new version directory; nothing in 1.0.0–1.0.5 was modified.

| Environment | Unit (cgroup) | AppArmor profile | FPM binary | Master config / pool directory | Socket |
| --- | --- | --- | --- | --- | --- |
| production | `wpcaregrid-production-php8.5-fpm.service` | `wpcaregrid-php-fpm-production` | `/usr/sbin/php-fpm8.5` | `/etc/php/8.5/fpm-wpcaregrid-production/` | `/run/php/wpcaregrid-production-php8.5-fpm.sock` |
| staging-next | `php8.5-fpm.service` (packaged) | `wpcaregrid-php-fpm-staging-next` | `/usr/sbin/php-fpm8.5` | `/etc/php/8.5/fpm/` | `/run/php/php8.5-fpm.sock` |
| staging-legacy | `php8.4-fpm.service` (packaged) | `wpcaregrid-php-fpm-staging-legacy` | `/usr/sbin/php-fpm8.4` | `/etc/php/8.4/fpm/` | `/run/php/php8.4-fpm.sock` |

Changed files (all under `deploy/php-fpm-confinement/1.0.6/`):

| File | Change |
| --- | --- |
| `systemd/wpcaregrid-production-php8.5-fpm.service` (replaces `…php8.4-fpm.service`) | Unit renamed; `php-fpm8.5`, `/etc/php/8.5/fpm-wpcaregrid-production/`; `InaccessiblePaths` also hides `/etc/php/8.5/fpm/pool.d` (the staging-next pool configuration). No `Alias=`, `Conflicts=`, ordering or dependency on the packaged `php8.5-fpm.service`. |
| `php-fpm/production/php-fpm.conf`, `pool.d/wpcaregrid-production.conf` | Installed under `/etc/php/8.5/fpm-wpcaregrid-production/`; pool socket `/run/php/wpcaregrid-production-php8.5-fpm.sock`. No `pid =`, so no pid file can collide with `/run/php/php8.5-fpm.pid`. |
| `apparmor/wpcaregrid-php-fpm-production` | Executable `/usr/sbin/php-fpm8.5`, own pool directory and socket. The packaged FPM directory is **no longer granted wholesale**: only `/etc/php/8.5/fpm/php.ini` and `conf.d/` (shared PHP configuration) are readable; `php-fpm.conf` and `pool.d/` (staging-next pool settings, possibly `env[]`) are audited denies. Also denies `/var/log/php[0-9].[0-9]-fpm.log`. Bound only to the production unit by name (no exec-path attachment). |
| `apparmor/wpcaregrid-php-fpm-staging-next` | One added audited deny: `/etc/php/8.5/fpm-wpcaregrid-production/`. |
| `systemd/php8.5-fpm.service.d/90-wpcaregrid-confinement.conf` | `InaccessiblePaths` also hides `/etc/php/8.5/fpm-wpcaregrid-production`. No other directive touched. |
| `apparmor/wpcaregrid-php-cli-production-deploy`, `…-build` | Interpreter `/usr/bin/php8.5` and `/etc/php/8.5/{cli,mods-available}`; the other-PHP deny glob is now `php8.[0-46-9]` (PHP 8.4 and everything but 8.5 stay denied; the 1.0.5 glob `php8.[0-35-9]` would have denied 8.5). Network and socket rules unchanged. |
| `apparmor/wpcaregrid-nginx` | The production upstream socket is `/run/php/wpcaregrid-production-php8.5-fpm.sock`. |
| `acceptance/matrix.json` | Production role unit/socket/CLI exe `php8.5`; new `role_runtime` section; `exec-php84` (deny, live) replaces `exec-php85` and `exec-own-php85` (allow, model-only) is added for both CLI profiles; production FPM gains `exec-php84-cli` and `staging-next-pool-config-read` (optional deny), staging-next gains `production-fpm-config-read` (optional deny). All matrix cross-environment checks (files, secrets, the other environments' sockets, `/proc`, ptrace, signals, unexpected unix/network sockets, abstract sockets, descriptor transfer) are carried over unchanged. |
| `acceptance/verify-acceptance.py` | Fail-closed identity rules, see below. |
| `binding.json` | Same 17 installed paths; production unit/config paths renamed; `aa_exec_profiles` exe/argv0 `php8.5`; new `rules.class_identity`, `rules.php_runtime`, `rules.role_runtime`. |

`acceptance/collect-peers.py`, `probe.py`, `derive-probe-profile.py`, `fixtures.py`, `fence-production.py`, `rollback-order.py`, `fd-sender.py` and `sibling-listener.py` are byte-identical to 1.0.5: none of them derives a class from an executable or PHP version (the collector records `exe` and takes the unit from the cgroup path by exact unit name; the rollback order is unit-name independent).

## Class identity: the executable no longer distinguishes environments

With production and staging-next both running `/usr/sbin/php-fpm8.5`, nothing may infer an environment from an executable, `argv0`, `comm` or PHP version. Every class distinction comes from the **(label, unit)** pair — the AppArmor label `<profile> (enforce)` plus the exact systemd unit/cgroup name — and from pool/socket paths.

* **Process classes** (`verify-acceptance.py`): a service process maps to a role only if its label belongs to the role's profile **and** its cgroup unit equals the role's unit. A production-labelled process in `php8.5-fpm.service`, a staging-next-labelled process in the production unit, swapped labels, `unconfined`/`complain` labels and an unconfined `php-fpm8.5` anywhere are all refused. The transient production CLI classes require label, `/usr/bin/php8.5` exe/argv0 **and** the root-console session scope with no unit; `php8.5` run by a staging job, by an unconfined shell, or with the 1.0.5 identity (`php8.4`) is not a production class.
* **Descriptors:** `/run/php` is allowed generically, so exact per-role pool socket and pid paths (and the production pool directory) are attributed to their own role: a production worker holding the staging-next socket or pid, staging-next holding the production socket or configuration, and the production CLI classes holding any pool socket are refused.
* **Matrix self-check:** the verifier refuses any matrix in which two roles share a unit, pool socket, pool directory or FPM profile, or that lacks `role_runtime` (`FAIL identity:`).
* **Rollback:** stop by unit name only. Never `kill`, `pkill` or `killall` by process name or binary (`php-fpm8.5`): that would hit both pools.

## Frozen packages

Packages are immutable: **1.0.0–1.0.5 are frozen** and unchanged by this release. The test suite pins each by git tree object id (a tree id commits to file names, modes and blob ids) and re-derives it from its historical commit when available:

| Package | Git tree id (`git rev-parse HEAD:deploy/php-fpm-confinement/<version>`) |
| --- | --- |
| 1.0.0 (withdrawn in 1.0.1, absent from the tree; historical tree at `cb3e014`) | `0a56a9a6498c1b000635cef18648d41d3407ec71` |
| 1.0.1 | `202e548782e0071d55fd9efc369b790e556ef43c` |
| 1.0.2 | `90502b788a660347c52a83ccbe2c99c965fba2ef` |
| 1.0.3 | `dcf18aa07cbdb8bcddde8f2a0527a13701ab0316` |
| 1.0.4 | `71dc6c8150a0a8f0658dd0a3a6fb62e0baf41912` |
| 1.0.5 | `e8e59b769ed6c6acd85a3bf259364bace5ec374b` |
| **1.0.6** (this release) | `6d239b64d5637971bf28ec7a2de6d7a5686804df` |

Pin for the helper successor: `SHA256SUMS` of 1.0.6 has SHA-256 `bf6e734ef0489c34006862c706a6d0fc59d62b8c18b00bbe04a276512c0a5e18`.

**Not bound by any helper:** package 1.0.6 is not bound by any helper yet. Production helper 2.1.4 and `scripts/deployment_environment_contract_2_1_4.py` are untouched and stay bound to package 1.0.5 (PHP 8.4 production). A separate helper 2.1.5 successor will bind 1.0.6 later; until then 1.0.6 must not be installed on a host that runs helper 2.1.4, because the helper checks unit names, profile hashes and the PHP 8.4 interpreter of 1.0.5.

## Installation deltas against GO-13

Everything in GO-13 (preconditions, Stages 0–8, live acceptance, rollback) applies unchanged **with 1.0.6 as the package** and these substitutions:

* Stage 0 package/harness directories use `1.0.6` (`/usr/local/libexec/wpcaregrid-php-fpm-confinement/1.0.6/`, `/usr/local/libexec/wpcaregrid-php-fpm-harness/1.0.6/`).
* Stage 2 (staging-next): the production configuration directory does not exist yet; the new `InaccessiblePaths=-/etc/php/8.5/fpm-wpcaregrid-production` in the drop-in is harmless until it does.
* **`-` InaccessiblePaths resolve only at unit start.** The `-/etc/php/8.5/fpm-wpcaregrid-production` entries (staging-next `php8.5-fpm.service` and legacy `php8.4-fpm.service` drop-ins) are skipped while the directory is absent and are not re-evaluated afterwards. After Stage 5 creates the production configuration directory, `php8.5-fpm.service` (and `php8.4-fpm.service`) must be restarted **inside the staging window**, and the nsenter acceptance check re-run, before the entry counts as effective. The AppArmor deny applies regardless; the optional `production-fpm-config-read` checks stay UNPROVEN until the directory exists.
* **Accepted residual (finding 5):** production and staging-next share `/etc/php/8.5/fpm/php.ini` and `conf.d/`. A staging-next process could read them, and a change there affects both pools. Accepted; optional hardening is `php_admin_value[...]` settings in the pool configuration or a dedicated production ini via `-c` (own file, not the shared one), each needing its own review.
* Stage 5: the unit is `wpcaregrid-production-php8.5-fpm.service`, the configuration is installed to `/etc/php/8.5/fpm-wpcaregrid-production/{php-fpm.conf,pool.d/wpcaregrid-production.conf}`, and the local FastCGI exercise targets `/run/php/wpcaregrid-production-php8.5-fpm.sock`. The production CLI helper commands run `/usr/bin/php8.5` under `wpcaregrid-php-cli-production-deploy` / `-build`.
* The post-phase acceptance is run for all seven profiles as before; the new live checks are `exec-php84`, `exec-php84-cli` and the optional pool-configuration denies (a baseline that cannot reach the optional target is reported **UNPROVEN**, never PASS).
* R1: `systemctl stop wpcaregrid-production-php8.5-fpm.service`.
* **Package upgrades:** the distribution's `php8.5-fpm` postinst restarts only `php8.5-fpm.service`. The production unit keeps running the replaced binary until it is restarted deliberately inside its own window; never restart it from a package script.
* `/etc/php/8.5/fpm/php.ini` and `conf.d/` are shared by production and staging-next. A change there affects both pools; treat it as a production change.

## Compile-only AppArmor parser check

No kernel load, no cache, no root required:

```bash
apparmor_parser -Q -K -I <dir> <profile>
# e.g. from the repository root, one profile:
apparmor_parser -Q -K -I deploy/php-fpm-confinement/1.0.6/apparmor deploy/php-fpm-confinement/1.0.6/apparmor/wpcaregrid-php-fpm-production
# or every profile of the package:
pkg=deploy/php-fpm-confinement/1.0.6
for profile in "$pkg"/apparmor/wpcaregrid-*; do
  apparmor_parser -Q -K -I "$pkg/apparmor" "$profile"
done
```

`-Q` skips the kernel load, `-K` skips the cache, `-I <dir>` resolves `#include <abstractions/wpcaregrid-php-fpm-common>`. A local run without `apparmor_parser` proves nothing about syntax. CI covers 1.0.6 twice: the real-parser class in `scripts/tests/php-fpm-confinement-test.py` (it compiles every profile and every derived probe profile of the current package, with `PHP_FPM_CONFINEMENT_REQUIRE_PARSER=1`) and the explicit compile-only step above in `.github/workflows/ci.yml`.

## Unchanged by this release

* The MySQL unix socket contract: the deploy CLI may connect one unix STREAM socket, `/run/mysqld/wpcaregrid-production.sock`, and nothing else; no TCP. Cache/storage writes stay with the separate build profile, which has no network or socket permission.
* **Phase B stays disabled** and is not authorized (`commands_disabled: phase-b-migration-runner` in both CLI profiles).
* `SECURITY_REMEDIATION_ENABLED=false`, `ADMIN_ACCESS_OPERATOR_FULL_ADMIN=false`, no live restore, production job units fenced, `permitted_group33_holders` empty, no www-data cron.

## Not proven by source tests

As in GO-13: kernel enforcement, the content of the external abstractions, descriptor behavior across `execve`, mount-namespace behavior, and that PHP 8.5 FPM and CLI actually run under these profiles are shown only by complain-mode soak and live acceptance on the host. Host assumptions specific to this release: that the host has `/usr/sbin/php-fpm8.5`, `/usr/bin/php8.5` and `/etc/php/8.5/{fpm,cli,mods-available}` (staging-next already depends on them); that FPM needs only the shared `php.ini` and `conf.d/` from the packaged FPM directory when started with `--fpm-config` (confirm in complain mode; any other read is a missing rule or a STOP, not a reason to re-grant `pool.d/`); and the extension directory under `/usr/lib/php/`.
