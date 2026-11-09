# Production G1 MySQL — operator runbook (GO-0 … GO-5)

Scope: remove the statically installed #325 package, install the #328 package
(`zz-` option files) and prepare — but, per gate, only with explicit Owner approval —
provision, initialize and start the two isolated MySQL 8.4 instances
`wpcaregrid-production` (127.0.0.1:3308) and `wpcaregrid-production-restore`
(127.0.0.1:3309). The existing `mysql.service` (3306) is never touched.

Companion documents: `docs/PRODUCTION_G1_MYSQL_PROVISIONING.md` (package contract,
why `zz-`), `scripts/production-g1-ops/` (the tools below, pinned by SHA-256).

## Rules that apply to every gate

* Each gate is a separate Owner approval. A gate ends with exactly one result line
  `<TAG>=PASS` (exit 0) or `<TAG>=STOP;CODE=<cause>` (non-zero). Anything else is a STOP.
* On STOP: do not retry, do not "fix up" by hand, do not continue to the next gate.
  Report the complete output. Nothing in this runbook deletes data or restarts
  `mysql.service`.
* All read-only checks are idempotent and may be repeated at any time.
* The tools read; only GO-2 (rollback), GO-4 (`install`) and GO-5 (`provision-paths`,
  `initialize`, `start`) change the host, and each of them changes only the named files.

## Tools and pins

| Item | Pinned by |
| --- | --- |
| Package `#328` | merge commit `bb7e20e8c62d7977b7f2650b39d76d9b203198c4` (four files, hashes in `go3-fetch-package.sh`) |
| Toolkit `scripts/production-g1-ops/` | the merge commit of the toolkit PR and the SHA-256 of its `SHA256SUMS` (printed in the hand-over, verified by GO-0) |

## GO-0 — fetch and verify the operator toolkit (writes only `/root/wpcg-g1-ops-<sha7>`)

Fill `OPS_SHA` (40 hex) and `SUMS_SHA256` (64 hex) from the hand-over. The block creates
the directory only if it does not exist, downloads into an `…incoming-UNVERIFIED`
staging directory and renames it only after every file matched.

```bash
( set -euo pipefail; umask 077
  OPS_SHA=<40-hex merge commit>; SUMS_SHA256=<64-hex sha256 of SHA256SUMS>
  [[ $OPS_SHA =~ ^[0-9a-f]{40}$ && $SUMS_SHA256 =~ ^[0-9a-f]{64}$ ]] || { echo "STOP: pins malformed"; exit 1; }
  D=/root/wpcg-g1-ops-${OPS_SHA:0:7}; W="$D.incoming-UNVERIFIED"
  [ "$(id -u)" = 0 ] || { echo "STOP: not root"; exit 1; }
  for p in "$D" "$W"; do { [ ! -e "$p" ] && [ ! -L "$p" ]; } || { echo "STOP: $p exists"; exit 1; }; done
  install -d -o root -g root -m 0700 "$W"; cd "$W"
  B=https://raw.githubusercontent.com/zsoltacsai/wpcaregrid/$OPS_SHA/scripts/production-g1-ops
  get() { curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --tlsv1.2 \
               --max-filesize 2000000 --max-time 60 --output "$1" "$B/$1" </dev/null; }
  get SHA256SUMS || { echo "STOP: download failed: SHA256SUMS"; exit 1; }
  echo "$SUMS_SHA256  SHA256SUMS" | sha256sum -c --strict - >/dev/null || { echo "STOP: SHA256SUMS hash mismatch"; exit 1; }
  while read -r digest name; do
    [[ $name =~ ^[a-z0-9][a-z0-9._-]*$ && $digest =~ ^[0-9a-f]{64}$ ]] || { echo "STOP: bad SHA256SUMS entry"; exit 1; }
    get "$name" || { echo "STOP: download failed: $name"; exit 1; }
  done < SHA256SUMS
  sha256sum -c --strict SHA256SUMS >/dev/null || { echo "STOP: file hash mismatch"; exit 1; }
  [ "$(ls -A | wc -l)" = "$(( $(wc -l < SHA256SUMS) + 1 ))" ] || { echo "STOP: unexpected file count"; exit 1; }
  chown -R root:root .; chmod 0600 ./*
  cd /; mv -T -- "$W" "$D"
  echo "G1_GO0_TOOLKIT=PASS $D" )
```

From here on `OPS=/root/wpcg-g1-ops-<sha7>`.

## GO-1 — preflight of the installed #325 state (read-only, done before; repeatable)

```bash
python3 -I -B "$OPS/g1-state-check.py" 325      # G1_GO1_PREFLIGHT=PASS
```

Expected: nine #325 files exact, both G1 units inactive, both profiles loaded in
`enforce`, effective `ExecStart` correct, no G1 data/tmp/log/socket paths, `mysql.service`
active with 3306 listening, 3308/3309 free, vendor template unchanged. Note the printed
`mysql.service MainPID=` — GO-5 compares against it.

## GO-2 — remove exactly the #325 artifacts (Owner approval)

Removes only the nine hash-identified files and the two empty drop-in directories and
unloads only the two named profiles. Pass 1 verifies every target (type, owner, mode,
SHA-256), inactivity of both units and the profile state before anything changes; each
file is verified again immediately before it is removed. The first error stops
everything and prints which profiles were unloaded and which files were removed.

```bash
bash "$OPS/go2-rollback.sh"                     # G1_GO2_ROLLBACK=PASS
python3 -I -B "$OPS/g1-state-check.py" clean    # G1_GO2_ROLLBACK_VERIFY=PASS
```

STOP handling: a `G1_GO2_ROLLBACK=STOP` line names `PROFILES_UNLOADED` and
`FILES_REMOVED`; the partial state is preserved. Re-running `g1-state-check.py 325` shows
what is left. Recovery is a fresh Owner decision (the #325 package could be reinstalled
from its pinned commit); never delete by hand.

## GO-3 — fetch and verify the #328 package (Owner approval; creates `/root/wpcg-production-g1-mysql-bb7e20e`)

```bash
bash "$OPS/go3-fetch-package.sh"                # G1_GO3_DOWNLOAD=PASS (pinned commit + hashes)
PKG=/root/wpcg-production-g1-mysql-bb7e20e
python3 -I -B "$OPS/go3-manifest-check.py" "$PKG"        # G1_GO3_MANIFEST=PASS
bash "$OPS/go3-apparmor-compile.sh" "$PKG"               # G1_GO3_APPARMOR_COMPILE=PASS (no kernel load)
python3 -I -B "$OPS/go3-effective-config-dryrun.py" "$PKG"   # G1_GO3_DRYRUN=PASS
```

`go3-fetch-package.sh` refuses an existing target or staging directory, downloads only
from the pinned commit over HTTPS, checks `SHA256SUMS` on its own, every source file
against the pinned hash and `sha256sum -c --strict`, and renames the verified directory
only at the end; a failed download leaves only the `…incoming-UNVERIFIED` directory.
The dry-run mirrors the host's real option-file directories in a private temporary
tree, leaves out the #325 `90-` files, adds the two `zz-` files and resolves the options
with the real `my_print_defaults`; it can run before or after GO-2 and changes nothing.
Expected after GO-2: all 13 contract options correct for both instances. A `MISMATCH`
names the instance, the option and the host option-file line that overrides it.

## GO-4 — install the package (Owner approval; changes the host)

Preconditions (run, all must PASS): `g1-state-check.py clean`, GO-3 complete, the package
hashes re-verified:

```bash
python3 -I -B "$OPS/g1-state-check.py" clean            # G1_GO2_ROLLBACK_VERIFY=PASS
cd "$PKG" && sha256sum -c --strict SHA256SUMS            # all OK
python3 -I ./production-g1-mysql.py install              # installer PASS line, exit 0
python3 -I -B "$OPS/g1-state-check.py" 328               # G1_GO4_POSTINSTALL=PASS
python3 -I -B "$OPS/g1-effective-config-check.py"        # G1_EFFECTIVE_CONFIG=PASS
```

`install` writes the nine files (no-overwrite), loads only the two named profiles and runs
`systemctl daemon-reload`; it does not initialize MySQL or start a unit. Its own failure
rollback covers only what that run created. Afterwards the engine checks the installed
bytes, owners, modes, drop-in directories, both profiles in `enforce`, the effective
`ExecStart`/`ExecStartPre`, absence of runtime paths, `mysql.service` (3306) and the vendor
template.

STOP after a failed `install`: if the installer reports
`install_rollback_incomplete_preserve_files`, nothing is deleted automatically; report and
decide (the state is then exactly what `g1-state-check.py 328`/`clean` print).

## GO-4b — log-directory hotfix of the installed helper (Owner approval; replaces two installed files)

Why: the helper installed by GO-4 refuses `/var/log/mysql` unless it is `mysql:adm 02750`; Ubuntu 26.04.1
ships `0750` and `provision-paths` stops with `managed_directory_mode_invalid` (nothing is created before that
check). The hotfix package accepts exactly `0750` and `02750` (see the package document). The installer is
no-overwrite, so GO-4b replaces **only** `/usr/local/libexec/wpcaregrid-g1-mysql.py` and
`/usr/local/libexec/production_g1_mysql_manifest.json`; the contract, the option files, the AppArmor profiles and
the systemd drop-ins stay byte-identical (the tool refuses to run otherwise). `<MERGE_SHA>` is the 40-hex merge
commit of the hotfix pull request, `OPS` is the toolkit directory fetched by GO-0 from that same commit.

```bash
python3 -I -B "$OPS/g1-state-check.py" 328                   # G1_GO4_POSTINSTALL=PASS  (old installed state, nothing provisioned)
bash "$OPS/go4b-fetch-hotfix-package.sh" <MERGE_SHA>         # G1_GO4B_DOWNLOAD=PASS    (new dir /root/wpcg-production-g1-mysql-hf-<sha7>)
HF=/root/wpcg-production-g1-mysql-hf-<sha7>
python3 -I -B "$OPS/go3-manifest-check.py" "$HF"             # G1_GO3_MANIFEST=PASS
python3 -I -B "$OPS/go4b-upgrade-installed-helper.py" "$HF"  # G1_GO4B_HELPER_UPGRADE=PASS
python3 -I -B "$OPS/g1-state-check.py" 328h                  # G1_GO4B_POSTUPGRADE=PASS
python3 -I -B "$OPS/g1-effective-config-check.py"            # G1_EFFECTIVE_CONFIG=PASS
```

The upgrade tool checks before any write: the package files (hash-pinned, root-owned directory), the installed
helper/contract/manifest (old pinned hashes, owner, mode, no symlink), both G1 units inactive and no leftover
temporary file. Each file is replaced by a same-directory temporary file, `fsync` and atomic `rename`; afterwards
the new helper's static verification (`verify_static_install()`, the same check `install` ends with: bundle, systemd,
AppArmor) must pass, otherwise the old bytes are restored (`ROLLED_BACK=yes`). It deliberately does not call the helper's
`verify-install` command: that also runs `mysqld --validate-config`, which needs the instance tmpdirs created only by
`provision-paths`, so it fails on every host between GO-4 and GO-5.1 (the first GO-4b attempt stopped with
`verify_install_failed` for exactly this reason; nothing was changed, the rollback restored the old files). A second
run reports `NOTE=already_upgraded`. State `328` in `g1-state-check.py` is the old installed state, `328h` the
hotfix state. `provision-paths` (GO-5.1) is a separate gate afterwards.

## GO-5.2 hotfix — base-config symlink (Owner approval; replaces the same two installed files)

Why: GO-5.2 `verify-install` stopped with `G1_MYSQL=STOP;CODE=managed_file_type_invalid` because Ubuntu provides
`/etc/mysql/my.cnf` as a symlink (`/etc/mysql/my.cnf` -> `/etc/alternatives/my.cnf` -> `/etc/mysql/mysql.cnf`) and the
installed helper's `verify_preflight()` applied the generic no-symlink `check_file()` to it. The new helper verifies
exactly that chain and its final file (see the package document, "Base configuration through the Ubuntu alternatives
symlink") and still refuses everything else. `/etc/mysql/my.cnf` is **not** replaced or edited. GO-5.1 is not repeated;
the already created data and temporary directories are left as they are; no MySQL is initialized or started and
`mysql.service` (3306) is not touched. The tool replaces **only** `/usr/local/libexec/wpcaregrid-g1-mysql.py` and
`/usr/local/libexec/production_g1_mysql_manifest.json`; contract, option files, AppArmor profiles and drop-ins must be
byte-identical: the tool itself checks the contract hash and that both units are inactive, and the mandatory
`g1-state-check.py 328hp` step (run first by the sequence) proves the rest (any STOP there ends the procedure). `<MERGE_SHA>` is the 40-hex merge commit of the hotfix pull request,
`OPS` the toolkit directory fetched by GO-0 from that same commit.

```bash
bash "$OPS/go52h-sequence.sh" <MERGE_SHA>                    # G1_GO52H_SEQUENCE=PASS
```

The sequence is written for the host as it really is after GO-5.1: `provision-paths` has created the empty datadir
(`mysql:mysql 0750`), tmpdir (`0700`) and error log (`0600`) of both instances, nothing was initialized or started.
Therefore it does **not** use state `328h` / `328h2` (those require the runtime paths to be absent, GO-4b era) but
`328hp` / `328h2p` (same installed files, plus: the three paths of each instance exist, empty, `mysql:mysql` with the
exact modes, no socket/pid marker). The script (a) detects the installed helper by SHA-256 (GO-4b hotfix 1 ->
`328hp`; already replaced -> `328h2p`; anything else is a STOP), (b) verifies that state first, (c) fetches the
package into `/root/wpcg-production-g1-mysql-hf2-<sha7>` or re-verifies it if the directory already exists,
(d) runs the manifest check and the (idempotent) upgrade tool, (e) verifies `328h2p` and the effective
configuration. It can therefore be started again from any point after a STOP that left the host unchanged or after
a successful upgrade. It never runs `provision-paths`, `initialize` or `start`, never deletes or changes the
MySQL directories, the logs, `mysql.service` or `/etc/mysql/my.cnf`. State `328h2` stays the pre-GO-5.1 state.


The upgrade tool checks before any write: the package files (hash-pinned, root-owned directory), the installed
helper/contract/manifest (GO-4b pins, owner, mode, no symlink), both G1 units inactive and no leftover temporary file.
Each file is replaced by a same-directory temporary file, `fsync` and atomic `rename`; then the new helper's
`verify_static_install()` **and** `check_base_config()` must pass, otherwise the old bytes are restored
(`ROLLED_BACK=yes`) and the exact code of the host's `/etc/mysql/my.cnf` problem is reported
(`verify_static_install_failed:<code>`). A second run reports `NOTE=already_upgraded`. State `328h2p` in
`g1-state-check.py` is the hotfix state after GO-5.1; `328hp` is the previous one (`328h` / `328h2` are the pre-GO-5.1 variants).

After PASS, GO-5.2 is repeated (`verify-install`, then `preflight --instance <each>`, see below). GO-5.1 is not.

**GO-5.2c — confined read-only probe (mandatory gate before GO-5.3).** AppArmor mediates the resolved path of a symlink,
and the generated profiles grant `/etc/mysql/my.cnf` but not its target `/etc/mysql/mysql.cnf`. `verify-install` runs
MySQL unconfined and cannot show this. The probe runs only `my_print_defaults` (reads option files, prints values,
starts no server) under the instance's enforcing profile and prints no option value:

```bash
for I in wpcaregrid-production wpcaregrid-production-restore; do
  OUT=$(aa-exec -p "wpcaregrid-mysql-${I#wpcaregrid-}" -- /usr/bin/my_print_defaults \
        --defaults-file=/etc/mysql/my.cnf --defaults-group-suffix=@"$I" mysqld 2>&1); RC=$?
  case "$OUT" in *"--datadir=/var/lib/mysql-$I"*) HAS=yes;; *) HAS=no;; esac
  echo "G1_GO52C_CONFINED_PROBE;INSTANCE=$I;RC=$RC;DATADIR_LINE=$HAS"
done
```

PASS only if both lines show `RC=0;DATADIR_LINE=yes`. Anything else means the confined mysqld cannot read the chain
target: STOP before GO-5.3 and request a separate profile hotfix (adding exactly `/etc/mysql/mysql.cnf r,` to the two
generated profiles; AppArmor mediates the resolved file, not the intermediate symlink); do not initialize.

## GO-5 — runtime preparation (each step is its own Owner gate)

`provision-paths` is the first step that creates instance paths. After it, the clean-state
rollback of GO-2 no longer applies as written (`g1_runtime_paths_absent` would STOP):
undoing it needs its own approved procedure that removes only the empty, exactly named
directories/files created by it.

| Gate | Command | Notes |
| --- | --- | --- |
| 5.1 | `python3 -I /usr/local/libexec/wpcaregrid-g1-mysql.py provision-paths` | creates the exact datadir (mysql:mysql 0750), tmpdir (0700) and error-log (0600) of both instances; refuses non-empty existing paths and active units |
| 5.2 | `python3 -I /usr/local/libexec/wpcaregrid-g1-mysql.py verify-install` and `preflight --instance <each>` | first run of `mysqld --validate-config` and `--verbose --help` (they need the tmpdirs); first attempt stopped with `managed_file_type_invalid` (symlinked `/etc/mysql/my.cnf`) -> GO-5.2 hotfix above, then repeat; GO-5.2c probe before 5.3 |
| 5.3 | `… initialize --instance wpcaregrid-production`, then `… wpcaregrid-production-restore` | one-time, never retried, never cleaned automatically |
| 5.4 | `… start --instance <each>` then `… verify-running --instance <each>` | one `systemctl start` per instance, no retry; failed postcheck stops only that instance and keeps datadir/log |
| 5.5 | `python3 -I -B "$OPS/go5-runtime-acceptance.py" --mysql-main-pid <GO-1 MainPID>` | read-only: AppArmor `enforce` label, instance group in the process arguments, loopback-only 3308/3309, no 33060, `mysql.service` PID unchanged, three distinct processes |

Every `G1_MYSQL=STOP` or non-zero result stops the sequence. No gate starts the next
automatically; no gate enables a unit at boot; application schemas, DB users, Laravel
deployment and routing are out of scope.

## STOP conditions (global)

* Any result line other than the documented `PASS`, a non-zero exit, or output that cannot be
  classified.
* A host file whose owner/mode/hash differs from the pinned value, a symlink where a regular
  file is expected, or a foreign file in a directory the runbook would remove.
* An active G1 unit, a G1 port in use, or any change to `mysql.service`, its PID, port 3306 or
  `/etc/apparmor.d/usr.sbin.mysqld`.
* A dry-run or effective-config `MISMATCH`.
