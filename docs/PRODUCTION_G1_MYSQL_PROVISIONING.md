# Production G1 — MySQL 8.4 instance provisioning package

Status: **root-console package prepared; not executed on the VPS**.

This package is fixed to the Owner-approved resource map. It does not accept
arbitrary hostnames, paths, databases, ports, services, profiles, or instance
names. It does not change `mysql.service`, port 3306, the legacy staging
database, staging-next, the vendor MySQL unit, or the vendor AppArmor profile.
It does not create application schemas/users, run Laravel, migrate, restore a
backup, enable units, or open production traffic.

## Fixed instance map

| Setting | Production | Disposable restore |
| --- | --- | --- |
| Instance / systemd unit | `wpcaregrid-production` / `mysql@wpcaregrid-production.service` | `wpcaregrid-production-restore` / `mysql@wpcaregrid-production-restore.service` |
| MySQL | 8.4 | 8.4 |
| Bind / port | `127.0.0.1:3308` | `127.0.0.1:3309` |
| Schema reserved for later G2 | `wpcg_prod_main` | `wpcg_prod_restore_main` |
| Datadir | `/var/lib/mysql-wpcaregrid-production` | `/var/lib/mysql-wpcaregrid-production-restore` |
| Socket / PID | `/run/mysqld/wpcaregrid-production.sock` / `/run/mysqld/wpcaregrid-production.pid` | `/run/mysqld/wpcaregrid-production-restore.sock` / `/run/mysqld/wpcaregrid-production-restore.pid` |
| Error log / tmp | `/var/log/mysql/wpcaregrid-production.log` / `/var/tmp/wpcaregrid-production` | `/var/log/mysql/wpcaregrid-production-restore.log` / `/var/tmp/wpcaregrid-production-restore` |
| AppArmor | `wpcaregrid-mysql-production` | `wpcaregrid-mysql-production-restore` |

Both instance configs set `secure-file-priv=NULL`, disable MySQL X, and explicitly
disable InnoDB table/redo/undo and binary-log encryption. Plugin/component load
options, keyring references, replication settings, authentication bypass and
startup SQL hooks are rejected from effective inherited options. Auxiliary
Admin/MySQL X listeners and loose options are also refused. The
G1 initializer uses MySQL's secure `--initialize` mode, not
`--initialize-insecure`; its generated temporary root credential is written
only to the corresponding error log, which is created `mysql:mysql 0600` and is
never echoed by this tool. The DBA must secure and retain credentials using the
approved secret store before application deployment.

## Package contents

The root-only source bundle directory must contain exactly these files:

* `production-g1-mysql.py` — no-overwrite installer and phase-separated runner.
* `production_g1_mysql_contract.py` — immutable instance map, config and
  fail-closed validators, and generated systemd/AppArmor artifacts.
* `production-g1-mysql-manifest.json` — generated source and installed-artifact
  SHA-256 manifest.
* `SHA256SUMS` — operator-side source checksums, delivered alongside the bundle.

Current locally prepared artifact hashes (recompute from the final reviewed
files before copying):

| Source artifact | SHA-256 |
| --- | --- |
| `production-g1-mysql.py` | `01914e40ef6230e9506911e20abe1e4eba0abe49d06e42e7ccc3ed48a455e4d1` |
| `production_g1_mysql_contract.py` | `270a7f2ddc93a38bbabc94197e11fa078602004b39c18a2c86a34bf4c6f05f8f` |
| `production-g1-mysql-manifest.json` | `14483b894240faed5aef77dde93728774be8a804b4657e7fc37d6b86e187c2c7` |

These are source-file hashes, not a protected-primary commit identity or a
VPS installation receipt. The code is not merged and the installation has not
been run.

The script source is required to be `root:root 0600` inside a `root:root 0700`
directory before it is executed. The installed files are `root:root 0755` for
the Python entrypoint and `root:root 0644` for its contract, manifest, MySQL
configs, systemd drop-ins and AppArmor profiles.

The installed systemd drop-ins reset the factory `ExecStartPre` list and replace
it with the fixed read-only checker. This prevents
`mysql-systemd-start pre @%I` from invoking the vendor helper that can initialize
the default `/var/lib/mysql`.

The drop-ins also reset `ExecStart` and replace it with
`/usr/sbin/mysqld --defaults-group-suffix=@<instance>` using the literal instance
name. The vendor template's `@%I` cannot be used: systemd's `%I` unescapes the
instance name (`-` becomes `/`), so `wpcaregrid-production` would select the
non-existent group `mysqld@wpcaregrid/production` and mysqld would start without
the instance's own configuration. The installer therefore requires the vendor
template to contain exactly `ExecStart=/usr/sbin/mysqld --defaults-group-suffix=@%I`
(so nothing is lost by the override), requires each drop-in to contain exactly one
reset and one literal override, and requires the effective unit to run exactly one
command whose argv is the mysqld binary and that instance's suffix. No vendor file
is edited.

### Option-file ordering (`zz-` file names)

The instance configs are installed as
`/etc/mysql/mysql.conf.d/zz-wpcaregrid-production.cnf` and
`/etc/mysql/mysql.conf.d/zz-wpcaregrid-production-restore.cnf`. mysqld and
`my_print_defaults` read `!includedir` files in file-name order and the **last**
occurrence of an option wins. The first package (#325/#327) used `90-` names, which
sort *before* the distribution's `mysqld.cnf`; on the staging VPS its shared
`[mysqld]` group therefore overrode `datadir`, `socket`, `pid-file` and
`log-error` of both instances (the contract values were present but not last), and
`GO-3` stopped with `effective_option_mismatch`. `zz-` sorts after every
distribution file (`mysqld.cnf`, `99-*.cnf`). The validators are unchanged: any
file that still sorts later and changes one of the 13 contract options is refused
at install-time and start-time (`effective_option_mismatch`). The installer also
refuses a leftover `90-<instance>.cnf` (`legacy_config_name_present`); it must be
removed by the Rollback procedure first. The shared files are never edited.
`scripts/tests/production-g1-mysql-config-order-test.py` proves this with the
real `my_print_defaults` and `mysqld --validate-config` of the official MySQL 8.4
image (no mocked output).

### X Plugin and inherited `mysqlx-*` options

Both instance configs disable the X Plugin (`mysqlx=0`). The distribution's shared
`[mysqld]` group ships `mysqlx-bind-address=127.0.0.1`, which both instances
inherit. Measured on MySQL Community Server 8.4.13: with `mysqlx=0` the plugin is
`DISABLED`, no X listener exists (only the instance port listens) and no
`mysqlx_*` variable is defined, whatever the inherited address is; without
`mysqlx=0` the plugin is `ACTIVE` and listens on port 33060; an unknown
`mysqlx-*` option makes mysqld abort. The verifier therefore accepts exactly one
inherited X option, `mysqlx-bind-address=127.0.0.1`, and only when every effective
`mysqlx` value is `0` (`x_protocol_not_disabled` otherwise). Every other
`mysqlx-*` option (other names, other values such as `0.0.0.0` or `::1`, `loose-`
and `admin-` forms, an option without a value) remains
`unsafe_effective_option_present`.

The resolved values printed by `mysqld --verbose --help` are compared after
normalizing both sides (mysqld renders `mysqlx=0` as `OFF` and booleans as
`FALSE`). `--help` prints `stderr` for `log-error` before the log subsystem
starts, although the configured file is honored at runtime; only `stderr` or the
exact path is accepted there, and the exact path is still required from
`my_print_defaults`.

### Shared log directory mode (`/var/log/mysql`)

`provision-paths` first checks the four shared parent directories: `/var/lib` (`root:root 0755`),
`/var/log/mysql`, `/var/tmp` (`root:root 1777`) and `/run/mysqld` (`mysql:mysql 0755`). The first package
required `/var/log/mysql` to be exactly `mysql:adm 02750`. On Ubuntu 26.04.1 LTS with MySQL 8.4.13 the
directory is `mysql:adm 0750` (no setgid bit), so `provision-paths` stopped with
`managed_directory_mode_invalid` before it created anything. The package now accepts exactly `0750` and
`02750` for `mysql:adm`: both give the owner full access, the group read/execute only and others nothing; the
only difference is that the setgid bit makes *new* entries inherit group `adm`, which the G1 instances do not
rely on because `provision-paths` creates their two log files with an explicit `mysql:mysql 0600` and mysqld
only writes to those existing files. Every other mode (for example `0700`, `0751`, `0755`, `0770`, `02770`,
`01750`, `04750`), another owner or group, or a symlink is still refused, and the other three shared
directories and all instance paths keep their exact checks. The shared directory is never changed by the tool.
The hotfix changes only `production-g1-mysql.py` and the manifest; the contract, the option files, the
AppArmor profiles and the systemd drop-ins are byte-identical to the previous package.

### Base configuration through the Ubuntu alternatives symlink (`/etc/mysql/my.cnf`)

On Ubuntu `/etc/mysql/my.cnf` is a symlink managed by `update-alternatives`:
`/etc/mysql/my.cnf` -> `/etc/alternatives/my.cnf` -> `/etc/mysql/mysql.cnf`. GO-5.2 (`verify-install`) stopped with
`managed_file_type_invalid` because the generic `check_file()` (which still refuses every symlink) was applied to
it. `check_base_config()` now accepts the base configuration only as

* a regular `root:root 0644` file (as before), or
* exactly this chain: each hop is a `root:root` symlink whose target string equals the next hop of a fixed table
  (absolute paths only; no relative, trailing-slash or other target; a repeated hop is a cycle), the final
  `/etc/mysql/mysql.cnf` is a `root:root 0644` regular file (checked by `check_file()`, so a symlink there is
  refused), and `/etc`, `/etc/mysql` and `/etc/alternatives` are root-owned real directories that are not writable
  by group or others.

Anything else (another alternatives target such as `/etc/mysql/mariadb.cnf`, a different owner or mode, a missing
hop, a writable directory) is refused with `base_config_link_target_unexpected`, `base_config_link_owner_invalid`,
`base_config_directory_unsafe`, `base_config_link_cycle`, `base_config_unavailable` or the usual `managed_file_*`
code. MySQL tools still receive the unresolved `--defaults-file=/etc/mysql/my.cnf`; nothing is rewritten or replaced.
The chain is checked immediately before the work of each command (not held open), which is acceptable because every
element is root-owned in root-only directories. Only `production-g1-mysql.py` and the manifest change; the contract,
the option files, the AppArmor profiles and the systemd drop-ins stay byte-identical (runbook, GO-5.2 hotfix).

**AppArmor (fixed by the GO-5.2c hotfix):** AppArmor mediates the resolved path, and the first profiles granted
`/etc/mysql/my.cnf r,` but not the symlink target, so the confined `my_print_defaults`/mysqld was denied reading
`/etc/mysql/mysql.cnf` (kernel log: `denied_mask="r"`). The generated profiles now contain exactly one more rule,
`/etc/mysql/mysql.cnf r,` (read only, one file, directly after the `my.cnf` rule); the previous profiles are reproduced
byte for byte by removing that line (tested). Contract, manifest and the two profiles changed; the helper, option files and
drop-ins did not. The in-place replacement is `go5p-upgrade-apparmor.py` (runbook, "GO-5.2c hotfix"); `verify-install`
cannot show this gap because it runs MySQL unconfined, which is why the confined probe `go5p-confined-probe.sh` is a gate.

### Initialization (`initialize`) process handling and completion proof

`initialize` runs `runuser -> aa-exec -> mysqld --initialize` in its own session with a 600 s limit. On a timeout, an interruption of the helper or an
orphan left after exit, the whole process group is stopped (`SIGTERM`, then `SIGKILL`) and verified gone via `/proc`; the datadir is always preserved
and a distinct `initialize_*` code is reported. Success requires evidence (exit 0, exact datadir contents, the server's start and end log lines, no error
line, no process or socket/pid marker); the existence of `mysql/` and `auto.cnf` alone proves nothing, because they appear early. A datadir that already
carries initialization artefacts is refused with `datadir_initialization_present`. See the runbook, "GO-5.3 incident", for the diagnostic and recovery plan.

### Upgrading an installed package

The installer never replaces a managed file: it accepts an existing file only if
it is byte-identical, so running a newer package over an installed one stops with
`managed_file_content_mismatch`. There is no upgrade command in the installer itself; the only supported
in-place replacements are the narrow, hash-pinned operator tools `go4b-upgrade-installed-helper.py` (log-directory
hotfix), `go5h-upgrade-installed-helper.py` (base-config symlink hotfix; helper and manifest) and
`go5p-upgrade-apparmor.py` (AppArmor hotfix; contract, manifest and the two profiles); each replaces only the named
files (see the runbook, GO-4b, GO-5.2 and GO-5.2c hotfix). Before
initialization, the supported path is the Rollback procedure below (exact
recorded files only, whose SHA-256 still matches the installed package) followed
by a fresh `install` of the new package. This is also the path from the
first package (#325, `90-` option-file names): its nine files and two profiles are
rolled back first because the new package uses different option-file names and a
leftover `90-` file is refused. After initialization the configuration
must not be reverted without a separately reviewed recovery procedure. Never
overwrite installed files by hand. Network
filtering permits only loopback IP traffic; the AppArmor profiles allow each
instance its own data, socket, PID, log and temp paths, with explicit denies for
the legacy `/var/lib/mysql` and the other new instance's paths.

## Root-console procedure

First place the reviewed four-file package in this exact private directory.
Do not execute it from a deploy-writable directory.

```sh
test ! -e /root/wpcg-production-g1-mysql-v1 && test ! -L /root/wpcg-production-g1-mysql-v1
install -d -o root -g root -m 0700 /root/wpcg-production-g1-mysql-v1
```

After the files have been copied there, run these as separate commands:

```sh
cd /root/wpcg-production-g1-mysql-v1
sha256sum -c SHA256SUMS
python3 -I ./production-g1-mysql.py install
python3 -I /usr/local/libexec/wpcaregrid-g1-mysql.py provision-paths
python3 -I /usr/local/libexec/wpcaregrid-g1-mysql.py verify-install
python3 -I /usr/local/libexec/wpcaregrid-g1-mysql.py preflight --instance wpcaregrid-production
python3 -I /usr/local/libexec/wpcaregrid-g1-mysql.py preflight --instance wpcaregrid-production-restore
python3 -I /usr/local/libexec/wpcaregrid-g1-mysql.py initialize --instance wpcaregrid-production
python3 -I /usr/local/libexec/wpcaregrid-g1-mysql.py initialize --instance wpcaregrid-production-restore
python3 -I /usr/local/libexec/wpcaregrid-g1-mysql.py start --instance wpcaregrid-production
python3 -I /usr/local/libexec/wpcaregrid-g1-mysql.py start --instance wpcaregrid-production-restore
python3 -I /usr/local/libexec/wpcaregrid-g1-mysql.py verify-running --instance wpcaregrid-production
python3 -I /usr/local/libexec/wpcaregrid-g1-mysql.py verify-running --instance wpcaregrid-production-restore
```

Each command must return exit 0 and its documented structured PASS. Any
`G1_MYSQL=STOP` or nonzero result means stop immediately; do not retry, delete a
datadir, restart a service, or invoke `mysql-systemd-start`. `install` performs
no MySQL initialization or service start. It validates source identities and
destinations, syntax-checks/loads only the two new named AppArmor profiles,
calls `systemctl daemon-reload`, then checks installed hashes, effective User=mysql/Group=mysql identity,
and all expected unit directives. `provision-paths` first verifies that both exact instance units are inactive, then creates only the exact approved datadir, log and tmp paths;
it requires their parent directory identities/modes to match the Ubuntu layout
and rolls back only empty files/directories created by that invocation if it
fails. It refuses any pre-existing non-empty datadir, tmpdir or log file for either instance and does not initialize either database. `verify-install` and each
read-only preflight run the VPS's actual `my_print_defaults`,
`mysqld --validate-config`, and resolved `mysqld --verbose --help` checks after
those paths exist.

The two `initialize` commands are distinct, one-time state changes. They use
`runuser` to execute MySQL as `mysql` inside that instance's AppArmor profile,
require the datadir to be empty, verify loopback port availability, and use
`--initialize` with the fixed defaults file and group suffix. They never start
the systemd unit. A failed or partial initialization is retained for forensic
inspection and must not be retried or cleaned automatically.

Each `start` command requires an initialized datadir, absent socket/PID markers,
an inactive exact unit, and a free exact port. It invokes `systemctl start` once;
there is no retry. A failed postcheck causes a stop of only that exact new
instance, retaining the datadir and logs. `verify-running` checks the exact
process arguments, AppArmor enforce label, loopback listener and instance UUID
fingerprint without printing UUIDs or credentials. It deliberately reports
`KEYRING_RUNTIME=PENDING_DBA_READONLY_CHECK`: SQL confirmation of the loaded
plugin/component registry requires the DBA to use the protected initial
credential and a read-only query. The tool does not claim this last proof.

## Required stop conditions and acceptance

Before publishing helper/configuration destinations, the installer rejects
unknown or symlinked targets, nonmatching owner/mode/content, unexpected systemd
drop-ins, active target instances, and a base unit that differs from the
recorded template. Atomic `link(2)` publication prevents replacing a
destination that appears in a race. After publication it syntax-checks and
loads only the two new AppArmor profiles, reloads systemd, then verifies the
effective units and artifact hashes. A profile parse/load or later install
failure triggers identity-checked rollback of only files and empty directories
created by that invocation and unloads only profiles it just added. If any
rollback step cannot be verified, the runner preserves remaining files and
returns `install_rollback_incomplete_preserve_files` for operator inspection.
The separate `provision-paths` phase validates the actual host parent
directories and refuses a mismatch before creating any instance data/log/tmp
path.

Acceptance is complete only after:

1. `install` and `verify-install` PASS, with both services effectively running as
   mysql:mysql, both named profiles loaded in
   enforce mode and both instance `ExecStartPre` overrides effective.
2. Both option-resolution/preflight commands PASS for exact MySQL 8.4 binaries,
   datadirs, sockets, PID files, logs, tmpdirs, loopback ports,
   `secure_file_priv=NULL`, disabled encryption/keyring configuration, and no
   replication options.
3. Both independent initialization commands PASS and their UUID SHA-256 values
   differ from legacy MySQL `3306` and each other.
4. Both exact instance units start once and `verify-running` PASSes. The
   independent SQL read-only plugin/component and resolved-variable checks are
   recorded without including credentials or SQL table contents.
5. `mysql.service` state/PID, port `3306`, `/etc/apparmor.d/usr.sbin.mysqld`
   hash, legacy and staging-next config/database hashes remain unchanged.

The root runner does not create application schemas or DB users. It does not
enable either instance at boot. No staging or production application release,
restore, migration, worker/scheduler activation, Nginx change, or public routing
is part of this package.

## Rollback

* **Before initialization:** the installer's own failure rollback is automatic
  and identity checked. If a later pre-initialization stop is needed, first
  prove both exact units inactive and both datadirs/tmpdirs empty and log files
  empty. Remove only the recorded exact helper/contract/manifest/config/drop-in
  files whose SHA-256 still matches this package; unload only these two named
  profiles if they are not attached to a running process; run
  `systemctl daemon-reload`. Retain empty data/log/tmp directories unless the
  operator has verified they were created by this run and are still empty.
* **After initialization or first start:** do not delete data or logs and do not
  roll back by reinstalling MySQL or dropping an instance. Stop only the exact
  affected new instance if its postcheck failed; preserve its datadir, error
  log, unit and profile for DBA inspection. Reverting config/profile files after
  initialization requires a separately reviewed recovery procedure because it
  can make the initialized instance inaccessible.
* Never stop or alter `mysql.service`, any staging unit/database, the vendor
  AppArmor profile, production application routing, or the two legacy
  Supervisor programs as part of rollback.

## Verification evidence boundary

Local deterministic tests cover both exact resource maps, disjoint paths,
MySQL option fallback rejection, missing/empty/wrong path rejection,
port/socket conflicts, plugin/keyring rejection, replication rejection,
unit pre-start replacement and AppArmor cross-instance denial. The target VPS
must still run the package's real MySQL 8.4 option parser and AppArmor parser
checks before initialization. This document and local synthetic tests are not
evidence that provisioning ran successfully on the VPS.
