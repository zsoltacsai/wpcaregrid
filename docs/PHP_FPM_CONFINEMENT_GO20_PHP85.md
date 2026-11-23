# GO-20 — PHP-FPM confinement 1.0.7: pure PHP 8.5 runtime and Invariant I peer isolation

Status: **source preparation only.** This document and `deploy/php-fpm-confinement/1.0.7/` do not authorize any host change. Nothing is loaded, installed, restarted or reloaded by the repository. Every host step needs its own exact-scope Owner GO after merge, independent QA and AppSec review, and a fresh read-only inventory, exactly as in `docs/PHP_FPM_CONFINEMENT_GO13.md`.

## Owner decision

1. **Production runtime is PHP 8.5** in a dedicated production FPM service (`wpcaregrid-production-php8.5-fpm.service`).
2. **The future separate staging VPS will also run PHP 8.5.**
3. **PHP 8.4 is not installed on either host** (neither production nor staging VPS). The legacy staging service `php8.4-fpm.service` is not installed and is obsolete. Stale assumptions regarding a co-located `staging-legacy` PHP 8.4 runtime (`php8.4-fpm.service`) are superseded.
4. **`stg1.wpcaregrid.com` is a production-preparation host, NOT a staging VPS.**
5. **Role of `staging-next`:** On `stg1.wpcaregrid.com`, `php8.5-fpm.service` is an existing system-installed PHP 8.5 service running as `www-data` (UID 33). Under **Invariant I** (peer isolation), every process sharing the `www-data` UID on the host must be enforce-attached before production secrets can exist. `staging-next` in Confinement 1.0.7 represents this co-located host peer service on `stg1.wpcaregrid.com`, strictly required to fulfill Invariant I, and is completely distinct from the future separate staging VPS.
6. **Defense-in-depth retention of PHP 8.4 denials:** Although PHP 8.4 is not installed, negative denial rules (`audit deny` for `/run/php/php8.4-fpm.{sock,pid}`, `/usr/bin/php8.4`, `/usr/sbin/php-fpm8.4`, `/etc/php/8.4/` and cross-role signal denials) and negative test assertions are deliberately retained in Confinement 1.0.7 as defense-in-depth protections. If any unexpected PHP 8.4 package or artifact ever appears on the host, it is denied live and fail-closed.
7. There is **no new Linux user, group or FPM UID**: production keeps `www-data` 33/33 under the confinement model, with the same invariant as in GO-13 (Invariant I: no production group-readable secret and no production route until every same-UID peer is enforce-attached and live-accepted).

## What 1.0.7 is

`deploy/php-fpm-confinement/1.0.7/` is the pure PHP 8.5 successor. It decouples the obsolete `staging-legacy` PHP 8.4 role, reducing service roles from seven to five: `production`, `staging-next`, `nginx`, `jobs-supervisor`, `jobs-scheduler`.

| Environment / Role | Unit (cgroup) | AppArmor profile | FPM binary | Master config / pool directory | Socket |
| --- | --- | --- | --- | --- | --- |
| production | `wpcaregrid-production-php8.5-fpm.service` | `wpcaregrid-php-fpm-production` | `/usr/sbin/php-fpm8.5` | `/etc/php/8.5/fpm-wpcaregrid-production/` | `/run/php/wpcaregrid-production-php8.5-fpm.sock` |
| staging-next (co-located peer on stg1) | `php8.5-fpm.service` (packaged) | `wpcaregrid-php-fpm-staging-next` | `/usr/sbin/php-fpm8.5` | `/etc/php/8.5/fpm/` | `/run/php/php8.5-fpm.sock` |

Artifacts in `deploy/php-fpm-confinement/1.0.7/`:
- **14 installed files** (`installed_files` in `binding.json`): 5 AppArmor profiles, 1 abstraction (`abstractions/wpcaregrid-php-fpm-common`), 4 systemd units/drop-ins, 2 FPM configurations, 2 CLI profiles.
- **5 service roles**: `production`, `staging-next`, `nginx`, `jobs-supervisor`, `jobs-scheduler`.
- **2 transient CLI roles**: `production-deploy-cli`, `production-build-cli` (both `/usr/bin/php8.5` under root-console session scope).

## Class identity: the executable no longer distinguishes environments

With production and staging-next both running `/usr/sbin/php-fpm8.5`, nothing may infer an environment from an executable, `argv0`, `comm` or PHP version. Every class distinction comes from the **(label, unit)** pair — the AppArmor label `<profile> (enforce)` plus the exact systemd unit/cgroup name — and from pool/socket paths.

* **Process classes** (`verify-acceptance.py`): a service process maps to a role only if its label belongs to the role's profile **and** its cgroup unit equals the role's unit. A production-labelled process in `php8.5-fpm.service`, a staging-next-labelled process in the production unit, swapped labels, `unconfined`/`complain` labels and an unconfined `php-fpm8.5` anywhere are all refused. The transient production CLI classes require label, `/usr/bin/php8.5` exe/argv0 **and** the root-console session scope with no unit; `php8.5` run by a staging job, by an unconfined shell, or with legacy identity (`php8.4`) is not a production class.
* **Descriptors:** `/run/php` is allowed generically, so exact per-role pool socket and pid paths (and the production pool directory) are attributed to their own role: a production worker holding the staging-next socket or pid, staging-next holding the production socket or configuration, and the production CLI classes holding any pool socket are refused.
* **Matrix self-check:** the verifier refuses any matrix in which two roles share a unit, pool socket, pool directory or FPM profile, or that lacks `role_runtime` (`FAIL identity:`).
* **Rollback:** stop by unit name only. Never `kill`, `pkill` or `killall` by process name or binary (`php-fpm8.5`): that would hit both pools.

## Frozen packages

Packages are immutable: **1.0.0–1.0.6 are frozen** and unchanged by this release. The test suite pins each by git tree object id (a tree id commits to file names, modes and blob ids) and re-derives it from its historical commit when available:

| Package | Git tree id (`git rev-parse HEAD:deploy/php-fpm-confinement/<version>`) |
| --- | --- |
| 1.0.0 (withdrawn in 1.0.1, absent from the tree; historical tree at `cb3e014`) | `0a56a9a6498c1b000635cef18648d41d3407ec71` |
| 1.0.1 | `202e548782e0071d55fd9efc369b790e556ef43c` |
| 1.0.2 | `90502b788a660347c52a83ccbe2c99c965fba2ef` |
| 1.0.3 | `dcf18aa07cbdb8bcddde8f2a0527a13701ab0316` |
| 1.0.4 | `71dc6c8150a0a8f0658dd0a3a6fb62e0baf41912` |
| 1.0.5 | `e8e59b769ed6c6acd85a3bf259364bace5ec374b` |
| 1.0.6 | `6d239b64d5637971bf28ec7a2de6d7a5686804df` |
| **1.0.7** (this release) | `56969ab240920ebb193c395eb1bb65be1260edb6` |

Pin for helper 2.1.6: `SHA256SUMS` of 1.0.7 has SHA-256 `089fbf681c7bbecd27c61a6eedbcab3ef36940f61f3a06629d9add4d7842233c`, git tree `56969ab240920ebb193c395eb1bb65be1260edb6`, binding digest `64a5ed015323fd576ec679169163591fdcb6483eef9cc1d7f7358a15fab9a6a7`.

**Helper binding:** package 1.0.5 is bound by helper 2.1.4. Package 1.0.6 was prepared for helper 2.1.5 (not bound). Package 1.0.7 is bound by helper 2.1.6 (`scripts/deployment_environment_contract_2_1_6.py`, `scripts/wpcaregrid-deploy-production-priv-2_1_6.py`). Production helper 2.1.4 and `scripts/deployment_environment_contract_2_1_4.py` are untouched and stay bound to package 1.0.5 (PHP 8.4 production). Until activated, 1.0.7 must not be installed on a host that runs helper 2.1.4.

## Installation deltas against GO-13

Everything in GO-13 (preconditions, Stages 0–8, live acceptance, rollback) applies unchanged **with 1.0.7 as the package** and these substitutions:

* Stage 0 package/harness directories use `1.0.7` (`/usr/local/libexec/wpcaregrid-php-fpm-confinement/1.0.7/`, `/usr/local/libexec/wpcaregrid-php-fpm-harness/1.0.7/`).
* Stage 2 (staging-next): the production configuration directory does not exist yet; the new `InaccessiblePaths=-/etc/php/8.5/fpm-wpcaregrid-production` in the drop-in is harmless until it does.
* **`-` InaccessiblePaths resolve only at unit start.** The `-/etc/php/8.5/fpm-wpcaregrid-production` entry (staging-next `php8.5-fpm.service` drop-in) is skipped while the directory is absent and is not re-evaluated afterwards. After Stage 5 creates the production configuration directory, `php8.5-fpm.service` must be restarted **inside the staging window**, and the nsenter acceptance check re-run, before the entry counts as effective. The AppArmor deny applies regardless; the optional `production-fpm-config-read` checks stay UNPROVEN until the directory exists.
* **Accepted residual (finding 5):** production and staging-next share `/etc/php/8.5/fpm/php.ini` and `conf.d/`. A staging-next process could read them, and a change there affects both pools. Accepted; optional hardening is `php_admin_value[...]` settings in the pool configuration or a dedicated production ini via `-c` (own file, not the shared one), each needing its own review.
* Stage 5: the unit is `wpcaregrid-production-php8.5-fpm.service`, the configuration is installed to `/etc/php/8.5/fpm-wpcaregrid-production/{php-fpm.conf,pool.d/wpcaregrid-production.conf}`, and the local FastCGI exercise targets `/run/php/wpcaregrid-production-php8.5-fpm.sock`. The production CLI helper commands run `/usr/bin/php8.5` under `wpcaregrid-php-cli-production-deploy` / `-build`.
* The post-phase acceptance is run for the 5 service profiles and 2 transient CLI profiles; the live checks include `exec-php84` (deny) and the optional pool-configuration denies (a baseline that cannot reach the optional target is reported **UNPROVEN**, never PASS).
* R1: `systemctl stop wpcaregrid-production-php8.5-fpm.service`.
* **Package upgrades:** the distribution's `php8.5-fpm` postinst restarts only `php8.5-fpm.service`. The production unit keeps running the replaced binary until it is restarted deliberately inside its own window; never restart it from a package script.
* `/etc/php/8.5/fpm/php.ini` and `conf.d/` are shared by production and staging-next. A change there affects both pools; treat it as a production change.

## Compile-only AppArmor parser check

No kernel load, no cache, no root required:

```bash
apparmor_parser -Q -K -I <dir> <profile>
# e.g. from the repository root, one profile:
apparmor_parser -Q -K -I deploy/php-fpm-confinement/1.0.7/apparmor deploy/php-fpm-confinement/1.0.7/apparmor/wpcaregrid-php-fpm-production
# or every profile of the package:
pkg=deploy/php-fpm-confinement/1.0.7
for profile in "$pkg"/apparmor/wpcaregrid-*; do
  apparmor_parser -Q -K -I "$pkg/apparmor" "$profile"
done
```

`-Q` skips the kernel load, `-K` skips the cache, `-I <dir>` resolves `#include <abstractions/wpcaregrid-php-fpm-common>`. A local run without `apparmor_parser` proves nothing about syntax. CI covers 1.0.7 twice: the real-parser class in `scripts/tests/php-fpm-confinement-test.py` (it compiles every profile and every derived probe profile of the current package, with `PHP_FPM_CONFINEMENT_REQUIRE_PARSER=1`) and the explicit compile-only step above in `.github/workflows/ci.yml`.

## Unchanged by this release

* The MySQL unix socket contract: the deploy CLI may connect one unix STREAM socket, `/run/mysqld/wpcaregrid-production.sock`, and nothing else; no TCP. Cache/storage writes stay with the separate build profile, which has no network or socket permission.
* **Phase B stays disabled** and is not authorized (`commands_disabled: phase-b-migration-runner` in both CLI profiles).
* `SECURITY_REMEDIATION_ENABLED=false`, `ADMIN_ACCESS_OPERATOR_FULL_ADMIN=false`, no live restore, production job units fenced, `permitted_group33_holders` empty, no www-data cron.

## Not proven by source tests

As in GO-13: kernel enforcement, the content of the external abstractions, descriptor behavior across `execve`, mount-namespace behavior, and that PHP 8.5 FPM and CLI actually run under these profiles are shown only by complain-mode soak and live acceptance on the host. Host assumptions specific to this release: that the host has `/usr/sbin/php-fpm8.5`, `/usr/bin/php8.5` and `/etc/php/8.5/{fpm,cli,mods-available}`; that FPM needs only the shared `php.ini` and `conf.d/` from the packaged FPM directory when started with `--fpm-config` (confirm in complain mode; any other read is a missing rule or a STOP, not a reason to re-grant `pool.d/`); and the extension directory under `/usr/lib/php/`.
