#!/usr/bin/python3
"""Narrow root helper for the WP CareGrid staging deploy controller.

This file is installed root-owned by an administrator from a reviewed commit.
The deployment controller never installs or updates it.
"""

import os
import hashlib
import glob
import fnmatch
import json
import posixpath
import re
import socket
import stat
import struct
import subprocess
import sys
import threading
import time
import uuid
from contextlib import contextmanager
from pathlib import Path, PurePosixPath


APP = Path("/var/www/wpcaregrid")
GATE_DIR = Path("/var/lib/wpcaregrid")
GATE = GATE_DIR / "deploy-maintenance"
SCHEDULER_LOCK = GATE_DIR / "scheduler.lock"
SCHEDULER_PAUSE = GATE_DIR / "scheduler-paused"
WORKERS_DISABLED = GATE_DIR / "workers-disabled"
APPROVED_ROLLBACK = GATE_DIR / "approved-rollback-sha"
SCHEDULER_SOURCE = APP / "scripts/run-scheduler.sh"
SCHEDULER_DEST = Path("/usr/local/bin/wpcaregrid-scheduler")
PHP_RUNTIME_LINK = Path("/etc/nginx/snippets/wpcaregrid-staging-runtime.conf")
PHP_RUNTIME_FILES = {
    "8.4": Path("/etc/nginx/snippets/wpcaregrid-staging-php84.conf"),
    "8.5": Path("/etc/nginx/snippets/wpcaregrid-staging-php85.conf"),
}
PHP_RUNTIME_DIRECTIVES = {
    "8.4": "fastcgi_pass unix:/run/php/php8.4-fpm.sock;\n",
    "8.5": "fastcgi_pass unix:/run/php/php8.5-fpm.sock;\n",
}
PHP_RUNTIME_BINARIES = {"8.4": Path("/usr/bin/php8.4"), "8.5": Path("/usr/bin/php8.5")}
PHP_RUNTIME_FPM_BINARIES = {"8.4": Path("/usr/sbin/php-fpm8.4"), "8.5": Path("/usr/sbin/php-fpm8.5")}
PHP_RUNTIME_FPM_CONFIGS = {"8.4": Path("/etc/php/8.4/fpm/php-fpm.conf"), "8.5": Path("/etc/php/8.5/fpm/php-fpm.conf")}
PHP_RUNTIME_SERVICES = {"8.4": "php8.4-fpm", "8.5": "php8.5-fpm"}
PHP_RUNTIME_FPM_CGROUPS = {"8.4": "/system.slice/php8.4-fpm.service", "8.5": "/system.slice/php8.5-fpm.service"}
PHP_RUNTIME_SOCKETS = {"8.4": Path("/run/php/php8.4-fpm.sock"), "8.5": Path("/run/php/php8.5-fpm.sock")}
PHP_RUNTIME_VHOST = "staging.wpcaregrid.com"
NGINX_ROOT_CONFIG = "/etc/nginx/nginx.conf"
NGINX_CONFIG_MAX_BYTES = 262144
NGINX_CONFIG_MAX_FILES = 256
NGINX_CONFIG_MAX_DEPTH = 32
NGINX_CONFIG_MAX_EXPANDED_NODES = 20000
PHP_RUNTIME_LAUNCHER_DEST = Path("/usr/local/bin/wpcaregrid-php")
SUPERVISOR_CONFIG = Path("/etc/supervisor/conf.d/wpcaregrid.conf")
OLD_CRON = "* * * * * cd /var/www/wpcaregrid && php artisan schedule:run >> /dev/null 2>&1"
GUARDED_CRON = "* * * * * /usr/local/bin/wpcaregrid-scheduler >> /dev/null 2>&1"
STAGING_DB = "wpcaregrid"
SCHEDULER_SOURCE_SHA256 = "de962446f29dea5bf13e33be6653ec77518ff81f48d5af1c1baec1335253885c"
PHP_RUNTIME_LAUNCHER_SHA256 = "4e7699229435e1fe21d1b436c8e10e44901ad5794c1b16cad0df18f7778d264c"
SCHEDULER_RUNTIME_SOURCE_SHA256 = "94470bbd9f13cc383723c17d4686e77aff0d5d13d5eb3290e5888911c776e5d4"
WORKER_COMMANDS = {
    "wpcaregrid-default": "/usr/local/bin/wpcaregrid-php /var/www/wpcaregrid/artisan queue:work database --queue=default --sleep=3 --tries=3 --backoff=60 --timeout=120 --max-time=3600 --memory=256",
    "wpcaregrid-backups": "/usr/local/bin/wpcaregrid-php /var/www/wpcaregrid/artisan queue:work backups --queue=backups --sleep=3 --timeout=3660 --max-time=14400 --memory=512",
}
WORKERS = {"wpcaregrid-default", "wpcaregrid-backups"}
SAFE_ENV = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C.UTF-8", "LC_ALL": "C.UTF-8"}

# Gate 1 transition paths are declarations only. The transition adapter below is
# deliberately not wired into dispatch(), deploy-staging.sh, or any consumer.
TRANSITION_JOURNAL = Path("/var/lib/wpcaregrid/transitions/journal.jsonl")
TRANSITION_LOCK = Path("/run/lock/wpcaregrid-transition.lock")
TRANSITION_STATUS = Path("/run/wpcaregrid/transition-status.json")
TRANSITION_PENDING = Path("/run/wpcaregrid/.transition-status.next")
TRANSITION_GENESIS_HASH = "0" * 64
TRANSITION_STATES = {
    "OPEN", "HOLD_REQUESTED", "CLOSING", "CLOSED", "DRAINING",
    "QUIESCED", "READY_PENDING_OWNER", "RECOVERY_REQUIRED",
}
TRANSITION_EDGES = {
    ("OPEN", "HOLD_REQUESTED"),
    ("HOLD_REQUESTED", "CLOSING"),
    ("CLOSING", "CLOSED"),
    ("CLOSED", "DRAINING"),
    ("DRAINING", "QUIESCED"),
    ("QUIESCED", "READY_PENDING_OWNER"),
    ("READY_PENDING_OWNER", "OPEN"),
    ("OPEN", "RECOVERY_REQUIRED"),
    ("HOLD_REQUESTED", "RECOVERY_REQUIRED"),
    ("CLOSING", "RECOVERY_REQUIRED"),
    ("CLOSED", "RECOVERY_REQUIRED"),
    ("DRAINING", "RECOVERY_REQUIRED"),
    ("QUIESCED", "RECOVERY_REQUIRED"),
    ("READY_PENDING_OWNER", "RECOVERY_REQUIRED"),
}
TRANSITION_TUPLE_FIELDS = (
    "state", "operation_id", "generation", "boot_id", "journal_seq",
    "journal_head_hash", "evidence_digest",
)
TRANSITION_RPC_VERSION = 1
TRANSITION_RPC_MAX_BYTES = 65536
TRANSITION_RPC_SOCKET_DIR = Path("/run/wpcaregrid")
TRANSITION_RPC_COMMAND = (
    str(PHP_RUNTIME_LAUNCHER_DEST), str(APP / "artisan"), "deployment:transition-rpc",
)
TRANSITION_RPC_OPERATIONS = frozenset({
    "read_tuple",
    "read_permit_manifest_hash",
    "verify_transition_preconditions",
    "validate_permit_manifest",
    "compare_and_set",
    "nonce_consumed",
    "validate_release",
    "assert_no_unresolved_drain_effects",
    "read_transition_receipt",
})
TRANSITION_PRODUCTION_EFFECT_CATALOG = ()


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


def open_root_trusted_directory(path):
    """Open a fixed root-owned directory chain with no symlinks or writable components."""
    parts = Path(path).parts
    if not parts or parts[0] != "/":
        raise ValueError("directory must be absolute")
    fd = os.open("/", os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        for part in parts[1:]:
            next_fd = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            info = os.fstat(next_fd)
            os.close(fd)
            fd = next_fd
            if info.st_uid != 0 or info.st_mode & 0o022:
                raise ValueError("untrusted root-owned directory chain")
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
    if action == "start" and (WORKERS_DISABLED.exists() or WORKERS_DISABLED.is_symlink()):
        require_regular(WORKERS_DISABLED, owner=0)
        raise ValueError("workers remain fail-closed during deployment")
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
    if len(data) != info.st_size or hashlib.sha256(data).hexdigest() not in {SCHEDULER_SOURCE_SHA256, SCHEDULER_RUNTIME_SOURCE_SHA256}:
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


def trusted_file_bytes(path, *, owner=0, mode=None, max_size=131072):
    """Read a fixed regular file through a no-follow descriptor below root-owned dirs."""
    directory_fd = open_root_trusted_directory(path.parent)
    try:
        current = os.fstat(directory_fd)
        if current.st_uid != 0 or current.st_mode & 0o022:
            raise ValueError("unsafe trusted directory")
        fd = os.open(path.name, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0), dir_fd=directory_fd)
        try:
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode) or info.st_uid != owner or info.st_size > max_size:
                raise ValueError("unsafe trusted file")
            if mode is not None and stat.S_IMODE(info.st_mode) != mode:
                raise ValueError("unexpected trusted file mode")
            data = bytearray()
            while len(data) <= max_size:
                chunk = os.read(fd, min(16384, max_size + 1 - len(data)))
                if not chunk:
                    break
                data.extend(chunk)
            if len(data) != info.st_size or len(data) > max_size:
                raise ValueError("trusted file changed while reading")
            return bytes(data)
        finally:
            os.close(fd)
    finally:
        os.close(directory_fd)


def deploy_source_bytes(relative, *, max_size):
    """Read one fixed deploy-owned source through a descriptor and reject writable metadata."""
    import pwd

    path = app_file(relative)
    directory_fd = open_directory(path.parent)
    try:
        parent = os.fstat(directory_fd)
        if parent.st_uid != pwd.getpwnam("deploy").pw_uid or parent.st_mode & 0o022:
            raise ValueError("unsafe deployment source directory")
        fd = os.open(path.name, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0), dir_fd=directory_fd)
        try:
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode) or info.st_uid != pwd.getpwnam("deploy").pw_uid or info.st_mode & 0o022 or info.st_size > max_size:
                raise ValueError("unsafe deployment source")
            data = bytearray()
            while len(data) <= max_size:
                chunk = os.read(fd, min(16384, max_size + 1 - len(data)))
                if not chunk:
                    break
                data.extend(chunk)
            if len(data) != info.st_size or len(data) > max_size:
                raise ValueError("deployment source changed while reading")
            return bytes(data)
        finally:
            os.close(fd)
    finally:
        os.close(directory_fd)


def atomic_root_file(path, data, *, mode=0o644):
    """Atomically install fixed bytes in a root-owned destination directory."""
    directory_fd = open_root_trusted_directory(path.parent)
    try:
        parent = os.fstat(directory_fd)
        if parent.st_uid != 0 or parent.st_mode & 0o022:
            raise ValueError("unsafe destination directory")
        temp_name = path.name + ".wpcaregrid-new"
        try:
            os.stat(temp_name, dir_fd=directory_fd, follow_symlinks=False)
        except FileNotFoundError:
            pass
        else:
            raise ValueError("unexpected installer temporary file")
        fd = os.open(temp_name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode, dir_fd=directory_fd)
        try:
            view = memoryview(data)
            while view:
                view = view[os.write(fd, view):]
            os.fchown(fd, 0, 0)
            os.fchmod(fd, mode)
            os.fsync(fd)
        finally:
            os.close(fd)
        try:
            try:
                existing = os.open(path.name, os.O_RDONLY | os.O_NOFOLLOW, dir_fd=directory_fd)
            except FileNotFoundError:
                existing = None
            if existing is not None:
                info = os.fstat(existing)
                os.close(existing)
                if not stat.S_ISREG(info.st_mode) or info.st_uid != 0:
                    raise ValueError("unsafe existing destination")
            os.replace(temp_name, path.name, src_dir_fd=directory_fd, dst_dir_fd=directory_fd)
            os.fsync(directory_fd)
        except Exception:
            try:
                os.unlink(temp_name, dir_fd=directory_fd)
            except OSError:
                pass
            raise
    finally:
        os.close(directory_fd)


def php_profile(profile):
    if profile not in PHP_RUNTIME_FILES:
        raise ValueError("unsupported PHP runtime profile")
    return profile


def runtime_switch_preconditions(gate_active, scheduler_paused, worker_states):
    return gate_active is True and scheduler_paused is True and worker_states == {name: "STOPPED" for name in WORKERS}


def set_workers_disabled(disabled):
    try:
        require_regular(GATE, owner=0)
        require_regular(SCHEDULER_PAUSE, owner=0)
    except (OSError, ValueError):
        raise ValueError("worker runtime state can change only behind both deployment gates") from None
    active_php_profile()
    marker(WORKERS_DISABLED, disabled)


def worker_runtime_enabled():
    if WORKERS_DISABLED.exists() or WORKERS_DISABLED.is_symlink():
        require_regular(WORKERS_DISABLED, owner=0)
        raise ValueError("queue workers are deliberately disabled")


def runtime_snippet(profile):
    profile = php_profile(profile)
    data = trusted_file_bytes(PHP_RUNTIME_FILES[profile], mode=0o644, max_size=128)
    if data != PHP_RUNTIME_DIRECTIVES[profile].encode("ascii"):
        raise ValueError("PHP runtime snippet differs from its fixed contract")


def active_php_profile():
    directory_fd = open_root_trusted_directory(PHP_RUNTIME_LINK.parent)
    try:
        link_info = os.stat(PHP_RUNTIME_LINK.name, dir_fd=directory_fd, follow_symlinks=False)
        if not stat.S_ISLNK(link_info.st_mode) or link_info.st_uid != 0:
            raise ValueError("unsafe PHP runtime selector")
        target = os.readlink(PHP_RUNTIME_LINK.name, dir_fd=directory_fd)
    finally:
        os.close(directory_fd)
    for profile, path in PHP_RUNTIME_FILES.items():
        if target == str(path):
            runtime_snippet(profile)
            return profile
    raise ValueError("unknown PHP runtime selector")


def nginx_runtime_contract(profile):
    """Prove the active selector is referenced only by staging and selects its socket."""
    profile = php_profile(profile)
    runtime_snippet(profile)
    if active_php_profile() != profile:
        raise ValueError("active PHP selector differs from expected profile")
    run(["/usr/sbin/nginx", "-t"])
    result = run(["/usr/sbin/nginx", "-T"], check=False, stdout=subprocess.PIPE)
    if result.returncode:
        raise ValueError("nginx configuration could not be inspected")
    validate_nginx_runtime_dump(result.stdout or "")


def _nginx_dump_sections(output):
    if not isinstance(output, str) or len(output.encode("utf-8")) > NGINX_CONFIG_MAX_BYTES:
        raise ValueError("Nginx configuration dump is missing or oversized")
    sections = {}
    current_path = None
    current_content = []
    for raw_line in output.splitlines(keepends=True):
        marker = re.fullmatch(r"# configuration file (/.+):\r?\n?", raw_line)
        if marker:
            if current_path is not None:
                if current_path in sections:
                    raise ValueError("duplicate Nginx configuration source marker")
                sections[current_path] = "".join(current_content)
            current_path = marker.group(1)
            if posixpath.normpath(current_path) != current_path or ".." in PurePosixPath(current_path).parts:
                raise ValueError("non-canonical Nginx configuration source path")
            current_content = []
        elif current_path is None:
            if raw_line.strip():
                raise ValueError("unexpected text before Nginx source markers")
        else:
            current_content.append(raw_line)
    if current_path is not None:
        if current_path in sections:
            raise ValueError("duplicate Nginx configuration source marker")
        sections[current_path] = "".join(current_content)
    if not sections or len(sections) > NGINX_CONFIG_MAX_FILES:
        raise ValueError("Nginx configuration source count is unsupported")
    if NGINX_ROOT_CONFIG not in sections:
        raise ValueError("Nginx root configuration source is absent")
    return sections


def _read_trusted_nginx_source(path):
    """Read one nginx -T source through root-owned, non-writable directories."""
    source = Path(path)
    if not source.is_absolute() or os.path.normpath(str(source)) != str(source):
        raise ValueError("Nginx source path is not canonical")
    parent_fd = open_root_trusted_directory(source.parent)
    try:
        source_info = os.stat(source.name, dir_fd=parent_fd, follow_symlinks=False)
        if source_info.st_uid != 0:
            raise ValueError("Nginx source is not root-owned")
        if stat.S_ISLNK(source_info.st_mode):
            target = os.readlink(source.name, dir_fd=parent_fd)
            target_path = Path(target) if os.path.isabs(target) else source.parent / target
            target_path = Path(os.path.normpath(str(target_path)))
            if not target_path.is_absolute() or ".." in target_path.parts:
                raise ValueError("Nginx source symlink target is unsafe")
            target_parent_fd = open_root_trusted_directory(target_path.parent)
            try:
                fd = os.open(target_path.name, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0), dir_fd=target_parent_fd)
            finally:
                os.close(target_parent_fd)
        elif stat.S_ISREG(source_info.st_mode):
            fd = os.open(source.name, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0), dir_fd=parent_fd)
        else:
            raise ValueError("Nginx source is not a regular file")
    finally:
        os.close(parent_fd)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
            raise ValueError("Nginx source metadata is unsafe")
        if info.st_size > NGINX_CONFIG_MAX_BYTES:
            raise ValueError("Nginx source is oversized")
        data = bytearray()
        while len(data) <= NGINX_CONFIG_MAX_BYTES:
            chunk = os.read(fd, min(16384, NGINX_CONFIG_MAX_BYTES + 1 - len(data)))
            if not chunk:
                break
            data.extend(chunk)
        if len(data) != info.st_size or len(data) > NGINX_CONFIG_MAX_BYTES:
            raise ValueError("Nginx source changed while reading")
        return bytes(data).decode("utf-8")
    finally:
        os.close(fd)


def _resolve_nginx_include(pattern, source_paths):
    if not isinstance(pattern, str) or not pattern.startswith("/") or "$" in pattern:
        raise ValueError("Nginx include path is relative or dynamic")
    if posixpath.normpath(pattern) != pattern or ".." in PurePosixPath(pattern).parts:
        raise ValueError("Nginx include path is non-canonical")
    matches = sorted(os.path.normpath(item) for item in glob.glob(pattern))
    dumped = sorted(path for path in source_paths if fnmatch.fnmatchcase(path, pattern))
    if not matches or matches != dumped:
        raise ValueError("Nginx include resolution differs from dumped sources")
    return matches


def _tokenize_nginx_config(text):
    tokens = []
    token = []
    quote = None
    escaped = False
    i = 0
    while i < len(text):
        ch = text[i]
        if quote:
            if escaped:
                token.append(ch)
                escaped = False
            elif ch == "\\":
                escaped = True
            elif ch == quote:
                quote = None
            else:
                token.append(ch)
        elif ch in ("'", '"'):
            quote = ch
        elif ch == "#":
            while i < len(text) and text[i] != "\n":
                i += 1
            if token:
                tokens.append("".join(token))
                token = []
        elif ch.isspace():
            if token:
                tokens.append("".join(token))
                token = []
        elif ch in "{};":
            if token:
                tokens.append("".join(token))
                token = []
            tokens.append(ch)
        elif ch == "\\" and i + 1 < len(text):
            token.extend((ch, text[i + 1]))
            i += 1
        else:
            token.append(ch)
        i += 1
    if quote or escaped:
        raise ValueError("unterminated Nginx quoted argument")
    if token:
        tokens.append("".join(token))
    if len(tokens) > NGINX_CONFIG_MAX_EXPANDED_NODES:
        raise ValueError("Nginx configuration has too many tokens")
    return tokens


def _parse_nginx_nodes(tokens, source):
    index = 0

    def parse_items(expect_close=False):
        nonlocal index
        nodes = []
        statement = []
        while index < len(tokens):
            token = tokens[index]
            index += 1
            if token == "}":
                if not expect_close or statement:
                    raise ValueError("unexpected Nginx closing brace")
                return nodes
            if token in (";", "{"):
                if not statement:
                    raise ValueError("empty Nginx directive")
                name, *args = statement
                if token == ";":
                    nodes.append({"kind": "directive", "name": name, "args": args, "source": source})
                else:
                    children = parse_items(expect_close=True)
                    nodes.append({"kind": "block", "name": name, "args": args, "children": children, "source": source})
                statement = []
            else:
                statement.append(token)
        if expect_close or statement:
            raise ValueError("unterminated Nginx directive or block")
        return nodes

    parsed = parse_items()
    if index != len(tokens):
        raise ValueError("unparsed Nginx tokens")
    return parsed


def _expand_nginx_includes(nodes, sections, parsed, source_reader, include_resolver, stack, reached, counter):
    if len(stack) > NGINX_CONFIG_MAX_DEPTH:
        raise ValueError("Nginx include nesting exceeds the bound")
    expanded = []
    for node in nodes:
        counter[0] += 1
        if counter[0] > NGINX_CONFIG_MAX_EXPANDED_NODES:
            raise ValueError("expanded Nginx configuration exceeds the bound")
        if node["kind"] == "directive" and node["name"] == "include":
            if len(node["args"]) != 1:
                raise ValueError("Nginx include must have exactly one path")
            targets = include_resolver(node["args"][0], sections.keys())
            children = []
            for target in targets:
                if target not in sections:
                    raise ValueError("Nginx include target is absent from nginx -T")
                if target in stack:
                    raise ValueError("Nginx include cycle detected")
                reached.add(target)
                child_nodes = parsed.get(target)
                if child_nodes is None:
                    child_nodes = _parse_nginx_nodes(_tokenize_nginx_config(sections[target]), target)
                    parsed[target] = child_nodes
                children.extend(_expand_nginx_includes(
                    child_nodes, sections, parsed, source_reader, include_resolver,
                    (*stack, target), reached, counter,
                ))
            expanded.append({"kind": "include", "path": node["args"][0], "targets": targets, "children": children, "source": node["source"]})
        elif node["kind"] == "block":
            node = dict(node)
            node["children"] = _expand_nginx_includes(
                node["children"], sections, parsed, source_reader, include_resolver,
                stack, reached, counter,
            )
            expanded.append(node)
        else:
            expanded.append(node)
    return expanded


def validate_nginx_runtime_dump(output, *, source_reader=None, include_resolver=None):
    """Resolve the bounded nginx -T include graph and validate effective routing context."""
    source_reader = source_reader or _read_trusted_nginx_source
    include_resolver = include_resolver or _resolve_nginx_include
    sections = _nginx_dump_sections(output)
    total_bytes = 0
    for path, contents in sections.items():
        source = source_reader(path)
        total_bytes += len(source.encode("utf-8"))
        if total_bytes > NGINX_CONFIG_MAX_BYTES:
            raise ValueError("combined Nginx configuration exceeds the bound")
        if source.replace("\r\n", "\n") != contents.replace("\r\n", "\n"):
            raise ValueError("nginx -T source differs from the trusted file")

    parsed = {path: _parse_nginx_nodes(_tokenize_nginx_config(text), path) for path, text in sections.items()}
    reached = {NGINX_ROOT_CONFIG}
    expanded = _expand_nginx_includes(
        parsed[NGINX_ROOT_CONFIG], sections, parsed, source_reader, include_resolver,
        (NGINX_ROOT_CONFIG,), reached, [0],
    )
    if reached != set(sections):
        raise ValueError("nginx -T contains unreachable or unproven source sections")

    selector_uses = []
    servers = []

    def visit(nodes, context):
        for node in nodes:
            if node["kind"] == "include":
                if node["path"] == PHP_RUNTIME_LINK.as_posix():
                    selector_uses.append((context, node))
                visit(node["children"], context)
            elif node["kind"] == "block":
                if node["name"] == "server":
                    servers.append((node, context))
                visit(node["children"], (*context, node))

    visit(expanded, ())
    if len(selector_uses) != 1:
        raise ValueError("PHP runtime selector is not uniquely included")
    selector_context, _ = selector_uses[0]
    server_contexts = [node for node in selector_context if node["name"] == "server"]
    location_contexts = [node for node in selector_context if node["name"] == "location"]
    http_contexts = [node for node in selector_context if node["name"] == "http"]
    if len(server_contexts) != 1 or not http_contexts or not location_contexts:
        raise ValueError("PHP runtime selector is not inside an HTTP staging location")
    selected_server = server_contexts[0]
    if not any(server is selected_server for server, _ in servers):
        raise ValueError("PHP runtime selector server context is not effective")

    def direct_directives(nodes, name):
        found = []
        for node in nodes:
            if node["kind"] == "include":
                found.extend(direct_directives(node["children"], name))
            elif node["kind"] == "directive" and node["name"] == name:
                found.append(node)
        return found

    def descendant_directives(nodes, name):
        found = []
        for node in nodes:
            if node["kind"] == "include":
                found.extend(descendant_directives(node["children"], name))
            elif node["kind"] == "directive" and node["name"] == name:
                found.append(node)
            elif node["kind"] == "block":
                found.extend(descendant_directives(node["children"], name))
        return found

    for server, _ in servers:
        name_directives = direct_directives(server["children"], "server_name")
        names = {name.lower() for directive in name_directives for name in directive["args"]}
        routes = descendant_directives(server["children"], "fastcgi_pass")
        has_selector = server is selected_server
        is_staging = PHP_RUNTIME_VHOST in names
        if has_selector:
            if len(name_directives) != 1 or names != {PHP_RUNTIME_VHOST}:
                raise ValueError("PHP runtime selector server_name must be exactly the staging vhost")
            if len(routes) != 1 or routes[0]["args"] != [f"unix:{PHP_RUNTIME_SOCKETS[active_php_profile()].as_posix()}"]:
                raise ValueError("staging server has a missing or competing FastCGI route")
        elif is_staging and routes:
            if names != {PHP_RUNTIME_VHOST}:
                raise ValueError("staging PHP route shares a server block with another hostname")
            raise ValueError("staging PHP route does not use the runtime selector")


def supervisor_runtime_contract(*, allow_legacy=False):
    acl = run(["/usr/bin/getfacl", "-cp", str(SUPERVISOR_CONFIG)], check=False, stdout=subprocess.PIPE)
    expected_acl = "user::rw-\ngroup::r--\nother::r--\n"
    if acl.returncode or (acl.stdout or "") != expected_acl:
        raise ValueError("Supervisor configuration ACL is not the fixed root-only baseline")
    data = trusted_file_bytes(SUPERVISOR_CONFIG, mode=0o644, max_size=32768)
    text = data.decode("utf-8")
    for name, expected in WORKER_COMMANDS.items():
        section = re.search(rf"(?ms)^\[program:{re.escape(name)}\][ \t]*\r?\n(.*?)(?=^\[|\Z)", text)
        if not section:
            raise ValueError("required Supervisor program is missing")
        commands = re.findall(r"(?m)^[ \t]*command[ \t]*=[ \t]*(.*?)[ \t]*\r?$", section.group(1))
        legacy = expected.replace("/usr/local/bin/wpcaregrid-php ", "php ", 1)
        if commands == [expected]:
            continue
        if allow_legacy and commands == [legacy]:
            continue
        raise ValueError("Supervisor runtime command differs from the fixed worker contract")


def rewrite_supervisor_runtime_commands(text):
    updated = text
    for name, command in WORKER_COMMANDS.items():
        legacy = command.replace("/usr/local/bin/wpcaregrid-php ", "php ", 1)
        section = re.search(rf"(?ms)^\[program:{re.escape(name)}\][ \t]*\r?\n(.*?)(?=^\[|\Z)", updated)
        if not section:
            raise ValueError("required Supervisor program is missing")
        commands = re.findall(r"(?m)^[ \t]*command[ \t]*=[ \t]*(.*?)[ \t]*\r?$", section.group(1))
        if commands == [command]:
            continue
        if commands != [legacy]:
            raise ValueError("Supervisor runtime command differs from the fixed migration source")
        pattern = rf"(?ms)(^\[program:{re.escape(name)}\][ \t]*\r?\n(?:(?!^\[).)*?^[ \t]*command[ \t]*=[ \t]*){re.escape(legacy)}([ \t]*\r?$)"
        updated, count = re.subn(pattern, rf"\g<1>{command}\g<2>", updated, count=1)
        if count != 1:
            raise ValueError("Supervisor worker command could not be updated exactly once")
    return updated


def validate_fpm_pool_listen(profile, output):
    """Validate the effective listener and least-privilege worker identity for each FPM pool."""
    profile = php_profile(profile)
    expected_socket = PHP_RUNTIME_SOCKETS[profile].as_posix()
    current_pool = None
    settings = {}
    for raw_line in output.splitlines():
        line = re.sub(r"^\[[^]]+\]\s+NOTICE:\s*", "", raw_line).strip()
        if line.startswith("[") and line.endswith("]"):
            current_pool = line[1:-1].strip()
            continue
        match = re.fullmatch(r"(user|group|listen)\s*=\s*(\S+)\s*", line)
        if match and current_pool is not None:
            settings.setdefault(current_pool, {}).setdefault(match.group(1), []).append(match.group(2))

    www = settings.get("www", {})
    if www.get("user") != ["www-data"]:
        raise ValueError("effective www FPM worker user differs from www-data")
    if www.get("group") != ["www-data"]:
        raise ValueError("effective www FPM worker group differs from www-data")
    if www.get("listen") != [expected_socket]:
        raise ValueError("effective www FPM pool listener differs from the selected socket")
    if any(pool != "www" and expected_socket in values.get("listen", []) for pool, values in settings.items()):
        raise ValueError("another FPM pool also claims the selected socket")


def parse_unix_listener_records(output, expected_socket):
    """Parse ss -xlepH evidence for one pathname Unix LISTEN socket."""
    records = []
    for line in output.splitlines():
        fields = line.split()
        if expected_socket not in fields:
            continue
        if len(fields) < 5 or fields[0] != "u_str" or fields[1] != "LISTEN":
            raise ValueError("expected Unix socket path is not in LISTEN state")
        inode_match = re.search(r"(?:^|\s)ino:(\d+)(?:\s|$)", line)
        owners = [(int(pid), int(fd)) for pid, fd in re.findall(r"pid=(\d+),fd=(\d+)", line)]
        if not inode_match or not owners:
            raise ValueError("Unix listener lacks inode or process evidence")
        records.append((int(inode_match.group(1)), owners))
    if len(records) != 1:
        raise ValueError("expected exactly one active Unix listener")
    return records[0]


def process_cgroup_paths(pid):
    try:
        data = Path(f"/proc/{pid}/cgroup").read_text(encoding="ascii")
    except (OSError, UnicodeError):
        raise ValueError("FPM process cgroup is unavailable") from None
    paths = set()
    for line in data.splitlines():
        fields = line.split(":", 2)
        if len(fields) == 3:
            paths.add(fields[2])
    return paths


def command_line_uses_fpm_config(profile, argv):
    config = PHP_RUNTIME_FPM_CONFIGS[profile].as_posix()
    for index, arg in enumerate(argv[:-1]):
        if arg in {"--fpm-config", "-y"}:
            return argv[index + 1] == config
    return f"master process ({config})" in " ".join(argv)


def process_uses_fpm_config(pid, profile):
    try:
        raw = Path(f"/proc/{pid}/cmdline").read_bytes().split(b"\0")
        argv = [part.decode("utf-8") for part in raw if part]
    except (OSError, UnicodeError):
        raise ValueError("FPM master command line is unavailable") from None
    return command_line_uses_fpm_config(profile, argv)


def validate_fpm_main_process(profile, *, pid, executable, cgroup_paths, command_line_uses_config):
    profile = php_profile(profile)
    if (not re.fullmatch(r"[1-9][0-9]{0,8}", str(pid))
            or executable != PHP_RUNTIME_FPM_BINARIES[profile].as_posix()
            or PHP_RUNTIME_FPM_CGROUPS[profile] not in cgroup_paths
            or command_line_uses_config is not True):
        raise ValueError("active FPM master identity or configuration differs from profile")


def validate_fpm_listener_processes(profile, inode, socket_path_inode, owners, process_evidence):
    profile = php_profile(profile)
    # `ss -e` reports the bound pathname's VFS inode; /proc/<pid>/fd points
    # at the socket object and has a distinct sockfs inode. Bind the listener
    # row to the configured path, then verify each `ss`-reported PID/FD still
    # names a socket held by the selected FPM service.
    if (not isinstance(inode, int) or inode <= 0 or not owners
            or not isinstance(socket_path_inode, int) or socket_path_inode != inode):
        raise ValueError("invalid FPM listener evidence")
    for pid, fd in owners:
        evidence = process_evidence(pid, fd)
        if (evidence.get("executable") != PHP_RUNTIME_FPM_BINARIES[profile].as_posix()
                or PHP_RUNTIME_FPM_CGROUPS[profile] not in evidence.get("cgroup_paths", set())
                or evidence.get("fd_is_socket") is not True):
            raise ValueError("FPM listener is not owned by the selected FPM service")


def php_runtime_preflight(profile, *, allow_legacy_supervisor=False):
    profile = php_profile(profile)
    runtime_snippet(profile)
    binary = PHP_RUNTIME_BINARIES[profile]
    binary_parent = open_root_trusted_directory(binary.parent)
    os.close(binary_parent)
    info = binary.lstat()
    binary_ok = stat.S_ISREG(info.st_mode) and info.st_uid == 0 and not (info.st_mode & 0o022) and os.access(binary, os.X_OK)
    result = run([str(binary), "-r", "echo PHP_MAJOR_VERSION, '.', PHP_MINOR_VERSION;"], check=False, stdout=subprocess.PIPE) if binary_ok else subprocess.CompletedProcess([], 1, stdout="")
    cli_version = (result.stdout or "").strip() if result.returncode == 0 else ""
    service = run(["/usr/bin/systemctl", "is-active", "--quiet", PHP_RUNTIME_SERVICES[profile]], check=False)
    main_pid = run(["/usr/bin/systemctl", "show", "--property=MainPID", "--value", PHP_RUNTIME_SERVICES[profile]], check=False, stdout=subprocess.PIPE)
    cgroup = run(["/usr/bin/systemctl", "show", "--property=ControlGroup", "--value", PHP_RUNTIME_SERVICES[profile]], check=False, stdout=subprocess.PIPE)
    pid = (main_pid.stdout or "").strip()
    if main_pid.returncode or not re.fullmatch(r"[1-9][0-9]{0,8}", pid):
        fpm_executable = ""
        main_cgroups = set()
        uses_expected_config = False
    else:
        try:
            fpm_executable = os.readlink(f"/proc/{pid}/exe")
            main_cgroups = process_cgroup_paths(pid)
            uses_expected_config = process_uses_fpm_config(pid, profile)
        except (OSError, ValueError):
            fpm_executable = ""
            main_cgroups = set()
            uses_expected_config = False
    validate_fpm_main_process(
        profile,
        pid=pid,
        executable=fpm_executable,
        cgroup_paths=main_cgroups,
        command_line_uses_config=uses_expected_config,
    )
    if cgroup.returncode or (cgroup.stdout or "").strip() != PHP_RUNTIME_FPM_CGROUPS[profile]:
        raise ValueError("PHP-FPM systemd service cgroup differs from profile")
    fpm_binary = PHP_RUNTIME_FPM_BINARIES[profile]
    try:
        fpm_parent = open_root_trusted_directory(fpm_binary.parent)
        os.close(fpm_parent)
        fpm_info = fpm_binary.lstat()
        fpm_binary_ok = stat.S_ISREG(fpm_info.st_mode) and fpm_info.st_uid == 0 and not (fpm_info.st_mode & 0o022) and os.access(fpm_binary, os.X_OK)
    except OSError:
        fpm_binary_ok = False
    fpm_version_result = run([str(fpm_binary), "-v"], check=False, stdout=subprocess.PIPE) if fpm_binary_ok else subprocess.CompletedProcess([], 1, stdout="")
    fpm_version_match = re.search(r"(?m)^PHP (8\.[0-9]+\.[0-9]+) \(fpm-fcgi\)", fpm_version_result.stdout or "") if fpm_version_result.returncode == 0 else None
    fpm_config_result = subprocess.run(
        [str(fpm_binary), "-tt", "--fpm-config", str(PHP_RUNTIME_FPM_CONFIGS[profile])],
        check=False, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        text=True, env=SAFE_ENV, close_fds=True, timeout=10,
    ) if fpm_binary_ok else subprocess.CompletedProcess([], 1, stdout="", stderr="")
    if fpm_config_result.returncode:
        raise ValueError("effective PHP-FPM pool configuration could not be inspected")
    validate_fpm_pool_listen(profile, (fpm_config_result.stdout or "") + "\n" + (fpm_config_result.stderr or ""))
    fpm_info_result = subprocess.run(
        [str(fpm_binary), "-i"], check=False, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL, text=True, env=SAFE_ENV, close_fds=True, timeout=10,
    ) if fpm_binary_ok else subprocess.CompletedProcess([], 1, stdout="")
    if fpm_info_result.returncode:
        raise ValueError("PHP-FPM configuration could not be inspected")
    validate_fpm_runtime_info(fpm_info_result.stdout or "")
    socket_parent = open_root_trusted_directory(PHP_RUNTIME_SOCKETS[profile].parent)
    os.close(socket_parent)
    socket_info = PHP_RUNTIME_SOCKETS[profile].lstat()
    import grp
    import pwd
    socket_mode = stat.S_IMODE(socket_info.st_mode)
    socket_type = stat.S_ISSOCK(socket_info.st_mode)
    expected_uid = pwd.getpwnam("www-data").pw_uid
    expected_gid = grp.getgrnam("www-data").gr_gid
    validate_runtime_evidence(
        profile,
        binary_ok=binary_ok,
        cli_version=cli_version,
        fpm_active=service.returncode == 0,
        fpm_binary_ok=fpm_binary_ok,
        fpm_executable=fpm_executable,
        fpm_version=fpm_version_match.group(1) if fpm_version_match else "",
        socket_type=socket_type,
        socket_uid=socket_info.st_uid,
        socket_gid=socket_info.st_gid,
        socket_mode=socket_mode,
        expected_uid=expected_uid,
        expected_gid=expected_gid,
    )
    listener_result = subprocess.run(
        ["/usr/bin/ss", "-xlepH"], check=False, stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True,
        env=SAFE_ENV, close_fds=True, timeout=10,
    )
    if listener_result.returncode:
        raise ValueError("active Unix socket listeners could not be inspected")
    listener_inode, listener_owners = parse_unix_listener_records(
        listener_result.stdout or "", PHP_RUNTIME_SOCKETS[profile].as_posix()
    )

    def listener_process_evidence(listener_pid, listener_fd):
        try:
            executable = os.readlink(f"/proc/{listener_pid}/exe")
            cgroups = process_cgroup_paths(listener_pid)
            fd_info = os.stat(f"/proc/{listener_pid}/fd/{listener_fd}")
        except OSError:
            raise ValueError("active FPM listener process evidence is unavailable") from None
        return {"executable": executable, "cgroup_paths": cgroups, "fd_is_socket": stat.S_ISSOCK(fd_info.st_mode)}

    validate_fpm_listener_processes(
        profile,
        listener_inode,
        socket_info.st_ino,
        listener_owners,
        listener_process_evidence,
    )
    nginx_runtime_contract(active_php_profile())
    supervisor_runtime_contract(allow_legacy=allow_legacy_supervisor)


def validate_runtime_evidence(profile, *, binary_ok, cli_version, fpm_active, fpm_binary_ok, fpm_executable, fpm_version, socket_type, socket_uid, socket_gid, socket_mode, expected_uid, expected_gid):
    profile = php_profile(profile)
    version = "8." + profile.split(".", 1)[1]
    if not binary_ok or cli_version != version:
        raise ValueError("PHP CLI binary or version differs from profile")
    if not fpm_binary_ok or not fpm_active or fpm_executable != PHP_RUNTIME_FPM_BINARIES[profile].as_posix() or not fpm_version.startswith(version + "."):
        raise ValueError("PHP-FPM process or version differs from profile")
    if not socket_type or socket_uid != expected_uid or socket_gid != expected_gid or socket_mode != 0o660:
        raise ValueError("PHP-FPM socket metadata differs from profile")


def validate_fpm_runtime_info(output):
    modules = set()
    in_modules = False
    settings = {}
    for line in output.splitlines():
        stripped = line.strip()
        if stripped == "[PHP Modules]":
            in_modules = True
            continue
        if stripped == "[Zend Modules]":
            in_modules = False
            continue
        if in_modules and re.fullmatch(r"[A-Za-z0-9_]+", stripped):
            modules.add(stripped.lower())
        match = re.fullmatch(r"(opcache\.validate_timestamps|opcache\.revalidate_freq)\s*=>\s*(.*?)\s*=>\s*.*", stripped)
        if match:
            settings[match.group(1)] = match.group(2)
    required = {
        "ctype", "curl", "dom", "fileinfo", "filter", "hash", "iconv", "intl", "json",
        "libxml", "mbstring", "openssl", "pcre", "pdo", "pdo_mysql", "session", "simplexml",
        "sodium", "tokenizer", "xml", "zip",
    }
    if not required.issubset(modules):
        raise ValueError("required PHP-FPM modules are absent")
    if settings.get("opcache.validate_timestamps", "").lower() != "on":
        raise ValueError("PHP-FPM OPcache timestamp validation is disabled")
    try:
        revalidate_freq = int(settings["opcache.revalidate_freq"])
    except (KeyError, ValueError):
        raise ValueError("PHP-FPM OPcache revalidation interval is unavailable") from None
    if not 0 <= revalidate_freq <= 2:
        raise ValueError("PHP-FPM OPcache revalidation interval is too high")


def install_runtime_assets():
    try:
        require_regular(GATE, owner=0)
        require_regular(SCHEDULER_PAUSE, owner=0)
    except OSError:
        raise ValueError("runtime assets may be installed only behind the maintenance gate") from None
    except ValueError:
        raise ValueError("runtime assets may be installed only behind the maintenance gate")
    require_regular(WORKERS_DISABLED, owner=0)
    worker_states = {}
    for name in WORKERS:
        output = run(["/usr/bin/supervisorctl", "status", name], check=False, stdout=subprocess.PIPE).stdout or ""
        worker_states[name] = normalize_worker_status(name, output).split()[1]
    if not runtime_switch_preconditions(True, True, worker_states):
        raise ValueError("workers must be stopped before runtime asset installation")
    launcher_data = deploy_source_bytes("scripts/run-php-runtime.sh", max_size=8192)
    if hashlib.sha256(launcher_data).hexdigest() != PHP_RUNTIME_LAUNCHER_SHA256:
        raise ValueError("PHP runtime launcher provenance mismatch")
    scheduler_data = deploy_source_bytes("scripts/run-scheduler.sh", max_size=32768)
    if hashlib.sha256(scheduler_data).hexdigest() != SCHEDULER_RUNTIME_SOURCE_SHA256:
        raise ValueError("scheduler runtime wrapper provenance mismatch")
    if not launcher_data.startswith(b"#!/usr/bin/env bash\n") or b"/etc/nginx/snippets/wpcaregrid-staging-runtime.conf" not in launcher_data:
        raise ValueError("PHP runtime launcher contract mismatch")
    if not scheduler_data.startswith(b"#!/usr/bin/env bash\n") or b"exec /usr/local/bin/wpcaregrid-php /var/www/wpcaregrid/artisan schedule:run" not in scheduler_data:
        raise ValueError("scheduler wrapper runtime contract mismatch")
    atomic_root_file(PHP_RUNTIME_LAUNCHER_DEST, launcher_data, mode=0o755)
    atomic_root_file(SCHEDULER_DEST, scheduler_data, mode=0o755)
    supervisor_runtime_contract(allow_legacy=True)
    data = trusted_file_bytes(SUPERVISOR_CONFIG, mode=0o644, max_size=32768).decode("utf-8")
    updated = rewrite_supervisor_runtime_commands(data)
    atomic_root_file(SUPERVISOR_CONFIG, updated.encode("utf-8"), mode=0o644)
    supervisor_runtime_contract()


def switch_php_runtime(profile):
    profile = php_profile(profile)
    try:
        require_regular(GATE, owner=0)
        require_regular(SCHEDULER_PAUSE, owner=0)
    except OSError:
        raise ValueError("runtime switch requires both maintenance markers") from None
    except ValueError:
        raise ValueError("runtime switch requires both maintenance markers")
    require_regular(WORKERS_DISABLED, owner=0)
    worker_states = {}
    for name in WORKERS:
        result = run(["/usr/bin/supervisorctl", "status", name], check=False, stdout=subprocess.PIPE)
        worker_states[name] = normalize_worker_status(name, result.stdout or "").split()[1]
    if not runtime_switch_preconditions(True, True, worker_states):
        raise ValueError("workers must be stopped during runtime switch")
    php_runtime_preflight(profile)
    previous = active_php_profile()
    if previous == profile:
        return
    directory_fd = open_root_trusted_directory(PHP_RUNTIME_LINK.parent)
    temp_name = PHP_RUNTIME_LINK.name + ".new"
    rollback_name = PHP_RUNTIME_LINK.name + ".rollback"
    temp_created = False
    rollback_created = False
    try:
        try:
            os.stat(temp_name, dir_fd=directory_fd, follow_symlinks=False)
        except FileNotFoundError:
            pass
        else:
            raise ValueError("unexpected runtime-selector temporary path")
        os.symlink(str(PHP_RUNTIME_FILES[profile]), temp_name, dir_fd=directory_fd)
        temp_created = True
        try:
            os.replace(temp_name, PHP_RUNTIME_LINK.name, src_dir_fd=directory_fd, dst_dir_fd=directory_fd)
            temp_created = False
            os.fsync(directory_fd)
            nginx_runtime_contract(profile)
            run(["/usr/bin/systemctl", "reload", "nginx"])
            if active_php_profile() != profile:
                raise ValueError("runtime selector did not persist")
            php_runtime_preflight(profile)
        except Exception:
            try:
                os.symlink(str(PHP_RUNTIME_FILES[previous]), rollback_name, dir_fd=directory_fd)
                rollback_created = True
                os.replace(rollback_name, PHP_RUNTIME_LINK.name, src_dir_fd=directory_fd, dst_dir_fd=directory_fd)
                rollback_created = False
                os.fsync(directory_fd)
                nginx_runtime_contract(previous)
                run(["/usr/bin/systemctl", "reload", "nginx"])
                php_runtime_preflight(previous)
            except Exception:
                raise ValueError("runtime switch failed and selector rollback requires manual recovery") from None
            raise ValueError("runtime switch failed; previous selector restored") from None
    finally:
        if temp_created:
            try:
                os.unlink(temp_name, dir_fd=directory_fd)
            except OSError:
                pass
        if rollback_created:
            try:
                os.unlink(rollback_name, dir_fd=directory_fd)
            except OSError:
                pass
        os.close(directory_fd)


def update_supervisor_runtime():
    worker_runtime_enabled()
    supervisor_runtime_contract()
    run(["/usr/bin/supervisorctl", "reread"])
    for name in sorted(WORKERS):
        run(["/usr/bin/supervisorctl", "update", name])


def validate_worker_runtime(profile, name, status, executable, argv):
    profile = php_profile(profile)
    if name not in WORKER_COMMANDS:
        raise ValueError("unsupported worker")
    normalized = normalize_worker_status(name, status)
    match = re.fullmatch(rf"{re.escape(name)} RUNNING pid (\d+)\n", normalized)
    if not match or executable != PHP_RUNTIME_BINARIES[profile].as_posix():
        raise ValueError("worker is not running the selected PHP executable")
    expected_tail = WORKER_COMMANDS[name].split()[1:]
    if argv != [PHP_RUNTIME_BINARIES[profile].as_posix(), *expected_tail]:
        raise ValueError("worker command line differs from the fixed runtime contract")


def worker_runtime_check(profile):
    profile = php_profile(profile)
    worker_runtime_enabled()
    for name in sorted(WORKERS):
        result = run(["/usr/bin/supervisorctl", "status", name], check=False, stdout=subprocess.PIPE)
        normalized = normalize_worker_status(name, result.stdout or "")
        match = re.fullmatch(rf"{re.escape(name)} RUNNING pid (\d+)\n", normalized)
        if not match:
            raise ValueError("worker is not running")
        pid = match.group(1)
        executable = os.readlink(f"/proc/{pid}/exe")
        raw_argv = Path(f"/proc/{pid}/cmdline").read_bytes().split(b"\0")
        argv = [part.decode("utf-8") for part in raw_argv if part]
        validate_worker_runtime(profile, name, result.stdout or "", executable, argv)


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
    parent_path = Path(path).parent
    parent_fd = None if os.name == "nt" else open_directory(parent_path)
    try:
        parent_info = parent_path.lstat() if parent_fd is None else os.fstat(parent_fd)
        if not stat.S_ISDIR(parent_info.st_mode) or stat.S_ISLNK(parent_info.st_mode) or (parent_fd is not None and parent_info.st_mode & 0o022):
            raise ValueError("unsafe rollback authorization directory")
        if parent_info.st_uid != expected_owner:
            raise ValueError("unsafe rollback authorization directory")
        open_flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0)
        fd = os.open(path, open_flags) if parent_fd is None else os.open(Path(path).name, open_flags, dir_fd=parent_fd)
        try:
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode) or info.st_uid != expected_owner or (expected_mode is not None and stat.S_IMODE(info.st_mode) != expected_mode) or info.st_size > 41:
                raise ValueError("unsafe rollback authorization file")
            content = os.read(fd, 42)
        finally:
            os.close(fd)
    finally:
        if parent_fd is not None:
            os.close(parent_fd)
    try:
        value = content.decode("ascii")
    except UnicodeDecodeError:
        raise ValueError("invalid rollback authorization") from None
    if not re.fullmatch(r"[0-9a-f]{40}\n", value):
        raise ValueError("invalid rollback authorization")
    return value[:-1]


class TransitionHold(RuntimeError):
    """A transition failed closed; callers must not infer a usable state."""


class TransitionDurabilityUnconfirmed(TransitionHold):
    """The projection rename committed, but its directory fsync failed."""


class TransitionRpcAmbiguous(TransitionHold):
    """A Laravel RPC may have committed; callers may only perform receipt readback."""

    def __init__(self, request_id, request_digest):
        super().__init__("Laravel transition RPC response is ambiguous; authoritative receipt readback is required")
        self.request_id = request_id
        self.request_digest = request_digest


class DrainEffectNotPerformed(TransitionHold):
    """An executor presents a proof reference that the adapter must verify."""

    def __init__(self, evidence_digest, authority_reference):
        if not isinstance(evidence_digest, str) or not re.fullmatch(r"[0-9a-f]{64}", evidence_digest):
            raise ValueError("not-performed evidence digest is invalid")
        if not isinstance(authority_reference, str) or not authority_reference:
            raise ValueError("authoritative no-effect reference is required")
        super().__init__("drain effect was authoritatively not performed")
        self.evidence_digest = evidence_digest
        self.authority_reference = authority_reference


def _transition_canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def _transition_uuid7():
    # UUIDv7 layout, kept compatible with the Python versions on supported hosts.
    millis = int(time.time_ns() // 1_000_000) & ((1 << 48) - 1)
    rand = int.from_bytes(os.urandom(10), "big") & ((1 << 74) - 1)
    value = (millis << 80) | (0x7 << 76) | (((rand >> 62) & 0xFFF) << 64) | (0b10 << 62) | (rand & ((1 << 62) - 1))
    return str(uuid.UUID(int=value))


class PosixTransitionLock:
    """Stable flock adapter. It never unlinks or replaces the lock inode."""

    def __init__(self, path):
        self.path = Path(path)
        self._local = threading.local()

    def is_held(self, mode=None):
        held = getattr(self._local, "mode", None)
        return held is not None and (mode is None or held == mode)

    @contextmanager
    def hold(self, mode):
        import fcntl
        import grp

        if mode not in {"shared", "exclusive"}:
            raise ValueError("invalid transition lock mode")
        if self.is_held():
            raise TransitionHold("nested transition locks are not supported")
        parent_fd = open_root_trusted_directory(self.path.parent)
        try:
            # flock(LOCK_SH) is valid on a read-only descriptor. The application
            # group has read-only access to this root:www-data 0640 inode.
            access = os.O_RDONLY if mode == "shared" else os.O_RDWR
            fd = os.open(self.path.name, access | getattr(os, "O_NOFOLLOW", 0), dir_fd=parent_fd)
        finally:
            os.close(parent_fd)
        try:
            before = os.fstat(fd)
            if (not stat.S_ISREG(before.st_mode) or before.st_uid != 0
                or before.st_gid != grp.getgrnam("www-data").gr_gid
                or stat.S_IMODE(before.st_mode) != 0o640 or before.st_nlink != 1):
                raise TransitionHold("transition lock ownership or mode is invalid")
            fcntl.flock(fd, fcntl.LOCK_SH if mode == "shared" else fcntl.LOCK_EX)
            current = os.stat(self.path, follow_symlinks=False)
            held = os.fstat(fd)
            if ((current.st_dev, current.st_ino) != (held.st_dev, held.st_ino)
                or held.st_nlink != 1):
                raise TransitionHold("transition lock inode changed")
            self._local.mode = mode
            yield fd
            current = os.stat(self.path, follow_symlinks=False)
            held = os.fstat(fd)
            if ((current.st_dev, current.st_ino) != (held.st_dev, held.st_ino)
                or held.st_nlink != 1):
                raise TransitionHold("transition lock inode changed")
        finally:
            if hasattr(self._local, "mode"):
                del self._local.mode
            try:
                fcntl.flock(fd, fcntl.LOCK_UN)
            finally:
                os.close(fd)


class LaravelTransitionDatabaseAdapter:
    """Fixed, typed root-to-Laravel DB bridge; never exposes generic SQL or DRAIN claims.

    This client is inert unless an explicitly constructed RootTransitionAdapter receives
    it. Laravel owns every SQL transaction and its durable receipt table. The child command
    name and runtime launcher are constants, not caller configuration. A lost mutating reply
    triggers only a read_transition_receipt RPC, never a second compare_and_set request.
    """

    _PAYLOAD_KEYS = {
        "read_tuple": set(),
        "read_permit_manifest_hash": set(),
        "verify_transition_preconditions": {"source", "target", "details", "permits"},
        "validate_permit_manifest": {"source", "permits", "digest"},
        "compare_and_set": {"request_id", "request_digest", "source", "target", "release", "details"},
        "nonce_consumed": {"nonce_digest"},
        "validate_release": {"authorization", "source", "nonce_digest"},
        "assert_no_unresolved_drain_effects": {"operation_id", "generation"},
        "read_transition_receipt": {"request_id", "request_digest"},
    }

    def __init__(self, *, socket_dir=TRANSITION_RPC_SOCKET_DIR, timeout_seconds=10.0):
        if type(timeout_seconds) not in {int, float} or not 0 < timeout_seconds <= 30:
            raise ValueError("transition RPC timeout must be between 0 and 30 seconds")
        self.socket_dir = Path(socket_dir)
        self.timeout_seconds = float(timeout_seconds)
        self.lock = None

    def bind_lock(self, lock):
        if not callable(getattr(lock, "is_held", None)):
            raise TypeError("Laravel transition RPC requires the stable transition lock")
        self.lock = lock

    @staticmethod
    def _request_digest(request_id, operation, payload):
        return hashlib.sha256(_transition_canonical({
            "version": TRANSITION_RPC_VERSION,
            "request_id": request_id,
            "operation": operation,
            "payload": payload,
        })).hexdigest()

    @staticmethod
    def _line(sock):
        data = bytearray()
        while len(data) <= TRANSITION_RPC_MAX_BYTES:
            chunk = sock.recv(min(4096, TRANSITION_RPC_MAX_BYTES + 1 - len(data)))
            if not chunk:
                break
            data.extend(chunk)
            if b"\n" in chunk:
                if data.count(b"\n") != 1 or not data.endswith(b"\n"):
                    raise TransitionHold("Laravel RPC returned multiple or trailing messages")
                if len(data) > TRANSITION_RPC_MAX_BYTES:
                    raise TransitionHold("Laravel RPC response exceeds its bound")
                return bytes(data)
        raise TransitionHold("Laravel RPC response is incomplete or exceeds its bound")

    @staticmethod
    def _drop_to_application_identity(uid, gid):
        os.setgroups([])
        os.setresgid(gid, gid, gid)
        os.setresuid(uid, uid, uid)

    def _exchange(self, operation, payload, *, request_id=None, request_digest=None):
        import grp
        import pwd

        if operation not in TRANSITION_RPC_OPERATIONS:
            raise TransitionHold("Laravel RPC operation is not allowlisted")
        if not isinstance(payload, dict) or set(payload) != self._PAYLOAD_KEYS[operation]:
            raise TransitionHold("Laravel RPC payload shape is invalid")
        if os.geteuid() != 0:
            raise TransitionHold("Laravel transition RPC requires the root lock owner")
        required_mode = "exclusive" if operation in {
            "verify_transition_preconditions", "validate_permit_manifest", "compare_and_set",
            "validate_release", "read_transition_receipt",
        } else None
        if self.lock is None or not self.lock.is_held(required_mode):
            raise TransitionHold("Laravel transition RPC requires the coordinated stable lock")
        try:
            app = pwd.getpwnam("www-data")
            app_group = grp.getgrnam("www-data")
        except KeyError as exc:
            raise TransitionHold("Laravel application identity is unavailable") from exc
        if app.pw_gid != app_group.gr_gid:
            raise TransitionHold("Laravel application UID/GID mapping is inconsistent")

        request_id = request_id or _transition_uuid7()
        request_digest = request_digest or self._request_digest(request_id, operation, payload)
        if (not isinstance(request_id, str)
            or not re.fullmatch(r"[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}", request_id, re.I)
            or not isinstance(request_digest, str)
            or not re.fullmatch(r"[0-9a-f]{64}", request_digest)):
            raise TransitionHold("Laravel RPC request identity is invalid")
        envelope = {
            "version": TRANSITION_RPC_VERSION,
            "request_id": request_id,
            "request_digest": request_digest,
            "operation": operation,
            "payload": payload,
        }
        encoded = _transition_canonical(envelope)
        if len(encoded) > TRANSITION_RPC_MAX_BYTES:
            raise TransitionHold("Laravel RPC request exceeds its bound")

        directory_fd = open_root_trusted_directory(self.socket_dir)
        try:
            directory = os.fstat(directory_fd)
            if not stat.S_ISDIR(directory.st_mode):
                raise TransitionHold("Laravel RPC socket parent is not a directory")
        finally:
            os.close(directory_fd)

        socket_path = self.socket_dir / (".transition-rpc-" + uuid.uuid4().hex + ".sock")
        listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        process = None
        socket_identity = None
        connection = None
        request_sent = False
        try:
            if len(os.fsencode(socket_path)) >= 108:
                raise TransitionHold("Laravel RPC socket path exceeds the Linux AF_UNIX bound")
            listener.settimeout(self.timeout_seconds)
            listener.bind(str(socket_path))
            info = socket_path.lstat()
            socket_identity = (info.st_dev, info.st_ino)
            os.chown(socket_path, 0, app_group.gr_gid, follow_symlinks=False)
            os.chmod(socket_path, 0o620, follow_symlinks=False)
            info = socket_path.lstat()
            if (not stat.S_ISSOCK(info.st_mode) or info.st_uid != 0
                or info.st_gid != app_group.gr_gid or stat.S_IMODE(info.st_mode) != 0o620
                or info.st_nlink != 1 or (info.st_dev, info.st_ino) != socket_identity):
                raise TransitionHold("Laravel RPC socket ownership or mode is invalid")
            listener.listen(1)
            argv = [*TRANSITION_RPC_COMMAND, "--connect-socket", str(socket_path)]
            process = subprocess.Popen(
                argv,
                cwd=str(APP),
                env=dict(SAFE_ENV),
                stdin=subprocess.DEVNULL,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.PIPE if TRANSITION_RPC_COMMAND[0] == sys.executable else subprocess.DEVNULL,
                close_fds=True,
                preexec_fn=lambda: self._drop_to_application_identity(app.pw_uid, app.pw_gid),
            )
            connection, _ = listener.accept()
            connection.settimeout(self.timeout_seconds)
            peer = connection.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, struct.calcsize("3i"))
            peer_pid, peer_uid, peer_gid = struct.unpack("3i", peer)
            if (peer_pid != process.pid or peer_uid != app.pw_uid or peer_gid != app.pw_gid):
                raise TransitionHold("Laravel RPC peer identity does not match the dropped CLI child")
            connection.sendall(encoded)
            request_sent = True
            response_bytes = self._line(connection)
            try:
                response = json.loads(response_bytes.decode("ascii"))
            except (UnicodeDecodeError, json.JSONDecodeError):
                raise TransitionHold("Laravel RPC response is malformed") from None
            if (not isinstance(response, dict)
                or set(response) != {"version", "request_id", "request_digest", "status", "result"}
                or response.get("version") != TRANSITION_RPC_VERSION
                or response.get("request_id") != request_id
                or response.get("request_digest") != request_digest
                or not isinstance(response.get("status"), str)
                or not isinstance(response.get("result"), dict)
                or _transition_canonical(response) != response_bytes):
                raise TransitionHold("Laravel RPC response binding is invalid")
            connection.close()
            connection = None
            try:
                return_code = process.wait(timeout=self.timeout_seconds)
            except subprocess.TimeoutExpired as exc:
                process.kill()
                process.wait()
                if operation == "compare_and_set":
                    raise TransitionRpcAmbiguous(request_id, request_digest) from exc
                raise TransitionHold("Laravel read RPC child did not exit within its bound") from exc
            if return_code != 0:
                if operation == "compare_and_set":
                    raise TransitionRpcAmbiguous(request_id, request_digest)
                raise TransitionHold("Laravel read RPC child exited unsuccessfully")
            return response
        except TransitionRpcAmbiguous:
            raise
        except TransitionHold as exc:
            if operation == "compare_and_set" and request_sent:
                if process is not None and process.stderr is not None and process.poll() is None:
                    try:
                        process.wait(timeout=0.5)
                    except subprocess.TimeoutExpired:
                        pass
                if process is not None and process.poll() is not None and process.stderr is not None:
                    diagnostic = process.stderr.read(16000).decode("utf-8", "replace")
                    if diagnostic:
                        raise TransitionHold("fake Laravel CLI failed: " + diagnostic[:1000]) from exc
                raise TransitionRpcAmbiguous(request_id, request_digest) from exc
            raise
        except (OSError, socket.timeout, subprocess.SubprocessError) as exc:
            if process is not None and process.stderr is not None and process.poll() is None:
                try:
                    process.wait(timeout=0.5)
                except subprocess.TimeoutExpired:
                    pass
            if process is not None and process.poll() is not None and process.stderr is not None:
                diagnostic = process.stderr.read(16000).decode("utf-8", "replace")
                if diagnostic:
                    raise TransitionHold("fake Laravel CLI failed: " + diagnostic[:1000]) from exc
            if operation == "compare_and_set":
                raise TransitionRpcAmbiguous(request_id, request_digest) from exc
            raise TransitionHold("Laravel transition RPC could not be completed") from exc
        finally:
            if connection is not None:
                connection.close()
            listener.close()
            if process is not None and process.poll() is None:
                process.kill()
                process.wait()
            if process is not None and process.stderr is not None:
                process.stderr.close()
            try:
                current = socket_path.lstat()
            except FileNotFoundError:
                current = None
            if (current is not None and socket_identity is not None
                and (current.st_dev, current.st_ino) == socket_identity
                and stat.S_ISSOCK(current.st_mode)):
                socket_path.unlink()

    def _call(self, operation, payload):
        response = self._exchange(operation, payload)
        if response["status"] != "OK":
            raise TransitionHold("Laravel transition DB operation was refused")
        return response["result"]

    def read_tuple(self):
        return self._call("read_tuple", {})

    def read_permit_manifest_hash(self):
        return self._call("read_permit_manifest_hash", {})

    def verify_transition_preconditions(self, source, target, details, permits):
        return self._call("verify_transition_preconditions", {
            "source": source, "target": target, "details": details, "permits": permits,
        })

    def validate_permit_manifest(self, source, permits, digest):
        return self._call("validate_permit_manifest", {
            "source": source, "permits": permits, "digest": digest,
        })

    def compare_and_set(self, source, target, *, release=None, details=None):
        details = dict(details or {})
        request_id = details.get("rpc_request_id")
        request_digest = details.get("rpc_request_digest")
        payload = {
            "request_id": request_id,
            "request_digest": request_digest,
            "source": source,
            "target": target,
            "release": release,
            "details": details,
        }
        try:
            response = self._exchange(
                "compare_and_set", payload, request_id=request_id, request_digest=request_digest,
            )
        except TransitionRpcAmbiguous as ambiguous:
            return self._verify_transition_receipt(
                ambiguous.request_id, ambiguous.request_digest, target, cause=ambiguous,
            )
        result = response["result"]
        if (response["status"] == "COMMITTED"
            and result.get("committed") is True
            and result.get("request_id") == request_id
            and result.get("request_digest") == request_digest
            and result.get("target") == target):
            return True
        return self._verify_transition_receipt(request_id, request_digest, target)

    def _verify_transition_receipt(self, request_id, request_digest, target, *, cause=None):
        receipt = self._call("read_transition_receipt", {
            "request_id": request_id, "request_digest": request_digest,
        })
        if (receipt.get("committed") is not True
            or receipt.get("request_id") != request_id
            or receipt.get("request_digest") != request_digest
            or receipt.get("target") != target):
            raise TransitionHold("ambiguous Laravel transition lacks a positively verified committed receipt") from cause
        return True

    def nonce_consumed(self, nonce_digest):
        return self._call("nonce_consumed", {"nonce_digest": nonce_digest}).get("consumed") is True

    def validate_release(self, authorization, source, nonce_digest):
        return self._call("validate_release", {
            "authorization": authorization, "source": source, "nonce_digest": nonce_digest,
        })

    def assert_no_unresolved_drain_effects(self, operation_id, generation):
        return self._call("assert_no_unresolved_drain_effects", {
            "operation_id": operation_id, "generation": generation,
        }).get("clear") is True

    def read_transition_receipt(self, request_id, request_digest):
        result = self._call("read_transition_receipt", {
            "request_id": request_id, "request_digest": request_digest,
        })
        return result

    def assert_exact_permit(self, _current, _binding):
        raise TransitionHold("root transition RPC cannot authorize application DRAIN work")

    def claim_drain_step(self, _current, _binding, _intent_id):
        raise TransitionHold("root transition RPC cannot create application DRAIN INTENTs")

    def verify_drain_effect_absent(self, *_args):
        raise TransitionHold("root transition RPC cannot resolve application DRAIN effects")

    def record_drain_outcome(self, *_args):
        raise TransitionHold("root transition RPC cannot record application DRAIN outcomes")

    def record_drain_reconciliation(self, *_args):
        raise TransitionHold("root transition RPC cannot reconcile application DRAIN effects")


class TransitionJournal:
    """Canonical append-only, hash-chained journal for the inert root adapter."""

    def __init__(self, path, *, require_root_owned=True, fsync_dir=None):
        self.path = Path(path)
        self.require_root_owned = require_root_owned
        self.fsync_dir = fsync_dir

    @staticmethod
    def _digest(record):
        return hashlib.sha256(_transition_canonical(record)).hexdigest()

    def records(self):
        try:
            info = self.path.lstat()
        except FileNotFoundError:
            return []
        if not stat.S_ISREG(info.st_mode) or self.path.is_symlink():
            raise TransitionHold("journal is not a regular file")
        if self.require_root_owned:
            if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o600:
                raise TransitionHold("journal ownership or mode is invalid")
        fd = os.open(self.path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
        try:
            opened = os.fstat(fd)
            if (opened.st_dev, opened.st_ino) != (info.st_dev, info.st_ino):
                raise TransitionHold("journal changed while opening")
            chunks = []
            total = 0
            while True:
                chunk = os.read(fd, min(65536, 64 * 1024 * 1024 + 1 - total))
                if not chunk:
                    break
                total += len(chunk)
                if total > 64 * 1024 * 1024:
                    raise TransitionHold("journal exceeds the supported read bound")
                chunks.append(chunk)
            raw = b"".join(chunks)
        finally:
            os.close(fd)
        if raw and not raw.endswith(b"\n"):
            raise TransitionHold("journal has a truncated final record")
        result = []
        previous = TRANSITION_GENESIS_HASH
        for expected_seq, line in enumerate(raw.splitlines(), 1):
            try:
                value = json.loads(line.decode("ascii"))
            except (UnicodeDecodeError, json.JSONDecodeError):
                raise TransitionHold("journal record is malformed") from None
            if not isinstance(value, dict) or set(value) != {
                "schema_version", "seq", "event", "state", "operation_id", "generation",
                "boot_id", "source_sha", "target_sha", "evidence_digest", "prior_hash",
                "details", "record_hash",
            }:
                raise TransitionHold("journal record has an invalid schema")
            claimed = value.pop("record_hash")
            if (type(value["schema_version"]) is not int or value["schema_version"] != 1
                or type(value["seq"]) is not int or value["seq"] != expected_seq
                or value["prior_hash"] != previous):
                raise TransitionHold("journal sequence or chain is invalid")
            if (not isinstance(value["state"], str) or value["state"] not in TRANSITION_STATES
                or not isinstance(value["event"], str)
                or value["event"] not in TRANSITION_STATES | {"RELEASE_COMMIT"}
                or (value["event"] == "RELEASE_COMMIT" and value["state"] != "OPEN")
                or not isinstance(value["operation_id"], str)
                or not re.fullmatch(r"[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}", value["operation_id"], re.I)
                or type(value["generation"]) is not int or value["generation"] < 0
                or not isinstance(value["boot_id"], str) or not re.fullmatch(r"[0-9a-f-]{36}", value["boot_id"], re.I)
                or not isinstance(value["source_sha"], str) or not re.fullmatch(r"[0-9a-f]{40}", value["source_sha"])
                or not isinstance(value["target_sha"], str) or not re.fullmatch(r"[0-9a-f]{40}", value["target_sha"])
                or not isinstance(value["evidence_digest"], str) or not re.fullmatch(r"[0-9a-f]{64}", value["evidence_digest"])):
                raise TransitionHold("journal record values are invalid")
            if (not isinstance(value["details"], dict)
                or _transition_canonical(dict(value, record_hash=claimed)) != line + b"\n"
                or self._digest(value) != claimed):
                raise TransitionHold("journal hash is invalid")
            previous = claimed
            value["record_hash"] = claimed
            result.append(value)
        return result

    def head(self):
        records = self.records()
        return (len(records), records[-1]["record_hash"] if records else TRANSITION_GENESIS_HASH)

    def prepare(self, record):
        records = self.records()
        base_seq = len(records)
        seq = base_seq + 1
        prior = records[-1]["record_hash"] if records else TRANSITION_GENESIS_HASH
        canonical = dict(record, schema_version=1, seq=seq, prior_hash=prior)
        digest = self._digest(canonical)
        line = _transition_canonical(dict(canonical, record_hash=digest))
        return seq, digest, line, base_seq, prior

    def append_prepared(self, prepared, *, fault=None):
        seq, digest, line, base_seq, prior = prepared
        if self.require_root_owned:
            parent_fd = open_root_trusted_directory(self.path.parent)
            try:
                parent_info = os.fstat(parent_fd)
                if stat.S_IMODE(parent_info.st_mode) != 0o700:
                    raise TransitionHold("journal directory mode is invalid")
            finally:
                os.close(parent_fd)
        else:
            self.path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        if self.require_root_owned:
            parent_info = self.path.parent.lstat()
            if not stat.S_ISDIR(parent_info.st_mode) or parent_info.st_uid != 0 or stat.S_IMODE(parent_info.st_mode) != 0o700:
                raise TransitionHold("journal directory ownership or mode is invalid")
        current_seq, current_head = self.head()
        if (current_seq, current_head) != (base_seq, prior):
            raise TransitionHold("journal head changed after record preparation")
        created = False
        try:
            fd = os.open(self.path, os.O_WRONLY | os.O_APPEND | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o600)
            created = True
        except FileExistsError:
            fd = os.open(self.path, os.O_WRONLY | os.O_APPEND | getattr(os, "O_NOFOLLOW", 0))
        try:
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode):
                raise TransitionHold("journal is not a regular file")
            if self.require_root_owned and (info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o600):
                raise TransitionHold("journal ownership or mode is invalid")
            view = memoryview(line)
            while view:
                written = os.write(fd, view)
                if written <= 0:
                    raise OSError("short journal append")
                view = view[written:]
            if fault is not None:
                fault("journal_appended")
                fault("before_journal_fsync")
            os.fsync(fd)
            if fault is not None:
                fault("journal_fsync")
        finally:
            os.close(fd)
        if created:
            if fault is not None:
                fault("before_journal_directory_fsync")
            if self.fsync_dir is not None:
                self.fsync_dir(self.path.parent)
            else:
                parent_fd = os.open(self.path.parent, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0) | getattr(os, "O_NOFOLLOW", 0))
                try:
                    os.fsync(parent_fd)
                finally:
                    os.close(parent_fd)
            if fault is not None:
                fault("journal_directory_fsync")
        return seq, digest

    def append(self, record, *, fault=None):
        return self.append_prepared(self.prepare(record), fault=fault)


class RootTransitionAdapter:
    """Inert Gate 1 v1.0.3 persistence adapter; no command dispatch calls it.

    The DB boundary is an injected transaction adapter (the Phase 1 M16 interface):
    read_tuple, read_permit_manifest_hash, verify_transition_preconditions,
    validate_permit_manifest, compare_and_set, nonce_consumed, validate_release,
    assert_exact_permit, claim_drain_step, verify_drain_effect_absent, record_drain_outcome,
    assert_no_unresolved_drain_effects, and record_drain_reconciliation. Claims
    atomically insert one immutable INTENT keyed by the exact operation/generation,
    permit, work, and step binding; within that same transaction the claim must
    reject unresolved INTENT/UNKNOWN work for another permit in the operation and
    generation. The returned claim reference identifies that durable row. The
    proposed UUID is correlation only and never the replay key. Outcomes are
    immutable INTENT -> SUCCEEDED/FAILED/UNKNOWN writes. Reconciliation is a
    separate immutable authoritative record; it must not rewrite an outcome.
    The current M16 claimFromDrain serializes on the fence but only enforces per-step
    uniqueness; a live adapter needs a reviewed operation-wide unresolved-work guard.
    This boundary performs no SQL or Laravel-model calls.
    Tests must use a fake. The constructor intentionally requires every path and
    adapter, so importing this module cannot touch /run, /var/lib, or a database.
    """

    def __init__(self, *, journal_path, status_path, pending_path, lock, database,
                 boot_id, host_id, require_root_owned=True, fault=None):
        if not isinstance(boot_id, str) or not re.fullmatch(r"[0-9a-f-]{36}", boot_id, re.I):
            raise ValueError("invalid boot ID")
        self.journal = TransitionJournal(journal_path, require_root_owned=require_root_owned,
                                        fsync_dir=self._fsync_dir)
        self.status_path = Path(status_path)
        self.pending_path = Path(pending_path)
        self.lock = lock
        self.database = database
        if isinstance(database, LaravelTransitionDatabaseAdapter):
            database.bind_lock(lock)
        self.boot_id = boot_id.lower()
        self.host_id = host_id
        self.require_root_owned = require_root_owned
        self.fault = fault or (lambda _point: None)

    @staticmethod
    def _tuple(value):
        if not isinstance(value, dict) or not set(TRANSITION_TUPLE_FIELDS).issubset(value):
            raise TransitionHold("transition tuple is incomplete")
        result = {key: value[key] for key in TRANSITION_TUPLE_FIELDS}
        if not isinstance(result["state"], str) or result["state"] not in TRANSITION_STATES:
            raise TransitionHold("transition state is unknown")
        if not isinstance(result["operation_id"], str) or not re.fullmatch(r"[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}", result["operation_id"], re.I):
            raise TransitionHold("operation ID must be UUIDv7")
        if type(result["generation"]) is not int or result["generation"] < 0:
            raise TransitionHold("generation is invalid")
        if type(result["journal_seq"]) is not int or result["journal_seq"] < 0:
            raise TransitionHold("journal sequence is invalid")
        for key in ("journal_head_hash", "evidence_digest"):
            if not isinstance(result[key], str) or not re.fullmatch(r"[0-9a-f]{64}", result[key]):
                raise TransitionHold("transition digest is invalid")
        if not isinstance(result["boot_id"], str) or not re.fullmatch(r"[0-9a-f-]{36}", result["boot_id"], re.I):
            raise TransitionHold("boot ID is invalid")
        return result

    def _event(self, point):
        self.fault(point)

    def _fsync_dir(self, path):
        fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0))
        try:
            os.fsync(fd)
        finally:
            os.close(fd)

    def _read_projection(self):
        if self.pending_path.exists() or self.pending_path.is_symlink():
            raise TransitionHold("pending marker blocks transition admission")
        try:
            info = self.status_path.lstat()
            if not stat.S_ISREG(info.st_mode) or self.status_path.is_symlink():
                raise TransitionHold("projection is not a regular file")
            if self.require_root_owned and (info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o644):
                raise TransitionHold("projection ownership or mode is invalid")
            fd = os.open(self.status_path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
            try:
                opened = os.fstat(fd)
                if (opened.st_dev, opened.st_ino) != (info.st_dev, info.st_ino):
                    raise TransitionHold("projection changed while opening")
                content = os.read(fd, 1024 * 1024 + 1)
            finally:
                os.close(fd)
            if len(content) > 1024 * 1024:
                raise TransitionHold("projection exceeds supported size")
            value = json.loads(content.decode("ascii"))
        except (OSError, UnicodeDecodeError, json.JSONDecodeError):
            raise TransitionHold("projection is missing or corrupt") from None
        if not isinstance(value, dict):
            raise TransitionHold("projection is invalid")
        if _transition_canonical(value) != content:
            raise TransitionHold("projection is not canonical")
        expected_shape = set(TRANSITION_TUPLE_FIELDS)
        if value.get("state") == "DRAINING":
            expected_shape.add("permit_manifest_hash")
        if set(value) != expected_shape:
            raise TransitionHold("projection shape is invalid")
        if self.require_root_owned:
            parent_fd = open_root_trusted_directory(self.status_path.parent)
            os.close(parent_fd)
        tuple_value = self._tuple(value)
        if tuple_value["boot_id"] != self.boot_id:
            raise TransitionHold("projection boot ID is stale")
        if tuple_value["journal_seq"] == 0:
            if tuple_value["journal_head_hash"] != TRANSITION_GENESIS_HASH:
                raise TransitionHold("genesis journal hash is invalid")
        seq, digest = self.journal.head()
        if (seq, digest) != (tuple_value["journal_seq"], tuple_value["journal_head_hash"]):
            raise TransitionHold("projection does not match journal head")
        db_tuple = self._tuple(self.database.read_tuple())
        if db_tuple != {key: tuple_value[key] for key in TRANSITION_TUPLE_FIELDS}:
            raise TransitionHold("projection and database tuple differ")
        if tuple_value["state"] == "DRAINING":
            manifest = value.get("permit_manifest_hash")
            if not isinstance(manifest, str) or not re.fullmatch(r"[0-9a-f]{64}", manifest):
                raise TransitionHold("drain manifest is invalid")
            if self.database.read_permit_manifest_hash() != manifest:
                raise TransitionHold("projection and database drain manifest differ")
            tuple_value["permit_manifest_hash"] = manifest
        return tuple_value

    def assert_open(self):
        with self.lock.hold("shared"):
            current = self._durability_preflight()
            if current["state"] != "OPEN":
                raise TransitionHold("admission is closed")
            return current

    def check_drain_permit(self, binding):
        """Check a permit for diagnostics only; it does not authorize an effect."""
        with self.lock.hold("shared"):
            current = self._durability_preflight()
            if current["state"] != "DRAINING":
                raise TransitionHold("drain is closed")
            self.database.assert_exact_permit(current, binding)
            return current

    def assert_drain_permit(self, binding):
        raise TransitionHold(
            "check-only drain validation is not authorization; use execute_drain_permit"
        )

    def execute_drain_permit(self, binding, effect, *, max_duration_seconds=30.0):
        """Atomically consume one exact permit step before a bounded external effect.

        The injected claim method must durably enforce uniqueness by exact permit
        and step binding, independent of the caller-supplied intent UUID, and reject
        unresolved work for another permit in the same operation/generation within
        the atomic claim transaction. Generic exceptions, timeouts, late results,
        and lost post-effect verification are UNKNOWN. FAILED requires adapter
        verification of authoritative no-effect evidence. INTENT remains durable
        if the process dies before outcome write. SHARED remains held throughout.
        """
        if self.require_root_owned or isinstance(self.database, LaravelTransitionDatabaseAdapter):
            raise TransitionHold("production root transition adapter has no enabled DRAIN effect catalog")
        if not callable(effect):
            raise TypeError("drain effect must be callable")
        if (type(max_duration_seconds) not in {int, float}
            or not 0 < max_duration_seconds <= 300):
            raise ValueError("drain effect bound must be between 0 and 300 seconds")
        with self.lock.hold("shared"):
            current = self._durability_preflight()
            if current["state"] != "DRAINING":
                raise TransitionHold("drain is closed")
            if self.database.assert_no_unresolved_drain_effects(
                current["operation_id"], current["generation"]
            ) is not True:
                raise TransitionHold("unresolved DRAIN intent/UNKNOWN blocks another effect")
            self.database.assert_exact_permit(current, binding)
            intent_id = _transition_uuid7()
            claim = self.database.claim_drain_step(current, binding, intent_id)
            if not claim:
                raise TransitionHold("atomic one-time drain permit claim was not recorded")
            self._event("drain_intent_durable")
            revalidated = self._read_projection()
            if self._tuple(revalidated) != self._tuple(current):
                raise TransitionHold("drain tuple changed after durable intent")
            # claim_drain_step atomically validated the exact binding and consumed
            # it. Rechecking the unclaimed-permit predicate here would correctly
            # reject our own claim; the retained SHARED lock and authoritative tuple
            # revalidation protect this boundary instead.
            deadline = time.monotonic() + max_duration_seconds
            try:
                self._event("before_drain_effect")
                result = effect(deadline)
                self._event("drain_effect_returned")
            except DrainEffectNotPerformed as exc:
                if self.database.verify_drain_effect_absent(
                    claim, intent_id, exc.evidence_digest, exc.authority_reference
                ) is not True:
                    raise TransitionHold(
                        "absence of external effect is unverified; durable INTENT remains unresolved"
                    ) from exc
                outcome = {"status": "FAILED", "evidence_digest": exc.evidence_digest}
                if self.database.record_drain_outcome(claim, intent_id, outcome) is not True:
                    raise TransitionHold("no-effect outcome was not durably recorded") from exc
                raise TransitionHold("drain effect did not occur; consumed permit cannot be reused") from exc
            except BaseException as exc:
                outcome = {
                    "status": "UNKNOWN",
                    "evidence_digest": hashlib.sha256(
                        ("ambiguous:" + type(exc).__name__).encode("ascii", "replace")
                    ).hexdigest(),
                }
                if self.database.record_drain_outcome(claim, intent_id, outcome) is not True:
                    raise TransitionHold("ambiguous outcome remains unresolved in durable INTENT") from exc
                raise TransitionHold("drain effect outcome is UNKNOWN; authoritative reconciliation required") from exc
            late = time.monotonic() > deadline
            try:
                revalidated = self._read_projection()
                if self._tuple(revalidated) != self._tuple(current):
                    raise TransitionHold("drain tuple changed during authorized effect")
            except TransitionHold as exc:
                outcome = {
                    "status": "UNKNOWN",
                    "evidence_digest": hashlib.sha256(
                        ("post-effect-revalidation:" + type(exc).__name__).encode("ascii", "replace")
                    ).hexdigest(),
                }
                if self.database.record_drain_outcome(claim, intent_id, outcome) is not True:
                    raise TransitionHold("post-effect outcome remains unresolved in durable INTENT") from exc
                raise TransitionHold("post-effect state is uncertain; authoritative reconciliation required") from exc
            if late:
                outcome = {
                    "status": "UNKNOWN",
                    "evidence_digest": hashlib.sha256(b"drain-effect-deadline-exceeded").hexdigest(),
                }
                if self.database.record_drain_outcome(claim, intent_id, outcome) is not True:
                    raise TransitionHold("late outcome remains unresolved in durable INTENT")
                raise TransitionHold("drain effect exceeded its deadline; outcome UNKNOWN")
            if not isinstance(result, dict) or set(result) != {"status", "evidence_digest"}:
                outcome = {
                    "status": "UNKNOWN",
                    "evidence_digest": hashlib.sha256(b"invalid-effect-outcome").hexdigest(),
                }
                if self.database.record_drain_outcome(claim, intent_id, outcome) is not True:
                    raise TransitionHold("invalid effect outcome remains unresolved in durable INTENT")
                raise TransitionHold("effect outcome is invalid and remains UNKNOWN")
            if (result["status"] != "SUCCEEDED"
                or not isinstance(result["evidence_digest"], str)
                or not re.fullmatch(r"[0-9a-f]{64}", result["evidence_digest"])):
                outcome = {
                    "status": "UNKNOWN",
                    "evidence_digest": hashlib.sha256(b"unproven-effect-outcome").hexdigest(),
                }
                if self.database.record_drain_outcome(claim, intent_id, outcome) is not True:
                    raise TransitionHold("unproven outcome remains unresolved in durable INTENT")
                raise TransitionHold("effect outcome is not proven; outcome UNKNOWN")
            if self.database.record_drain_outcome(claim, intent_id, result) is not True:
                raise TransitionHold("successful effect outcome remains unresolved in durable INTENT")
            return result

    def reconcile_drain_effect(self, claim, *, resolution, evidence_digest, authority_reference):
        """Record immutable external reconciliation without rewriting the intent/outcome.

        The injected adapter must validate the authority reference against its
        authoritative source and durably append a separate reconciliation record.
        The current M16 TransitionExecution model has no reconciliation table, so
        a live DB adapter must provide that reviewed persistence extension first.
        """
        if self.require_root_owned or isinstance(self.database, LaravelTransitionDatabaseAdapter):
            raise TransitionHold("root transition adapter cannot reconcile application DRAIN effects")
        if resolution not in {"SUCCEEDED", "FAILED"}:
            raise ValueError("reconciliation resolution must be SUCCEEDED or FAILED")
        if not isinstance(evidence_digest, str) or not re.fullmatch(r"[0-9a-f]{64}", evidence_digest):
            raise ValueError("reconciliation evidence digest is invalid")
        if not isinstance(authority_reference, str) or not authority_reference:
            raise ValueError("authoritative reconciliation reference is required")
        with self.lock.hold("exclusive"):
            source = self._durability_preflight()
            if source["state"] != "DRAINING":
                raise TransitionHold("drain reconciliation is only valid while DRAINING")
            if self.database.record_drain_reconciliation(
                claim, resolution, evidence_digest, authority_reference
            ) is not True:
                raise TransitionHold("authoritative reconciliation was not durably recorded")
            return {"status": resolution, "evidence_digest": evidence_digest}

    def recover_uncertain_transition(self):
        """Finish projection publication only after a positive Laravel commit receipt.

        This path never replays compare_and_set. An absent, mismatched, or ambiguous
        receipt leaves the pending marker in place and the transition held.
        """
        with self.lock.hold("exclusive"):
            if not self.pending_path.exists() or self.pending_path.is_symlink():
                raise TransitionHold("no safe pending transition is available for receipt recovery")

            def read_local_projection(path):
                try:
                    info = path.lstat()
                    if not stat.S_ISREG(info.st_mode) or path.is_symlink():
                        raise TransitionHold("recovery projection is not a regular file")
                    if self.require_root_owned and (info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o644):
                        raise TransitionHold("recovery projection ownership or mode is invalid")
                    fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
                    try:
                        opened = os.fstat(fd)
                        if (opened.st_dev, opened.st_ino) != (info.st_dev, info.st_ino):
                            raise TransitionHold("recovery projection changed while opening")
                        content = os.read(fd, 1024 * 1024 + 1)
                    finally:
                        os.close(fd)
                    value = json.loads(content.decode("ascii"))
                except (OSError, UnicodeDecodeError, json.JSONDecodeError):
                    raise TransitionHold("recovery projection is missing or corrupt") from None
                if len(content) > 1024 * 1024 or not isinstance(value, dict):
                    raise TransitionHold("recovery projection is invalid or too large")
                if _transition_canonical(value) != content:
                    raise TransitionHold("recovery projection is not canonical")
                expected = set(TRANSITION_TUPLE_FIELDS)
                if value.get("state") == "DRAINING":
                    expected.add("permit_manifest_hash")
                if set(value) != expected:
                    raise TransitionHold("recovery projection shape is invalid")
                tuple_value = self._tuple(value)
                if tuple_value["boot_id"] != self.boot_id:
                    raise TransitionHold("recovery projection belongs to a stale boot")
                if value["state"] == "DRAINING":
                    manifest = value.get("permit_manifest_hash")
                    if not isinstance(manifest, str) or not re.fullmatch(r"[0-9a-f]{64}", manifest):
                        raise TransitionHold("recovery drain manifest is invalid")
                    tuple_value["permit_manifest_hash"] = manifest
                return tuple_value

            if self.require_root_owned:
                for parent in (self.pending_path.parent, self.status_path.parent):
                    parent_fd = open_root_trusted_directory(parent)
                    os.close(parent_fd)
            source = read_local_projection(self.status_path)
            target = read_local_projection(self.pending_path)
            records = self.journal.records()
            if not records:
                raise TransitionHold("pending transition has no durable journal record")
            record = records[-1]
            if (record["seq"] != target["journal_seq"]
                or record["record_hash"] != target["journal_head_hash"]
                or record["state"] != target["state"]
                or record["operation_id"] != target["operation_id"]
                or record["generation"] != target["generation"]
                or record["boot_id"] != target["boot_id"]
                or record["prior_hash"] != source["journal_head_hash"]
                or record["seq"] != source["journal_seq"] + 1
                or (source["state"], target["state"]) not in TRANSITION_EDGES):
                raise TransitionHold("pending transition does not match the durable journal edge")
            details = record.get("details")
            request_id = details.get("rpc_request_id") if isinstance(details, dict) else None
            request_digest = details.get("rpc_request_digest") if isinstance(details, dict) else None
            if (not isinstance(request_id, str)
                or not re.fullmatch(r"[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}", request_id, re.I)
                or not isinstance(request_digest, str)
                or not re.fullmatch(r"[0-9a-f]{64}", request_digest)):
                raise TransitionHold("journal has no valid durable Laravel request identity")
            receipt = self.database.read_transition_receipt(request_id, request_digest)
            if (not isinstance(receipt, dict)
                or receipt.get("committed") is not True
                or receipt.get("request_id") != request_id
                or receipt.get("request_digest") != request_digest
                or receipt.get("target") != target):
                raise TransitionHold("Laravel has not positively verified the pending transition commit")
            db_tuple = self._tuple(self.database.read_tuple())
            if db_tuple != {key: target[key] for key in TRANSITION_TUPLE_FIELDS}:
                raise TransitionHold("Laravel current tuple does not match the committed receipt")
            if target["state"] == "DRAINING" and self.database.read_permit_manifest_hash() != target["permit_manifest_hash"]:
                raise TransitionHold("Laravel permit manifest does not match the pending transition")
            self._event("before_recovery_projection_rename")
            os.replace(self.pending_path, self.status_path)
            self._event("recovery_projection_rename")
            try:
                self._fsync_dir(self.status_path.parent)
            except Exception as exc:
                raise TransitionDurabilityUnconfirmed("COMMITTED_DURABILITY_UNCONFIRMED") from exc
            self._event("recovery_projection_directory_fsync")
            recovered = self._read_projection()
            if self._tuple(recovered) != {key: target[key] for key in TRANSITION_TUPLE_FIELDS}:
                raise TransitionHold("recovered projection failed authoritative verification")
            return recovered

    def _durability_preflight(self):
        """Re-establish projection-directory durability before any new commit.

        After a rename plus failed directory fsync, same-boot retry must fsync the
        parent and verify projection, journal, and authoritative DB equality. A
        stale boot ID remains a hard hold because post-reboot durability is uncertain.
        """
        try:
            self._fsync_dir(self.status_path.parent)
            current = self._read_projection()
            db_tuple = self._tuple(self.database.read_tuple())
            if db_tuple != {key: current[key] for key in TRANSITION_TUPLE_FIELDS}:
                raise TransitionHold("durability preflight authoritative tuple mismatch")
            return current
        except TransitionHold:
            raise
        except OSError as exc:
            raise TransitionHold("durability preflight could not fsync projection directory") from exc

    def _publish(self, source, target, event, *, details, source_sha, target_sha,
                 evidence_digest, permit_manifest_hash=None, release=None,
                 release_record=None):
        if self.pending_path.exists() or self.pending_path.is_symlink():
            raise TransitionHold("pending marker already exists")
        record_details = dict(details)
        if release_record is not None:
            record_details["release"] = release_record
        request_id = _transition_uuid7()
        request_target = {key: target[key] for key in TRANSITION_TUPLE_FIELDS if key != "journal_head_hash"}
        if permit_manifest_hash is not None:
            request_target["permit_manifest_hash"] = permit_manifest_hash
        request_digest = hashlib.sha256(_transition_canonical({
            "version": TRANSITION_RPC_VERSION,
            "request_id": request_id,
            "event": event,
            "source": self._tuple(source),
            "target": request_target,
            "details": record_details,
            "release": release,
        })).hexdigest()
        record_details["rpc_request_id"] = request_id
        record_details["rpc_request_digest"] = request_digest
        record = {
            "event": event,
            "state": target["state"],
            "operation_id": target["operation_id"],
            "generation": target["generation"],
            "boot_id": target["boot_id"],
            "source_sha": source_sha,
            "target_sha": target_sha,
            "evidence_digest": evidence_digest,
            "details": record_details,
        }
        prepared = self.journal.prepare(record)
        seq, head, _line, _base_seq, _prior = prepared
        target["journal_seq"] = seq
        target["journal_head_hash"] = head
        target["evidence_digest"] = evidence_digest
        if permit_manifest_hash is not None:
            target["permit_manifest_hash"] = permit_manifest_hash
        encoded = _transition_canonical(target)
        self._event("before_marker_create")
        flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0)
        if self.require_root_owned:
            parent_fd = open_root_trusted_directory(self.pending_path.parent)
            os.close(parent_fd)
        fd = os.open(self.pending_path, flags, 0o644)
        try:
            if self.require_root_owned:
                os.fchown(fd, 0, 0)
            os.fchmod(fd, 0o644)
            self._event("marker_created")
            view = memoryview(encoded)
            partial = False
            while view:
                chunk = max(1, len(view) // 2) if not partial else len(view)
                written = os.write(fd, view[:chunk])
                if written <= 0:
                    raise OSError("short marker write")
                view = view[written:]
                if not partial and view:
                    partial = True
                    self._event("marker_partial_write")
            self._event("marker_written")
            self._event("before_marker_file_fsync")
            os.fsync(fd)
            self._event("marker_file_fsync")
        finally:
            os.close(fd)
        self._event("before_marker_directory_fsync")
        self._fsync_dir(self.pending_path.parent)
        self._event("marker_directory_fsync")
        self._event("before_journal_append")
        self.journal.append_prepared(prepared, fault=self._event)
        self._event("before_database_commit")
        self.database.compare_and_set(source, target, release=release, details=record_details)
        self._event("database_commit")
        if self._tuple(self.database.read_tuple()) != self._tuple(target):
            raise TransitionHold("database did not persist exact target tuple")
        jseq, jhead = self.journal.head()
        if (jseq, jhead) != (seq, head):
            raise TransitionHold("journal changed before projection commit")
        self._event("before_projection_rename")
        os.replace(self.pending_path, self.status_path)
        self._event("projection_rename")
        try:
            self._event("before_projection_directory_fsync")
            self._fsync_dir(self.status_path.parent)
        except Exception as exc:
            raise TransitionDurabilityUnconfirmed("COMMITTED_DURABILITY_UNCONFIRMED") from exc
        self._event("projection_directory_fsync")
        published = self._read_projection()
        if self._tuple(published) != self._tuple(target) or self._tuple(self.database.read_tuple()) != self._tuple(target):
            raise TransitionHold("final projection/database verification failed")
        return target

    def transition(self, target_state, *, source_sha, target_sha, evidence_digest,
                   details=None, preconditions=None, permits=None):
        if target_state not in TRANSITION_STATES:
            raise ValueError("unknown target state")
        for name, value in (("source SHA", source_sha), ("target SHA", target_sha)):
            if not isinstance(value, str) or not re.fullmatch(r"[0-9a-f]{40}", value):
                raise ValueError("invalid " + name)
        if not isinstance(evidence_digest, str) or not re.fullmatch(r"[0-9a-f]{64}", evidence_digest):
            raise ValueError("invalid evidence digest")
        details = dict(details or {})
        def has_sensitive_key(value):
            if isinstance(value, dict):
                return any(re.search(r"secret|token|password|credential|api[_-]?key", str(key), re.I)
                           or has_sensitive_key(nested) for key, nested in value.items())
            if isinstance(value, (list, tuple)):
                return any(has_sensitive_key(item) for item in value)
            return False
        if has_sensitive_key(details):
            raise ValueError("secret-like journal fields are forbidden")
        with self.lock.hold("exclusive"):
            source = self._durability_preflight()
            if source["state"] == "DRAINING" and self.database.assert_no_unresolved_drain_effects(
                source["operation_id"], source["generation"]
            ) is not True:
                raise TransitionHold("unresolved DRAIN intent/UNKNOWN outcome blocks transition progression")
            if (source["state"], target_state) not in TRANSITION_EDGES:
                raise TransitionHold("transition is not allowed")
            new_operation = source["state"] == "OPEN" and target_state == "HOLD_REQUESTED"
            target = {
                "state": target_state,
                "operation_id": _transition_uuid7() if new_operation else source["operation_id"],
                "generation": source["generation"] + (1 if new_operation else 0),
                "boot_id": self.boot_id,
                "journal_seq": source["journal_seq"] + 1,
                "journal_head_hash": source["journal_head_hash"],
                "evidence_digest": evidence_digest,
            }
            manifest = None
            if target_state == "DRAINING":
                if permits is None or not isinstance(permits, list):
                    raise TransitionHold("DRAINING requires a finite permit manifest")
                forbidden = {"CONNECTOR", "BACKUP", "RESTORE", "UPDATE", "UPLOAD", "TRANSFER", "RETENTION"}
                for permit in permits:
                    if not isinstance(permit, dict):
                        raise TransitionHold("drain permit is invalid")
                    domain = str(permit.get("domain", permit.get("work_type", ""))).upper()
                    if domain in forbidden:
                        raise TransitionHold("Connector and backup work have no drain exception")
                manifest_payload = {
                    "operation_id": target["operation_id"],
                    "generation": target["generation"],
                    "cutoff_seq": source["journal_seq"],
                    "permits": permits,
                }
                manifest = hashlib.sha256(_transition_canonical(manifest_payload)).hexdigest()
                details["cutoff_seq"] = source["journal_seq"]
                details["permit_manifest_hash"] = manifest
                self.database.validate_permit_manifest(source, permits, manifest)
            self.database.verify_transition_preconditions(source, target, details, permits)
            if preconditions is not None:
                preconditions(source, target_state)
            event = target_state
            return self._publish(source, target, event, details=details,
                                 source_sha=source_sha, target_sha=target_sha,
                                 evidence_digest=evidence_digest,
                                 permit_manifest_hash=manifest)

    def release(self, authorization, *, source_sha, target_sha, evidence_digest):
        for name, value in (("source SHA", source_sha), ("target SHA", target_sha)):
            if not isinstance(value, str) or not re.fullmatch(r"[0-9a-f]{40}", value):
                raise ValueError("invalid " + name)
        if not isinstance(evidence_digest, str) or not re.fullmatch(r"[0-9a-f]{64}", evidence_digest):
            raise ValueError("invalid evidence digest")
        required = {"host_id", "operation_id", "generation", "source_sha", "target_sha",
                    "approval_ref", "php_profile", "rollback_evidence", "expected_journal_seq", "nonce"}
        if not isinstance(authorization, dict) or set(authorization) != required:
            raise TransitionHold("release authorization shape is invalid")
        nonce = authorization["nonce"]
        if not isinstance(nonce, str) or not re.fullmatch(r"[A-Za-z0-9_-]{32,128}", nonce):
            raise TransitionHold("release nonce is invalid")
        nonce_digest = hashlib.sha256(nonce.encode("ascii")).hexdigest()
        with self.lock.hold("exclusive"):
            source = self._durability_preflight()
            if self.database.assert_no_unresolved_drain_effects(
                source["operation_id"], source["generation"]
            ) is not True:
                raise TransitionHold("unresolved DRAIN intent/UNKNOWN outcome blocks RELEASE")
            if self.database.nonce_consumed(nonce_digest) or any(
                record.get("event") == "RELEASE_COMMIT"
                and record.get("details", {}).get("nonce_digest") == nonce_digest
                for record in self.journal.records()
            ):
                raise TransitionHold("release nonce was already used")
            if source["state"] != "READY_PENDING_OWNER":
                raise TransitionHold("release source state is not ready")
            if authorization["php_profile"] != "8.4" or authorization["host_id"] != self.host_id:
                raise TransitionHold("release host or PHP profile is invalid")
            if authorization["operation_id"] != source["operation_id"] or authorization["generation"] != source["generation"]:
                raise TransitionHold("release operation scope is invalid")
            if authorization["source_sha"] != source_sha or authorization["target_sha"] != target_sha:
                raise TransitionHold("release SHA scope is invalid")
            if authorization["expected_journal_seq"] != source["journal_seq"] + 1:
                raise TransitionHold("release journal sequence is invalid")
            if (not isinstance(authorization["approval_ref"], str) or not authorization["approval_ref"]
                or not isinstance(authorization["rollback_evidence"], str)
                or not re.fullmatch(r"[0-9a-f]{64}", authorization["rollback_evidence"])):
                raise TransitionHold("release evidence is incomplete")
            target = dict(source, state="OPEN", boot_id=self.boot_id,
                          journal_seq=source["journal_seq"] + 1,
                          evidence_digest=evidence_digest)
            details = {"approval_ref": authorization["approval_ref"], "nonce_digest": nonce_digest}
            release_binding = {key: value for key, value in authorization.items() if key != "nonce"}
            release_binding["nonce_digest"] = nonce_digest
            self.database.validate_release(release_binding, source, nonce_digest)
            return self._publish(source, target, "RELEASE_COMMIT", details=details,
                                 source_sha=source_sha, target_sha=target_sha,
                                 evidence_digest=evidence_digest,
                                 release=release_binding,
                                 release_record=release_binding)


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
    elif args == ["php-runtime-status"]:
        sys.stdout.write(active_php_profile() + "\n")
    elif len(args) == 2 and args[0] == "php-runtime-preflight":
        php_runtime_preflight(args[1], allow_legacy_supervisor=True)
    elif args == ["php-runtime-install"]:
        install_runtime_assets()
    elif len(args) == 2 and args[0] == "php-runtime-switch":
        switch_php_runtime(args[1])
    elif args == ["php-runtime-supervisor-update"]:
        update_supervisor_runtime()
    elif len(args) == 2 and args[0] == "worker-runtime-check":
        worker_runtime_check(args[1])
    elif args == ["worker-runtime-disable"]:
        set_workers_disabled(True)
    elif args == ["worker-runtime-enable"]:
        set_workers_disabled(False)
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
        if args[1] == "stop-all":
            set_workers_disabled(True)
        elif args[1] == "start-all":
            worker_runtime_enabled()
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
