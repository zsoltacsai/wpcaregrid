#!/usr/bin/env python3
"""GO-6.2 Option A: standalone, fixed-scope, read-only production inventory.

Run from the root console with no arguments:

    python3 -I -B /usr/local/libexec/wpcaregrid-g1-inventory/go62-inventory.py

The helper prints one JSON document to stdout and exits:

    0  PASS
    3  FAIL
    4  UNKNOWN
    5  UNAVAILABLE

It is deliberately not a general-purpose host audit, deploy helper or database
client.  It uses fixed paths and fixed argv arrays only, never a shell, never a
credential file and never a caller-supplied path.  It does not connect to MySQL.
Privilege state that would require authenticated database access is reported as
UNKNOWN, never inferred.  A backup file or gzip check is not restore proof.
"""

from __future__ import annotations

import errno
import gzip
import hashlib
import json
import os
import platform
import re
import secrets
import stat
import subprocess
import sys
import time
import zlib
from datetime import datetime, timezone

TOOL = "go62-production-inventory"
VERSION = "1.0.0"
SCHEMA = 1
MAX_COMMAND_OUTPUT = 2 * 1024 * 1024
MAX_TEXT_FILE = 1024 * 1024
MAX_ARTIFACT_BYTES = 8 * 1024 * 1024
MAX_JOURNAL_BYTES = 256 * 1024
MAX_BACKUPS = 16
MAX_BACKUP_HASH_BYTES = 512 * 1024 * 1024
MAX_BACKUP_UNCOMPRESSED_BYTES = 1024 * 1024 * 1024
MAX_JOURNALS = 16

PRODUCTION_BASE = "/var/www/wpcaregrid-production"
PRODUCTION_CURRENT = PRODUCTION_BASE + "/current"
PRODUCTION_RELEASES = PRODUCTION_BASE + "/releases"
PRODUCTION_STATE = "/var/lib/wpcaregrid-deploy/production/state"
PRODUCTION_BACKUPS = "/var/lib/wpcaregrid-deploy/production/database-backups"

G1_UNITS = {
    "wpcaregrid-production": "mysql@wpcaregrid-production.service",
    "wpcaregrid-production-restore": "mysql@wpcaregrid-production-restore.service",
}
MYSQL_UNIT = "mysql.service"
FPM_UNIT = "php8.4-fpm.service"
NGINX_UNIT = "nginx.service"

G1_ARTIFACTS = (
    ("helper", "/usr/local/libexec/wpcaregrid-g1-mysql.py", 0o755),
    ("contract", "/usr/local/libexec/production_g1_mysql_contract.py", 0o644),
    ("manifest", "/usr/local/libexec/production_g1_mysql_manifest.json", 0o644),
    ("config_production", "/etc/mysql/mysql.conf.d/zz-wpcaregrid-production.cnf", 0o644),
    ("config_restore", "/etc/mysql/mysql.conf.d/zz-wpcaregrid-production-restore.cnf", 0o644),
    ("systemd_dropin_production",
     "/etc/systemd/system/mysql@wpcaregrid-production.service.d/90-wpcaregrid-g1.conf", 0o644),
    ("systemd_dropin_restore",
     "/etc/systemd/system/mysql@wpcaregrid-production-restore.service.d/90-wpcaregrid-g1.conf", 0o644),
    ("apparmor_production", "/etc/apparmor.d/wpcaregrid-mysql-production", 0o644),
    ("apparmor_restore", "/etc/apparmor.d/wpcaregrid-mysql-production-restore", 0o644),
)

G1_PROFILES = (
    "wpcaregrid-mysql-production",
    "wpcaregrid-mysql-production-restore",
)
VENDOR_PROFILE = "usr.sbin.mysqld"
LOCK_PATHS = (
    ("deploy_lock", PRODUCTION_STATE + "/deploy.lock"),
    ("migration_lock", PRODUCTION_STATE + "/database-release.lock"),
)
KNOWN_DENIAL_PATHS = {
    "/etc/mysql/mysql.cnf": "mysql_base_config",
    "/etc/mysql/my.cnf": "mysql_base_config_link",
    "/run/mysqld/wpcaregrid-production.sock": "g1_production_socket",
    "/run/mysqld/wpcaregrid-production.sock.lock": "g1_production_socket_lock",
    "/run/mysqld/wpcaregrid-production.pid": "g1_production_pid",
    "/run/mysqld/wpcaregrid-production-restore.sock": "g1_restore_socket",
    "/run/mysqld/wpcaregrid-production-restore.sock.lock": "g1_restore_socket_lock",
    "/run/mysqld/wpcaregrid-production-restore.pid": "g1_restore_pid",
    "/var/lib/mysql-wpcaregrid-production": "g1_production_datadir",
    "/var/lib/mysql-wpcaregrid-production-restore": "g1_restore_datadir",
    "/var/tmp/wpcaregrid-production": "g1_production_tmpdir",
    "/var/tmp/wpcaregrid-production-restore": "g1_restore_tmpdir",
    "/var/log/mysql/wpcaregrid-production.log": "g1_production_log",
    "/var/log/mysql/wpcaregrid-production-restore.log": "g1_restore_log",
}
RESTORE_CHECKPOINT_KEYS = {
    "backup_sha256",
    "target_schema_sha256",
    "target_ledger_sha256",
    "target_data_sha256",
    "restored_schema_sha256",
    "restored_ledger_sha256",
    "restored_data_sha256",
    "restore_mysql_uuid_sha256",
    "restore_receipt_sha256",
}
BACKUP_RE = re.compile(r"production-[0-9a-f]{40}-[0-9]{1,30}-[0-9a-f]{8}\.sql\.gz\Z")
SHA_RE = re.compile(r"[0-9a-f]{40}\Z")
SHA256_RE = re.compile(r"[0-9a-f]{64}\Z")
JOURNAL_RE = re.compile(r"db-release-([0-9a-f]{32})\.json\Z")
TOKEN_RE = re.compile(r"[A-Za-z0-9_.:+/@=-]{1,128}\Z")
COMM_RE = re.compile(r"[A-Za-z0-9_.-]{1,64}\Z")
TEXT_RE = re.compile(r"[A-Za-z0-9 .,()/_+:@%=-]{0,200}\Z")
MODE_RE = re.compile(r"0o[0-7]{4}\Z")
ALLOWED_COMMANDS = frozenset({
    "/usr/bin/systemctl",
    "/usr/bin/journalctl",
    "/usr/bin/my_print_defaults",
    "/usr/bin/php",
    "/usr/sbin/php-fpm8.4",
    "/usr/sbin/nginx",
    "/usr/sbin/mysqld",
})

MYSQL_KEYS = {
    "binlog-format", "binlog-expire-logs-seconds", "binlog-row-image", "expire-logs-days",
    "local-infile", "log-bin", "skip-log-bin", "disable-log-bin", "log-bin-basename", "log-bin-trust-function-creators",
    "read-only", "skip-grant-tables", "sql-mode", "super-read-only", "sync-binlog",
}


class Stop(Exception):
    """A fail-closed inventory classification with a fixed public code."""

    def __init__(self, code, detail=None):
        super().__init__(code)
        self.code = code
        self.detail = detail


class Unavailable(Stop):
    """The source needed for an observation is absent, not malformed."""


def utc_now():
    return datetime.now(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")


def combine(statuses):
    values = [value for value in statuses if value]
    for wanted in ("FAIL", "UNKNOWN", "UNAVAILABLE"):
        if wanted in values:
            return wanted
    return "PASS"


def exit_code(status):
    return {"PASS": 0, "FAIL": 3, "UNKNOWN": 4, "UNAVAILABLE": 5}.get(status, 4)


def rooted(root, path):
    if not root:
        return path
    return root.rstrip("/") + path


def _safe_token(value):
    if not isinstance(value, str) or not TOKEN_RE.fullmatch(value):
        return "REDACTED"
    return value


def _safe_text(value):
    if not isinstance(value, str) or not TEXT_RE.fullmatch(value):
        return "REDACTED"
    return value


def _safe_comm(value):
    if not isinstance(value, str) or not COMM_RE.fullmatch(value):
        return "REDACTED"
    return value


def _safe_system_path(value):
    if not isinstance(value, str) or not value.startswith("/") or ".." in value.split("/"):
        return "REDACTED"
    if not re.fullmatch(r"/(?:usr|lib|etc|run|var)/[A-Za-z0-9._/-]{1,220}", value):
        return "REDACTED"
    return value


def _safe_mode(value):
    return value if isinstance(value, str) and MODE_RE.fullmatch(value) else "REDACTED"


def _metadata(path, *, expected_uid=None, expected_mode=None):
    try:
        info = os.lstat(path)
    except OSError:
        raise Unavailable("file_unavailable") from None
    if stat.S_ISLNK(info.st_mode):
        raise Stop("file_symlink_rejected")
    if not stat.S_ISREG(info.st_mode):
        raise Stop("file_type_invalid")
    if expected_uid is not None and info.st_uid != expected_uid:
        raise Stop("file_owner_invalid")
    if expected_mode is not None and stat.S_IMODE(info.st_mode) != expected_mode:
        raise Stop("file_mode_invalid")
    return info


def _read_regular(path, *, limit=MAX_TEXT_FILE, expected_uid=None, expected_mode=None):
    """Read one fixed regular file without following a final symlink."""
    before = _metadata(path, expected_uid=expected_uid, expected_mode=expected_mode)
    flags = os.O_RDONLY | getattr(os, "O_CLOEXEC", 0) | getattr(os, "O_NOFOLLOW", 0)
    try:
        fd = os.open(path, flags)
    except OSError as exc:
        if exc.errno in (errno.ELOOP, errno.ENOENT):
            raise Stop("file_open_rejected") from None
        raise Unavailable("file_unavailable") from None
    try:
        opened = os.fstat(fd)
        if (opened.st_ino != before.st_ino or opened.st_dev != before.st_dev
                or not stat.S_ISREG(opened.st_mode) or opened.st_size > limit):
            raise Stop("file_changed_or_too_large")
        chunks = []
        remaining = limit + 1
        while remaining > 0:
            block = os.read(fd, min(1 << 20, remaining))
            if not block:
                break
            chunks.append(block)
            remaining -= len(block)
        data = b"".join(chunks)
        if len(data) > limit:
            raise Stop("file_too_large")
        return data, opened
    except OSError:
        raise Unavailable("file_unavailable") from None
    finally:
        os.close(fd)


def _sha256_regular(path, *, limit=MAX_ARTIFACT_BYTES):
    before = _metadata(path)
    flags = os.O_RDONLY | getattr(os, "O_CLOEXEC", 0) | getattr(os, "O_NOFOLLOW", 0)
    try:
        fd = os.open(path, flags)
    except OSError as exc:
        if exc.errno in (errno.ELOOP, errno.ENOENT):
            raise Stop("file_open_rejected") from None
        raise Unavailable("file_unavailable") from None
    try:
        opened = os.fstat(fd)
        if (opened.st_ino != before.st_ino or opened.st_dev != before.st_dev
                or not stat.S_ISREG(opened.st_mode) or opened.st_size > limit):
            raise Stop("file_changed_or_too_large")
        digest = hashlib.sha256()
        total = 0
        while True:
            block = os.read(fd, 1 << 20)
            if not block:
                break
            total += len(block)
            if total > limit:
                raise Stop("file_too_large")
            digest.update(block)
        return digest.hexdigest(), total, opened
    except OSError:
        raise Unavailable("file_unavailable") from None
    finally:
        os.close(fd)


def _parse_key_values(data):
    values = []
    for raw in data.decode("utf-8", "replace").splitlines()[:5000]:
        line = raw.strip()
        if not line or line.startswith("#") or line.startswith(";"):
            continue
        if line.startswith("--"):
            line = line[2:]
        if "=" in line:
            key, value = line.split("=", 1)
        else:
            key, value = line, "ON"
        key = key.strip().lstrip("-").replace("_", "-").lower()
        if key:
            values.append((key, value))
    return values


def _bool_value(value):
    if value is None:
        return None
    lowered = value.strip().lower()
    if lowered in {"1", "on", "true", "yes", "enabled"}:
        return True
    if lowered in {"0", "off", "false", "no", "disabled", ""}:
        return False
    return None


def _number_or_none(value):
    if isinstance(value, str) and re.fullmatch(r"[0-9]{1,20}", value.strip()):
        return int(value.strip())
    return None


def _default_run(argv, timeout=15):
    if not isinstance(argv, tuple) or not argv or argv[0] not in ALLOWED_COMMANDS:
        raise Stop("command_not_allowed")
    for argument in argv:
        if not isinstance(argument, str) or "\x00" in argument or "\n" in argument or len(argument) > 4096:
            raise Stop("command_argument_invalid")
    try:
        done = subprocess.run(
            list(argv),
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=timeout,
            check=False,
            close_fds=True,
            env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C", "LC_ALL": "C"},
        )
    except subprocess.TimeoutExpired:
        raise Unavailable("command_timeout") from None
    except OSError:
        raise Unavailable("command_unavailable") from None
    if len(done.stdout) > MAX_COMMAND_OUTPUT or len(done.stderr) > MAX_COMMAND_OUTPUT:
        raise Stop("command_output_too_large")
    return done.returncode, done.stdout, done.stderr


def _version_from(run, argv, pattern, *, stderr=False):
    rc, out, err = run(argv)
    if rc != 0:
        raise Unavailable("command_unavailable")
    text = (err if stderr else out).decode("utf-8", "replace")
    match = re.search(pattern, text)
    if not match:
        raise Stop("version_unrecognised")
    return match.group(1)


def _unit_snapshot(run, unit, *, expected_active=None):
    properties = (
        "LoadState", "ActiveState", "SubState", "UnitFileState", "MainPID",
        "FragmentPath", "DropInPaths", "Result", "ExecMainStartTimestamp",
    )
    argv = ("/usr/bin/systemctl", "show", unit, "--no-pager", "--property=" + ",".join(properties))
    rc, out, _err = run(argv)
    if rc != 0:
        raise Unavailable("systemctl_unavailable")
    parsed = {}
    for line in out.decode("utf-8", "replace").splitlines()[:100]:
        if "=" in line:
            key, value = line.split("=", 1)
            if key in properties:
                parsed[key] = value.strip()
    if not parsed:
        raise Stop("systemctl_output_invalid")
    load = _safe_token(parsed.get("LoadState", "UNKNOWN"))
    active = _safe_token(parsed.get("ActiveState", "UNKNOWN"))
    sub = _safe_token(parsed.get("SubState", "UNKNOWN"))
    enabled = _safe_token(parsed.get("UnitFileState", "UNKNOWN"))
    pid = parsed.get("MainPID", "0")
    pid_value = int(pid) if pid.isdigit() else None
    fragment = _safe_system_path(parsed.get("FragmentPath", "")) if parsed.get("FragmentPath") else None
    dropins = []
    for item in parsed.get("DropInPaths", "").split()[:8]:
        dropins.append(_safe_system_path(item))
    result = _safe_token(parsed.get("Result", "UNKNOWN"))
    started = _safe_text(parsed.get("ExecMainStartTimestamp", "")) if parsed.get("ExecMainStartTimestamp") else None
    if load == "not-found":
        status = "UNAVAILABLE"
    elif active == "failed" or result not in {"success", "UNKNOWN", ""}:
        status = "FAIL"
    elif active not in {"active", "inactive", "failed"}:
        status = "UNKNOWN"
    elif active == "active" and (pid_value is None or pid_value <= 0):
        status = "UNKNOWN"
    else:
        status = "PASS"
    if expected_active is not None and active != expected_active:
        status = "FAIL" if status == "PASS" else status
    return {
        "status": status,
        "unit": unit,
        "load_state": load,
        "active_state": active,
        "sub_state": sub,
        "unit_file_state": enabled,
        "main_pid": pid_value,
        "fragment_path": fragment,
        "drop_in_paths": dropins,
        "result": result,
        "exec_main_start_timestamp": started,
    }


def _section(fn):
    try:
        result = fn()
        if not isinstance(result, dict):
            raise Stop("section_result_invalid")
        if "status" not in result:
            result["status"] = combine(
                item.get("status") for item in result.values() if isinstance(item, dict))
        return result
    except Unavailable as exc:
        return {"status": "UNAVAILABLE", "reason": exc.code}
    except Stop as exc:
        return {"status": "FAIL", "reason": exc.code}
    except (OSError, ValueError, json.JSONDecodeError):
        return {"status": "UNKNOWN", "reason": "read_error"}
    except Exception:  # fail closed; never leak a traceback or a raw exception message
        return {"status": "UNKNOWN", "reason": "internal_error"}


def collect_runtime(run, root):
    os_release = None
    for candidate in ("/usr/lib/os-release", "/etc/os-release"):
        try:
            data, _info = _read_regular(rooted(root, candidate), limit=64 * 1024)
        except (Unavailable, Stop):
            continue
        values = {}
        for line in data.decode("utf-8", "replace").splitlines()[:100]:
            if "=" in line and not line.startswith("#"):
                key, value = line.split("=", 1)
                if key in {"ID", "VERSION_ID", "PRETTY_NAME", "VERSION_CODENAME"}:
                    values[key] = value.strip().strip("\"'")
        os_release = {
            "status": "PASS",
            "source": candidate,
            "id": _safe_token(values.get("ID", "UNKNOWN")),
            "version_id": _safe_token(values.get("VERSION_ID", "UNKNOWN")),
            "pretty_name": _safe_text(values.get("PRETTY_NAME", "UNKNOWN")),
            "version_codename": _safe_token(values.get("VERSION_CODENAME", "UNKNOWN")),
        }
        break
    if os_release is None:
        os_release = {"status": "UNAVAILABLE", "reason": "os_release_unavailable"}

    components = {"os": os_release, "kernel_release": {"status": "PASS", "release": _safe_text(platform.release())}}

    def version_component(name, argv, pattern, *, stderr=False):
        try:
            components[name] = {
                "status": "PASS",
                "version": _version_from(run, tuple(argv), pattern, stderr=stderr),
            }
        except Unavailable:
            components[name] = {"status": "UNAVAILABLE", "reason": "command_unavailable"}
        except Stop as exc:
            components[name] = {"status": "FAIL", "reason": exc.code}

    version_component("php_cli", ("/usr/bin/php", "-v"), r"PHP\s+(\d+\.\d+\.\d+)")
    version_component("php_fpm", ("/usr/sbin/php-fpm8.4", "-v"), r"PHP\s+(\d+\.\d+\.\d+)")
    version_component("nginx", ("/usr/sbin/nginx", "-v"), r"nginx/(\d+\.\d+\.\d+)", stderr=True)
    version_component("mysql", ("/usr/sbin/mysqld", "--version"), r"Ver\s+(\d+\.\d+\.\d+)")

    for key, unit in (("php_fpm_unit", FPM_UNIT), ("nginx_unit", NGINX_UNIT), ("mysql_unit", MYSQL_UNIT)):
        try:
            components[key] = _unit_snapshot(run, unit)
        except Unavailable:
            components[key] = {"status": "UNAVAILABLE", "reason": "systemctl_unavailable"}
        except Stop as exc:
            components[key] = {"status": "FAIL", "reason": exc.code}

    return {"status": combine(item.get("status") for item in components.values()), **components}


def collect_g1_systemd(run):
    units = {}
    for name, unit in G1_UNITS.items():
        try:
            units[name] = _unit_snapshot(run, unit)
        except Unavailable:
            units[name] = {"status": "UNAVAILABLE", "reason": "systemctl_unavailable", "unit": unit}
        except Stop as exc:
            units[name] = {"status": "FAIL", "reason": exc.code, "unit": unit}
    try:
        mysql = _unit_snapshot(run, MYSQL_UNIT)
    except Unavailable:
        mysql = {"status": "UNAVAILABLE", "reason": "systemctl_unavailable", "unit": MYSQL_UNIT}
    except Stop as exc:
        mysql = {"status": "FAIL", "reason": exc.code, "unit": MYSQL_UNIT}
    states = [units[name].get("active_state") for name in units]
    return {
        "status": combine([item.get("status") for item in units.values()] + [mysql.get("status")]),
        "units": units,
        "mysql_service": mysql,
        "both_g1_units_inactive": states == ["inactive", "inactive"],
        "g1_units_active": [name for name in units if units[name].get("active_state") == "active"],
    }


def collect_artifacts(root, root_uid, root_gid=None):
    expected_gid = root_uid if root_gid is None else root_gid
    artifacts = {}
    for name, path, mode in G1_ARTIFACTS:
        full = rooted(root, path)
        try:
            info = os.lstat(full)
        except FileNotFoundError:
            artifacts[name] = {"status": "UNAVAILABLE", "path": path, "reason": "artifact_missing"}
            continue
        except OSError:
            artifacts[name] = {"status": "UNKNOWN", "path": path, "reason": "stat_unavailable"}
            continue
        if stat.S_ISLNK(info.st_mode):
            artifacts[name] = {"status": "FAIL", "path": path, "reason": "symlink_rejected"}
            continue
        if not stat.S_ISREG(info.st_mode):
            artifacts[name] = {"status": "FAIL", "path": path, "reason": "not_regular"}
            continue
        owner_ok = info.st_uid == root_uid and info.st_gid == expected_gid
        mode_ok = stat.S_IMODE(info.st_mode) == mode
        item = {
            "path": path,
            "owner": f"{info.st_uid}:{info.st_gid}",
            "mode": f"0o{stat.S_IMODE(info.st_mode):04o}",
            "size": info.st_size,
            "owner_ok": owner_ok,
            "mode_ok": mode_ok,
        }
        try:
            digest, _size, opened = _sha256_regular(full)
            if (opened.st_dev, opened.st_ino) != (info.st_dev, info.st_ino):
                raise Stop("file_changed_during_inventory")
            item["sha256"] = digest
            item["status"] = "PASS" if owner_ok and mode_ok else "FAIL"
        except Unavailable:
            item["status"] = "UNKNOWN"
            item["reason"] = "hash_unavailable"
        except Stop as exc:
            item["status"] = "FAIL" if exc.code in {"file_type_invalid", "file_changed_or_too_large"} else "UNKNOWN"
            item["reason"] = exc.code
        artifacts[name] = item
    return {
        "status": combine(item.get("status") for item in artifacts.values()),
        "expected_owner": f"{root_uid}:{expected_gid}",
        "artifacts": artifacts,
    }


def _safe_denial(line):
    if 'apparmor="DENIED"' not in line:
        return None
    fields = {}
    for key, value in re.findall(r'([A-Za-z_]+)="((?:\\.|[^"])*)"', line):
        if key in {"profile", "operation", "denied_mask", "name", "comm", "requested_mask"}:
            fields[key] = value
    profile = fields.get("profile", "")
    if profile in G1_PROFILES or profile == VENDOR_PROFILE:
        safe_profile = profile
    else:
        safe_profile = "OTHER_PROFILE"
    name = fields.get("name", "")
    path_class = KNOWN_DENIAL_PATHS.get(name, "REDACTED")
    return {
        "profile": safe_profile,
        "operation": _safe_token(fields.get("operation", "UNKNOWN")),
        "denied_mask": _safe_token(fields.get("denied_mask", "UNKNOWN")),
        "requested_mask": _safe_token(fields.get("requested_mask", "UNKNOWN")),
        "path": path_class,
        "comm": _safe_comm(fields.get("comm", "UNKNOWN")),
    }


def collect_apparmor(run, root):
    profiles_path = rooted(root, "/sys/kernel/security/apparmor/profiles")
    try:
        data, _info = _read_regular(profiles_path, limit=MAX_TEXT_FILE)
    except Unavailable:
        return {"status": "UNAVAILABLE", "reason": "apparmor_unavailable"}
    loaded = {}
    for line in data.decode("utf-8", "replace").splitlines()[:2000]:
        match = re.fullmatch(r"(.+) \(([a-z]+)\)", line.strip())
        if match and match.group(1) not in loaded:
            loaded[match.group(1)] = match.group(2)
    profiles = {}
    for name in (*G1_PROFILES, VENDOR_PROFILE):
        mode = loaded.get(name)
        if mode is None:
            profiles[name] = {"status": "UNAVAILABLE", "mode": "NOT_LOADED"}
        elif mode == "enforce":
            profiles[name] = {"status": "PASS", "mode": "enforce"}
        else:
            profiles[name] = {"status": "FAIL", "mode": _safe_token(mode)}

    try:
        rc, out, _err = run(("/usr/bin/journalctl", "-k", "-n", "2000", "--no-pager", "-o", "cat"))
        if rc != 0:
            raise Unavailable("journal_unavailable")
    except Unavailable:
        denials = {"status": "UNAVAILABLE", "reason": "denial_source_unavailable", "entries": []}
    except Stop as exc:
        denials = {"status": "UNKNOWN", "reason": exc.code, "entries": []}
    else:
        counted = {}
        for line in out.decode("utf-8", "replace").splitlines()[:5000]:
            entry = _safe_denial(line)
            if entry is None:
                continue
            key = (entry["profile"], entry["operation"], entry["denied_mask"],
                   entry["requested_mask"], entry["path"], entry["comm"])
            counted[key] = counted.get(key, 0) + 1
        entries = [
            {"profile": key[0], "operation": key[1], "denied_mask": key[2],
             "requested_mask": key[3], "path": key[4], "comm": key[5], "count": count}
            for key, count in sorted(counted.items(), key=lambda item: (-item[1], item[0]))[:20]
        ]
        denials = {
            "status": "FAIL" if entries else "PASS",
            "entries": entries,
            "window": "last 2000 kernel records",
            "exhaustive": False,
            "sanitized_paths": True,
        }
    return {
        "status": combine([item.get("status") for item in profiles.values()] + [denials.get("status")]),
        "profiles": profiles,
        "denials": denials,
        "source": "/sys/kernel/security/apparmor/profiles + journalctl -k",
    }


def collect_mysql(run):
    try:
        rc, out, _err = run((
            "/usr/bin/my_print_defaults", "--defaults-file=/etc/mysql/my.cnf",
            "mysqld", "server", "mysqld-8.4",
        ))
    except Unavailable:
        return {"status": "UNAVAILABLE", "reason": "mysql_config_unavailable"}
    except Stop as exc:
        return {"status": "FAIL", "reason": exc.code}
    if rc != 0:
        return {"status": "UNAVAILABLE", "reason": "mysql_config_unavailable"}
    parsed = _parse_key_values(out)
    values = {}
    for key, value in parsed:
        if key in MYSQL_KEYS:
            values[key] = value

    def setting(key, kind="token"):
        raw = values.get(key)
        if raw is None:
            return {"configured": False, "value": "UNKNOWN"}
        if kind == "bool":
            value = _bool_value(raw)
            return {"configured": True, "value": "UNKNOWN" if value is None else ("ON" if value else "OFF")}
        if kind == "number":
            value = _number_or_none(raw)
            return {"configured": True, "value": "UNKNOWN" if value is None else value}
        if kind == "sqlmode":
            tokens = [_safe_token(item.strip()) for item in raw.split(",")[:20]]
            return {"configured": True, "value": ",".join(tokens) if all(t != "REDACTED" for t in tokens) else "REDACTED"}
        if kind == "basename":
            lowered = raw.strip().lower()
            if lowered in {"", "0", "off", "false", "no", "disabled"}:
                return {"configured": False, "value": "OFF"}
            return {"configured": True, "value": "CONFIGURED"}
        return {"configured": True, "value": _safe_token(raw)}

    binlog_format = setting("binlog-format")
    if binlog_format["value"] != "UNKNOWN":
        binlog_format["value"] = binlog_format["value"].upper()
    if binlog_format["value"] not in {"ROW", "STATEMENT", "MIXED", "UNKNOWN"}:
        binlog_format["value"] = "UNKNOWN"
    log_bin_options = [(key, value) for key, value in parsed
                       if key in {"log-bin", "skip-log-bin", "disable-log-bin"}]
    if not log_bin_options:
        log_bin = {"configured": False, "value": "UNKNOWN"}
    else:
        key, _value = log_bin_options[-1]
        log_bin = {"configured": True, "value": "OFF" if key in {"skip-log-bin", "disable-log-bin"} else "ON"}
    settings = {
        "log_bin": log_bin,
        "binlog_format": binlog_format,
        "binlog_row_image": setting("binlog-row-image"),
        "sync_binlog": setting("sync-binlog", "number"),
        "binlog_expire_logs_seconds": setting("binlog-expire-logs-seconds", "number"),
        "expire_logs_days": setting("expire-logs-days", "number"),
        "log_bin_trust_function_creators": setting("log-bin-trust-function-creators", "bool"),
        "read_only": setting("read-only", "bool"),
        "super_read_only": setting("super-read-only", "bool"),
        "local_infile": setting("local-infile", "bool"),
        "skip_grant_tables": setting("skip-grant-tables", "bool"),
        "sql_mode": setting("sql-mode", "sqlmode"),
    }
    unsafe = []
    if settings["log_bin"]["value"] == "OFF":
        unsafe.append("binary_logging_off")
    if settings["log_bin_trust_function_creators"]["value"] == "ON":
        unsafe.append("log_bin_trust_function_creators_on")
    if settings["local_infile"]["value"] == "ON":
        unsafe.append("local_infile_on")
    if settings["skip_grant_tables"]["value"] == "ON":
        unsafe.append("skip_grant_tables_on")
    if settings["binlog_format"]["value"] == "STATEMENT":
        unsafe.append("binlog_format_statement")
    config_status = (
        "FAIL" if unsafe else
        "UNKNOWN" if settings["log_bin"]["value"] == "UNKNOWN"
        or settings["log_bin_trust_function_creators"]["value"] == "UNKNOWN"
        or settings["binlog_format"]["value"] == "UNKNOWN"
        else "PASS"
    )
    privileges = {
        "status": "UNKNOWN",
        "classification": "UNKNOWN",
        "reason": "privileged_db_auth_not_authorized",
        "credentials_used": "NONE",
        "query_output": "REDACTED",
        "categories": {
            name: "UNKNOWN" for name in (
                "application_dml", "application_ddl", "application_file", "application_process",
                "application_super", "application_grant", "application_replication",
                "restore_dml", "restore_ddl",
            )
        },
    }
    return {
        "status": combine([config_status, privileges["status"]]),
        "config_status": config_status,
        "reason": "unsafe_mysql_configuration" if unsafe else None,
        "unsafe": unsafe,
        "settings": settings,
        "triggers": {
            "status": "UNKNOWN",
            "reason": "trigger_definitions_require_privileged_db_auth",
            "definitions_output": "REDACTED",
            "log_bin_trust_function_creators": settings["log_bin_trust_function_creators"],
        },
        "privileges": privileges,
        "source": "/etc/mysql/my.cnf via my_print_defaults",
    }


def _safe_symlink_release(root, root_uid):
    current = rooted(root, PRODUCTION_CURRENT)
    releases = rooted(root, PRODUCTION_RELEASES)
    try:
        info = os.lstat(current)
    except FileNotFoundError:
        raise Unavailable("active_release_missing")
    except OSError:
        raise Unavailable("active_release_unreadable")
    if not stat.S_ISLNK(info.st_mode):
        raise Stop("active_release_not_symlink")
    try:
        target_text = os.readlink(current)
    except OSError:
        raise Unavailable("active_release_unreadable")
    target = target_text if os.path.isabs(target_text) else os.path.join(os.path.dirname(current), target_text)
    target = os.path.normpath(target)
    if not target.startswith(releases + "/"):
        raise Stop("active_release_target_invalid")
    relative = target[len(releases) + 1:]
    if "/" in relative or not SHA_RE.fullmatch(relative):
        raise Stop("active_release_target_invalid")
    try:
        release_info = os.lstat(target)
        releases_info = os.lstat(releases)
    except OSError:
        raise Unavailable("active_release_missing")
    if stat.S_ISLNK(releases_info.st_mode) or not stat.S_ISDIR(releases_info.st_mode):
        raise Stop("releases_directory_invalid")
    if (not stat.S_ISDIR(release_info.st_mode) or stat.S_ISLNK(release_info.st_mode)
            or release_info.st_uid != root_uid or stat.S_IMODE(release_info.st_mode) != 0o755):
        raise Stop("active_release_metadata_invalid")
    return current, target, relative, release_info


def collect_release(root, root_uid):
    try:
        _current, target, sha, info = _safe_symlink_release(root, root_uid)
    except Unavailable:
        return {"status": "UNAVAILABLE", "reason": "active_release_missing"}
    except Stop as exc:
        return {"status": "FAIL", "reason": exc.code}
    manifest_path = os.path.join(target, "release-manifest.json")
    try:
        data, _m = _read_regular(manifest_path, limit=32 * 1024)
    except Unavailable:
        return {"status": "UNKNOWN", "reason": "release_manifest_unavailable", "application_sha": sha}
    except Stop as exc:
        return {"status": "FAIL", "reason": exc.code, "application_sha": sha}
    try:
        manifest = json.loads(data.decode("utf-8"))
    except (UnicodeError, ValueError):
        return {"status": "FAIL", "reason": "release_manifest_invalid", "application_sha": sha}
    if not isinstance(manifest, dict):
        return {"status": "FAIL", "reason": "release_manifest_invalid", "application_sha": sha}
    identity_ok = (
        type(manifest.get("schema")) is int
        and manifest.get("schema") == 1
        and manifest.get("profile") == "production"
        and manifest.get("application_sha") == sha
        and isinstance(manifest.get("base_application_sha"), str)
        and bool(SHA_RE.fullmatch(manifest["base_application_sha"]))
        and isinstance(manifest.get("artifact_sha256"), str)
        and bool(SHA256_RE.fullmatch(manifest["artifact_sha256"]))
        and isinstance(manifest.get("files_sha256"), str)
        and bool(SHA256_RE.fullmatch(manifest["files_sha256"]))
        and manifest.get("database_scope") in {"unchanged", "migrations"}
    )
    release_count = 0
    releases = rooted(root, PRODUCTION_RELEASES)
    try:
        for name in os.listdir(releases)[:10000]:
            if SHA_RE.fullmatch(name):
                release_count += 1
    except OSError:
        pass
    if not identity_ok:
        return {
            "status": "FAIL", "reason": "release_manifest_identity_invalid",
            "application_sha": sha, "release_count": release_count,
        }
    return {
        "status": "PASS",
        "application_sha": sha,
        "release_path": "releases/" + sha,
        "release_owner": f"{info.st_uid}:{info.st_gid}",
        "release_mode": f"0o{stat.S_IMODE(info.st_mode):04o}",
        "base_application_sha": manifest["base_application_sha"],
        "artifact_sha256": manifest["artifact_sha256"],
        "files_sha256": manifest["files_sha256"],
        "database_scope": manifest["database_scope"],
        "migration_count": len(manifest.get("database_migrations", []))
        if isinstance(manifest.get("database_migrations"), list) else 0,
        "php_minimum": _safe_token(str(manifest.get("php_minimum", "UNKNOWN"))),
        "helper_version": _safe_token(str(manifest.get("helper_version", "UNKNOWN"))),
        "helper_contract": _safe_token(str(manifest.get("helper_contract", "UNKNOWN"))),
        "release_count": release_count,
    }


def _read_journals(root, root_uid):
    state = rooted(root, PRODUCTION_STATE)
    try:
        info = os.lstat(state)
    except FileNotFoundError:
        raise Unavailable("state_directory_missing")
    except OSError:
        raise Unavailable("state_directory_unavailable")
    if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode):
        raise Stop("state_directory_invalid")
    metadata_ok = info.st_uid == root_uid and stat.S_IMODE(info.st_mode) == 0o700
    try:
        names = sorted(os.listdir(state))
    except OSError:
        raise Unavailable("state_directory_unavailable")
    journals = []
    for name in [item for item in names if JOURNAL_RE.fullmatch(item)][:MAX_JOURNALS]:
        path = os.path.join(state, name)
        item = {"filename": "journal", "transaction_id": JOURNAL_RE.fullmatch(name).group(1)}
        try:
            data, opened = _read_regular(path, limit=MAX_JOURNAL_BYTES, expected_uid=root_uid, expected_mode=0o600)
            value = json.loads(data.decode("utf-8"))
        except Unavailable:
            item.update({"status": "UNKNOWN", "reason": "journal_unavailable"})
            journals.append(item)
            continue
        except (Stop, UnicodeError, ValueError):
            item.update({"status": "FAIL", "reason": "journal_invalid"})
            journals.append(item)
            continue
        if not isinstance(value, dict):
            item.update({"status": "FAIL", "reason": "journal_invalid"})
            journals.append(item)
            continue
        txid = value.get("transaction_id")
        app_sha = value.get("application_sha")
        state_value = value.get("state")
        evidence = value.get("evidence")
        if (not isinstance(txid, str) or not re.fullmatch(r"[0-9a-f]{32}", txid)
                or not isinstance(app_sha, str) or not SHA_RE.fullmatch(app_sha)
                or not isinstance(state_value, str) or not re.fullmatch(r"[A-Z_]{1,40}", state_value)
                or not isinstance(evidence, dict)):
            item.update({"status": "FAIL", "reason": "journal_invalid"})
            journals.append(item)
            continue
        checkpoint = evidence.get("RESTORE_REHEARSAL_VERIFIED")
        checkpoint_ok = (
            isinstance(checkpoint, dict)
            and set(checkpoint) == RESTORE_CHECKPOINT_KEYS
            and all(isinstance(checkpoint.get(key), str) and SHA256_RE.fullmatch(checkpoint[key])
                    for key in RESTORE_CHECKPOINT_KEYS)
        )
        item.update({
            "status": "PASS",
            "transaction_id": txid,
            "application_sha": app_sha,
            "state": state_value,
            "backup_filename": value.get("backup_filename")
            if isinstance(value.get("backup_filename"), str) and BACKUP_RE.fullmatch(value.get("backup_filename"))
            else "REDACTED",
            "backup_sha256": value.get("backup_sha256")
            if isinstance(value.get("backup_sha256"), str) and SHA256_RE.fullmatch(value.get("backup_sha256"))
            else "REDACTED",
            "migration_count": len(value.get("migrations", [])) if isinstance(value.get("migrations"), list) else 0,
            "restore_checkpoint_present": checkpoint_ok,
            "updated_at": value.get("updated_at") if isinstance(value.get("updated_at"), int) else opened.st_mtime_ns,
        })
        journals.append(item)
    return {
        "status": "PASS" if metadata_ok and all(item.get("status") == "PASS" for item in journals) else
        ("FAIL" if not metadata_ok or any(item.get("status") == "FAIL" for item in journals) else "UNKNOWN"),
        "state_directory_owner": f"{info.st_uid}:{info.st_gid}",
        "state_directory_mode": f"0o{stat.S_IMODE(info.st_mode):04o}",
        "state_directory_metadata_ok": metadata_ok,
        "journal_count": len(journals),
        "journals": journals[:MAX_JOURNALS],
    }


def _backup_integrity(path, size, expected_info=None):
    if size > MAX_BACKUP_HASH_BYTES:
        return None, "UNKNOWN", "hash_budget_exceeded"
    flags = os.O_RDONLY | getattr(os, "O_CLOEXEC", 0) | getattr(os, "O_NOFOLLOW", 0)
    try:
        fd = os.open(path, flags)
    except OSError as exc:
        return None, "UNKNOWN", "backup_open_unavailable" if exc.errno != errno.ELOOP else "backup_symlink_rejected"
    try:
        opened = os.fstat(fd)
        if not stat.S_ISREG(opened.st_mode) or opened.st_size != size:
            return None, "UNKNOWN", "backup_changed_before_read"
        if expected_info is not None and (opened.st_dev, opened.st_ino) != (
                expected_info.st_dev, expected_info.st_ino):
            return None, "UNKNOWN", "backup_changed_before_read"
        digest = hashlib.sha256()
        total = 0
        while True:
            block = os.read(fd, 1 << 20)
            if not block:
                break
            total += len(block)
            if total > MAX_BACKUP_HASH_BYTES:
                return None, "UNKNOWN", "hash_budget_exceeded"
            digest.update(block)
        if total != size:
            return None, "UNKNOWN", "backup_changed_during_hash"
        sha256 = digest.hexdigest()
        os.lseek(fd, 0, os.SEEK_SET)
        with os.fdopen(fd, "rb", closefd=False) as stream:
            if stream.read(2) != b"\x1f\x8b":
                return sha256, "FAIL", "gzip_header_invalid"
            stream.seek(0)
            try:
                with gzip.GzipFile(fileobj=stream, mode="rb") as decompressed:
                    total = 0
                    while True:
                        block = decompressed.read(1 << 20)
                        if not block:
                            break
                        total += len(block)
                        if total > MAX_BACKUP_UNCOMPRESSED_BYTES:
                            return sha256, "UNKNOWN", "uncompressed_budget_exceeded"
            except (gzip.BadGzipFile, EOFError, OSError, zlib.error):
                return sha256, "FAIL", "gzip_integrity_failed"
        after = os.fstat(fd)
        if ((after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns)
                != (opened.st_dev, opened.st_ino, opened.st_size, opened.st_mtime_ns, opened.st_ctime_ns)):
            return sha256, "UNKNOWN", "backup_changed_during_read"
    finally:
        os.close(fd)
    return sha256, "PASS", None


def collect_backups(root, root_uid, journals_result=None):
    directory = rooted(root, PRODUCTION_BACKUPS)
    try:
        info = os.lstat(directory)
    except FileNotFoundError:
        return {"status": "UNAVAILABLE", "reason": "backup_directory_missing"}
    except OSError:
        return {"status": "UNAVAILABLE", "reason": "backup_directory_unavailable"}
    if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode):
        return {"status": "FAIL", "reason": "backup_directory_invalid"}
    directory_ok = info.st_uid == root_uid and stat.S_IMODE(info.st_mode) == 0o700
    try:
        names = os.listdir(directory)
    except OSError:
        return {"status": "UNAVAILABLE", "reason": "backup_directory_unavailable"}
    candidates = [name for name in names if BACKUP_RE.fullmatch(name)]
    journal_by_name = {}
    if isinstance(journals_result, dict):
        for journal in journals_result.get("journals", []):
            if isinstance(journal, dict) and journal.get("backup_filename", "REDACTED") != "REDACTED":
                journal_by_name[journal["backup_filename"]] = journal
    ordered = []
    for name in candidates:
        try:
            ordered.append((os.lstat(os.path.join(directory, name)).st_mtime_ns, name))
        except OSError:
            ordered.append((0, name))
    ordered.sort(key=lambda pair: (-pair[0], pair[1]))
    entries = []
    for _mtime, name in ordered[:MAX_BACKUPS]:
        path = os.path.join(directory, name)
        item = {"filename": name, "status": "UNKNOWN"}
        try:
            file_info = os.lstat(path)
        except OSError:
            item.update({"status": "UNKNOWN", "reason": "backup_stat_unavailable"})
            entries.append(item)
            continue
        if stat.S_ISLNK(file_info.st_mode) or not stat.S_ISREG(file_info.st_mode):
            item.update({"status": "FAIL", "reason": "backup_type_invalid"})
            entries.append(item)
            continue
        metadata_ok = (
            file_info.st_uid == root_uid and stat.S_IMODE(file_info.st_mode) == 0o600
            and file_info.st_size > 0
        )
        item.update({
            "size": file_info.st_size,
            "owner": f"{file_info.st_uid}:{file_info.st_gid}",
            "mode": f"0o{stat.S_IMODE(file_info.st_mode):04o}",
            "metadata_ok": metadata_ok,
        })
        digest, integrity, reason = _backup_integrity(path, file_info.st_size, file_info)
        item["sha256"] = digest if digest else "NOT_COMPUTED"
        item["integrity"] = integrity
        if reason:
            item["integrity_reason"] = reason
        journal = journal_by_name.get(name)
        if journal is None:
            item["evidence_match"] = "UNKNOWN"
        elif digest is None:
            item["evidence_match"] = "UNKNOWN"
        elif journal.get("backup_sha256") == digest:
            item["evidence_match"] = "PASS"
        else:
            item["evidence_match"] = "FAIL"
        item["restore_proof"] = "NOT_PROVEN"
        item["status"] = combine([
            "PASS" if metadata_ok else "FAIL",
            integrity,
            item["evidence_match"],
        ])
        entries.append(item)
    if not entries:
        status = "FAIL"
        reason = "no_backups_present" if directory_ok else "backup_directory_metadata_invalid"
    else:
        status = combine([item["status"] for item in entries] + [
            "PASS" if directory_ok else "FAIL"])
        reason = None
    return {
        "status": status,
        "reason": reason,
        "backup_directory": PRODUCTION_BACKUPS,
        "backup_directory_owner": f"{info.st_uid}:{info.st_gid}",
        "backup_directory_mode": f"0o{stat.S_IMODE(info.st_mode):04o}",
        "backup_directory_metadata_ok": directory_ok,
        "backup_count": len(candidates),
        "inspected_count": len(entries),
        "inspection_limit": MAX_BACKUPS,
        "backups": entries,
        "restore_proof": "NOT_PROVEN",
        "metadata_only_backup_does_not_prove_restore": True,
    }


def collect_restore(root, root_uid, journals_result):
    directory = rooted(root, "/var/lib/mysql-wpcaregrid-production-restore")
    try:
        info = os.lstat(directory)
        datadir_present = stat.S_ISDIR(info.st_mode) and not stat.S_ISLNK(info.st_mode)
        markers = []
        if datadir_present:
            for marker in ("mysql", "auto.cnf"):
                if os.path.lexists(os.path.join(directory, marker)):
                    markers.append(marker)
    except FileNotFoundError:
        datadir_present = False
        markers = []
    except OSError:
        return {"status": "UNKNOWN", "reason": "restore_datadir_unavailable"}
    if (not isinstance(journals_result, dict) or journals_result.get("status") == "UNAVAILABLE"
            or "journals" not in journals_result):
        return {
            "status": "UNAVAILABLE", "reason": "restore_evidence_unavailable",
            "datadir_present": datadir_present, "initialized_markers": markers,
            "readiness": "UNKNOWN", "restore_proof": "NOT_PROVEN",
            "restore_checkpoint_present": False,
        }
    journals = journals_result.get("journals", [])
    usable = [item for item in journals if isinstance(item, dict) and item.get("status") == "PASS"]
    with_checkpoint = [item for item in usable if item.get("restore_checkpoint_present")]
    latest = max(with_checkpoint or usable, key=lambda item: item.get("updated_at", 0), default=None)
    checkpoint = bool(with_checkpoint)
    if journals_result.get("status") == "FAIL":
        status = "FAIL"
        reason = "restore_evidence_invalid"
    elif checkpoint and journals_result.get("status") == "PASS":
        status = "PASS"
        reason = None
    elif checkpoint:
        status = "UNKNOWN"
        reason = "restore_evidence_incomplete"
    elif journals:
        status = "UNKNOWN"
        reason = "restore_evidence_not_recorded"
    else:
        status = "UNKNOWN"
        reason = "restore_evidence_absent"
    if checkpoint and status == "PASS" and datadir_present:
        readiness = "EVIDENCE_RECORDED"
    elif checkpoint and status == "PASS":
        readiness = "EVIDENCE_RECORDED_INSTANCE_UNAVAILABLE"
    elif checkpoint:
        readiness = "EVIDENCE_INCOMPLETE"
    elif datadir_present:
        readiness = "NO_EVIDENCE_INSTANCE_PRESENT"
    else:
        readiness = "NO_EVIDENCE"
    return {
        "status": status,
        "reason": reason,
        "datadir_present": datadir_present,
        "initialized_markers": markers,
        "readiness": readiness,
        "restore_checkpoint_present": checkpoint,
        "latest_journal": ({
            "state": latest.get("state"),
            "updated_at": latest.get("updated_at"),
            "restore_checkpoint_present": latest.get("restore_checkpoint_present", False),
        } if latest else None),
        "live_restore_validation": "NOT_PERFORMED",
        "restore_proof": "NOT_PROVEN",
        "metadata_only_backup_does_not_prove_restore": True,
    }


def _probe_lock(path, root_uid, proc_locks_path="/proc/locks"):
    try:
        info = os.lstat(path)
    except FileNotFoundError:
        return {"status": "PASS", "state": "ABSENT", "path": path}
    except OSError:
        return {"status": "UNKNOWN", "state": "UNREADABLE", "path": path}
    if stat.S_ISLNK(info.st_mode) or not stat.S_ISREG(info.st_mode):
        return {"status": "FAIL", "state": "INVALID", "path": path, "reason": "lock_type_invalid"}
    if info.st_uid != root_uid or stat.S_IMODE(info.st_mode) != 0o600:
        return {
            "status": "FAIL", "state": "INVALID", "path": path, "reason": "lock_metadata_invalid",
            "owner": f"{info.st_uid}:{info.st_gid}", "mode": f"0o{stat.S_IMODE(info.st_mode):04o}",
        }
    try:
        lock_data, _lock_info = _read_regular(proc_locks_path, limit=MAX_TEXT_FILE)
    except Unavailable:
        return {"status": "UNKNOWN", "state": "UNKNOWN", "path": path, "reason": "proc_locks_unavailable"}
    except Stop:
        return {"status": "UNKNOWN", "state": "UNKNOWN", "path": path, "reason": "proc_locks_invalid"}
    expected = (os.major(info.st_dev), os.minor(info.st_dev), info.st_ino)
    for line in lock_data.decode("ascii", "replace").splitlines()[:10000]:
        fields = line.split()
        try:
            flock_index = fields.index("FLOCK")
            identity = fields[flock_index + 4]
            major_hex, minor_hex, inode_decimal = identity.split(":", 2)
            observed = (int(major_hex, 16), int(minor_hex, 16), int(inode_decimal, 10))
        except (ValueError, IndexError):
            continue
        if observed == expected:
            return {"status": "FAIL", "state": "HELD", "path": path}
    return {"status": "PASS", "state": "FREE", "path": path}


def collect_locks(root, root_uid):
    locks = {}
    for name, path in LOCK_PATHS:
        locks[name] = _probe_lock(rooted(root, path), root_uid, rooted(root, "/proc/locks"))
    locks["backup_lock"] = {
        "status": "PASS",
        "state": "NOT_SEPARATELY_DEFINED",
        "note": "backup creation runs under the database release transaction lock",
    }
    return {
        "status": combine(item.get("status") for item in locks.values()),
        "locks": locks,
    }


def collect(root, run, root_uid, root_gid=None):
    sections = {}
    sections["runtime"] = _section(lambda: collect_runtime(run, root))
    sections["g1_systemd"] = _section(lambda: collect_g1_systemd(run))
    sections["g1_artifacts"] = _section(lambda: collect_artifacts(root, root_uid, root_gid))
    sections["apparmor"] = _section(lambda: collect_apparmor(run, root))
    sections["mysql"] = _section(lambda: collect_mysql(run))
    sections["release"] = _section(lambda: collect_release(root, root_uid))
    journals = _section(lambda: _read_journals(root, root_uid))
    sections["backups"] = _section(lambda: collect_backups(root, root_uid, journals))
    sections["restore"] = _section(lambda: collect_restore(root, root_uid, journals))
    sections["locks"] = _section(lambda: collect_locks(root, root_uid))
    return sections


def _empty_report(invocation_id, started, *, status, reason=None):
    report = {
        "schema": SCHEMA,
        "tool": TOOL,
        "version": VERSION,
        "generated_at": utc_now(),
        "audit": {
            "invocation_id": invocation_id,
            "started_at": started,
            "finished_at": utc_now(),
            "mode": "READ_ONLY",
            "arguments": "NONE",
            "persistent": "UNAVAILABLE",
            "status": "UNKNOWN",
        },
        "overall": status,
        "exit_code": exit_code(status),
        "sections": {},
    }
    if reason:
        report["reason"] = reason
    return report


def syslog_audit(record):
    """Write one fixed-field audit record to the local auth journal."""
    import syslog

    fields = {
        "tool": TOOL,
        "version": VERSION,
        "event": record["event"],
        "invocation_id": record["invocation_id"],
        "uid": record["uid"],
        "euid": record["euid"],
        "helper_sha256": record["helper_sha256"],
        "result": record.get("result", "START"),
    }
    message = " ".join(f"{key}={fields[key]}" for key in sorted(fields))
    try:
        syslog.openlog("wpcaregrid-g1-inventory", syslog.LOG_PID, syslog.LOG_AUTH)
        syslog.syslog(syslog.LOG_NOTICE, message)
    except (OSError, ValueError):
        raise Unavailable("audit_unavailable") from None


def _helper_sha256():
    try:
        with open(__file__, "rb") as stream:
            data = stream.read(MAX_TEXT_FILE + 1)
        if len(data) > MAX_TEXT_FILE:
            return "UNAVAILABLE"
        return hashlib.sha256(data).hexdigest()
    except OSError:
        return "UNAVAILABLE"


def execute(argv=None, *, run=None, euid=None, root="", root_uid=0, root_gid=None, audit=None, now=utc_now):
    argv = list(sys.argv[1:] if argv is None else argv)
    run = _default_run if run is None else run
    euid = os.geteuid() if euid is None else euid
    audit = syslog_audit if audit is None else audit
    invocation_id = secrets.token_hex(16)
    started = now()
    helper_sha = _helper_sha256()
    audit_record = {
        "event": "start", "invocation_id": invocation_id, "uid": os.getuid(),
        "euid": euid, "helper_sha256": helper_sha, "result": "START",
    }
    if argv:
        report = _empty_report(invocation_id, started, status="FAIL", reason="arguments_not_allowed")
        audit_status = "PASS"
        try:
            audit(dict(audit_record))
            audit_record.update({"event": "finish", "result": "FAIL"})
            audit(dict(audit_record))
        except Exception:
            audit_status = "UNKNOWN"
        report["audit"].update({
            "uid": os.getuid(), "euid": euid, "helper_sha256": helper_sha,
            "arguments": "REJECTED", "persistent": "SYSLOG" if audit_status == "PASS" else "UNAVAILABLE",
            "status": audit_status,
        })
        return report, exit_code("FAIL")
    if euid != 0:
        report = _empty_report(invocation_id, started, status="FAIL", reason="not_root")
        audit_status = "PASS"
        try:
            audit(dict(audit_record))
            audit_record.update({"event": "finish", "result": "FAIL"})
            audit(dict(audit_record))
        except Exception:
            audit_status = "UNKNOWN"
        report["audit"].update({
            "uid": os.getuid(), "euid": euid, "helper_sha256": helper_sha,
            "persistent": "SYSLOG" if audit_status == "PASS" else "UNAVAILABLE",
            "status": audit_status,
        })
        return report, exit_code("FAIL")
    try:
        audit(dict(audit_record))
    except Exception:
        report = _empty_report(invocation_id, started, status="UNKNOWN", reason="audit_unavailable")
        report["audit"].update({
            "uid": os.getuid(), "euid": euid, "helper_sha256": helper_sha,
            "persistent": "UNAVAILABLE",
        })
        return report, exit_code("UNKNOWN")
    sections = collect(root, run, root_uid, root_gid)
    overall = combine(section.get("status") for section in sections.values())
    audit_record.update({"event": "finish", "result": overall})
    try:
        audit(dict(audit_record))
        persistent = "SYSLOG"
        audit_status = "PASS"
    except Exception:
        persistent = "UNAVAILABLE"
        audit_status = "UNKNOWN"
        overall = combine([overall, audit_status])
    sections["audit"] = {
        "status": audit_status,
        "persistent": persistent,
        "invocation_id": invocation_id,
        "started_at": started,
        "finished_at": now(),
        "uid": os.getuid(),
        "euid": euid,
        "helper_sha256": helper_sha,
        "mode": "READ_ONLY",
        "arguments": "NONE",
    }
    overall = combine([overall, audit_status])
    report = {
        "schema": SCHEMA,
        "tool": TOOL,
        "version": VERSION,
        "generated_at": utc_now(),
        "audit": sections.pop("audit"),
        "overall": overall,
        "exit_code": exit_code(overall),
        "sections": sections,
        "constraints": [
            "No shell, no caller-supplied path and no arbitrary command is used.",
            "No database credential or credential file is read; privilege state is UNKNOWN.",
            "Backup metadata, hashes and gzip integrity do not prove a restore.",
            "Output is operator-only; do not publish it to CI or a public log.",
        ],
    }
    return report, exit_code(overall)


def main(argv=None):
    started = utc_now()
    invocation_id = secrets.token_hex(16)
    try:
        report, code = execute(argv)
    except BaseException:
        report = _empty_report(invocation_id, started, status="UNKNOWN", reason="internal_error")
        code = exit_code("UNKNOWN")
    try:
        sys.stdout.write(json.dumps(report, sort_keys=True, indent=2) + "\n")
    except BrokenPipeError:
        return code
    return code


if __name__ == "__main__":
    raise SystemExit(main())
