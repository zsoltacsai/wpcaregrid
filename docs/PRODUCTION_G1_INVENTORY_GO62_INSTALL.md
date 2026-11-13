# Production G1 — GO-6.2 inventory package installation runbook

**Status: preparation only. Do not execute this runbook without a separate,
exact-scope Owner host-install GO.** This document does not authorize helper
installation, helper execution, privilege expansion, service changes, MySQL
changes, AppArmor changes, migration, backup restore, or deployment.

The helper is read-only, but installation writes files under
`/usr/local/libexec`, and its output contains production infrastructure
metadata. The helper has no lock of its own and cannot prove that it was started
from a root login rather than another UID-0 context. A designated operator must
perform this procedure from the specifically approved root-console session.
Do not use the deploy account, add sudoers rules, or create another privileged
access path to make this procedure easier.

## 1. Exact release and artifact pins

All artifacts below are from protected primary commit
`ed6ce061d90aa9045c611922c380778d68d0a7ac`, tree
`065cb0f9cc45320745aa94e2d3c84d7bcd46de81`.

| File | Bytes | SHA-256 | Installed? |
| --- | ---: | --- | --- |
| `go62-inventory.py` | 72097 | `63bf9843b6949089c7a8dd05a1da553fb2c3a2b72a40abe66bd27b82fc1cdbca` | Yes, mode 0644 |
| `go62-inventory-manifest.json` | 1299 | `4fd7e24144c75f76432948a8546c880a2f44fb14e56929926fc13d5fb4858d9f` | Yes, mode 0644 |
| `SHA256SUMS` | 179 | `52348801ddf7470eb8afd68f50ee7838a7a706847710cf56824213ac45e855de` | No; kept in root-only handoff |

The `SHA256SUMS` file covers the helper and manifest but not itself. Therefore
the operator must verify the three pinned hashes above from this reviewed
document or another approved, independently authenticated channel. Do not trust
a transferred `SHA256SUMS` file by itself.

## 2. Prepare package in a trusted checkout (unprivileged)

Run on a trusted development/operator checkout, never on the production host.
The output directory must not already exist.

```sh
set -eu
umask 077
commit=ed6ce061d90aa9045c611922c380778d68d0a7ac
tree=065cb0f9cc45320745aa94e2d3c84d7bcd46de81
test "$(git rev-parse "$commit^{commit}")" = "$commit"
test "$(git rev-parse "$commit^{tree}")" = "$tree"
pkg="$PWD/go62-inventory-package"
test ! -e "$pkg" && test ! -L "$pkg"
mkdir -m 0700 "$pkg"
for f in go62-inventory.py go62-inventory-manifest.json SHA256SUMS; do
  git show "$commit:scripts/production-g1-inventory/$f" > "$pkg/$f"
done
(
  cd "$pkg"
  sha256sum -c --strict SHA256SUMS
  printf '%s  %s\n' \
    63bf9843b6949089c7a8dd05a1da553fb2c3a2b72a40abe66bd27b82fc1cdbca go62-inventory.py \
    4fd7e24144c75f76432948a8546c880a2f44fb14e56929926fc13d5fb4858d9f go62-inventory-manifest.json \
    52348801ddf7470eb8afd68f50ee7838a7a706847710cf56824213ac45e855de SHA256SUMS \
    | sha256sum -c --strict -
  test "$(stat -c %s go62-inventory.py)" = 72097
  test "$(stat -c %s go62-inventory-manifest.json)" = 1299
  test "$(stat -c %s SHA256SUMS)" = 179
)
```

Transfer the three files only through the separately approved operator channel.
Do not transfer inventory output, secrets, customer data, or environment files.

## 3. Root-console handoff verification

Run all command snippets from §4 through §8 in one Bash root-console shell so the
private shell variables and fixed helper functions remain in scope. The Owner
GO must identify the target host and an approved root console. Verify
the live host identity using the approved console before continuing. The helper
package is not itself permission to access the host.

Create the handoff directory only if absent. Place exactly the three package
files there using the approved transfer process, then verify:

```sh
set -eu
umask 077
H_HELPER=63bf9843b6949089c7a8dd05a1da553fb2c3a2b72a40abe66bd27b82fc1cdbca
H_MANIFEST=4fd7e24144c75f76432948a8546c880a2f44fb14e56929926fc13d5fb4858d9f
H_SUMS=52348801ddf7470eb8afd68f50ee7838a7a706847710cf56824213ac45e855de
handoff=/root/go62-handoff
PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PATH
test ! -e "$handoff" && test ! -L "$handoff"
mkdir -m 0700 "$handoff"
# Place go62-inventory.py, go62-inventory-manifest.json and SHA256SUMS here.
cd "$handoff"
test "$(find . -mindepth 1 -maxdepth 1 -printf '%f\n' | LC_ALL=C sort | tr '\n' ' ')" = \
  'SHA256SUMS go62-inventory-manifest.json go62-inventory.py '
for f in *; do test -f "$f" && test ! -L "$f"; done
chown -R root:root "$handoff"
chmod 0700 "$handoff"
chmod 0600 "$handoff"/*
test "$(stat -c '%U:%G %a' "$handoff")" = 'root:root 700'
for f in SHA256SUMS go62-inventory-manifest.json go62-inventory.py; do
  test "$(stat -c '%U:%G %a' "$f")" = 'root:root 600'
done
printf '%s  %s\n' "$H_HELPER" go62-inventory.py \
  "$H_MANIFEST" go62-inventory-manifest.json "$H_SUMS" SHA256SUMS \
  | sha256sum -c --strict -
sha256sum -c --strict SHA256SUMS
test "$(stat -c %s go62-inventory.py)" = 72097
test "$(stat -c %s go62-inventory-manifest.json)" = 1299
test "$(stat -c %s SHA256SUMS)" = 179
```

Any existing path, extra file, symlink, owner/mode mismatch, size mismatch, or
hash mismatch means **STOP**. Preserve the evidence. Do not delete or overwrite
anything to make the checks pass.

## 4. Blocking preflight

Do not create `/usr/local/libexec` if it is absent; that is a separate host
change. Check every parent component used by the target path for symlinks,
root ownership, and no group/other write permission. Confirm `/usr/local/libexec`
itself exists as a real directory owned by `root:root` and is not group/world
writable. Confirm the final target and staging-name pattern are absent.

The following are checks only; they do not install or alter anything:

```sh
set -eu
umask 077
PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PATH
test "$(id -u)" -eq 0
test "$(id -un)" = root
for d in /usr /usr/local /usr/local/libexec; do
  test -d "$d" && test ! -L "$d"
  test "$(stat -c '%U:%G' "$d")" = root:root
  mode=$(stat -c '%a' "$d")
  test $(( 0$mode & 022 )) -eq 0
done
target=/usr/local/libexec/wpcaregrid-g1-inventory
test ! -e "$target" && test ! -L "$target"
if compgen -G '/usr/local/libexec/.go62-stage.*' >/dev/null; then
  echo 'STOP: stale staging directory exists'; exit 1
fi
test -S /dev/log
test -d /run/systemd/system
test -x /usr/bin/python3
test "$(/usr/bin/python3 -c 'import sys; print(sys.version_info >= (3, 11))')" = True
for x in /usr/bin/systemctl /usr/bin/journalctl /usr/bin/my_print_defaults \
         /usr/bin/php /usr/sbin/php-fpm8.4 /usr/sbin/nginx /usr/sbin/mysqld; do
  test -x "$x" || { echo "STOP: required fixed command unavailable: $x"; exit 1; }
done
/usr/bin/systemctl is-active --quiet systemd-journald
for x in /usr/bin/install /usr/bin/mktemp /usr/bin/stat /usr/bin/find \
         /usr/bin/sha256sum /usr/bin/chown /usr/bin/chmod /usr/bin/grep \
         /usr/bin/sort /usr/bin/tr /usr/bin/wc /usr/bin/date; do
  test -x "$x" || { echo "STOP: required installation utility unavailable: $x"; exit 1; }
done
```

`my_print_defaults` is required to assess G1 option-file settings. Do not install
packages or alter PATH to satisfy this preflight. If it or any fixed command is
missing, stop and report the exact missing prerequisite.

The operator must also verify from the approved operational source that no
deployment, migration, backup, or other installation transaction conflicts
with this short file operation. Do not acquire, clear, or modify a production
lock. If the target host, root-console authorization, parent-directory
permissions, or transaction state is uncertain, **STOP**.

The helper can read up to 16 backups, each with bounded compressed and
decompressed sizes. Schedule its later inventory run for a quiet period. Do not
run the inventory as part of installation CI or a deployment workflow.

## 5. Create and verify a private staging directory

`/usr/local/libexec` must already pass §4. Keep staging on that same filesystem.
The installed directory is `root:root 0700`; its only two files are the helper
and manifest, both `root:root 0644`. The private directory is important because
Python versions before 3.11 do not give `-I` the same script-path isolation.
This is not the existing GO-5 MySQL startup helper at
`/usr/local/libexec/wpcaregrid-g1-mysql.py` (mode 0755); do not substitute or
modify that helper.

```sh
set -eu
umask 077
H_HELPER=63bf9843b6949089c7a8dd05a1da553fb2c3a2b72a40abe66bd27b82fc1cdbca
H_MANIFEST=4fd7e24144c75f76432948a8546c880a2f44fb14e56929926fc13d5fb4858d9f
stage=$(mktemp -d -p /usr/local/libexec .go62-stage.XXXXXXXX)
test -d "$stage" && test ! -L "$stage"
chown root:root "$stage"
chmod 0700 "$stage"
install -o root -g root -m 0644 /root/go62-handoff/go62-inventory.py "$stage/go62-inventory.py"
install -o root -g root -m 0644 /root/go62-handoff/go62-inventory-manifest.json "$stage/go62-inventory-manifest.json"
test "$(stat -c '%U:%G %a' "$stage")" = 'root:root 700'
test "$(stat -c '%U:%G %a' "$stage/go62-inventory.py")" = 'root:root 644'
test "$(stat -c '%U:%G %a' "$stage/go62-inventory-manifest.json")" = 'root:root 644'
test "$(stat -c %s "$stage/go62-inventory.py")" = 72097
test "$(stat -c %s "$stage/go62-inventory-manifest.json")" = 1299
test "$(find "$stage" -mindepth 1 -maxdepth 1 -printf '%f\n' | LC_ALL=C sort | tr '\n' ' ')" = \
  'go62-inventory-manifest.json go62-inventory.py '
for f in "$stage"/*; do test -f "$f" && test ! -L "$f"; done
printf '%s  %s\n' "$H_HELPER" go62-inventory.py "$H_MANIFEST" go62-inventory-manifest.json \
  | (cd "$stage" && sha256sum -c --strict -)
/usr/bin/python3 -I - "$stage/go62-inventory-manifest.json" "$H_HELPER" <<'PY'
import json, sys
with open(sys.argv[1], encoding="utf-8") as f:
    m = json.load(f)
assert m["schema"] == 1
assert m["package"] == "wpcaregrid-g1-inventory"
assert m["version"] == "1.0.4"
assert m["tool"] == "go62-production-inventory"
assert m["arguments"] == "NONE"
assert m["files"] == [{
    "name": "go62-inventory.py",
    "install_path": "/usr/local/libexec/wpcaregrid-g1-inventory/go62-inventory.py",
    "owner": "root:root", "mode": "0644", "sha256": sys.argv[2],
}]
PY
```

The manifest's single `files` entry describes the helper only. Its own SHA-256
is the separate out-of-band `H_MANIFEST` pin; do not claim the manifest
self-hashes. `SHA256SUMS` stays in `/root/go62-handoff` and is not installed.
Define these fixed, local helpers in the same root-console shell. They use only
absolute interpreter paths, fixed options and the two literal source/target
arguments. If `renameat2` is unavailable or refuses a move, they fail; never
fall back to a replacing rename.

```sh
rename_noreplace() {
  /usr/bin/python3 -I - "$1" "$2" <<'PY'
import ctypes, os, sys
src, dst = sys.argv[1:]
libc = ctypes.CDLL("libc.so.6", use_errno=True)
try:
    renameat2 = libc.renameat2
except AttributeError:
    raise SystemExit("STOP: renameat2 unavailable")
renameat2.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
renameat2.restype = ctypes.c_int
if renameat2(-100, os.fsencode(src), -100, os.fsencode(dst), 1) != 0:
    err = ctypes.get_errno()
    raise SystemExit("STOP: renameat2 failed: " + os.strerror(err))
PY
}
fsync_paths() {
  /usr/bin/python3 -I - "$@" <<'PY'
import os, stat, sys
for path in sys.argv[1:]:
    st = os.lstat(path)
    if stat.S_ISLNK(st.st_mode) or not (stat.S_ISREG(st.st_mode) or stat.S_ISDIR(st.st_mode)):
        raise SystemExit("STOP: fsync target type invalid")
    flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC
    if stat.S_ISDIR(st.st_mode):
        flags |= os.O_DIRECTORY
    fd = os.open(path, flags)
    try:
        opened = os.fstat(fd)
        if (opened.st_dev, opened.st_ino) != (st.st_dev, st.st_ino):
            raise SystemExit("STOP: fsync target changed")
        os.fsync(fd)
    finally:
        os.close(fd)
PY
}
```

Fsync both staged files and the staging directory before promotion. On any
failure, leave the stage in place and preserve it for review; do not recursively
delete it.

## 6. Atomic no-replace promotion

Do not use plain `mv`, `mv -T`, `os.rename`, or POSIX `rename(2)` for promotion;
those can replace an existing empty directory. Use Linux `renameat2` with
`RENAME_NOREPLACE`. If libc/kernel support is unavailable or any command fails,
**STOP** without fallback.

Immediately before the command, recheck that the target remains absent and that
stage and target parent are on the same filesystem:

```sh
set -eu
target=/usr/local/libexec/wpcaregrid-g1-inventory
test ! -e "$target" && test ! -L "$target"
test "$(stat -c %d "$stage")" = "$(stat -c %d /usr/local/libexec)"
fsync_paths "$stage/go62-inventory.py" "$stage/go62-inventory-manifest.json" "$stage"
rename_noreplace "$stage" "$target"
fsync_paths /usr/local/libexec
```

The target directory must now be `root:root 0700`, contain exactly the helper
and manifest at `root:root 0644`, and both files must match the out-of-band
hashes. Confirm the staging path no longer exists. Any difference is **STOP**;
preserve evidence and do not overwrite or remove anything.

```sh
test ! -e "$stage" && test ! -L "$stage"
test -d "$target" && test ! -L "$target"
test "$(stat -c '%U:%G %a' "$target")" = 'root:root 700'
test "$(find "$target" -mindepth 1 -maxdepth 1 -printf '%f\n' | LC_ALL=C sort | tr '\n' ' ')" = \
  'go62-inventory-manifest.json go62-inventory.py '
test "$(stat -c '%U:%G %a' "$target/go62-inventory.py")" = 'root:root 644'
test "$(stat -c '%U:%G %a' "$target/go62-inventory-manifest.json")" = 'root:root 644'
printf '%s  %s\n' "$H_HELPER" "$target/go62-inventory.py" \
  "$H_MANIFEST" "$target/go62-inventory-manifest.json" | sha256sum -c --strict -
```

## 7. Before each authorized invocation

This section is **not** authorization to run the helper. A later, separate
Owner GO must specify the single read-only invocation, output handling and
reviewer. Before every run, re-check that the installed directory remains
`root:root 0700`, contains exactly the two expected files, and the files remain
regular non-symlinks with the pinned owner, mode, size and SHA-256. Verify using
the out-of-band pins, not an installed checksum file:

```sh
set -eu
H_HELPER=63bf9843b6949089c7a8dd05a1da553fb2c3a2b72a40abe66bd27b82fc1cdbca
H_MANIFEST=4fd7e24144c75f76432948a8546c880a2f44fb14e56929926fc13d5fb4858d9f
target=/usr/local/libexec/wpcaregrid-g1-inventory
test -d "$target" && test ! -L "$target"
test "$(stat -c '%U:%G %a' "$target")" = 'root:root 700'
test "$(find "$target" -mindepth 1 -maxdepth 1 -printf '%f\n' | LC_ALL=C sort | tr '\n' ' ')" = \
  'go62-inventory-manifest.json go62-inventory.py '
for f in "$target/go62-inventory.py" "$target/go62-inventory-manifest.json"; do
  test -f "$f" && test ! -L "$f"
done
test "$(stat -c '%U:%G %a' "$target/go62-inventory.py")" = 'root:root 644'
test "$(stat -c '%U:%G %a' "$target/go62-inventory-manifest.json")" = 'root:root 644'
test "$(stat -c %s "$target/go62-inventory.py")" = 72097
test "$(stat -c %s "$target/go62-inventory-manifest.json")" = 1299
printf '%s  %s\n' "$H_HELPER" "$target/go62-inventory.py" \
  "$H_MANIFEST" "$target/go62-inventory-manifest.json" | sha256sum -c --strict -
ev=/root/go62-evidence-$(date -u +%Y%m%dT%H%M%SZ)
test ! -e "$ev" && test ! -L "$ev"
mkdir -m 0700 "$ev"
test "$(stat -c '%U:%G %a' "$ev")" = 'root:root 700'
invocation_started=$(date --utc --iso-8601=seconds)
set +e
/usr/bin/python3 -I -B "$target/go62-inventory.py" > "$ev/report.json" 2> "$ev/report.err"
rc=$?
set -e
chmod 0600 "$ev/report.json" "$ev/report.err"
printf '%s\n' "$rc" > "$ev/process-exit.txt"
chmod 0600 "$ev/process-exit.txt"
test ! -s "$ev/report.err"
/usr/bin/python3 -I - "$ev/report.json" "$H_HELPER" "$rc" <<'PY'
import json, sys
expected_sections = {
    "runtime", "g1_systemd", "g1_artifacts", "apparmor", "mysql",
    "release", "journals", "backups", "restore", "locks",
}
with open(sys.argv[1], encoding="utf-8") as f:
    report = json.load(f)
assert report["schema"] == 1
assert report["tool"] == "go62-production-inventory"
assert report["version"] == "1.0.4"
assert set(report["sections"]) == expected_sections
assert report["exit_code"] == int(sys.argv[3])
assert report["overall"] in {"PASS", "FAIL", "UNKNOWN", "UNAVAILABLE"}
assert isinstance(report["audit"].get("invocation_id"), str)
assert report["audit"]["invocation_id"]
assert all(section["status"] in {"PASS", "FAIL", "UNKNOWN", "UNAVAILABLE"}
           for section in report["sections"].values())
audit = report["audit"]
assert audit["mode"] == "READ_ONLY" and audit["arguments"] == "NONE"
assert audit["uid"] == 0 and audit["euid"] == 0
assert audit["helper_sha256"] == sys.argv[2]
assert audit["persistent"] == "ATTEMPTED_UNVERIFIED"
assert audit["status"] == "UNKNOWN"
PY
```

Use the full interpreter path and isolation flags, from the specifically
approved root-console session. Save output only in a root-only evidence
directory; never paste raw JSON into GitHub, CI, tickets, chat or public logs.
Run during a quiet period because bounded backup hashing/decompression can
consume I/O. Do not run concurrently with another deployment, migration,
backup or inventory operation.
The helper's `/usr/sbin/mysqld --version` query may itself be subject to the
loaded vendor AppArmor profile on a particular host. Treat a correlated denial
as evidence; do not alter or reload AppArmor to make the inventory succeed.

Expected schema: `schema=1`, `tool=go62-production-inventory`,
`version=1.0.4`, with exactly these ten section names:
`runtime`, `g1_systemd`, `g1_artifacts`, `apparmor`, `mysql`, `release`,
`journals`, `backups`, `restore`, `locks`. All section statuses must be one of
`PASS`, `FAIL`, `UNKNOWN`, `UNAVAILABLE`.

When no section is `FAIL`, expect exit code `4` and `overall=UNKNOWN` because
audit status is always `UNKNOWN`; a real `FAIL` section can override this and
produce exit code `3`. Privilege and trigger facts remain `UNKNOWN`, and
`restore_proof=NOT_PROVEN` / `live_restore_validation=NOT_PERFORMED`. Validate
the report/process exit-code agreement, exact section set, status enum,
`audit.mode=READ_ONLY`, `audit.arguments=NONE`, `audit.uid=0`, `audit.euid=0`,
and `audit.helper_sha256` against the independently verified helper pin. UID 0
does not prove which console path was used. A production health `FAIL` is a
valid inventory result: preserve it and HOLD; do not treat it as an install
failure or attempt a service repair under this procedure.

An inventory section with `FAIL` is a production finding requiring HOLD and
review; it does not justify restarting a service or changing database or
AppArmor state. `UNAVAILABLE` or unexpected `UNKNOWN` means incomplete
evidence. A malformed report, helper hash mismatch, `audit.persistent` other
than `ATTEMPTED_UNVERIFIED`, or inability to verify both audit records is a
smoke-test failure. Exit 4 is expected and is not a production readiness PASS.

Verify the start and finish records in the system journal using trusted journal
metadata (`_UID=0` and `SYSLOG_IDENTIFIER=wpcaregrid-g1-inventory`) together
with the report's invocation ID. Message text alone is not provenance: another
process can submit similar text, and the helper's own hash is self-reported.
Keep raw journal output in the same root-only evidence directory.

```sh
iid=$(/usr/bin/python3 -I -c 'import json,sys; print(json.load(open(sys.argv[1], encoding="utf-8"))["audit"]["invocation_id"])' "$ev/report.json")
journalctl _UID=0 SYSLOG_IDENTIFIER=wpcaregrid-g1-inventory --no-pager -o cat --since "$invocation_started" > "$ev/journal.txt"
chmod 0600 "$ev/journal.txt"
test "$(grep -c "invocation_id=$iid" "$ev/journal.txt")" -eq 2
grep -F "invocation_id=$iid" "$ev/journal.txt" | grep -F 'event=start' >/dev/null
grep -F "invocation_id=$iid" "$ev/journal.txt" | grep -F 'event=finish' >/dev/null
grep -F "invocation_id=$iid" "$ev/journal.txt" | grep -F "helper_sha256=$H_HELPER" >/dev/null
```

## 8. Rollback to quarantine (only after this package was promoted)

Rollback is a filesystem quarantine operation only. It does not remove files or
touch services, MySQL, AppArmor, sudoers, deployment state or backups. Confirm
the operator has stopped using the helper and no invocation is running; no
process lock exists, so if concurrency cannot be ruled out, **STOP**. Verify
that the installed path is still the exact reviewed directory and hashes. Do
not move or delete an unknown or modified directory.

Choose a new quarantine name and use the same `renameat2(RENAME_NOREPLACE)`
routine from §6 so an existing quarantine path is never replaced:

```sh
set -eu
H_HELPER=63bf9843b6949089c7a8dd05a1da553fb2c3a2b72a40abe66bd27b82fc1cdbca
H_MANIFEST=4fd7e24144c75f76432948a8546c880a2f44fb14e56929926fc13d5fb4858d9f
dir=/usr/local/libexec/wpcaregrid-g1-inventory
test -d "$dir" && test ! -L "$dir"
test "$(stat -c '%U:%G %a' "$dir")" = 'root:root 700'
test "$(find "$dir" -mindepth 1 -maxdepth 1 -printf '%f\n' | LC_ALL=C sort | tr '\n' ' ')" = \
  'go62-inventory-manifest.json go62-inventory.py '
test "$(stat -c '%U:%G %a' "$dir/go62-inventory.py")" = 'root:root 644'
test "$(stat -c '%U:%G %a' "$dir/go62-inventory-manifest.json")" = 'root:root 644'
for f in "$dir"/*; do test -f "$f" && test ! -L "$f"; done
printf '%s  %s\n' "$H_HELPER" go62-inventory.py \
  "$H_MANIFEST" go62-inventory-manifest.json | (cd "$dir" && sha256sum -c --strict -)
q=/usr/local/libexec/.go62-quarantine-$(date -u +%Y%m%dT%H%M%SZ)
test ! -e "$q" && test ! -L "$q"
/usr/bin/python3 -I - "$dir" "$q" <<'PY'
import ctypes, os, sys
src, dst = sys.argv[1:]
libc = ctypes.CDLL("libc.so.6", use_errno=True)
try:
    renameat2 = libc.renameat2
except AttributeError:
    raise SystemExit("STOP: renameat2 unavailable")
renameat2.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
renameat2.restype = ctypes.c_int
if renameat2(-100, os.fsencode(src), -100, os.fsencode(dst), 1) != 0:
    err = ctypes.get_errno()
    raise SystemExit("STOP: renameat2 failed: " + os.strerror(err))
fd = os.open("/usr/local/libexec", os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
try:
    os.fsync(fd)
finally:
    os.close(fd)
PY
test ! -e "$dir" && test ! -L "$dir"
test -d "$q" && test ! -L "$q"
printf '%s  %s\n' "$H_HELPER" "$q/go62-inventory.py" \
  "$H_MANIFEST" "$q/go62-inventory-manifest.json" | sha256sum -c --strict -
```

The quarantine remains root-owned and preserved. Do not delete it under this
procedure. If directory contents, permissions or hashes differ, **STOP** and
preserve evidence for review.

## 9. Explicitly prohibited under this preparation

Do not install or execute the helper, create `/usr/local/libexec`, add sudoers
rules, install a missing package, access a database, start/reset/restart a G1
unit, alter MySQL settings, reload/change AppArmor, run migrations, restore a
backup, deploy application code, or change firewall/service configuration.
Each requires its own authorization and applicable safety gates.
