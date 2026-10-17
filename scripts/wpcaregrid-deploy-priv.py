#!/usr/bin/python3
"""Narrow root helper for the WP CareGrid staging deploy controller.

This file is installed root-owned by an administrator from a reviewed commit.
The deployment controller never installs or updates it.
"""

import os
import hashlib
import re
import stat
import subprocess
import sys
from pathlib import Path


APP = Path("/var/www/wpcaregrid")
GATE_DIR = Path("/var/lib/wpcaregrid")
GATE = GATE_DIR / "deploy-maintenance"
SCHEDULER_LOCK = GATE_DIR / "scheduler.lock"
SCHEDULER_PAUSE = GATE_DIR / "scheduler-paused"
APPROVED_ROLLBACK = GATE_DIR / "approved-rollback-sha"
SCHEDULER_SOURCE = APP / "scripts/run-scheduler.sh"
SCHEDULER_DEST = Path("/usr/local/bin/wpcaregrid-scheduler")
OLD_CRON = "* * * * * cd /var/www/wpcaregrid && php artisan schedule:run >> /dev/null 2>&1"
GUARDED_CRON = "* * * * * /usr/local/bin/wpcaregrid-scheduler >> /dev/null 2>&1"
STAGING_DB = "wpcaregrid"
SCHEDULER_SOURCE_SHA256 = "de962446f29dea5bf13e33be6653ec77518ff81f48d5af1c1baec1335253885c"
WORKERS = {"wpcaregrid-default", "wpcaregrid-backups"}
SAFE_ENV = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C.UTF-8", "LC_ALL": "C.UTF-8"}


def run(argv, *, check=True, stdout=None, input_text=None, pass_fds=()):
    return subprocess.run(
        argv,
        check=check,
        stdin=subprocess.PIPE if input_text is not None else subprocess.DEVNULL,
        stdout=stdout,
        stderr=subprocess.DEVNULL,
        input=input_text,
        text=True,
        env=SAFE_ENV,
        close_fds=True,
        pass_fds=pass_fds,
    )


def require_regular(path, *, owner=None, max_size=None):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or path.is_symlink():
        raise ValueError("unsafe file")
    if owner is not None and info.st_uid != owner:
        raise ValueError("unexpected file owner")
    if max_size is not None and info.st_size > max_size:
        raise ValueError("file too large")
    return info


def open_directory(path):
    """Open an absolute directory without following any path-component symlink."""
    parts = Path(path).parts
    if not parts or parts[0] != "/":
        raise ValueError("directory must be absolute")
    fd = os.open("/", os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        for part in parts[1:]:
            next_fd = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd)
            fd = next_fd
        return fd
    except Exception:
        os.close(fd)
        raise


def ensure_gate_dir():
    try:
        GATE_DIR.mkdir(mode=0o755)
    except FileExistsError:
        pass
    info = GATE_DIR.lstat()
    if not stat.S_ISDIR(info.st_mode) or GATE_DIR.is_symlink() or info.st_uid != 0:
        raise ValueError("unsafe gate directory")
    os.chmod(GATE_DIR, 0o755)


def marker(path, enabled):
    ensure_gate_dir()
    if enabled:
        flags = os.O_WRONLY | os.O_CREAT | getattr(os, "O_NOFOLLOW", 0)
        fd = os.open(path, flags, 0o644)
        try:
            os.fchown(fd, 0, 0)
            os.fchmod(fd, 0o644)
        finally:
            os.close(fd)
    elif path.exists() or path.is_symlink():
        require_regular(path, owner=0)
        path.unlink()


def worker(action, name):
    if name not in WORKERS or action not in {"status", "start", "stop"}:
        raise ValueError("unsupported worker operation")
    result = run(["/usr/bin/supervisorctl", action, name], check=False, stdout=subprocess.PIPE)
    output = result.stdout or ""
    if action == "status":
        sys.stdout.write(normalize_worker_status(name, output))
        return
    if result.returncode:
        raise ValueError("worker operation failed")
    sys.stdout.write(output)


def normalize_worker_status(name, output):
    """Normalize one fixed Supervisor status line, including STOPPED exit codes."""
    lines = output.splitlines()
    if len(lines) != 1:
        raise ValueError("unexpected worker status response")
    line = lines[0].strip()
    running = re.fullmatch(
        rf"{re.escape(name)}\s+RUNNING\s+pid\s+(\d+)(?:,\s+uptime\s+.+)?",
        line,
    )
    if running:
        return f"{name} RUNNING pid {running.group(1)}\n"
    states = "STOPPED|STARTING|BACKOFF|STOPPING|EXITED|FATAL|UNKNOWN"
    state = re.fullmatch(rf"{re.escape(name)}\s+({states})(?:\s+.*)?", line)
    if state:
        return f"{name} {state.group(1)}\n"
    raise ValueError("unexpected worker status response")


def scheduler_lock_init():
    import grp
    import pwd

    ensure_gate_dir()
    try:
        fd = os.open(SCHEDULER_LOCK, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o660)
    except FileExistsError:
        fd = os.open(SCHEDULER_LOCK, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode):
            raise ValueError("unsafe scheduler lock")
        os.fchown(fd, pwd.getpwnam("deploy").pw_uid, grp.getgrnam("www-data").gr_gid)
        os.fchmod(fd, 0o660)
    finally:
        os.close(fd)


def install_scheduler():
    import pwd

    destination_fd = open_directory(SCHEDULER_DEST.parent)
    parent = os.fstat(destination_fd)
    if parent.st_uid != 0 or parent.st_mode & 0o022:
        os.close(destination_fd)
        raise ValueError("unsafe scheduler destination directory")
    source_dir_fd = open_directory(SCHEDULER_SOURCE.parent)
    try:
        source_fd = os.open(SCHEDULER_SOURCE.name, os.O_RDONLY | os.O_NOFOLLOW, dir_fd=source_dir_fd)
    finally:
        os.close(source_dir_fd)
    info = os.fstat(source_fd)
    if not stat.S_ISREG(info.st_mode) or info.st_uid != pwd.getpwnam("deploy").pw_uid or info.st_size > 32768:
        os.close(source_fd)
        os.close(destination_fd)
        raise ValueError("unsafe scheduler source")
    if info.st_mode & 0o022:
        os.close(source_fd)
        os.close(destination_fd)
        raise ValueError("scheduler source is group/world writable")
    with os.fdopen(source_fd, "rb", closefd=False) as source_file:
        data = source_file.read(32769)
    if len(data) != info.st_size or hashlib.sha256(data).hexdigest() != SCHEDULER_SOURCE_SHA256:
        os.close(source_fd)
        os.close(destination_fd)
        raise ValueError("scheduler source hash mismatch")
    os.close(source_fd)
    if not data.startswith(b"#!/usr/bin/env bash\n") or b"/var/www/wpcaregrid" not in data:
        os.close(destination_fd)
        raise ValueError("scheduler source contract mismatch")
    try:
        existing = os.open(SCHEDULER_DEST.name, os.O_RDONLY | os.O_NOFOLLOW, dir_fd=destination_fd)
    except FileNotFoundError:
        existing = None
    if existing is not None:
        existing_info = os.fstat(existing)
        os.close(existing)
        if not stat.S_ISREG(existing_info.st_mode) or existing_info.st_uid != 0:
            os.close(destination_fd)
            raise ValueError("unsafe existing scheduler wrapper")
    temp_name = SCHEDULER_DEST.name + ".new"
    try:
        existing_temp = os.open(temp_name, os.O_RDONLY | os.O_NOFOLLOW, dir_fd=destination_fd)
    except FileNotFoundError:
        existing_temp = None
    if existing_temp is not None:
        temp_info = os.fstat(existing_temp)
        os.close(existing_temp)
        if not stat.S_ISREG(temp_info.st_mode) or temp_info.st_uid != 0:
            os.close(destination_fd)
            raise ValueError("unsafe temporary scheduler wrapper")
        os.unlink(temp_name, dir_fd=destination_fd)
    fd = os.open(temp_name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o755, dir_fd=destination_fd)
    try:
        view = memoryview(data)
        while view:
            view = view[os.write(fd, view):]
        os.fchown(fd, 0, 0)
        os.fchmod(fd, 0o755)
        os.fsync(fd)
    finally:
        os.close(fd)
    os.replace(temp_name, SCHEDULER_DEST.name, src_dir_fd=destination_fd, dst_dir_fd=destination_fd)
    os.close(destination_fd)


def ensure_scheduler_cron():
    result = run(["/usr/bin/crontab", "-u", "www-data", "-l"], check=False, stdout=subprocess.PIPE)
    if result.returncode:
        raise ValueError("cannot read www-data crontab")
    lines = (result.stdout or "").splitlines()
    if lines.count(GUARDED_CRON) == 1 and not any("schedule:run" in line for line in lines):
        return
    if lines.count(OLD_CRON) != 1 or lines.count(GUARDED_CRON) != 0:
        raise ValueError("www-data crontab does not match reviewed scheduler entry")
    updated = "\n".join(GUARDED_CRON if line == OLD_CRON else line for line in lines) + "\n"
    run(["/usr/bin/crontab", "-u", "www-data", "-"], input_text=updated)


def cron_seen(since):
    if not re.fullmatch(r"\d{4}-\d\d-\d\d \d\d:\d\d:\d\d UTC", since):
        raise ValueError("invalid timestamp")
    process = subprocess.Popen(
        ["/usr/bin/journalctl", "-u", "cron", "--since", since, "-n", "2000", "-o", "cat", "--no-pager"],
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        env=SAFE_ENV,
        close_fds=True,
        text=True,
    )
    found = False
    for line in process.stdout:
        if SCHEDULER_DEST.as_posix() in line:
            found = True
            break
    if process.poll() is None:
        process.terminate()
    process.wait(timeout=3)
    if not found:
        raise ValueError("scheduler invocation not observed")


def app_file(relative):
    if not re.fullmatch(r"[A-Za-z0-9_./-]{1,240}", relative) or ".." in Path(relative).parts:
        raise ValueError("invalid application path")
    path = APP / relative
    resolved = path.resolve(strict=True)
    if not resolved.is_relative_to(APP) or path.is_symlink():
        raise ValueError("application path escaped or is a symlink")
    current = path
    while current != APP:
        if current.is_symlink():
            raise ValueError("application path contains a symlink")
        current = current.parent
    return resolved


def access_check(user, test, relative):
    if user not in {"www-data", "nobody"} or test not in {"read", "write", "execute", "not-readable"}:
        raise ValueError("unsupported access check")
    path = app_file(relative)
    flag = {"read": "-r", "write": "-w", "execute": "-x", "not-readable": "!"}[test]
    if test == "not-readable":
        result = run(["/usr/sbin/runuser", "-u", user, "--", "/usr/bin/test", "!", "-r", str(path)], check=False)
    else:
        result = run(["/usr/sbin/runuser", "-u", user, "--", "/usr/bin/test", flag, str(path)], check=False)
    if result.returncode:
        raise ValueError("access check failed")


def runtime_cache_acls():
    import pwd

    for relative in ("bootstrap/cache", "storage/framework/views"):
        path = APP / relative
        fd = open_directory(path)
        info = os.fstat(fd)
        if info.st_uid != pwd.getpwnam("deploy").pw_uid:
            os.close(fd)
            raise ValueError("runtime cache path is not a directory")
        fd_path = f"/proc/self/fd/{fd}"
        run(["/usr/bin/setfacl", "-k", fd_path], pass_fds=(fd,))
        run(["/usr/bin/setfacl", "-m", "d:u::rwx,d:u:deploy:rwx,d:u:www-data:rwx,d:g::---,d:m::rwx,d:o::---", fd_path], pass_fds=(fd,))
        os.close(fd)


def secure_cache_file(relative):
    parts = Path(relative).parts
    if not parts:
        raise ValueError("cache file outside approved paths")
    directory_parts = parts[:-1]
    if directory_parts not in (("bootstrap", "cache"), ("storage", "framework", "views")) or not re.fullmatch(r"[A-Za-z0-9_.-]{1,180}\.php", parts[-1]):
        raise ValueError("cache file outside approved paths")
    directory = open_directory(APP / Path(*directory_parts))
    file_fd = os.open(parts[-1], os.O_RDONLY | os.O_NOFOLLOW, dir_fd=directory)
    try:
        info = os.fstat(file_fd)
        if not stat.S_ISREG(info.st_mode):
            raise ValueError("cache entry is not a regular file")
        os.fchmod(file_fd, 0o600)
        fd_path = f"/proc/self/fd/{file_fd}"
        run(["/usr/bin/setfacl", "-b", fd_path], pass_fds=(file_fd,))
        run(["/usr/bin/setfacl", "-m", "u:www-data:r--,g::---", fd_path], pass_fds=(file_fd,))
    finally:
        os.close(file_fd)
        os.close(directory)


def nginx_gate_check():
    run(["/usr/sbin/nginx", "-t"])
    result = run(["/usr/sbin/nginx", "-T"], check=False, stdout=subprocess.PIPE)
    if result.returncode or "if (-f /var/lib/wpcaregrid/deploy-maintenance)" not in (result.stdout or ""):
        raise ValueError("reviewed nginx gate is not configured")


def vendor_readable():
    result = run(
        ["/usr/sbin/runuser", "-u", "www-data", "--", "/usr/bin/find", str(APP / "vendor"), "-type", "f", "-name", "*.php", "!", "-readable", "-print", "-quit"],
        check=False,
        stdout=subprocess.PIPE,
    )
    if result.returncode or (result.stdout or ""):
        raise ValueError("vendor contains unreadable PHP files")


def read_approved_rollback(path=APPROVED_ROLLBACK, expected_owner=0, expected_mode=0o600):
    """Read one administrator-installed prior release SHA; never write it here."""
    fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != expected_owner or (expected_mode is not None and stat.S_IMODE(info.st_mode) != expected_mode) or info.st_size > 41:
            raise ValueError("unsafe rollback authorization file")
        content = os.read(fd, 42)
    finally:
        os.close(fd)
    try:
        value = content.decode("ascii")
    except UnicodeDecodeError:
        raise ValueError("invalid rollback authorization") from None
    if not re.fullmatch(r"[0-9a-f]{40}\n", value):
        raise ValueError("invalid rollback authorization")
    return value[:-1]


def dispatch(args):
    if args == ["preflight"]:
        nginx_gate_check()
        run(["/usr/bin/systemctl", "is-active", "--quiet", "cron"])
    elif args == ["nginx-check"]:
        run(["/usr/sbin/nginx", "-t"])
    elif args == ["nginx-gate-check"]:
        nginx_gate_check()
    elif args == ["cron-service-check"]:
        run(["/usr/bin/systemctl", "is-active", "--quiet", "cron"])
    elif args == ["vendor-readable"]:
        vendor_readable()
    elif len(args) == 2 and args[0] == "rollback-allowed":
        if not re.fullmatch(r"[0-9a-f]{40}", args[1]) or read_approved_rollback() != args[1]:
            raise ValueError("rollback target is not administrator-approved")
    elif args == ["scheduler-lock-check"]:
        import grp
        import pwd

        info = require_regular(SCHEDULER_LOCK, owner=pwd.getpwnam("deploy").pw_uid)
        if info.st_gid != grp.getgrnam("www-data").gr_gid or stat.S_IMODE(info.st_mode) != 0o660:
            raise ValueError("scheduler lock permissions invalid")
    elif args == ["scheduler-resume"]:
        marker(SCHEDULER_PAUSE, False)
    elif args == ["gate-dir-init"]:
        ensure_gate_dir()
    elif args == ["scheduler-lock-init"]:
        scheduler_lock_init()
    elif args == ["scheduler-install"]:
        install_scheduler()
    elif args == ["scheduler-cron-ensure"]:
        ensure_scheduler_cron()
    elif len(args) == 2 and args[0] == "cron-seen":
        cron_seen(args[1])
    elif args == ["scheduler-probe"]:
        result = subprocess.run(
            ["/usr/sbin/runuser", "-u", "www-data", "--", str(SCHEDULER_DEST)],
            check=False,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            env=SAFE_ENV,
            close_fds=True,
        )
        if result.returncode or result.stdout or result.stderr.strip() != "wpcaregrid scheduler skipped: deployment guard active":
            raise ValueError("scheduler guard probe failed")
    elif args == ["scheduler-pause"]:
        marker(SCHEDULER_PAUSE, True)
    elif args == ["gate-on"]:
        marker(GATE, True)
    elif args == ["gate-off"]:
        marker(GATE, False)
    elif len(args) == 2 and args[0] == "worker" and args[1] in {"status-all", "stop-all", "start-all"}:
        action = {"status-all": "status", "stop-all": "stop", "start-all": "start"}[args[1]]
        for name in sorted(WORKERS):
            worker(action, name)
    elif len(args) == 3 and args[0] == "worker":
        worker(args[1], args[2])
    elif args == ["cache-acl"]:
        runtime_cache_acls()
    elif len(args) == 4 and args[0] == "file-check":
        access_check(args[1], args[2], args[3])
    elif len(args) == 2 and args[0] == "cache-file-secure":
        secure_cache_file(args[1])
    elif args == ["db-ping"]:
        run(["/usr/bin/mysql", "-NBe", "SELECT 1"], stdout=subprocess.DEVNULL)
    elif args == ["db-dump"]:
        run(["/usr/bin/mysqldump", "--single-transaction", "--routines", "--triggers", "--databases", STAGING_DB], stdout=None)
    else:
        raise ValueError("unsupported privileged operation")


def main():
    os.environ.clear()
    os.environ.update(SAFE_ENV)
    if os.geteuid() != 0:
        print("privileged helper requires root", file=sys.stderr)
        return 77
    try:
        dispatch(sys.argv[1:])
    except Exception:
        # Never echo arguments, paths, database names, or external command output.
        print("wpcaregrid deploy helper: operation refused or failed", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
