#!/usr/bin/env python3
"""GO-5.4a read-only host readiness evidence collector (pretransaction preflight).

Strictly read-only: writes nothing, starts or stops nothing, loads no AppArmor
profile, never starts or initializes MySQL, and reads no credential or option
file. It mirrors the pinned GO-5.4a transaction constants in
scripts/production-g1-ops/go54a-upgrade-apparmor.py (verified byte-identical at
commit 0951d9e829364296796a1d4acd303feef698cbc7 and current primary
ef4e1d1ea6f4bc928d52bf06137ca242312e23a7) and reports the host-technical
preflight the upgrader itself will demand:

  both G1 units exactly inactive, the five installed artifacts exactly at the
  OLD pins (NEW is reported as already-upgraded, never as ready), both G1
  AppArmor profiles enforced, vendor mysql.service identity and MainPID stable,
  both datadirs/logs preserved (combined fingerprints only, no raw content),
  no active or interrupted GO-5.4a transaction (journal, temporary files,
  transaction lock), and explicit validation of any staged GO-5.4a package.

Not checked here on purpose (notes say so): apparmor_parser compilation, the
generated-profile hashes and the package acceptance check that the transaction
tool itself performs. Backup restore evidence is NOT verifiable by a host read
and is reported as UNKNOWN with a pointer to the Rackforest checklist; a
backup's existence alone never produces BACKUP_VERIFIED=PASS.

This tool lives in scripts/production-g1-diagnostics/ with its own SHA256SUMS.
The scripts/production-g1-ops/ transaction toolkit is a frozen, GO-0-anchored
directory and is never modified or extended by this change. When both
directories are staged together, the toolkit companions beside this tool (the
upgrader, the fetch script and the ops SHA256SUMS) are verified against their
reviewed pins; a mismatched or partial companion set is a HOLD, and nothing
unrecognized is accepted implicitly.

Classification is fail-closed: every incomplete evidence item is HOLD or
UNKNOWN, the verdict is the LAST line, and HOLD/UNKNOWN exit non-zero.

The readiness verdict is ADVISORY, NON-ATOMIC evidence: the four critical
transaction-relevant checks are re-observed at the end of the run and any
deviation is reported as stale, but a read-only collector cannot serialize
with a concurrent transaction. PASS never asserts that no transaction is
active or will start — the Owner must serialize evidence collection against
any transaction (the GO-5.4a preflight procedure step 2 requires
this sequencing explicitly).

Local synthetic test passes do NOT make this tool approved; independent
Integration QA and AppSec PASS records plus a separate Owner GO are
required before any GO-5.4a transaction.

Usage: python3 -I -B go54a-readiness.py [--package DIR]
"""

import errno
import fcntl
import hashlib
import os
import pwd
import grp
import re
import stat
import subprocess
import sys

TOOL = "go54a-readiness"
VERSION = "1.9.0"

LIBEXEC = "/usr/local/libexec"
APPARMOR = "/etc/apparmor.d"
HELPER = LIBEXEC + "/wpcaregrid-g1-mysql.py"
CONTRACT = LIBEXEC + "/production_g1_mysql_contract.py"
MANIFEST = LIBEXEC + "/production_g1_mysql_manifest.json"
PROFILE_P = APPARMOR + "/wpcaregrid-mysql-production"
PROFILE_R = APPARMOR + "/wpcaregrid-mysql-production-restore"
VENDOR_PROFILE = APPARMOR + "/usr.sbin.mysqld"
LOADED = "/sys/kernel/security/apparmor/profiles"
DATADIRS = ("/var/lib/mysql-wpcaregrid-production", "/var/lib/mysql-wpcaregrid-production-restore")
LOGS = ("/var/log/mysql/wpcaregrid-production.log", "/var/log/mysql/wpcaregrid-production-restore.log")
UNITS = ("mysql@wpcaregrid-production.service", "mysql@wpcaregrid-production-restore.service")
VENDOR_UNIT = "mysql.service"
LOCK = "/run/wpcaregrid-go54a-apparmor.lock"
LOCK_DIR = "/run"
JOURNAL = LIBEXEC + "/.go54a-apparmor-upgrade.json"
ROOT_DIR = "/root"
FETCH_PREFIX = "wpcg-go54a-"
INCOMING_SUFFIX = ".incoming-UNVERIFIED"

OLD = {
    HELPER: "27c7e1e6ba8536f7f64e9a4bd53ed31fe846678ff05b0a051ca2ca7abe705e1b",
    CONTRACT: "270a7f2ddc93a38bbabc94197e11fa078602004b39c18a2c86a34bf4c6f05f8f",
    MANIFEST: "0af365c9515e2eff34d552c185c8d46fff3a5d5f7cade6d1ab3c0de1809ef911",
    PROFILE_P: "9a40053158880f0e6baf0db06431587651701487910634015e712e878dcfe13d",
    PROFILE_R: "ce56847e98f23b45590aaf55ad069df89f97dc12b672c2a289bb0fa7fb923b3e",
}
NEW = {
    HELPER: "9ff1b969fa2371d3967c15ff9f6fcf4553ecf5fcda2b039b46e924d885bbf274",
    CONTRACT: "0023be227381e617f482c1e905cd0019c1fba604a7aa7050345a95f208c89cf8",
    MANIFEST: "eef752c71e15c3a41f1cf5948305b699a5eba6a91b66aeae243787ba9e4e5f9b",
    PROFILE_P: "28e02508e195efe285be40932df74b9e6f3c6aa792250ef67c078da40699f7d2",
    PROFILE_R: "9567eab67e96d352ab971ca26adafe4e35bc5e12891ef788147ff673bf7cfbab",
}
PACKAGE = {
    HELPER: "production-g1-mysql.py",
    CONTRACT: "production_g1_mysql_contract.py",
    MANIFEST: "production-g1-mysql-manifest.json",
}
MODES = {HELPER: 0o755, CONTRACT: 0o644, MANIFEST: 0o644, PROFILE_P: 0o644, PROFILE_R: 0o644}
ORDER = (CONTRACT, MANIFEST, HELPER, PROFILE_P, PROFILE_R)
PROFILE_NAMES = {PROFILE_P: "wpcaregrid-mysql-production", PROFILE_R: "wpcaregrid-mysql-production-restore"}
VENDOR_PROFILE_NAME = "/usr/sbin/mysqld"

# Toolkit companions pinned by scripts/production-g1-ops/SHA256SUMS at the reviewed commit.
EXPECTED_UPGRADER_SHA = "1cef57b9cd244a2b78818c4731c3b439fe9feb531c691ea898ca3e15c250908a"
EXPECTED_FETCH_SHA = "9b454cfabce3cf17f77ad72bca139358191f352c598823dbc71e2fa22735ad75"
EXPECTED_OPS_SUMS_SHA = "70f92e3a2aba84ca13f5d6c81f732be605c50a09531308878991f7b671c48a9e"
UPGRADER_NAME = "go54a-upgrade-apparmor.py"
FETCH_NAME = "go54a-fetch-package.sh"
OPS_SUMS_NAME = "SHA256SUMS"
TOOLKIT_DIR_NAME = "production-g1-ops"

MAX_FILE_BYTES = 64 * 1024 * 1024
MAX_LOG_BYTES = 64 * 1024 * 1024
MAX_DATADIR_ENTRIES = 50000
MAX_DATADIR_BYTES = 2 * 1024 * 1024 * 1024
MAX_PROFILE_SOURCE_BYTES = 4 * 1024 * 1024
COMMAND_TIMEOUT = 60
MUTATION_IDENTITY = ("st_dev", "st_ino", "st_size", "st_mtime_ns", "st_ctime_ns")
ENV = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "HOME": "/nonexistent", "LANG": "C", "LC_ALL": "C"}


class HostError(Exception):
    def __init__(self, code, detail=""):
        super().__init__(code if not detail else code + ":" + str(detail))
        self.code, self.detail = code, str(detail)


class UnsafeState(HostError):
    """A detected host state that violates a GO-5.4a precondition (classified HOLD)."""


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def temp_name(path):
    return path + (".dpkg-new" if path.startswith(APPARMOR + "/") else ".g1-upgrade-tmp")


class RealHost:
    """Bounded, race-conscious filesystem and command access. Every method is read-only."""

    def euid(self):
        return os.geteuid()

    def ids(self, name):
        try:
            return pwd.getpwnam(name).pw_uid, grp.getgrnam(name).gr_gid
        except (KeyError, OSError) as exc:
            raise HostError("identity_unavailable", name) from exc

    def script_dir(self):
        return os.path.dirname(os.path.realpath(__file__))

    def lexists(self, path):
        return os.path.lexists(path)

    def lstat(self, path):
        return os.lstat(path)

    def listdir(self, path):
        return os.listdir(path)

    def read_regular(self, path, max_bytes):
        fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0))
        try:
            if not stat.S_ISREG(os.fstat(fd).st_mode):
                raise HostError("not_regular", os.path.basename(path))
            data = b""
            while len(data) < max_bytes:
                chunk = os.read(fd, min(65536, max_bytes - len(data)))
                if not chunk:
                    break
                data += chunk
            if len(data) >= max_bytes and os.read(fd, 1):
                raise HostError("too_large", os.path.basename(path))
            return data
        finally:
            os.close(fd)

    def read_regular_stable(self, path, max_bytes):
        """AppSec R4-F2/R5-F2 fix: open ONCE with no-follow semantics, validate
        a regular file on the descriptor, read bounded bytes, and return
        (data, st_fd_before, st_fd_after, st_path_after) so the caller can
        bind validation, hashing, descriptor-stability AND pathname binding
        to the same opened object."""
        fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0))
        try:
            st_fd_before = os.fstat(fd)
            if not stat.S_ISREG(st_fd_before.st_mode):
                raise HostError("not_regular", os.path.basename(path))
            data = b""
            while len(data) < max_bytes:
                chunk = os.read(fd, min(65536, max_bytes - len(data)))
                if not chunk:
                    break
                data += chunk
            if len(data) >= max_bytes and os.read(fd, 1):
                raise HostError("too_large", os.path.basename(path))
            st_fd_after = os.fstat(fd)
            st_path_after = os.stat(path, follow_symlinks=False)
        finally:
            os.close(fd)
        return data, st_fd_before, st_fd_after, st_path_after

    def read_text(self, path, max_bytes):
        return self.read_regular(path, max_bytes).decode("utf-8", errors="replace")

    def walk_tree(self, path):
        def fail(exc):
            raise HostError("walk_failed", errno.errorcode.get(exc.errno, str(exc.errno))) from exc

        for base, dirs, files, dfd in os.fwalk(path, topdown=True, follow_symlinks=False, onerror=fail):
            dirs.sort()
            files.sort()
            for name in dirs + files:
                st = os.stat(name, dir_fd=dfd, follow_symlinks=False)
                yield os.path.relpath(os.path.join(base, name), path), st

    def datadir_fingerprint(self, path, mysql_ids, max_entries, max_total_bytes, max_file_bytes):
        """Upgrader-grade hardened datadir walk (AppSec F2/R3-F2/R4-F1/R5-F1 fix).

        Mirrors go54a-upgrade-apparmor.fingerprint_tree with explicit resource
        bounds: every entry is stat'ed through the walk's directory FD, every
        regular file is opened through that same FD with O_NOFOLLOW, and the
        opened descriptor's identity is compared with the walked stat before
        hashing. Every child directory's opened identity/change metadata is
        compared against the stat its PARENT recorded before the walk
        descended, and every enumerated directory is re-verified after the
        whole walk, so a mutation in any window (before descent, during a
        level's enumeration, or after it) fails closed instead of being
        silently omitted from the fingerprint.

        Residual bound (documented, AppSec R6-F4): the per-level entry cap is
        enforced while processing a level's entries; a single directory's
        name enumeration and sort happen before that cap, so a pathological
        single directory can consume enumeration memory/time bounded by the
        operating system before the walk fails closed."""
        root = os.lstat(path)
        datadir_root_check(root, path, mysql_ids)
        records, entries, total = [], 0, 0
        levels = []
        parent_stats = {}

        def fail(exc):
            raise HostError("walk_failed", errno.errorcode.get(exc.errno, str(exc.errno))) from exc

        for base, dirs, files, dfd in os.fwalk(path, topdown=True, follow_symlinks=False, onerror=fail):
            dirs.sort()
            files.sort()
            dir_stat = os.fstat(dfd)
            rel_base = os.path.relpath(base, path)
            if rel_base == ".":
                if any(getattr(dir_stat, field) != getattr(root, field)
                       for field in MUTATION_IDENTITY):
                    raise HostError("datadir_changed_during_read", os.path.basename(path))
                levels.append(("", dir_stat))
            else:
                expected = parent_stats.pop(rel_base, None)
                if expected is not None and any(getattr(dir_stat, field) != getattr(expected, field)
                                                for field in MUTATION_IDENTITY):
                    raise HostError("datadir_changed_during_read", rel_base)
                levels.append((rel_base, dir_stat))
            for name in dirs + files:
                rel = os.path.relpath(os.path.join(base, name), path)
                st = os.stat(name, dir_fd=dfd, follow_symlinks=False)
                entries += 1
                if entries > max_entries:
                    raise HostError("datadir_entry_count_exceeded", path)
                validate_datadir_entry(rel, st, mysql_ids)
                if stat.S_ISDIR(st.st_mode):
                    parent_stats[rel] = st
                digest = "-"
                if stat.S_ISREG(st.st_mode):
                    if st.st_size > max_file_bytes or total + st.st_size > max_total_bytes:
                        raise HostError("datadir_exceeds_bounds", rel)
                    fd = os.open(name, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0)
                                 | getattr(os, "O_NONBLOCK", 0), dir_fd=dfd)
                    try:
                        opened = os.fstat(fd)
                        datadir_open_identity_check(rel, st, opened)
                        data = b""
                        while len(data) < max_file_bytes:
                            chunk = os.read(fd, min(65536, max_file_bytes - len(data)))
                            if not chunk:
                                break
                            data += chunk
                        if len(data) >= max_file_bytes and os.read(fd, 1):
                            raise HostError("datadir_exceeds_bounds", rel)
                        digest = hashlib.sha256(data).hexdigest()
                        fd_after = os.fstat(fd)
                    finally:
                        os.close(fd)
                    st_path = os.stat(name, dir_fd=dfd, follow_symlinks=False)
                    total = datadir_read_check(rel, st, fd_after, st_path, len(data), total,
                                               max_total_bytes)
                records.append(datadir_record(rel, st, digest))
        if parent_stats:
            raise HostError("datadir_changed_during_read", "unvisited_directory")
        for rel, st_recorded in levels:
            current = os.stat(os.path.join(path, rel) if rel else path, follow_symlinks=False)
            if any(getattr(current, field) != getattr(st_recorded, field)
                   for field in MUTATION_IDENTITY):
                raise HostError("datadir_changed_during_read", rel or os.path.basename(path))
        material = repr((stat.S_IMODE(root.st_mode), root.st_uid, root.st_gid, root.st_mtime_ns, records)).encode()
        return sha256(material), entries, total

    def run(self, args, timeout=COMMAND_TIMEOUT):
        try:
            done = subprocess.run(args, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                  stderr=subprocess.DEVNULL, timeout=timeout, check=False,
                                  close_fds=True, env=ENV)
        except (OSError, subprocess.TimeoutExpired) as exc:
            raise HostError("command_failed", args[0]) from exc
        return done.returncode, done.stdout

    def flock_shared_probe(self, path):
        # O_NONBLOCK (AppSec R8-F3a): a path replacement with a FIFO between
        # the pre-checks and this open must never block the diagnostic.
        fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0)
                     | getattr(os, "O_NONBLOCK", 0))
        try:
            if not stat.S_ISREG(os.fstat(fd).st_mode):
                raise HostError("lock_not_regular", os.path.basename(path))
            try:
                fcntl.flock(fd, fcntl.LOCK_SH | fcntl.LOCK_NB)
            except BlockingIOError:
                return "held"
            except OSError as exc:
                raise HostError("lock_probe_failed", errno.errorcode.get(exc.errno, str(exc.errno))) from exc
            fcntl.flock(fd, fcntl.LOCK_UN)
            return "free"
        finally:
            os.close(fd)


def guard(fn):
    """Never let an unexpected error look like success: map it to a fail-closed verdict."""
    try:
        return fn()
    except UnsafeState as exc:
        return ("HOLD", exc.code + ((":" + exc.detail) if exc.detail else ""))
    except HostError as exc:
        return ("UNKNOWN", exc.code + ((":" + exc.detail) if exc.detail else ""))
    except OSError as exc:
        return ("UNKNOWN", "filesystem_error:" + errno.errorcode.get(exc.errno, str(exc.errno)))
    except Exception as exc:  # noqa: BLE001 - a diagnostic must never crash into a false PASS
        return ("UNKNOWN", "internal_error:" + exc.__class__.__name__)


def unit_property(host, unit, prop):
    rc, out = host.run(["/usr/bin/systemctl", "show", unit, "--property=" + prop, "--value"])
    if rc != 0:
        raise HostError("systemctl_show_failed", unit + ":" + prop)
    return out.decode("utf-8", errors="replace").strip()


def validate_package_dir(host, path):
    """Read-only revalidation of a staged GO-5.4a package; mirrors the upgrader's intake.

    Deliberately does NOT compile profiles or regenerate them from the contract:
    the transaction tool performs those checks at execution time.
    """
    if not host.lexists(path):
        return "invalid:package_directory_missing"
    st = host.lstat(path)
    if not stat.S_ISDIR(st.st_mode):
        return "invalid:package_directory_not_directory"
    if (st.st_uid, st.st_gid) != (0, 0):
        return "invalid:package_directory_owner"
    if stat.S_IMODE(st.st_mode) != 0o700:
        return "invalid:package_directory_mode"
    names = set(PACKAGE.values()) | {OPS_SUMS_NAME}
    if set(host.listdir(path)) != names:
        return "invalid:package_contents"
    sums_path = os.path.join(path, OPS_SUMS_NAME)
    st = host.lstat(sums_path)
    if not stat.S_ISREG(st.st_mode) or (st.st_uid, st.st_gid) != (0, 0) or stat.S_IMODE(st.st_mode) != 0o600:
        return "invalid:package_sums_metadata"
    parsed = {}
    for line in host.read_text(sums_path, 1024 * 1024).splitlines():
        cols = line.split("  ")
        if len(cols) != 2 or not re.fullmatch(r"[0-9a-f]{64}", cols[0]) or cols[1] in parsed:
            return "invalid:package_sums_invalid"
        parsed[cols[1]] = cols[0]
    if parsed != {name: NEW[path_] for path_, name in PACKAGE.items()}:
        return "invalid:package_sums_pin_mismatch"
    for path_, name in PACKAGE.items():
        entry = os.path.join(path, name)
        st = host.lstat(entry)
        if not stat.S_ISREG(st.st_mode) or (st.st_uid, st.st_gid) != (0, 0) or stat.S_IMODE(st.st_mode) != 0o600:
            return "invalid:package_file_metadata:" + name
        if sha256(host.read_regular(entry, MAX_FILE_BYTES)) != NEW[path_]:
            return "invalid:package_hash_mismatch:" + name
    return "valid"


def check_console_root(host, emit):
    status = "PASS" if host.euid() == 0 else "UNKNOWN"
    detail = "" if status == "PASS" else "not_root_euid"
    emit("NOTE root_euid_only: console authorization is a separate operator record")
    return status, detail


def check_lock_directory(host, emit):
    st = host.lstat(LOCK_DIR)
    if not stat.S_ISDIR(st.st_mode):
        return "HOLD", "lock_directory_not_directory"
    if (st.st_uid, st.st_gid) != (0, 0):
        return "HOLD", "lock_directory_owner_invalid"
    if stat.S_IMODE(st.st_mode) & 0o022:
        return "HOLD", "lock_directory_mode_unsafe"
    return "PASS", "owner_and_mode_expected"


def check_lock_state(host, emit):
    if not host.lexists(LOCK):
        return "PASS", "lock_absent_at_observation"
    st = host.lstat(LOCK)
    if stat.S_ISLNK(st.st_mode):
        return "HOLD", "lock_file_symlink"
    if not stat.S_ISREG(st.st_mode):
        return "HOLD", "lock_file_not_regular"
    if (st.st_uid, st.st_gid) != (0, 0):
        return "HOLD", "lock_file_owner_invalid"
    if stat.S_IMODE(st.st_mode) != 0o600:
        return "HOLD", "lock_file_mode_invalid"
    if host.flock_shared_probe(LOCK) == "held":
        return "HOLD", "lock_held_by_other_process"
    return "PASS", "lock_unheld_at_observation"


def check_parent_directories(host, emit):
    """Mirror the upgrader's require_dir preconditions for LIBEXEC and APPARMOR:
    an unsafe parent directory must stop the readiness verdict, not just the
    later transaction."""
    for path in (LIBEXEC, APPARMOR):
        if not host.lexists(path):
            return "HOLD", "parent_directory_missing:" + path
        st = host.lstat(path)
        if not stat.S_ISDIR(st.st_mode):
            return "HOLD", "parent_directory_not_directory:" + path
        if (st.st_uid, st.st_gid) != (0, 0):
            return "HOLD", "parent_directory_owner_invalid:" + path
        if stat.S_IMODE(st.st_mode) & 0o022:
            return "HOLD", "parent_directory_mode_unsafe:" + path
    return "PASS", "libexec_and_apparmor_parents_safe"


def check_units_inactive(host, emit):
    for unit in UNITS:
        load = unit_property(host, unit, "LoadState")
        active = unit_property(host, unit, "ActiveState")
        sub = unit_property(host, unit, "SubState")
        emit("UNIT %s LoadState=%s ActiveState=%s SubState=%s"
             % (unit, sanitize(load), sanitize(active), sanitize(sub)))
        if load == "masked":
            return "HOLD", "g1_unit_masked:" + unit
        if load == "not-found":
            return "HOLD", "g1_unit_not_found:" + unit
        if load != "loaded":
            return "HOLD", "g1_unit_loadstate_unexpected:" + unit
        if active != "inactive":
            return "HOLD", "g1_unit_not_inactive:" + unit
    return "PASS", "both_units_exactly_inactive"


def check_installed_baseline(host, emit):
    states, problems = [], []
    for path in ORDER:
        name = os.path.basename(path)
        if not host.lexists(path):
            return "HOLD", "installed_file_missing:" + name
        st = host.lstat(path)
        if stat.S_ISLNK(st.st_mode):
            return "HOLD", "installed_file_symlink:" + name
        if not stat.S_ISREG(st.st_mode):
            return "HOLD", "installed_file_not_regular:" + name
        if (st.st_uid, st.st_gid) != (0, 0):
            return "HOLD", "installed_file_owner_invalid:" + name
        if stat.S_IMODE(st.st_mode) != MODES[path]:
            return "HOLD", "installed_file_mode_invalid:" + name
        digest = sha256(host.read_regular(path, MAX_FILE_BYTES))
        if digest == OLD[path]:
            states.append("old")
            emit("ARTIFACT %s sha256=%s pin=old" % (name, digest))
        elif digest == NEW[path]:
            states.append("new")
            emit("ARTIFACT %s sha256=%s pin=new" % (name, digest))
        else:
            problems.append(name)
            emit("ARTIFACT %s sha256=%s pin=none" % (name, digest))
    if problems:
        return "HOLD", "file_hash_mismatch:" + ",".join(problems)
    if all(state == "old" for state in states):
        return "PASS", "baseline=old"
    if all(state == "new" for state in states):
        return "HOLD", "already_upgraded_baseline=new"
    return "HOLD", "baseline_mixed_old_and_new"


def check_apparmor_enforced(host, emit):
    lines = host.read_text(LOADED, 16 * 1024 * 1024).splitlines()
    for profile in PROFILE_NAMES.values():
        matches = [line for line in lines if line.startswith(profile + " (")]
        if len(matches) != 1:
            return "HOLD", ("profile_not_loaded" if not matches else "profile_duplicate_lines") + ":" + profile
        if not matches[0].endswith(" (enforce)"):
            return "HOLD", "profile_not_enforced:" + profile
    vendor = [line for line in lines if line.startswith(VENDOR_PROFILE_NAME + " (")]
    if len(vendor) != 1:
        return "HOLD", "vendor_profile_line_unexpected"
    emit("VENDOR_PROFILE_LOADED " + sanitize(vendor[0]))
    emit("NOTE parser_compilation_not_checked_by_this_tool")
    emit("NOTE package_state_not_checked_by_this_tool")
    return "PASS", "both_g1_profiles_enforced"


def check_vendor_mysql(host, emit):
    active = unit_property(host, VENDOR_UNIT, "ActiveState")
    if active != "active":
        return "HOLD", "vendor_mysql_not_active:ActiveState=" + active
    pid = unit_property(host, VENDOR_UNIT, "MainPID")
    if not pid.isdigit() or int(pid) < 1:
        return "HOLD", "vendor_mysql_pid_invalid"
    try:
        comm = host.read_text("/proc/%s/comm" % pid, 64).strip()
    except HostError:
        return "UNKNOWN", "vendor_mysql_pid_unreadable:" + pid
    if comm != "mysqld":
        return "HOLD", "vendor_mysql_pid_not_mysqld:" + pid + ":comm=" + comm
    return pid


def check_vendor_profile_source(host, emit):
    st = host.lstat(VENDOR_PROFILE)
    if stat.S_ISLNK(st.st_mode):
        return "HOLD", "vendor_profile_symlink"
    if not stat.S_ISREG(st.st_mode):
        return "HOLD", "vendor_profile_not_regular"
    if (st.st_uid, st.st_gid) != (0, 0):
        return "HOLD", "vendor_profile_owner_invalid"
    digest = sha256(host.read_regular(VENDOR_PROFILE, MAX_PROFILE_SOURCE_BYTES))
    emit("VENDOR_PROFILE_SOURCE sha256=%s mode=%o" % (digest, stat.S_IMODE(st.st_mode)))
    return "PASS", "recorded_for_pre_post_comparison"


def datadir_root_check(st, path, mysql_ids):
    """Shared datadir validation used by both the real host walk and the
    synthetic test host, so the two cannot drift apart."""
    if not stat.S_ISDIR(st.st_mode):
        raise UnsafeState("datadir_not_directory", path)
    if (st.st_uid, st.st_gid) != mysql_ids or stat.S_IMODE(st.st_mode) & 0o022:
        raise UnsafeState("datadir_root_owner_or_mode_invalid", path)


def validate_datadir_entry(rel, st, mysql_ids):
    if stat.S_ISLNK(st.st_mode) or not (stat.S_ISDIR(st.st_mode) or stat.S_ISREG(st.st_mode)):
        raise UnsafeState("datadir_entry_invalid", rel)
    if (st.st_uid, st.st_gid) != mysql_ids or stat.S_IMODE(st.st_mode) & 0o022:
        raise UnsafeState("datadir_entry_owner_or_mode_invalid", rel)


def datadir_record(rel, st, digest):
    return (rel, st.st_mode, st.st_uid, st.st_gid, st.st_size, st.st_mtime_ns, digest)


def datadir_open_identity_check(rel, st, opened):
    """Shared open-identity check used by BOTH the real host walk and the
    synthetic test walk, so regressions exercise the production logic
    (AppSec R7-F2): the opened descriptor must still be the regular file the
    walk stat'ed, or the evidence fails closed as UNKNOWN."""
    if not stat.S_ISREG(opened.st_mode) or (opened.st_dev, opened.st_ino) != (st.st_dev, st.st_ino):
        raise HostError("datadir_changed_during_read", rel)


def datadir_read_check(rel, st_before, st_after_fd, st_after_path, data_len, total, max_total_bytes):
    """Charge the AGGREGATE against bytes actually read and detect concurrent
    mutation (AppSec R2/R3 findings): the comparison covers device, inode,
    size, mtime AND ctime for both the still-open descriptor (in-place
    rewrite; ctime cannot be restored by any writer) and the re-stat'ed
    pathname (same-size inode replacement with restored mtime). Any mismatch
    makes the fingerprint unreliable and fails closed."""
    identity = ("st_dev", "st_ino", "st_size", "st_mtime_ns", "st_ctime_ns")
    for label, st_after in (("fd", st_after_fd), ("path", st_after_path)):
        if st_after is None:
            continue
        if any(getattr(st_after, field) != getattr(st_before, field) for field in identity):
            raise HostError("datadir_changed_during_read", rel + ":" + label)
    if total + data_len > max_total_bytes:
        raise HostError("datadir_exceeds_bounds", rel)
    return total + data_len


def sanitize(text):
    """Untrusted evidence and arguments never reach the archived output
    verbatim: ASCII control characters, DEL, C1 controls and Unicode
    line/paragraph separators are all masked (AppSec R2/R4 hardening)."""
    separators = {" ", " "}
    return "".join("?" if (ch < " " or ch == "\x7f" or "\x80" <= ch <= "\x9f"
                           or ch in separators) else ch
                   for ch in str(text))


def check_datadirs(host, emit):
    mysql_ids = host.ids("mysql")
    for path in DATADIRS:
        if not host.lexists(path):
            return "HOLD", "datadir_missing:" + path
        fingerprint, entries, total = host.datadir_fingerprint(
            path, mysql_ids, MAX_DATADIR_ENTRIES, MAX_DATADIR_BYTES, MAX_FILE_BYTES)
        emit("FINGERPRINT datadir:%s sha256=%s entries=%d bytes=%d" % (path, fingerprint, entries, total))
    return "PASS", "both_datadirs_fingerprinted"


def check_logs(host, emit):
    """AppSec R4-F2/R5-F2 fix: validation and hashing bind to the SAME
    descriptor, and the pathname must still name that descriptor when the
    check completes. The lstat pre-checks keep their HOLD classifications;
    the opened descriptor's metadata is re-validated; the initial lstat is
    compared with the opened descriptor (path rebind between them fails);
    and a post-read comparison covers BOTH the still-open descriptor and a
    fresh stat of the pathname — concurrent replacement, in-place rewrite or
    path rebinding all fail closed as UNKNOWN."""
    mysql_ids = host.ids("mysql")
    material = []
    for path in LOGS:
        name = os.path.basename(path)
        if not host.lexists(path):
            return "HOLD", "mysql_log_missing:" + name
        st = host.lstat(path)
        if stat.S_ISLNK(st.st_mode) or not stat.S_ISREG(st.st_mode):
            return "HOLD", "mysql_log_not_regular:" + name
        if (st.st_uid, st.st_gid) != mysql_ids:
            return "HOLD", "mysql_log_owner_invalid:" + name
        if stat.S_IMODE(st.st_mode) != 0o600:
            return "HOLD", "mysql_log_mode_invalid:" + name
        if st.st_size > MAX_LOG_BYTES:
            return "UNKNOWN", "mysql_log_exceeds_bounds:" + name
        data, st_fd_before, st_fd_after, st_path_after = host.read_regular_stable(path, MAX_LOG_BYTES)
        if (not stat.S_ISREG(st_fd_before.st_mode)
                or (st_fd_before.st_uid, st_fd_before.st_gid) != mysql_ids
                or stat.S_IMODE(st_fd_before.st_mode) != 0o600
                or st_fd_before.st_size > MAX_LOG_BYTES):
            return "HOLD", "mysql_log_descriptor_metadata_invalid:" + name
        if any(getattr(st_fd_before, field) != getattr(st, field) for field in MUTATION_IDENTITY):
            return "UNKNOWN", "mysql_log_changed_during_read:" + name
        if any(getattr(st_fd_after, field) != getattr(st_fd_before, field)
               for field in MUTATION_IDENTITY):
            return "UNKNOWN", "mysql_log_changed_during_read:" + name
        if any(getattr(st_path_after, field) != getattr(st_fd_before, field)
               for field in MUTATION_IDENTITY):
            return "UNKNOWN", "mysql_log_changed_during_read:" + name
        material.append((name, sha256(data), st_fd_before.st_size))
    emit("FINGERPRINT logs sha256=%s" % sha256(repr(material).encode()))
    return "PASS", "both_logs_fingerprinted"


def check_transaction_leftovers(host, emit):
    if host.lexists(JOURNAL):
        return "HOLD", "upgrade_journal_present_manual_review_required"
    if host.lexists(JOURNAL + ".tmp"):
        return "HOLD", "journal_temporary_present_manual_review_required"
    for path in ORDER:
        if host.lexists(temp_name(path)):
            return "HOLD", "leftover_temporary_file:" + os.path.basename(temp_name(path))
    return "PASS", "no_journal_no_temporaries"


def scan_staged_packages(host, emit):
    """The /root staging scan runs in BOTH modes (AppSec F5 fix): an unverified
    incoming directory or an invalid staged package is a HOLD even when an
    explicit --package was supplied."""
    try:
        names = host.listdir(ROOT_DIR)
    except (HostError, OSError):
        return "UNKNOWN", "root_scan_unavailable"
    staged = sorted(name for name in names if name.startswith(FETCH_PREFIX))
    for name in staged:
        if name.endswith(INCOMING_SUFFIX):
            return "HOLD", "incoming_package_unverified_present:" + name
    for name in staged:
        verdict = validate_package_dir(host, os.path.join(ROOT_DIR, name))
        if verdict != "valid":
            return "HOLD", "staged_package_invalid:" + name + ":" + verdict
        emit("NOTE staged_package_validated:" + sanitize(name))
        emit("NOTE package_generated_profiles_and_parser_compilation_not_checked_here")
    return "PASS", ("staged_packages_recognized" if staged else "none")


def check_package_intake(host, package_dir, emit):
    status, detail = scan_staged_packages(host, emit)
    if status != "PASS":
        return status, detail
    if package_dir is None:
        if detail == "none":
            return "PASS", "no_staged_go54a_package"
        return "PASS", "staged_packages_recognized_and_validated"
    verdict = validate_package_dir(host, package_dir)
    if verdict != "valid":
        return "HOLD", "package_invalid:" + verdict
    emit("NOTE package_generated_profiles_and_parser_compilation_not_checked_here")
    return "PASS", "package_verified:" + package_dir


def toolkit_candidate_dirs(host):
    """Fixed, caller-independent lookup: the frozen ops toolkit staged as a
    sibling directory (the repository layout), and this tool's own directory
    (flat staging). No caller-supplied path is ever consulted."""
    base = host.script_dir().rstrip("/")
    sibling = os.path.join(os.path.dirname(base), TOOLKIT_DIR_NAME)
    candidates = []
    for path in (sibling, base):
        if path and path not in candidates:
            candidates.append(path)
    return candidates


def companion_pins():
    """Built at call time so the module-attribute pins stay the single seam."""
    return (
        (UPGRADER_NAME, EXPECTED_UPGRADER_SHA),
        (FETCH_NAME, EXPECTED_FETCH_SHA),
        (OPS_SUMS_NAME, EXPECTED_OPS_SUMS_SHA),
    )


def check_toolkit_companions(host, emit):
    """Verify the frozen ops toolkit beside this tool (QA B2 fix).

    Supported layouts: the repository/sibling layout and a standalone
    diagnostics directory. In the sibling ops directory any of the three pins
    triggers full validation. In this tool's own directory only the executable
    companions trigger validation — the diagnostics directory legitimately
    carries its own SHA256SUMS, which must never be mistaken for a partial ops
    companion set. Wherever verification is triggered, all three companions
    must be present with the exact reviewed bytes."""
    pins = companion_pins()
    pin_names = {name for name, _ in pins}
    verified = False
    for directory in toolkit_candidate_dirs(host):
        own_directory = os.path.basename(directory.rstrip("/")) != TOOLKIT_DIR_NAME
        triggers = {UPGRADER_NAME, FETCH_NAME} if own_directory else pin_names
        present = {name for name in pin_names if host.lexists(os.path.join(directory, name))}
        if not present & triggers:
            continue
        missing = sorted(pin_names - present)
        if missing:
            return "HOLD", ("toolkit_companions_incomplete:" + os.path.basename(directory.rstrip("/"))
                            + ":" + ",".join(missing))
        for name, expected in pins:
            digest = sha256(host.read_regular(os.path.join(directory, name), MAX_FILE_BYTES))
            if digest != expected:
                return "HOLD", ("toolkit_companion_mismatch:" + os.path.basename(directory.rstrip("/"))
                                + "/" + name)
        emit("NOTE toolkit_companions_verified:" + sanitize(directory))
        verified = True
    if not verified:
        emit("NOTE toolkit_companions_absent_standalone_run_verify_this_tool_by_its_pr_pinned_hash")
        return "PASS", "companions_absent"
    return "PASS", "companions_match_reviewed_pins"


def main(argv=None, host=None, stream=None):
    stream = stream if stream is not None else sys.stdout

    def emit(line):
        print(line, file=stream)

    argv = list(sys.argv[1:] if argv is None else argv)
    package_dir = None
    index = 0
    while index < len(argv):
        argument = argv[index]
        if argument == "--package":
            index += 1
            if index >= len(argv):
                emit("G1_GO54A_READINESS=HOLD;CODE=package_argument_missing")
                return 3
            value = argv[index]
            if not value:
                emit("G1_GO54A_READINESS=HOLD;CODE=package_argument_invalid:empty")
                return 3
            if package_dir is not None:
                emit("G1_GO54A_READINESS=HOLD;CODE=duplicate_package_argument")
                return 3
            package_dir = value
        else:
            emit("G1_GO54A_READINESS=HOLD;CODE=argument_invalid:" + sanitize(argument))
            return 3
        index += 1

    host = host if host is not None else RealHost()
    emit("TOOL %s %s mode=read_only" % (TOOL, VERSION))
    results = []
    initial_critical = {}

    status, detail = guard(lambda: check_console_root(host, emit))
    results.append(("console_root", status, detail))

    status, detail = guard(lambda: check_lock_directory(host, emit))
    results.append(("lock_directory", status, detail))

    status, detail = guard(lambda: check_lock_state(host, emit))
    results.append(("lock_state", status, detail))
    initial_critical["lock_state"] = (status, detail)

    status, detail = guard(lambda: check_parent_directories(host, emit))
    results.append(("parent_directories", status, detail))

    status, detail = guard(lambda: check_units_inactive(host, emit))
    results.append(("g1_units_inactive", status, detail))
    initial_critical["g1_units_inactive"] = (status, detail)

    status, detail = guard(lambda: check_installed_baseline(host, emit))
    results.append(("installed_baseline", status, detail))
    initial_critical["installed_baseline"] = (status, detail)

    status, detail = guard(lambda: check_apparmor_enforced(host, emit))
    results.append(("apparmor_enforced", status, detail))

    vendor_pid = None

    def vendor_step():
        nonlocal vendor_pid
        outcome = check_vendor_mysql(host, emit)
        if isinstance(outcome, tuple):
            return outcome
        vendor_pid = outcome
        return "PASS", "identity_and_pid_valid"

    status, detail = guard(vendor_step)
    results.append(("vendor_mysql_service", status, detail))

    status, detail = guard(lambda: check_vendor_profile_source(host, emit))
    results.append(("vendor_profile_source", status, detail))

    status, detail = guard(lambda: check_datadirs(host, emit))
    results.append(("datadirs_intact", status, detail))

    status, detail = guard(lambda: check_logs(host, emit))
    results.append(("mysql_logs_intact", status, detail))

    status, detail = guard(lambda: check_transaction_leftovers(host, emit))
    results.append(("transaction_leftovers", status, detail))
    initial_critical["transaction_leftovers"] = (status, detail)

    status, detail = guard(lambda: check_package_intake(host, package_dir, emit))
    results.append(("package_intake", status, detail))

    status, detail = guard(lambda: check_toolkit_companions(host, emit))
    results.append(("toolkit_companions", status, detail))

    if vendor_pid is not None:
        # AppSec R9-F1: the closing vendor observation re-observes BOTH
        # ActiveState and MainPID; equality of samples only proves the two
        # observations matched, never continuous stability, and a
        # non-active closing state fails closed.
        closing_state = guard(lambda: unit_property(host, VENDOR_UNIT, "ActiveState"))
        closing_pid = guard(lambda: unit_property(host, VENDOR_UNIT, "MainPID"))
        if isinstance(closing_state, tuple):
            results.append(("vendor_mysql_revalidation", "UNKNOWN", closing_state[1]))
        elif isinstance(closing_pid, tuple):
            results.append(("vendor_mysql_revalidation", "UNKNOWN", closing_pid[1]))
        elif closing_state != "active":
            results.append(("vendor_mysql_revalidation", "HOLD",
                             "vendor_mysql_not_active_at_close:" + closing_state))
        elif closing_pid != vendor_pid:
            results.append(("vendor_mysql_revalidation", "HOLD",
                            "vendor_mysql_pid_changed_between_observations"))
        else:
            results.append(("vendor_mysql_revalidation", "PASS",
                            "vendor_mysql_observations_match_at_close=" + vendor_pid))

    # AppSec R6-F1/R7-F1: the four critical transaction-relevant checks
    # are re-run quietly (through a sink so the evidence transcript is not
    # duplicated) and any deviation from the initial observation classifies
    # the whole evidence set as stale (UNKNOWN). This NARROWS the concurrency
    # window but cannot eliminate it: a read-only collector produces ADVISORY,
    # NON-ATOMIC evidence. PASS never asserts that no transaction is active
    # or will start — the Owner must serialize evidence collection against
    # any transaction (the GO-5.4a preflight procedure step 2 requires
    # this sequencing explicitly).
    def revalidate_critical_state():
        def sink(_line):
            return None

        closing = {
            "lock_state": guard(lambda: check_lock_state(host, sink)),
            "g1_units_inactive": guard(lambda: check_units_inactive(host, sink)),
            "installed_baseline": guard(lambda: check_installed_baseline(host, sink)),
            "transaction_leftovers": guard(lambda: check_transaction_leftovers(host, sink)),
        }
        for name, observed in closing.items():
            if observed != initial_critical.get(name):
                return "UNKNOWN", "evidence_stale:" + name + ":" + observed[0] + ":" + observed[1]
        return "PASS", "critical_observations_match_at_close"

    status, detail = guard(revalidate_critical_state)
    results.append(("state_revalidation", status, detail))

    for name, status, detail in results:
        emit("CHECK %s %s%s" % (name, status, (" " + sanitize(detail)) if detail else ""))
    emit("NOTE backup_evidence restore_evidence_is_not_verifiable_by_a_host_read")
    emit("BACKUP_EVIDENCE=UNKNOWN;CHECKLIST=RACKFOREST_BACKUP_VERIFICATION_CHECKLIST")
    if any(status == "HOLD" for _, status, _ in results):
        verdict, code = "HOLD", 3
    elif any(status == "UNKNOWN" for _, status, _ in results):
        verdict, code = "UNKNOWN", 4
    else:
        verdict, code = "PASS", 0
    emit("G1_GO54A_READINESS=" + verdict)
    return code


if __name__ == "__main__":
    raise SystemExit(main())
