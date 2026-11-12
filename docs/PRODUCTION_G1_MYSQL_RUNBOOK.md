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
    [[ $name =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ && $digest =~ ^[0-9a-f]{64}$ ]] || { echo "STOP: bad SHA256SUMS entry"; exit 1; }
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

Before anything else the script re-verifies the toolkit directory (`sha256sum -c --strict SHA256SUMS`, exact file
count). An existing package directory is reused only after the same checks the fetch script applies before its rename
(root:root 0700 directory, exactly four root:root 0600 files, the pinned `SHA256SUMS` hash, `sha256sum -c --strict`).
If a fetch was interrupted it leaves `/root/wpcg-production-g1-mysql-hf2-<sha7>.incoming-UNVERIFIED`; the next run
then stops with `staging_directory_exists`. Nothing was installed at that point: report it, the Owner removes that one
unverified staging directory by hand (it is not a MySQL path) and starts the same command again. A hard kill
(SIGKILL, power loss) during the upgrade can leave a `.g1-upgrade-tmp` file or a helper/manifest pair that is half
replaced; the sequence then stops (`leftover_temporary_file` / `installed_helper_or_manifest_unrecognized`) and needs a
manual look before any retry.


The upgrade tool checks before any write: the package files (hash-pinned, root-owned directory), the installed
helper/contract/manifest (GO-4b pins, owner, mode, no symlink), both G1 units inactive and no leftover temporary file.
Each file is replaced by a same-directory temporary file, `fsync` and atomic `rename`; then the new helper's
`verify_static_install()` **and** `check_base_config()` must pass, otherwise the old bytes are restored
(`ROLLED_BACK=yes`) and the exact code of the host's `/etc/mysql/my.cnf` problem is reported
(`verify_static_install_failed:<code>`). A second run reports `NOTE=already_upgraded`. State `328h2p` in
`g1-state-check.py` is the hotfix state after GO-5.1; `328hp` is the previous one (`328h` / `328h2` are the pre-GO-5.1 variants).

After PASS, GO-5.2 is repeated (`verify-install`, then `preflight --instance <each>`, see below). GO-5.1 is not.

**GO-5.2c — confined read-only probe (mandatory gate before GO-5.3).** AppArmor mediates the resolved path of a symlink.
`verify-install` runs MySQL unconfined and cannot show a profile gap. The probe `go5p-confined-probe.sh` runs only
`/usr/bin/my_print_defaults` (reads option files, starts no server, writes nothing) under each instance's enforcing
profile, prints no option value and reports `G1_GO52C_CONFINED_PROBE=PASS` only if both instances exit 0 and resolve
exactly their own `--datadir=` line. It is also the last step of the AppArmor hotfix sequence below and can be run
alone: `bash "$OPS/go5p-confined-probe.sh"`.

## GO-5.2c hotfix — AppArmor profiles (Owner approval; replaces four installed files, reloads two profiles)

Why: the GO-5.2c probe was denied for both instances (`apparmor="DENIED" ... name="/etc/mysql/mysql.cnf"
denied_mask="r"`): `/etc/mysql/my.cnf` is the Ubuntu alternatives symlink whose resolved target
`/etc/mysql/mysql.cnf` the generated profiles did not allow. The new contract generates both profiles with exactly one
extra line, `/etc/mysql/mysql.cnf r,` (read only, that file only; pinned: removing the line reproduces the previous profile
hashes). The helper (`32da9353…`), the option files and the systemd drop-ins are unchanged. `<MERGE_SHA>` is the 40-hex
merge commit of the hotfix pull request; the toolkit directory `OPS` is fetched by GO-0 from that same commit.

```bash
bash "$OPS/go5p-sequence.sh" <MERGE_SHA>                     # G1_GO52C_SEQUENCE=PASS
```

The sequence (a) checks the toolkit directory (`sha256sum -c --strict`, exact file count), the installed helper hash and
detects the installed contract by hash (hotfix-2 contract -> state `328h2p`; already upgraded -> `328ap`; else STOP),
(b) records `mysql.service` MainPID and the SHA-256 of `/etc/apparmor.d/usr.sbin.mysqld`, (c) verifies the state
(`g1-state-check.py`; `328h2p`/`328ap` require the exact post-GO-5.1 runtime paths and no socket/pid), (d) fetches the
package into `/root/wpcg-production-g1-mysql-ap-<sha7>` or re-verifies an existing directory with the fetch script's
checks, (e) `go5p-manifest-check.py`, (f) `go5p-upgrade-apparmor.py`, (g) `g1-state-check.py 328ap` and
`g1-effective-config-check.py`, (h) proves MainPID and the `usr.sbin.mysqld` profile hash unchanged (else STOP), (i) runs
the confined probe. It never runs `provision-paths`, `initialize` or `start`, never touches the MySQL directories, logs,
`mysql.service` (3306) or `/etc/mysql/my.cnf`.

`go5p-upgrade-apparmor.py` checks before any write: package hashes (contract, manifest), the profiles generated by exactly
those contract bytes (pinned), `apparmor_parser -Q -T` compile of both new profiles (no kernel load), the pinned helper,
contract/manifest/both profiles all OLD or all NEW, both units inactive, both profiles loaded in enforce, no leftover
temporary file. Then each file is replaced by a same-directory temporary file (`.dpkg-new` suffix in `/etc/apparmor.d`, which
the AppArmor loader ignores), `fsync` and atomic `rename`; exactly the two named profiles are reloaded with
`apparmor_parser -r`; the helper's `verify_static_install()` and `check_base_config()` must pass and both profiles must be
in enforce mode. Any failure (including: first profile reloaded, second reload fails) restores the four old files, reloads both
old profiles, re-checks that both are loaded in enforce mode and reports `ROLLED_BACK=yes|NO` (`NO` means a file, a reload or
the enforce check of the rollback itself failed: stop and look). After a failed upgrade the sequence prints
`G1_GO52C_BASELINE_UNCHANGED=yes|NO` (mysql.service MainPID, `usr.sbin.mysqld` file/local override/loaded profile).
Limit of the evidence: the kernel does not expose the loaded policy text, so "old policy restored" is shown by the reload of the old
files (`-r -T`, cache skipped) plus the enforce-mode re-check, not by comparing policy content; the confined probe is the functional proof. A second run reports `NOTE=already_upgraded` and re-reloads the two
profiles (heals an interruption between swap and reload). After a hard kill (SIGKILL, power loss) a leftover `*.dpkg-new` /
`*.g1-upgrade-tmp` file or a half-replaced set makes the tool and the sequence STOP (`leftover_temporary_file` /
`installed_files_unrecognized`): look before retrying. Rollback by hand is never needed for the normal failure paths.

If the probe still fails after PASS of the sequence, STOP before GO-5.3; nothing in this procedure initializes or starts
MySQL. GO-5.3 stays a separate Owner approval.

## GO-5.3 incident — interrupted `initialize` of `wpcaregrid-production` (recovery, read-only diagnosis)

**Proven on the VPS:** `initialize` stopped with `G1_MYSQL=STOP;CODE=command_unavailable_or_timeout` after the 180 s limit; the
`mysqld --initialize` process (PPID 1, 28 threads waiting) kept running for more than 7 minutes, created about 200 MB of data in the datadir (Owner's report)
(`mysql` directory, `auto.cnf`, redo/undo files) and an error log of only 655 bytes; the Owner stopped it with a verified
`SIGTERM`; the datadir is preserved, the restore instance was not initialized, `mysql.service` (MainPID 1131) is unchanged.

**Root causes (separated):**
* *Proven (reproduced by `InitializerRecoveryTest.test_the_old_subprocess_run_timeout_really_orphaned_the_child_chain`):* the helper ran the chain
  `runuser -> aa-exec -> mysqld` with the blocking run-with-timeout helper of `subprocess`; on a timeout that kills only its direct
  child (`runuser`), the rest is re-parented to init and keeps running, and nothing checked for it afterwards.
* *Proven by measurement (real MySQL 8.4.13):* a normal `--initialize` takes about 5 s and logs, in order,
  `MY-015017 Initialization - start`, `MY-013576 InnoDB initialization has started`, `MY-013577 ... has ended` and, last,
  `MY-015018 Initialization - end`. `mysql`, `auto.cnf` and the other artefacts appear long before the end marker, so their presence
  proves nothing about completion.
* *Not proven (hypothesis):* why this run stalled. A 655-byte log is consistent with a stall inside InnoDB initialization (start lines present,
  no `has ended`). Candidates are an AppArmor denial, storage/AIO limits or memory limits; the diagnostic below prints the
  kernel's `wpcaregrid-mysql` AppArmor denials and the log marker flags to decide this without guessing.
* The old success test looked only at the existence of `mysql/` and `auto.cnf` after an exit code 0; the new proof (below) does not.

**Fix in the helper (`production-g1-mysql.py`, no longer a simple timeout increase):**
* the chain runs in its own session; on timeout, on `SIGTERM`/`SIGHUP`/`SIGINT` of the helper and on an orphan left after exit
  the helper sends `SIGTERM`, then `SIGKILL`, to that process group, verifies by `/proc` that neither the group nor any process naming the
  instance's `--defaults-group-suffix` is left, and stops with a distinct code:
  `initialize_timeout_terminated_partial_datadir`, `initialize_interrupted_partial_datadir`,
  `initialize_orphan_process_after_exit`, `initialize_failed_partial_datadir` (non-zero exit) or `initialize_process_survived`;
  all of them print `INITIALIZE_DATADIR=PRESERVED;INITIALIZE_RETRY=NOT_ATTEMPTED`. The limit is 600 s (it was 180 s; a real run takes seconds);
* a second start is refused while any process of the instance exists (`initialize_process_already_running`) and while the error log is not empty (`initialize_log_not_empty`, so the proof can only come from this run's markers);
* success needs proof (`initialize_completion_not_proven` otherwise, with `INITIALIZE_PROOF_MISSING=<token>`): exit 0, the exact datadir
  contents of a finished initialization (no symlink/special file/foreign owner anywhere in the tree), the server's own start line followed by its end
  line in the error log (never printed: it contains the generated root password), no `[ERROR]` line, log `mysql:mysql 0600`, no process, no socket/pid file;
* a datadir that already holds initialization artefacts is refused with `datadir_initialization_present` (other content: `datadir_not_empty`),
  so an accidental second initialization is impossible. Nothing is ever deleted, cleaned or restarted by the helper.

**Read-only diagnostic (`go53-init-state.py`, toolkit; root; no argument; writes, signals and starts nothing):**

```bash
python3 -I -B "$OPS/go53-init-state.py"
```

It prints per instance: entry count, size, the first 40 top-level names, log size and marker flags (`start`, `end`, `innodb_started`,
`innodb_ended`, `errors`), process and socket/pid markers, missing required entries and the `STATE`, then the kernel AppArmor denials
of the two profiles (distinct paths only) and a final `G1_GO53_INIT_STATE=DONE;WPCAREGRID_PRODUCTION=<state>;WPCAREGRID_PRODUCTION_RESTORE=<state>`.
States: `EMPTY` (exactly as `provision-paths` left it), `PROVEN_COMPLETE` (proof above), `PARTIAL` (sane, artefacts present, end marker missing),
`UNCLEAR` (anything else, e.g. symlink, foreign owner/mode, running process, end marker without the files, error lines): completion is **not**
proven for `PARTIAL` and `UNCLEAR`; STOP. Expected for the incident: production `PARTIAL` with `innodb_started=yes innodb_ended=no`, restore `EMPTY`.

**Recovery plan (every step a separate Owner decision; nothing automatic):**
1. Run the diagnostic. `PROVEN_COMPLETE` would allow `verify-install`/`preflight` and a later GO-5.4; `PARTIAL`/`UNCLEAR` stays STOP.
2. Find out *why* the run stalled before any retry (denials in the diagnostic, then `journalctl -k` for the AppArmor profile, storage and memory limits). A retry
   without a cause is not approved.
3. The fixed helper must be installed (replaces helper and manifest only, hash-pinned, like the earlier hotfixes) before `initialize` runs again; the installed
   helper still has the 180 s blind timeout. That installer is a separate follow-up change.
4. A `PARTIAL` datadir contains only system tables created by the killed run (the instance never started, no application data). Cleaning it is a
   deliberate Owner decision with its own narrowly scoped, verified procedure (state `PARTIAL`, units inactive, no process, exact path, no symlink; it must
   also reset the instance error log to an empty `mysql:mysql 0600` file, because `initialize` refuses a non-empty log with `initialize_log_not_empty`); the helper and the
   toolkit contain no such step and never will remove the datadir on their own. Until then the datadir stays untouched and `initialize` is refused with `datadir_initialization_present`.
5. GO-5.4 (start) and GO-5.5 stay HOLD; `mysql.service`, 3306, `/etc/mysql/my.cnf`, `/etc/apparmor.d/usr.sbin.mysqld` and the restore datadir are never touched.

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
