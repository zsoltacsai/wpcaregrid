# Production helper 2.1.3 — GO-13 Workstream A (source only)

Status: **source only. Not installed, not enabled, not release-ready.** Nothing in this change
touches a host, sudoers, a service, a database or production. Every host step below needs its
own exact-scope Owner GO after merge and independent QA/AppSec review.

Helper 2.1.3 is an immutable successor of 2.1.2. The 2.1.2 bytes, the shared transaction module
(`scripts/deployment_transaction_v2.py`), the original environment contract
(`scripts/deployment_environment_contract.py`) and the V3 release-review policy
(`scripts/release_review_policy_v3.py`) are unchanged; `scripts/tests/production-helper-2-1-3-test.py`
pins their bytes.

## What changes in 2.1.3 (and only this)

| Area | 2.1.2 | 2.1.3 |
| --- | --- | --- |
| Privileged caller | `deploy-production` through a NOPASSWD sudoers file | Owner-controlled **root console**, direct invocation. No production sudoers rule exists. The entrypoint, controller and installer refuse any `SUDO_*`, `PKEXEC_UID` or `DOAS_USER` variable, any non-root real/effective UID/GID, and a non-isolated interpreter. |
| Transfer identity | `deploy-production` | the existing `deploy` account, **untrusted and transfer-only**. No new user, group or FPM UID. |
| Environment contract | `deployment_environment_contract.py` (schema 1, dedicated FPM UID) | `deployment_environment_contract_2_1_3.py` (policy schema 2, `www-data` 33/33 only with the exact PHP-FPM confinement binding and a passing schema-3 observation) |
| Release admission | V3 records, exact-head artifacts, latest protected-primary SHA, CI | **unchanged** (byte-identical functions) |
| Application PHP run by the helper | `runuser` as the FPM user | `runuser` as `www-data` **and** `aa-exec -p wpcaregrid-php-fpm-production` |

### `deploy` keeps its staging grant exactly

`deploy` keeps its existing staging-only grant for `/usr/local/sbin/wpcaregrid-deploy-priv`
(see `docs/STAGING_DEPLOY_PRIVILEGE.md`). 2.1.3 does not require `deploy` to have no sudo and
never edits sudoers. The installer instead verifies, before and after installation:

* `sudo -n -l -U deploy` lists only rules whose command is exactly
  `/usr/local/sbin/wpcaregrid-deploy-priv` (any other command, an `ALL` argument, or any
  production path is the conflict `deploy_sudo_rule_outside_staging_helper`), and the listing is
  identical after installation;
* the SHA-256 of `/etc/sudoers` and of every `/etc/sudoers.d` entry is identical after
  installation (`sudoers_changed_during_install`);
* no sudoers entry mentions a production helper path and no `wpcaregrid-production*` sudoers file
  exists (`production_sudoers_rule_present`);
* `deploy` cannot run the helper through sudo or directly (negative probes).

### Untrusted transfer inputs

Everything under `deploy` control is untrusted: the installer source directory
`/home/deploy/.private/wpcg-production-helper-2.1.3` and the incoming directory
`/var/lib/wpcaregrid-deploy/production/incoming`. Each is opened once as a directory descriptor
(`O_DIRECTORY|O_NOFOLLOW`) and verified by `lstat`/`fstat` (device, inode, owner, mode `0700`).
Every file is opened relative to that descriptor with `O_NOFOLLOW|O_NONBLOCK` and must be a
regular, single-link, `deploy`-owned `0600` file whose device, inode, owner, mode and size match
between `lstat` and `fstat`; its exact bytes are hashed while being copied, the read must not see
any size, `mtime` or `ctime` change, and the hash must equal the pin. Bytes are copied into a
root-owned private inbox (`/var/lib/wpcaregrid-deploy/production-helper-inbox/2.1.3` for the
installer, the profile `inbox` for release archives) and only the root-owned copy is used. No
deploy-owned file is executed, imported, linked or renamed by root.

### PHP-FPM confinement binding (Workstream B dependency)

`CONFINEMENT_PIN` in `scripts/deployment_environment_contract_2_1_3.py` is **unset** (`None`)
until the Workstream B package (`deploy/php-fpm-confinement/<version>/`) is corrected, reviewed
and approved. While it is unset:

* `resolve_environment` refuses every production policy with `confinement_package_not_approved`;
* the helper therefore refuses `preflight`, `verify-install`, `authorize`, `stage` and `deploy`;
* the installed policy stays `enabled=false`.

The pin is a single `ConfinementPin(package_version, sha256sums_sha256, file_sha256)` over the
fixed fourteen-path installed file set (`CONFINEMENT_FILES`). Paths, units, profile names and
peer classes follow the Workstream B package structure (branch `t3/go-13-php-fpm-confinement`
at `97b8a4b`, package 1.0.1); **no B hash is pinned**. **The last remaining source change** is
to set the pin to B's final approved package, then re-pin the helper (environment hash → artifact
digest → controller → installer → manifest → this runbook); the 2.1.3 test fails until every pin
matches.

When the pin is set, a schema-2 production policy must echo it in `php_fpm_confinement`
(`schema` 2, `package_version`, `package_sha256sums_sha256`, `www_data_crontab_sha256` (the exact
approved www-data crontab hash, or `null` for none), the exact `files` map and the six service
bindings: `production`, `staging-next`, `staging-legacy`, `nginx`, `jobs-supervisor`,
`jobs-scheduler`). Before `preflight`, `stage` and `deploy` the backend collects, as root and only
from fixed host interfaces, a schema-3 observation:

```text
{schema: 3, apparmor_enabled,
 production_jobs_fence: {
   units: {<scheduler_unit .timer>, <twin .service>: {load_state, active_state, unit_file_state}},
   supervisor: {config_complete, runtime_available, programs: [{name, state, autostart}]},
   markers: {worker_fence_marker, scheduler_fence_marker: {type, uid, gid, mode, nlink, sha256} | null}},
 files: {path: {sha256, uid, gid, mode, type, nlink, parents_trusted}},
 services: {role: {unit, load_state, active_state, main_pid, main_label,
                   apparmor_profile_property, loaded_profile_mode}},
 www_data_cron: {crontab_sha256, cron_d_entries, wrapper, profile_mode},
 same_uid_peers: {complete, incomplete_reasons,
                  processes: [{pid, starttime, uids, gids, ppid, state, comm, exe, argv0,
                               cmdline_sha256, cgroup, unit, label, fds}]}}
```

**Production jobs fence (checked first).** GO-13 keeps background SaaS processing disabled, so
production queue workers and the scheduler must be provably unable to run. The entrypoint runs
the whole confinement gate before **every** operation (`preflight`, `verify-install`,
`authorize`, `stage`, `deploy`), and the fence is the first check inside it; any missing element
refuses:

* the policy's `scheduler_unit` timer and its twin `.service` both `LoadState=masked`,
  `UnitFileState=masked`, `ActiveState=inactive` (`systemctl mask` links them to `/dev/null`, so no
  timer, dependency or `systemctl start` can run them);
* Supervisor: every production-scoped program — named in `worker_programs`, prefixed
  `wpcaregrid-production-` (including `group:` and `numprocs` `_NN` instances), or whose
  configuration mentions `/var/www/wpcaregrid-production` or `/var/lib/wpcaregrid-deploy/production`
  — is preferably absent; if defined it must be exactly `STOPPED` in `supervisorctl status` with
  `autostart=false` in its configuration (Supervisor's default `autostart` is true). If
  `supervisor.service` is inactive, a defined program must still have `autostart=false`. A program
  defined but not loaded, running without a readable definition, an unreadable/unparseable
  `/etc/supervisor` configuration, or an unavailable `supervisorctl` while Supervisor runs refuses;
* both fence markers (`worker_fence_marker`, `scheduler_fence_marker` from the policy) exist as
  regular, single-link, `root:root 0600` files with the exact Phase B content
  `schema=1;profile=production;workers=disabled` / `schema=1;profile=production;scheduler=paused`.

Refusal codes: `confinement_production_jobs_not_fenced`, `confinement_production_jobs_unverifiable`,
`confinement_production_fence_marker_invalid`.

**Same-UID peer inventory.** Every `/proc/<pid>` is read; a process is a peer when any of its
real, effective, saved or filesystem UID **or GID**, or any supplementary group, is 33, whatever
its command name (a `deploy` login in group `www-data` is a peer and holds preflight). For each
peer the collector records UIDs/GIDs/groups, start time, state, parent, `comm`, the kernel `exe` link,
`argv0` and a SHA-256 of the command line (never the raw arguments), the unified cgroup and the
systemd unit derived from it, the AppArmor label and every descriptor target. Start time is read
again at the end; a change is `peer_pid_reused`. A read or parse error counts as an exit only when
`/proc/<pid>` is proven absent; on a live PID (permission, parse or missing entry) or a reused PID
the inventory is incomplete and preflight holds. Zombies are not peers. PIDs that appear during
the scan are inventoried in up to three passes; continued churn is `peer_set_unstable` (hold).

`verify_confinement_observation` maps every peer to exactly one class or refuses:

| Class | Unit (from cgroup) | Label | Executables |
| --- | --- | --- | --- |
| `production` | `wpcaregrid-production-php8.4-fpm.service` | `wpcaregrid-php-fpm-production (enforce)` | `php-fpm8.4`, `kill` |
| `staging-next` | `php8.5-fpm.service` | `wpcaregrid-php-fpm-staging-next (enforce)` | `php-fpm8.5`, `kill`, `dash`, `php-fpm-socket-helper` |
| `staging-legacy` | `php8.4-fpm.service` | `wpcaregrid-php-fpm-staging-legacy (enforce)` | `php-fpm8.4` |
| `nginx` | `nginx.service` | `wpcaregrid-nginx (enforce)` | `nginx` |
| `jobs-supervisor`, `jobs-scheduler` | `supervisor.service`, `wpcaregrid-staging-next-scheduler.service` | `wpcaregrid-php-cli-staging (enforce)` | `php8.4`, `php8.5`, `dash`, `bash`, `flock`, `logger` |
| `jobs-cron` (pinned cron only) | `cron.service` | `wpcaregrid-php-cli-staging (enforce)` | as jobs |
| `cron-shim` (pinned cron only; the only unconfined class) | `cron.service` | `unconfined` | `/usr/sbin/cron`, `/usr/bin/dash` with `comm` `cron`/`CRON`/`sh`/`dash` |

Refusals: `confinement_same_uid_peer_unconfined` (any other unconfined peer, e.g. unconfined
nginx, PHP CLI, queue/scheduler worker, shell, renamed FPM or a GID-33-only process),
`confinement_process_not_confined` (complain/kill/other modes), `confinement_same_uid_peer_unknown`
(unknown unit and profile), `confinement_peer_label_outside_unit`,
`confinement_peer_executable_unexpected`, `confinement_peer_cgroup_unexpected`,
`confinement_peer_foreign_descriptor` (a staging/nginx/job peer holding a descriptor under
`/var/lib/wpcaregrid-deploy/production/`, or a production peer under a staging tree),
`confinement_peer_inventory_incomplete`, `confinement_worker_labels_missing` (a serving role with
no www-data worker), `confinement_master_not_confined` (an active unit's main process not in
its enforce label), and the cron codes `confinement_cron_unpinned`,
`confinement_cron_wrapper_unsafe`, `confinement_cron_wrapper_unconfined`. The cron classes are
allowed only when the www-data crontab hash equals the policy pin, no `/etc/crontab` or
`/etc/cron.d` line runs as www-data (unparseable lines count), the wrapper
`/usr/local/bin/wpcaregrid-scheduler` is a root-owned, non-writable, single-link file and the job
profile is loaded in enforce mode. Files, services (all six attached without a `-` fail-open
prefix and in `enforce`; serving roles active) are checked as before. A pass is recorded state
only; kernel enforcement remains the separate live acceptance gate in the Workstream B runbook.

Pending verification with B: the production profile must permit the PHP CLI
(`/usr/bin/php8.4`) that the helper runs through `/usr/bin/aa-exec -p
wpcaregrid-php-fpm-production`, and `aa-exec` must exist at that path. If not, `preflight`
holds (fail closed); widen nothing without a reviewed package version.

## Pinned artifacts

<!-- PINS:BEGIN -->
| Artifact | SHA-256 |
| --- | --- |
| `scripts/wpcaregrid-deploy-production-priv-2_1_3.py` | `357b0e5715ed86e144a55ac50e0291159116872b266a81f66c5aa3330bbe73c3` |
| `scripts/deployment_backend_v2_1_3.py` | `52a809d42bb2ead54252053ddd48ae8dcbca756132bcc8d29e863b22ac603b37` |
| `scripts/deployment_transaction_v2.py` (shared, unchanged) | `ba4c9fd3ea821cb8a1aa5581a74da8c1477b5e30484fc2e09546a26fe60f2d00` |
| `scripts/deployment_environment_contract_2_1_3.py` | `eaf8f73e57f082a32cba1a1d16449f00839f096fb5af9e5baa0bb1e4504772ff` |
| `scripts/release_review_policy_v3.py` (shared, unchanged) | `e50785233d46d7d03e3f734a15fc64764ab85196482789db0c0157cb4307c9db` |
| helper artifact digest | `c2ccbf6119bdbb1ff280bb9daadb38c24660040d61d292055c201ccc2c86b2ac` |
| `scripts/production_deploy_controller_2_1_3.py` | `a91081dbf87864cc13b546097befbefdc6e34207ebeb74523ab9e68ae275fc1b` |
| `scripts/install_production_helper_2_1_3.py` | `8156481b783001db573c9491a388f05744581a0a0d5c0b9e92295e30be33595b` |
<!-- PINS:END -->

Manifest: `deploy/release-manifests/production-deployment-helper-2.1.3.json`.

## Installed layout (when separately approved)

All root-owned; no sudoers file.

* `/usr/local/libexec/wpcaregrid-deploy/production/2.1.3/` (`0755`):
  `wpcaregrid-deploy-production-priv` (`0755`), `deployment_backend_v2_1_3.py`,
  `deployment_transaction_v2.py`, `deployment_environment_contract_2_1_3.py`,
  `release_review_policy_v3.py`, `production_deploy_controller.py` (all `0644`)
* `/etc/wpcaregrid-deploy/profiles/production-v2.1.3.json` (`0644`): disabled schema-2 policy,
  every environment value and `php_fpm_confinement` null, `privileged_invocation`
  `root-console-direct`

The installer refuses (`legacy_production_helper_present`) while any 2.0.0, 2.1.0, 2.1.1 or 2.1.2
helper directory, policy or sudoers file exists, and while the Phase B policy or production
database credentials exist. It refuses a partial installation (any target or a leftover private
inbox) and never repairs or overwrites it; a complete exact installation is verified read-only
(`INSTALL=NO_OP`). Publication is temp-file + `fsync` + hard link (no overwrite) per file; on any
failure it removes only the inodes and empty directories it created (`ROLLBACK=PASS`), otherwise
`ROLLBACK=HOLD` means stop and keep state for review.

## Command sequences (each needs its own Owner GO)

### Transfer (as `deploy`, untrusted)

Copy the six source files listed in the installer (`SOURCE_HASHES`) from the reviewed merge SHA
into `/home/deploy/.private/wpcg-production-helper-2.1.3` (`deploy:deploy 0700`, files `0600`,
nothing else in the directory). Copy `scripts/install_production_helper_2_1_3.py` separately.

### Install (Owner root console, not sudo)

```sh
(
  set -eu
  src=/home/deploy/.private/wpcg-production-helper-installer-2.1.3/install_production_helper_2_1_3.py
  expected=<root_installer_sha256 from the table above>
  d=$(/usr/bin/mktemp -d /root/wpcg-production-installer.XXXXXX)
  trap '/usr/bin/rm -f -- "$d/install.py"; /usr/bin/rmdir -- "$d"' EXIT
  test "$(/usr/bin/stat -c '%U:%G:%a' "$d")" = root:root:700
  /usr/bin/install -o root -g root -m 700 -- "$src" "$d/install.py"
  test "$(/usr/bin/sha256sum "$d/install.py" | /usr/bin/cut -d' ' -f1)" = "$expected"
  /usr/bin/env -i /usr/bin/python3 -I "$d/install.py"
)
```

Expected: `INSTALL=PASS`, `PRODUCTION_PROFILE=DISABLED`, `PRODUCTION_SUDOERS=ABSENT`,
`DEPLOY_STAGING_SUDO=UNCHANGED` and a JSON receipt. Re-check later with the same command plus
`--verify`. The installer never runs a helper operation.

### Release (after the pin, an approved schema-2 policy and recovery evidence exist)

1. Root console: `env -i /usr/bin/python3 -I .../2.1.3/production_deploy_controller.py preflight`
   — prints the active `application_sha` (the base).
2. As `deploy`: `python3 -I .../2.1.3/production_deploy_controller.py package <app-SHA> <base-SHA>`
   — builds from deploy's own checkout and writes `incoming/<app-SHA>.tar`; prints its SHA-256.
3. Root console: `env -i /usr/bin/python3 -I .../2.1.3/production_deploy_controller.py release
   <app-SHA> <artifact-SHA-256>` — runs the helper directly: `preflight` → `authorize` (exact
   latest protected primary, CI, V3 records and artifacts) → `stage` (untrusted copy into the
   root inbox) → `deploy`. A stale or wrong SHA, a V3 refusal or a confinement refusal holds
   before anything is staged.

The root side never runs git, npm, a shell or a deploy-owned file; the artifact SHA-256 supplied
by `deploy` is only a claim the helper re-verifies.

## Gates that remain (none satisfied by this change)

1. Workstream B package corrected, reviewed and approved; `CONFINEMENT_PIN` set and the helper
   re-pinned (the only remaining source change for 2.1.3).
2. Independent exact-head QA and AppSec V3 records for this pull request; merge and exact
   merge-SHA CI by the Owner's process.
3. Owner GO for installation on a named host; Owner decision on any installed 2.0.0–2.1.2 paths.
4. Confinement staged cutover and live acceptance (Workstream B runbook) on that host.
5. A separately reviewed schema-2 production policy, Phase B policy, recovery evidence and
   database isolation (unchanged production gates).
6. A real-host rehearsal of `verify-install` and `preflight` before any `authorize`.
7. `.github/workflows/weekly-dependency-release.yml` still names the 2.1.0 controller; re-pointing
   it is a separate Owner-approved change and is not made here.

## Known limitations

* Same GitHub API quota and ledger cap as 2.1.0–2.1.2.
* The observation proves recorded state, not kernel enforcement.
* `deploy` can still use its staging helper grant; production treats `deploy` as untrusted and
  never relies on it, but the staging helper's own boundary is outside this change.
* A non-www-data account in supplementary group `www-data` (for example `deploy`) makes
  preflight hold while any of its processes run; remove that membership on the host (separate GO).
* Any transient www-data child outside the class table (for example PHP `mail()` running
  `sendmail` from a legacy pool) makes preflight hold until it exits; retry, do not widen.
* Contract convergence: this `scripts/deployment_environment_contract_2_1_3.py` (schema-3
  observation, production only) is the **canonical single shared module** for the combined
  GO-13 source integration. Workstream B's same-path file (`97b8a4b`: schema-1 observation,
  `deploy_user = "deploy-production"`) is superseded by it; B keeps the package, profiles and
  acceptance tooling and supplies only the final `ConfinementPin`.
