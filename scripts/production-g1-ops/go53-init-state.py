#!/usr/bin/env python3
"""GO-5.3 recovery diagnostic: classify the initialization state of both Production G1 MySQL datadirs. READ-ONLY.

Writes, deletes, starts, stops and signals nothing and never prints file contents: the instance error log contains the
generated root password, so it is only scanned for the server's own marker lines (booleans and counts are printed).
Fixed paths only; no argument. Usage (root): python3 -I -B go53-init-state.py

Per instance one of:
  EMPTY            datadir exactly as `provision-paths` created it (empty, mysql:mysql 0750), empty log, no process
  PROVEN_COMPLETE  a finished `mysqld --initialize`: the exact datadir contents, the server's own start line followed
                   by its end line in the error log, no [ERROR] line, no process, no socket/pid marker
  PARTIAL          sane but unfinished: initialization artefacts present, end marker missing (and nothing else odd)
  UNCLEAR          anything else (symlink, foreign owner/group/mode, special file, running process, end marker without the
                   files, error lines, unreadable ...). The completion of an UNCLEAR or PARTIAL datadir is NOT proven.
Final line:  G1_GO53_INIT_STATE=DONE;WPCAREGRID_PRODUCTION=<state>;WPCAREGRID_PRODUCTION_RESTORE=<state>   (exit 0)
             G1_GO53_INIT_STATE=STOP;CODE=<cause>                                                        (exit 3/4)
"""
import grp
import os
import pwd
import re
import stat
import subprocess
import sys

INSTANCES = {
    "wpcaregrid-production": {
        "datadir": "/var/lib/mysql-wpcaregrid-production", "log": "/var/log/mysql/wpcaregrid-production.log",
        "socket": "/run/mysqld/wpcaregrid-production.sock", "pid": "/run/mysqld/wpcaregrid-production.pid",
        "tmpdir": "/var/tmp/wpcaregrid-production", "unit": "mysql@wpcaregrid-production.service"},
    "wpcaregrid-production-restore": {
        "datadir": "/var/lib/mysql-wpcaregrid-production-restore", "log": "/var/log/mysql/wpcaregrid-production-restore.log",
        "socket": "/run/mysqld/wpcaregrid-production-restore.sock", "pid": "/run/mysqld/wpcaregrid-production-restore.pid",
        "tmpdir": "/var/tmp/wpcaregrid-production-restore", "unit": "mysql@wpcaregrid-production-restore.service"},
}
REQUIRED_DIRS = ("mysql", "sys", "performance_schema", "#innodb_redo")
REQUIRED_FILES = ("auto.cnf", "ibdata1", "mysql.ibd", "undo_001", "undo_002", "mysql_upgrade_history")
START = b"[MY-015017] [Server] MySQL Server Initialization - start."
END = b"[MY-015018] [Server] MySQL Server Initialization - end."
INNODB_STARTED, INNODB_ENDED = b"[MY-013576] [InnoDB] InnoDB initialization has started.", b"[MY-013577] [InnoDB] InnoDB initialization has ended."
LOG_LIMIT = 2 * 1024 * 1024
WALK_LIMIT = 200000
SAFE_NAME = re.compile(r"^[A-Za-z0-9_.#@+:-]{1,120}$")
SAFE_PATH = re.compile(r"^[A-Za-z0-9_./#@+:-]{1,200}$")
ENV = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "HOME": "/nonexistent", "LANG": "C", "LC_ALL": "C"}


class Stop(Exception):
    pass


def default_run(args):
    done = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=30, check=False,
                          close_fds=True, stdin=subprocess.DEVNULL, env=ENV)
    return done.returncode, done.stdout.decode("utf-8", "replace")


def live_processes(proc_root="/proc"):
    for entry in os.listdir(proc_root):
        if not entry.isdigit():
            continue
        try:
            with open(f"{proc_root}/{entry}/stat", encoding="ascii", errors="replace") as handle:
                text = handle.read()
            with open(f"{proc_root}/{entry}/cmdline", "rb") as handle:
                raw = handle.read()
            state = text[text.rindex(")") + 2:].split()[0]
        except (OSError, ValueError, IndexError):
            continue
        if state in ("Z", "X") or int(entry) == os.getpid():
            continue
        yield int(entry), [a.decode("utf-8", "replace") for a in raw.split(b"\0") if a]


PUBLIC_DATADIR_FILES = frozenset({"ca.pem", "server-cert.pem", "client-cert.pem", "public_key.pem"})
PRIVATE_DATADIR_FILES = frozenset({"ca-key.pem", "server-key.pem", "client-key.pem", "private_key.pem"})


def datadir_entry_problems(info, uid, gid, *, public_file=False, private_file=False):
    """Return stable refusal reasons for one lstat result from the datadir walk."""
    problems = []
    is_dir, is_file = stat.S_ISDIR(info.st_mode), stat.S_ISREG(info.st_mode)
    if stat.S_ISLNK(info.st_mode):
        problems.append("symlink_in_datadir")
    elif not (is_dir or is_file):
        problems.append("special_file_in_datadir")
    if info.st_uid != uid:
        problems.append("foreign_owner_in_datadir")
    if info.st_gid != gid:
        problems.append("foreign_group_in_datadir")
    mode = stat.S_IMODE(info.st_mode)
    if is_dir:
        if mode & 0o007 or mode & 0o020 or mode & 0o700 != 0o700 or mode & 0o7000:
            problems.append("unsafe_mode_in_datadir")
    elif is_file:
        if public_file:
            if mode != 0o644:
                problems.append("unsafe_mode_in_datadir")
        elif private_file:
            if mode != 0o600:
                problems.append("unsafe_mode_in_datadir")
        elif mode & 0o007 or mode & 0o020 or mode & 0o600 != 0o600 or mode & 0o7111:
            problems.append("unsafe_mode_in_datadir")
    return problems


def same_entry_snapshot(before, opened):
    return ((before.st_dev, before.st_ino, before.st_mode, before.st_uid, before.st_gid)
            == (opened.st_dev, opened.st_ino, opened.st_mode, opened.st_uid, opened.st_gid))


def scan_datadir_fd(root_fd, uid, gid, unclear):
    """Traverse using held descriptors; never resolve child names through a replaceable path."""
    stack = [(os.dup(root_fd), True)]
    seen = size = 0
    directory_flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
    entry_flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
    try:
        while stack:
            current_fd, top_level = stack.pop()
            try:
                try:
                    names = sorted(os.listdir(current_fd))
                except OSError:
                    unclear.append("datadir_unreadable")
                    continue
                for entry in names:
                    seen += 1
                    if seen > WALK_LIMIT:
                        unclear.append("datadir_too_large_to_scan")
                        return size
                    try:
                        before = os.stat(entry, dir_fd=current_fd, follow_symlinks=False)
                    except OSError:
                        unclear.append("entry_unreadable")
                        continue
                    public_file = top_level and entry in PUBLIC_DATADIR_FILES
                    private_file = entry in PRIVATE_DATADIR_FILES
                    unclear.extend(datadir_entry_problems(before, uid, gid, public_file=public_file,
                                                         private_file=private_file))
                    if stat.S_ISLNK(before.st_mode) or not (stat.S_ISDIR(before.st_mode) or stat.S_ISREG(before.st_mode)):
                        continue
                    flags = directory_flags if stat.S_ISDIR(before.st_mode) else entry_flags
                    try:
                        entry_fd = os.open(entry, flags, dir_fd=current_fd)
                    except OSError:
                        unclear.append("entry_unreadable")
                        continue
                    try:
                        opened = os.fstat(entry_fd)
                        if not same_entry_snapshot(before, opened):
                            unclear.append("entry_changed_during_read")
                            continue
                        unclear.extend(datadir_entry_problems(opened, uid, gid, public_file=public_file,
                                                             private_file=private_file))
                        if stat.S_ISDIR(opened.st_mode):
                            stack.append((entry_fd, False))
                            entry_fd = -1
                        else:
                            size += opened.st_size
                    finally:
                        if entry_fd >= 0:
                            os.close(entry_fd)
            finally:
                os.close(current_fd)
    finally:
        for pending_fd, _ in stack:
            os.close(pending_fd)
    return size


def classify(name, spec, *, root="/", proc_root="/proc", ids=None):
    """Returns (state, reasons, facts). Read-only."""
    R = lambda p: root.rstrip("/") + p  # noqa: E731
    uid, gid = ids() if ids else (pwd.getpwnam("mysql").pw_uid, grp.getgrnam("mysql").gr_gid)
    facts = {"entries": 0, "size": 0, "top": [], "log_size": None, "start": False, "end": False,
             "innodb_started": False, "innodb_ended": False, "errors": 0, "processes": 0, "initializer": 0,
             "socket": "absent", "pid": "absent", "missing": []}
    unclear = []
    suffix = f"--defaults-group-suffix=@{name}"
    procs = [argv for _, argv in live_processes(proc_root) if suffix in argv]
    facts["processes"] = len(procs)
    facts["initializer"] = sum(1 for argv in procs if "--initialize" in argv)
    if facts["initializer"]:
        unclear.append("initializer_running")
    elif procs:
        unclear.append("instance_process_present")
    for key in ("socket", "pid"):
        if os.path.lexists(R(spec[key])):
            facts[key] = "PRESENT"
            unclear.append("runtime_marker_present")
    datadir = R(spec["datadir"])
    try:
        st = os.lstat(datadir)
    except OSError:
        return "UNCLEAR", unclear + ["datadir_missing"], facts
    if not stat.S_ISDIR(st.st_mode):
        return "UNCLEAR", unclear + ["datadir_not_a_directory"], facts
    if st.st_uid != uid or st.st_gid != gid or stat.S_IMODE(st.st_mode) != 0o750:
        unclear.append("datadir_owner_or_mode")
    root_fd = None
    try:
        root_fd = os.open(datadir, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
        opened_root = os.fstat(root_fd)
        if not same_entry_snapshot(st, opened_root):
            os.close(root_fd)
            return "UNCLEAR", unclear + ["datadir_changed_during_read"], facts
        top = sorted(os.listdir(root_fd))
    except OSError:
        if root_fd is not None:
            os.close(root_fd)
        return "UNCLEAR", unclear + ["datadir_unreadable"], facts
    facts["entries"] = len(top)
    facts["top"] = [n if SAFE_NAME.fullmatch(n) else "<unsafe-name>" for n in top[:40]]
    missing = []
    for entry in REQUIRED_DIRS + REQUIRED_FILES:
        try:
            info = os.stat(entry, dir_fd=root_fd, follow_symlinks=False)
        except FileNotFoundError:
            missing.append(entry)
        except OSError:
            unclear.append("required_entry_unreadable")
        else:
            want_dir = entry in REQUIRED_DIRS
            if (stat.S_ISDIR(info.st_mode) if want_dir else stat.S_ISREG(info.st_mode)) is not True:
                unclear.append("required_entry_wrong_type")
    try:
        # The root descriptor pins the checked directory. Child directories are opened relative to held fds
        # with O_NOFOLLOW and checked against their lstat snapshot before traversal.
        facts["size"] = scan_datadir_fd(root_fd, uid, gid, unclear)
    finally:
        os.close(root_fd)
    # error log: scanned for the server's own marker lines only, never printed
    log = b""
    try:
        info = os.lstat(R(spec["log"]))
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != uid or info.st_gid != gid
                or stat.S_IMODE(info.st_mode) != 0o600):
            unclear.append("log_type_owner_or_mode")
        else:
            fd = os.open(R(spec["log"]), os.O_RDONLY | os.O_NONBLOCK | getattr(os, "O_NOFOLLOW", 0))
            with os.fdopen(fd, "rb") as handle:
                opened = os.fstat(handle.fileno())
                if not same_entry_snapshot(info, opened):
                    unclear.append("log_changed_during_read")
                elif (not stat.S_ISREG(opened.st_mode) or opened.st_uid != uid or opened.st_gid != gid
                      or stat.S_IMODE(opened.st_mode) != 0o600):
                    unclear.append("log_type_owner_or_mode")
                else:
                    facts["log_size"] = opened.st_size
                    log = handle.read(LOG_LIMIT)
    except OSError:
        unclear.append("log_unavailable")
    start, end = log.find(START), log.rfind(END)
    facts.update(start=start >= 0, end=end >= 0 and end > start >= 0, innodb_started=INNODB_STARTED in log,
                 innodb_ended=INNODB_ENDED in log, errors=log.count(b"[ERROR]"))
    facts["missing"] = missing
    if unclear:
        return "UNCLEAR", sorted(set(unclear)), facts
    if facts["entries"] == 0:
        if facts["log_size"] == 0:
            return "EMPTY", [], facts
        return "UNCLEAR", ["datadir_empty_but_log_not_empty"], facts
    if facts["end"]:
        if missing:
            return "UNCLEAR", ["end_marker_but_required_entries_missing"], facts
        if facts["errors"]:
            return "UNCLEAR", ["end_marker_but_error_lines"], facts
        return "PROVEN_COMPLETE", [], facts
    if not facts["start"]:
        return "UNCLEAR", ["datadir_not_empty_but_log_has_no_start_marker"], facts
    reasons = ["log_end_marker_missing"] + (["required_entries_missing"] if missing else [])
    return "PARTIAL", reasons, facts


def kernel_denials(run=default_run):
    try:
        rc, out = run(["/usr/bin/dmesg", "--color=never"])
    except (OSError, subprocess.SubprocessError):
        return None
    if rc != 0:
        return None
    counts = {}
    for line in out.splitlines():
        if 'apparmor="DENIED"' not in line or 'profile="wpcaregrid-mysql' not in line:
            continue
        fields = dict(re.findall(r'(\w+)="([^"]*)"', line))
        key = tuple(fields.get(k, "?") for k in ("profile", "operation", "name", "denied_mask"))
        if all(SAFE_PATH.fullmatch(part) for part in key):
            counts[key] = counts.get(key, 0) + 1
    return counts


def report(states, facts_by, denials, *, out=print):
    for name, (state, reasons) in states.items():
        f = facts_by[name]
        out(f"INSTANCE={name}")
        out(f"  DATADIR entries={f['entries']} size_bytes={f['size']} top={','.join(f['top']) or '-'}")
        out(f"  LOG size={f['log_size']} start={'yes' if f['start'] else 'no'} end={'yes' if f['end'] else 'no'} "
            f"innodb_started={'yes' if f['innodb_started'] else 'no'} innodb_ended={'yes' if f['innodb_ended'] else 'no'} errors={f['errors']}")
        out(f"  PROCESSES count={f['processes']} initializer={f['initializer']}")
        out(f"  MARKERS socket={f['socket']} pid={f['pid']}")
        out(f"  MISSING={','.join(f['missing']) or '-'}")
        out(f"  STATE={state};REASONS={','.join(reasons) or '-'}")
    if denials is None:
        out("KERNEL_DENIALS=UNAVAILABLE")
    else:
        out(f"KERNEL_DENIALS=distinct:{len(denials)};total:{sum(denials.values())}")
        for (profile, operation, path, mask), count in sorted(denials.items(), key=lambda kv: -kv[1])[:20]:
            out(f"  DENIED count={count} profile={profile} operation={operation} name={path} mask={mask}")


def main(root="/", proc_root="/proc", ids=None, euid=None, run=default_run, out=print):
    if (os.geteuid() if euid is None else euid) != 0:
        raise Stop("not_root")
    states, facts_by = {}, {}
    for name, spec in INSTANCES.items():
        state, reasons, facts = classify(name, spec, root=root, proc_root=proc_root, ids=ids)
        states[name], facts_by[name] = (state, reasons), facts
    report(states, facts_by, kernel_denials(run), out=out)
    out("G1_GO53_INIT_STATE=DONE;WPCAREGRID_PRODUCTION=%s;WPCAREGRID_PRODUCTION_RESTORE=%s" % (
        states["wpcaregrid-production"][0], states["wpcaregrid-production-restore"][0]))


if __name__ == "__main__":
    if len(sys.argv) != 1:
        print("G1_GO53_INIT_STATE=STOP;CODE=usage")
        raise SystemExit(2)
    try:
        main()
    except Stop as exc:
        print(f"G1_GO53_INIT_STATE=STOP;CODE={exc}")
        raise SystemExit(3)
    except BaseException as exc:
        print(f"G1_GO53_INIT_STATE=STOP;CODE=internal_error:{exc.__class__.__name__}")
        raise SystemExit(4)
