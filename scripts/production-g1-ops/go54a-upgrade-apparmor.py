#!/usr/bin/env python3
"""GO-5.4a socket-lock policy transaction for the confirmed installed G1 baseline.

This tool is preparation only until a separate Owner GO. It never starts MySQL,
initializes data, changes option files, or touches mysql.service/vendor policy.
"""
import base64
import fcntl
import hashlib
import json
import os
import pwd
import grp
import stat
import subprocess
import sys
import tempfile
import time
import types

LIBEXEC = "/usr/local/libexec"
APPARMOR = "/etc/apparmor.d"
HELPER = LIBEXEC + "/wpcaregrid-g1-mysql.py"
CONTRACT = LIBEXEC + "/production_g1_mysql_contract.py"
MANIFEST = LIBEXEC + "/production_g1_mysql_manifest.json"
PROFILE_P = APPARMOR + "/wpcaregrid-mysql-production"
PROFILE_R = APPARMOR + "/wpcaregrid-mysql-production-restore"
PARSER = "/usr/sbin/apparmor_parser"
LOADED = "/sys/kernel/security/apparmor/profiles"
VENDOR_PROFILE = "/etc/apparmor.d/usr.sbin.mysqld"
DATADIRS = ("/var/lib/mysql-wpcaregrid-production", "/var/lib/mysql-wpcaregrid-production-restore")
LOGS = ("/var/log/mysql/wpcaregrid-production.log", "/var/log/mysql/wpcaregrid-production-restore.log")
UNITS = ("mysql@wpcaregrid-production.service", "mysql@wpcaregrid-production-restore.service")
LOCK = "/run/wpcaregrid-go54a-apparmor.lock"
JOURNAL = LIBEXEC + "/.go54a-apparmor-upgrade.json"

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
ENV = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "HOME": "/nonexistent", "LANG": "C", "LC_ALL": "C"}


class Stop(Exception):
    pass


def sha(data):
    return hashlib.sha256(data).hexdigest()


def temp(path):
    return path + (".dpkg-new" if path.startswith(APPARMOR + "/") else ".g1-upgrade-tmp")


def read_root_file(path, mode):
    fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0))
    try:
        st = os.fstat(fd)
        if not stat.S_ISREG(st.st_mode) or (st.st_uid, st.st_gid) != (0, 0):
            raise Stop("target_type_or_owner_invalid:" + os.path.basename(path))
        if stat.S_IMODE(st.st_mode) != mode:
            raise Stop("target_mode_invalid:" + os.path.basename(path))
        with os.fdopen(fd, "rb", closefd=False) as stream:
            return stream.read()
    finally:
        os.close(fd)


def replace(path, data, mode):
    tmp = temp(path)
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o600)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.chown(tmp, 0, 0)
        os.chmod(tmp, mode)
        os.replace(tmp, path)
        dfd = os.open(os.path.dirname(path), os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(dfd)
        finally:
            os.close(dfd)
    except BaseException:
        if os.path.lexists(tmp):
            os.unlink(tmp)
        raise


def run(args, data=None):
    return subprocess.run(args, input=data, stdin=subprocess.DEVNULL if data is None else None,
                          stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=120,
                          close_fds=True, env=ENV, check=False)


def require_dir(path, code):
    st = os.lstat(path)
    if not stat.S_ISDIR(st.st_mode) or (st.st_uid, st.st_gid) != (0, 0) or stat.S_IMODE(st.st_mode) & 0o022:
        raise Stop(code)


def load_profiles(runfn=run):
    for path in (PROFILE_P, PROFILE_R):
        done = runfn([PARSER, "-r", "-T", path])
        if done.returncode:
            raise Stop("apparmor_reload_failed:" + os.path.basename(path))


def enforce_state():
    lines = open(LOADED, encoding="utf-8").read().splitlines()
    for name in PROFILE_NAMES.values():
        matches = [line for line in lines if line.startswith(name + " (")]
        if len(matches) != 1 or not matches[0].endswith(" (enforce)"):
            raise Stop("profile_not_enforced:" + name)


def unit_inactive(runfn=run):
    for unit in UNITS:
        done = runfn(["/usr/bin/systemctl", "show", unit, "--property=ActiveState", "--value"])
        if done.returncode or done.stdout.decode().strip() != "inactive":
            raise Stop("g1_unit_not_inactive:" + unit)


def mysql_pid(runfn=run):
    done = runfn(["/usr/bin/systemctl", "show", "mysql.service", "--property=MainPID", "--value"])
    value = done.stdout.decode().strip()
    if done.returncode or not value.isdigit() or int(value) < 1:
        raise Stop("mysql_service_identity_unverified")
    return value


def vendor_policy_state():
    fd = os.open(VENDOR_PROFILE, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0))
    try:
        st = os.fstat(fd)
        if not stat.S_ISREG(st.st_mode) or (st.st_uid, st.st_gid) != (0, 0):
            raise Stop("vendor_apparmor_profile_identity_invalid")
        h = hashlib.sha256()
        with os.fdopen(fd, "rb", closefd=False) as stream:
            for chunk in iter(lambda: stream.read(1024 * 1024), b""):
                h.update(chunk)
        lines = open(LOADED, encoding="utf-8").read().splitlines()
        legacy = [line for line in lines if line.startswith("/usr/sbin/mysqld (")]
        if len(legacy) != 1:
            raise Stop("vendor_apparmor_profile_state_unverified")
        return (st.st_mode, st.st_uid, st.st_gid, h.hexdigest(), legacy[0])
    finally:
        os.close(fd)


def fingerprint_tree(path):
    root = os.lstat(path)
    if not stat.S_ISDIR(root.st_mode):
        raise Stop("datadir_not_directory")
    owner = (pwd.getpwnam("mysql").pw_uid, grp.getgrnam("mysql").gr_gid)
    if (root.st_uid, root.st_gid) != owner or stat.S_IMODE(root.st_mode) & 0o022:
        raise Stop("datadir_owner_or_mode_invalid")
    records = []
    def fail(exc):
        raise Stop("datadir_walk_failed") from exc
    for base, dirs, files, dfd in os.fwalk(path, topdown=True, follow_symlinks=False, onerror=fail):
        dirs.sort(); files.sort()
        for name in dirs + files:
            st = os.stat(name, dir_fd=dfd, follow_symlinks=False)
            if stat.S_ISLNK(st.st_mode) or not (stat.S_ISDIR(st.st_mode) or stat.S_ISREG(st.st_mode)):
                raise Stop("datadir_entry_invalid")
            if (st.st_uid, st.st_gid) != owner or stat.S_IMODE(st.st_mode) & 0o022:
                raise Stop("datadir_entry_owner_or_mode_invalid")
            digest = "-"
            if stat.S_ISREG(st.st_mode):
                fd = os.open(name, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0), dir_fd=dfd)
                try:
                    opened = os.fstat(fd)
                    if not stat.S_ISREG(opened.st_mode) or (opened.st_dev, opened.st_ino) != (st.st_dev, st.st_ino):
                        raise Stop("datadir_changed_during_read")
                    h = hashlib.sha256()
                    with os.fdopen(fd, "rb", closefd=False) as stream:
                        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
                            h.update(chunk)
                    digest = h.hexdigest()
                finally:
                    os.close(fd)
            records.append((os.path.relpath(os.path.join(base, name), path), st.st_mode, st.st_uid,
                            st.st_gid, st.st_size, st.st_mtime_ns, digest))
    return sha(repr((root.st_mode, root.st_uid, root.st_gid, root.st_mtime_ns, records)).encode())


def fingerprint_logs():
    out = []
    owner = (pwd.getpwnam("mysql").pw_uid, grp.getgrnam("mysql").gr_gid)
    for path in LOGS:
        fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0))
        try:
            st = os.fstat(fd)
            if not stat.S_ISREG(st.st_mode) or (st.st_uid, st.st_gid) != owner or stat.S_IMODE(st.st_mode) != 0o600:
                raise Stop("mysql_log_type_owner_or_mode_invalid")
            h = hashlib.sha256()
            with os.fdopen(fd, "rb", closefd=False) as stream:
                for chunk in iter(lambda: stream.read(1024 * 1024), b""):
                    h.update(chunk)
            out.append(h.hexdigest())
        finally:
            os.close(fd)
    return out


def generated_profiles(contract_bytes):
    module = types.ModuleType("go54a_contract")
    module.__file__ = "<go54a_contract>"
    sys.modules[module.__name__] = module
    exec(compile(contract_bytes, "<go54a_contract>", "exec"), module.__dict__)
    artifacts = module.artifact_files()
    return {PROFILE_P: artifacts[PROFILE_P], PROFILE_R: artifacts[PROFILE_R]}


def package_bytes(package_dir):
    st = os.lstat(package_dir)
    if not stat.S_ISDIR(st.st_mode) or (st.st_uid, st.st_gid) != (0, 0) or stat.S_IMODE(st.st_mode) != 0o700:
        raise Stop("package_directory_invalid")
    names = set(PACKAGE.values()) | {"SHA256SUMS"}
    if set(os.listdir(package_dir)) != names:
        raise Stop("package_contents_invalid")
    sums = read_root_file(os.path.join(package_dir, "SHA256SUMS"), 0o600).decode("ascii")
    expected = {name: NEW[path] for path, name in PACKAGE.items()}
    parsed = {}
    for line in sums.splitlines():
        cols = line.split("  ")
        if len(cols) != 2 or len(cols[0]) != 64 or cols[1] in parsed:
            raise Stop("package_sums_invalid")
        parsed[cols[1]] = cols[0]
    if parsed != expected:
        raise Stop("package_sums_pin_mismatch")
    contents = {}
    for path, name in PACKAGE.items():
        contents[path] = read_root_file(os.path.join(package_dir, name), 0o600)
        if sha(contents[path]) != NEW[path]:
            raise Stop("package_hash_mismatch:" + name)
    profiles = generated_profiles(contents[CONTRACT])
    contents.update(profiles)
    if any(sha(contents[path]) != NEW[path] for path in (PROFILE_P, PROFILE_R)):
        raise Stop("generated_profile_hash_mismatch")
    return contents


def compile_profiles(contents, runfn=run):
    for path in (PROFILE_P, PROFILE_R):
        fd, temp_path = tempfile.mkstemp(prefix="wpcaregrid-go54a-", dir="/run")
        try:
            os.fchmod(fd, 0o600)
            with os.fdopen(fd, "wb") as stream:
                stream.write(contents[path])
                stream.flush()
                os.fsync(stream.fileno())
            done = runfn([PARSER, "-Q", "-T", temp_path])
            if done.returncode:
                raise Stop("apparmor_compile_failed:" + os.path.basename(path))
        finally:
            if os.path.lexists(temp_path):
                os.unlink(temp_path)


def journal_write(obj):
    path = JOURNAL + ".tmp"
    data = (json.dumps(obj, sort_keys=True, separators=(",", ":")) + "\n").encode()
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o600)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(data); stream.flush(); os.fsync(stream.fileno())
        os.chown(path, 0, 0); os.chmod(path, 0o600); os.replace(path, JOURNAL)
        dfd = os.open(LIBEXEC, os.O_RDONLY | os.O_DIRECTORY)
        try: os.fsync(dfd)
        finally: os.close(dfd)
    except BaseException:
        if os.path.lexists(path): os.unlink(path)
        raise


def recover_interrupted(runfn=run):
    if not os.path.lexists(JOURNAL):
        if os.path.lexists(JOURNAL + ".tmp"):
            raise Stop("journal_temporary_file_requires_manual_recovery")
        return False
    raw = read_root_file(JOURNAL, 0o600)
    try:
        obj = json.loads(raw)
        if (set(obj) != {"schema", "phase", "old", "new", "backup"} or obj["schema"] != 1
                or obj["phase"] not in ("prepared", "replacing", "committed")
                or obj["old"] != OLD or obj["new"] != NEW or set(obj["backup"]) != set(ORDER)):
            raise ValueError()
        backup = {p: base64.b64decode(obj["backup"][p], validate=True) for p in ORDER}
        if any(sha(backup[p]) != OLD[p] for p in ORDER):
            raise ValueError()
    except Exception as exc:
        raise Stop("journal_unrecognized_manual_recovery_required") from exc
    current = {p: sha(read_root_file(p, MODES[p])) for p in ORDER}
    if any(current[p] not in (OLD[p], NEW[p]) for p in ORDER):
        raise Stop("journal_target_state_unknown_manual_recovery_required")
    for path in ORDER:
        if os.path.lexists(temp(path)):
            st = os.lstat(temp(path))
            if not stat.S_ISREG(st.st_mode) or (st.st_uid, st.st_gid) != (0, 0) or st.st_nlink != 1:
                raise Stop("journal_temporary_file_unrecognized_manual_recovery_required")
            os.unlink(temp(path))
    unit_inactive(runfn)
    if obj["phase"] == "committed":
        if any(current[path] != NEW[path] for path in ORDER):
            raise Stop("committed_journal_state_mismatch_manual_recovery_required")
        load_profiles(runfn)
        enforce_state()
        os.unlink(JOURNAL)
        dfd = os.open(LIBEXEC, os.O_RDONLY | os.O_DIRECTORY)
        try: os.fsync(dfd)
        finally: os.close(dfd)
        return True
    for path in reversed(ORDER):
        if current[path] != OLD[path]:
            replace(path, backup[path], MODES[path])
    load_profiles(runfn)
    enforce_state()
    if any(sha(read_root_file(p, MODES[p])) != OLD[p] for p in ORDER):
        raise Stop("journal_recovery_hash_mismatch_manual_recovery_required")
    os.unlink(JOURNAL)
    dfd = os.open(LIBEXEC, os.O_RDONLY | os.O_DIRECTORY)
    try: os.fsync(dfd)
    finally: os.close(dfd)
    return True


def read_targets():
    return {p: read_root_file(p, MODES[p]) for p in ORDER}


def rollback(old_bytes, runfn=run, expected_state=None):
    ok = True
    for path in reversed(ORDER):
        try:
            if sha(read_root_file(path, MODES[path])) != OLD[path]:
                replace(path, old_bytes[path], MODES[path])
        except BaseException:
            ok = False
    if ok:
        try:
            load_profiles(runfn); enforce_state()
            ok = all(sha(read_root_file(path, MODES[path])) == OLD[path] for path in ORDER)
            if ok and expected_state is not None:
                expected_pid, expected_vendor_policy, expected_data, expected_logs = expected_state
                ok = (mysql_pid(runfn) == expected_pid
                      and vendor_policy_state() == expected_vendor_policy
                      and [fingerprint_tree(p) for p in DATADIRS] == expected_data
                      and fingerprint_logs() == expected_logs)
        except BaseException:
            ok = False
    return ok


def _transaction(package_dir, runfn=run, euid=None):
    if (os.geteuid() if euid is None else euid) != 0:
        raise Stop("not_root")
    require_dir(LIBEXEC, "libexec_directory_unsafe")
    require_dir(APPARMOR, "apparmor_directory_unsafe")
    unit_inactive(runfn)
    if recover_interrupted(runfn):
        raise Stop("interrupted_transaction_recovered_manual_review_required")
    new_bytes = package_bytes(package_dir)
    compile_profiles(new_bytes, runfn)
    for path in ORDER:
        if os.path.lexists(temp(path)):
            raise Stop("leftover_temporary_file")
    if os.path.lexists(JOURNAL) or os.path.lexists(JOURNAL + ".tmp"):
        raise Stop("journal_recovery_incomplete")
    old_bytes = read_targets()
    state = {p: sha(old_bytes[p]) for p in ORDER}
    if all(state[p] == NEW[p] for p in ORDER):
        unit_inactive(runfn); enforce_state()
        return "G1_GO54A_APPARMOR=PASS;NOTE=already_upgraded"
    if any(state[p] != OLD[p] for p in ORDER):
        raise Stop("installed_baseline_mismatch")
    unit_inactive(runfn); enforce_state()
    before_pid = mysql_pid(runfn)
    before_vendor_policy = vendor_policy_state()
    before_data = [fingerprint_tree(p) for p in DATADIRS]
    before_logs = fingerprint_logs()
    def invariants():
        unit_inactive(runfn)
        if mysql_pid(runfn) != before_pid: raise Stop("mysql_service_pid_changed")
        if vendor_policy_state() != before_vendor_policy: raise Stop("vendor_apparmor_policy_changed")
        if [fingerprint_tree(p) for p in DATADIRS] != before_data: raise Stop("datadir_changed")
        if fingerprint_logs() != before_logs: raise Stop("mysql_logs_changed")
    journal_write({"schema": 1, "phase": "prepared", "old": OLD, "new": NEW,
                   "backup": {p: base64.b64encode(old_bytes[p]).decode("ascii") for p in ORDER}})
    journal_write({"schema": 1, "phase": "replacing", "old": OLD, "new": NEW,
                   "backup": {p: base64.b64encode(old_bytes[p]).decode("ascii") for p in ORDER}})
    try:
        for path in ORDER:
            replace(path, new_bytes[path], MODES[path])
            if sha(read_root_file(path, MODES[path])) != NEW[path]: raise Stop("post_write_hash_mismatch")
        load_profiles(runfn); enforce_state()
        code = "import runpy; h=runpy.run_path(%r); h['verify_static_install'](); h['check_base_config'](); print('STATIC_VERIFY=PASS')" % HELPER
        done = runfn(["/usr/bin/python3", "-I", "-B", "-c", code])
        if done.returncode or b"STATIC_VERIFY=PASS" not in done.stdout:
            raise Stop("new_helper_static_verification_failed")
        invariants()
        if any(sha(read_root_file(path, MODES[path])) != NEW[path] for path in ORDER):
            raise Stop("post_transaction_hash_mismatch")
        journal_write({"schema": 1, "phase": "committed", "old": OLD, "new": NEW,
                       "backup": {p: base64.b64encode(old_bytes[p]).decode("ascii") for p in ORDER}})
        os.unlink(JOURNAL)
        dfd = os.open(LIBEXEC, os.O_RDONLY | os.O_DIRECTORY)
        try: os.fsync(dfd)
        finally: os.close(dfd)
        return "G1_GO54A_APPARMOR=PASS"
    except BaseException as exc:
        restored = rollback(old_bytes, runfn, (before_pid, before_vendor_policy, before_data, before_logs))
        if restored and os.path.lexists(JOURNAL):
            os.unlink(JOURNAL)
        code = str(exc) if isinstance(exc, Stop) else "internal_error:" + exc.__class__.__name__
        raise Stop(code + ";ROLLED_BACK=" + ("yes" if restored else "NO"))


def main(package_dir):
    if os.geteuid() != 0:
        raise Stop("not_root")
    dfd = os.open("/run", os.O_RDONLY | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0))
    try:
        st = os.fstat(dfd)
        if not stat.S_ISDIR(st.st_mode) or (st.st_uid, st.st_gid) != (0, 0) or stat.S_IMODE(st.st_mode) & 0o022:
            raise Stop("lock_directory_unsafe")
    finally: os.close(dfd)
    fd = os.open(LOCK, os.O_RDWR | os.O_CREAT | getattr(os, "O_NOFOLLOW", 0), 0o600)
    try:
        st = os.fstat(fd)
        if not stat.S_ISREG(st.st_mode) or (st.st_uid, st.st_gid) != (0, 0) or stat.S_IMODE(st.st_mode) != 0o600:
            raise Stop("lock_file_unsafe")
        deadline = time.monotonic() + 30
        while True:
            try: fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB); break
            except BlockingIOError:
                if time.monotonic() >= deadline: raise Stop("lock_timeout")
                time.sleep(0.1)
        return _transaction(package_dir)
    finally:
        fcntl.flock(fd, fcntl.LOCK_UN); os.close(fd)


if __name__ == "__main__":
    try:
        if len(sys.argv) != 2:
            raise Stop("package_directory_argument_required")
        print(main(sys.argv[1]))
    except Stop as exc:
        print("G1_GO54A_APPARMOR=STOP;CODE=" + str(exc))
        raise SystemExit(3)
