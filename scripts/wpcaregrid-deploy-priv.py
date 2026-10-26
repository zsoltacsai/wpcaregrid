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


def validate_nginx_runtime_dump(output):
    sections = []
    current_path = ""
    current_lines = []
    for line in output.splitlines():
        marker = re.fullmatch(r"# configuration file (/.+):", line)
        if marker:
            if current_path:
                sections.append((current_path, current_lines))
            current_path = marker.group(1)
            current_lines = []
        elif current_path:
            current_lines.append(line)
    if current_path:
        sections.append((current_path, current_lines))

    include_count = 0
    selected_include_blocks = 0
    for path, lines in sections:
        depth = 0
        server = None
        server_base_depth = None
        for raw_line in lines:
            line = raw_line.split("#", 1)[0].strip()
            if not line:
                continue
            if server is None and re.match(r"^server\s*\{", line):
                server = {"path": path, "names": set(), "selector": False, "php_sockets": []}
                server_base_depth = depth
            if server is not None:
                names = re.fullmatch(r"server_name\s+(.+?)\s*;", line)
                if names:
                    server["names"].update(token.lower() for token in names.group(1).split())
            selector = re.fullmatch(r"include\s+/etc/nginx/snippets/wpcaregrid-staging-runtime\.conf\s*;", line)
            if selector:
                include_count += 1
                if server is None:
                    raise ValueError("PHP runtime selector is outside a server block")
                else:
                    server["selector"] = True
            if server is not None:
                socket = re.fullmatch(r"fastcgi_pass\s+(.+?)\s*;", line)
                if socket:
                    server["php_sockets"].append(socket.group(1))
            depth += line.count("{") - line.count("}")
            if server is not None and depth == server_base_depth:
                is_staging = PHP_RUNTIME_VHOST in server["names"]
                if server["selector"] and server["names"] != {PHP_RUNTIME_VHOST}:
                    raise ValueError("PHP runtime selector server_name must be exactly the staging vhost")
                if server["selector"] and is_staging:
                    selected_include_blocks += 1
                if server["selector"] and not is_staging:
                    raise ValueError("PHP runtime selector is referenced by a non-staging vhost")
                if is_staging and server["selector"]:
                    # The include's contents appear as a separate section in
                    # `nginx -T`; runtime_snippet() above verifies its exact
                    # socket directive. Reject any competing inline PHP route.
                    if server["php_sockets"]:
                        raise ValueError("staging vhost has a competing inline PHP socket")
                elif is_staging and server["php_sockets"]:
                    raise ValueError("staging vhost has an unselected PHP socket")
                server = None
    if include_count != 1 or selected_include_blocks != 1:
        raise ValueError("PHP runtime selector is not uniquely scoped to a staging vhost")


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
    """Validate the effective fixed FPM pool dump, not merely a source file."""
    profile = php_profile(profile)
    expected_socket = PHP_RUNTIME_SOCKETS[profile].as_posix()
    current_pool = None
    listens = {}
    for raw_line in output.splitlines():
        line = re.sub(r"^\[[^]]+\]\s+NOTICE:\s*", "", raw_line).strip()
        if line.startswith("[") and line.endswith("]"):
            current_pool = line[1:-1].strip()
            continue
        match = re.fullmatch(r"listen\s*=\s*(\S+)\s*", line)
        if match and current_pool is not None:
            listens.setdefault(current_pool, []).append(match.group(1))
    if listens.get("www") != [expected_socket]:
        raise ValueError("effective www FPM pool listener differs from the selected socket")
    if any(pool != "www" and expected_socket in values for pool, values in listens.items()):
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
