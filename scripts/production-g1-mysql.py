#!/usr/bin/env python3
"""Root-only, fixed-target G1 provisioning and verification for the two MySQL 8.4 instances.

Commands are intentionally phase-separated. `install` never initializes or
starts MySQL; `initialize` never starts a unit; `start` only starts its fixed
instance. No path, port, unit, profile, or service target is user-selectable.
"""

from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import os
import pwd
import grp
import stat
import subprocess
import sys
import time
from pathlib import Path
from typing import Callable

_CONTRACT_SPEC = importlib.util.spec_from_file_location(
    "production_g1_mysql_contract",
    Path(__file__).resolve().with_name("production_g1_mysql_contract.py"),
)
if _CONTRACT_SPEC is None or _CONTRACT_SPEC.loader is None:
    raise RuntimeError("contract_unavailable")
_CONTRACT = importlib.util.module_from_spec(_CONTRACT_SPEC)
sys.modules[_CONTRACT_SPEC.name] = _CONTRACT
sys.dont_write_bytecode = True
_CONTRACT_SPEC.loader.exec_module(_CONTRACT)
INSTANCES = _CONTRACT.INSTANCES
Refused = _CONTRACT.Refused
Instance = _CONTRACT.Instance
artifact_files = _CONTRACT.artifact_files
get_instance = _CONTRACT.get_instance
validate_effective_options = _CONTRACT.validate_effective_options
validate_instance_group = _CONTRACT.validate_instance_group
validate_resolved_options = _CONTRACT.validate_resolved_options
validate_keyring_registry_count = _CONTRACT.validate_keyring_registry_count
normalize_resolved_value = _CONTRACT.normalize_resolved_value
validate_port_probe = _CONTRACT.validate_port_probe
validate_unit_dropin = _CONTRACT.validate_unit_dropin
validate_vendor_execstart = _CONTRACT.validate_vendor_execstart
validate_effective_execstart = _CONTRACT.validate_effective_execstart
validate_process_arguments = _CONTRACT.validate_process_arguments
provisioning_path_specs = _CONTRACT.provisioning_path_specs
validate_provisioning_resource_empty = _CONTRACT.validate_provisioning_resource_empty
validate_unit_inactive = _CONTRACT.validate_unit_inactive
validate_systemd_identity = _CONTRACT.validate_systemd_identity


ROOT = Path("/")
HELPER = Path("/usr/local/libexec/wpcaregrid-g1-mysql.py")
CONTRACT = Path("/usr/local/libexec/production_g1_mysql_contract.py")
INSTALLED_MANIFEST = Path("/usr/local/libexec/production_g1_mysql_manifest.json")
LIBEXEC_DIR = Path("/usr/local/libexec")
APPARMOR_DIR = Path("/etc/apparmor.d")
MYSQLD = Path("/usr/sbin/mysqld")
BASE_CONFIG = Path("/etc/mysql/my.cnf")
MY_PRINT_DEFAULTS = Path("/usr/bin/my_print_defaults")
APPARMOR_PARSER = Path("/usr/sbin/apparmor_parser")
SYSTEMCTL = Path("/usr/bin/systemctl")
AA_EXEC = Path("/usr/bin/aa-exec")
MANAGED_VERSION = "wpcaregrid-production-mysql-g1-v1"
LEGACY_MYSQL_SERVER_UUID_SHA256 = "bc35682d80f0894c2c2c2879552fb8fe13dc2a530868d79d817a6280e31bde1c"
ALLOWED_CREATE_DIRS = {
    Path("/usr/local/libexec"),
    Path("/etc/systemd/system/mysql@wpcaregrid-production.service.d"),
    Path("/etc/systemd/system/mysql@wpcaregrid-production-restore.service.d"),
}


def refuse(code: str) -> None:
    raise Refused(code)


def run(args: list[str], *, timeout: int = 20, input_text: str | None = None) -> str:
    """Run a fixed local tool. Output remains in memory and is never echoed."""
    try:
        result = subprocess.run(
            args,
            input=input_text,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            encoding="utf-8",
            errors="strict",
            timeout=timeout,
            check=False,
            close_fds=True,
            env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "HOME": "/nonexistent",
                 "LANG": "C", "LC_ALL": "C"},
        )
    except (OSError, subprocess.TimeoutExpired, UnicodeError):
        refuse("command_unavailable_or_timeout")
    if result.returncode != 0:
        refuse("command_failed")
    return result.stdout


def require_root() -> None:
    if os.geteuid() != 0:
        refuse("root_required")


def check_file(path: Path, *, owner: str, group: str, mode: int, expected: bytes | None = None) -> bytes:
    try:
        st = path.lstat()
        if not stat.S_ISREG(st.st_mode) or stat.S_ISLNK(st.st_mode):
            refuse("managed_file_type_invalid")
        if pwd.getpwuid(st.st_uid).pw_name != owner or grp.getgrgid(st.st_gid).gr_name != group:
            refuse("managed_file_owner_invalid")
        if stat.S_IMODE(st.st_mode) != mode:
            refuse("managed_file_mode_invalid")
        data = path.read_bytes()
    except Refused:
        raise
    except (OSError, KeyError):
        refuse("managed_file_unavailable")
    if expected is not None and data != expected:
        refuse("managed_file_content_mismatch")
    return data


def check_directory(path: Path, *, owner: str, group: str, mode: int) -> None:
    try:
        st = path.lstat()
        if not stat.S_ISDIR(st.st_mode) or stat.S_ISLNK(st.st_mode):
            refuse("managed_directory_type_invalid")
        if pwd.getpwuid(st.st_uid).pw_name != owner or grp.getgrgid(st.st_gid).gr_name != group:
            refuse("managed_directory_owner_invalid")
        if stat.S_IMODE(st.st_mode) != mode:
            refuse("managed_directory_mode_invalid")
    except Refused:
        raise
    except (OSError, KeyError):
        refuse("managed_directory_unavailable")


def verify_systemd_contract(instance: Instance) -> None:
    dropin = Path(f"/etc/systemd/system/{instance.unit}.d/90-wpcaregrid-g1.conf")
    text = check_file(dropin, owner="root", group="root", mode=0o644).decode("utf-8", "strict")
    validate_unit_dropin(instance, text)
    template_dropin = Path("/etc/systemd/system/mysql@.service.d")
    if template_dropin.exists() or template_dropin.is_symlink():
        check_directory(template_dropin, owner="root", group="root", mode=0o755)
        if any(template_dropin.iterdir()):
            refuse("unexpected_template_dropin")
    instance_dropin_dir = dropin.parent
    check_directory(instance_dropin_dir, owner="root", group="root", mode=0o755)
    try:
        if {entry.name for entry in instance_dropin_dir.iterdir()} != {dropin.name}:
            refuse("unexpected_instance_dropin")
    except OSError:
        refuse("instance_dropin_unreadable")
    service_user = run([str(SYSTEMCTL), "show", instance.unit, "--property=User", "--value"])
    service_group = run([str(SYSTEMCTL), "show", instance.unit, "--property=Group", "--value"])
    try:
        validate_systemd_identity(service_user, service_group)
    except Refused as exc:
        refuse(str(exc))


def verify_apparmor(instance: Instance) -> None:
    profile_path = APPARMOR_DIR / instance.apparmor_profile
    check_file(profile_path, owner="root", group="root", mode=0o644)
    loaded = Path("/sys/kernel/security/apparmor/profiles")
    try:
        lines = loaded.read_text(encoding="utf-8").splitlines()
    except OSError:
        refuse("apparmor_status_unavailable")
    prefix = instance.apparmor_profile + " ("
    matches = [line for line in lines if line.startswith(prefix)]
    if len(matches) != 1 or "(enforce)" not in matches[0]:
        refuse("apparmor_not_enforced")


def _apparmor_state(instance: Instance) -> str:
    try:
        lines = Path("/sys/kernel/security/apparmor/profiles").read_text(encoding="utf-8").splitlines()
    except OSError:
        refuse("apparmor_status_unavailable")
    matches = [line for line in lines if line.startswith(instance.apparmor_profile + " (")]
    if not matches:
        return "absent"
    if len(matches) != 1:
        refuse("apparmor_profile_ambiguous")
    return "enforce" if "(enforce)" in matches[0] else "other"


def _assert_parent_chain(path: Path) -> None:
    current = ROOT
    for component in path.parts[1:]:
        current = current / component
        try:
            st = current.lstat()
        except FileNotFoundError:
            continue
        except OSError:
            refuse("target_parent_unavailable")
        if stat.S_ISLNK(st.st_mode) or not stat.S_ISDIR(st.st_mode):
            refuse("target_parent_invalid")


def _apparmor_state(instance: Instance) -> str:
    try:
        lines = Path("/sys/kernel/security/apparmor/profiles").read_text(encoding="utf-8").splitlines()
    except OSError:
        refuse("apparmor_status_unavailable")
    matches = [line for line in lines if line.startswith(instance.apparmor_profile + " (")]
    if not matches:
        return "absent"
    if len(matches) != 1:
        refuse("apparmor_profile_ambiguous")
    return "enforce" if "(enforce)" in matches[0] else "other"


def _assert_parent_chain(path: Path) -> None:
    current = ROOT
    for component in path.parts[1:]:
        current = current / component
        try:
            st = current.lstat()
        except FileNotFoundError:
            continue
        except OSError:
            refuse("target_parent_unavailable")
        if stat.S_ISLNK(st.st_mode) or not stat.S_ISDIR(st.st_mode):
            refuse("target_parent_invalid")


def _server_version() -> str:
    output = run([str(MYSQLD), "--no-defaults", "--version"])
    # Do not expose the command output; accept only a clearly identifiable 8.4 build.
    import re

    match = re.search(r"\bVer\s+(\d+\.\d+\.\d+)\b", output)
    if not match or not match.group(1).startswith("8.4."):
        refuse("mysql_version_mismatch")
    return match.group(1)


def _defaults(instance: Instance) -> str:
    version = _server_version().split(".")[:2]
    groups = ["mysqld", "server", "mysqld-" + ".".join(version)]
    return run([
        str(MY_PRINT_DEFAULTS),
        f"--defaults-file={BASE_CONFIG}",
        f"--defaults-group-suffix=@{instance.name}",
        *groups,
    ])


def verify_effective_config(instance: Instance) -> None:
    if os.path.lexists(instance.legacy_config_path):
        refuse("legacy_config_name_present")
    config = Path(instance.config_path)
    text = check_file(config, owner="root", group="root", mode=0o644).decode("utf-8", "strict")
    validate_instance_group(instance, text)
    defaults = _defaults(instance)
    validate_effective_options(instance, defaults)
    # MySQL itself must parse and validate the same suffixed configuration.
    run([str(MYSQLD), f"--defaults-file={BASE_CONFIG}",
         f"--defaults-group-suffix=@{instance.name}", "--validate-config"])
    configured = _effective_help(instance)
    try:
        validate_resolved_options(instance, configured)
    except Refused as exc:
        refuse(str(exc))


def _effective_help(instance: Instance) -> dict[str, str]:
    output = run([
        str(MYSQLD),
        f"--defaults-file={BASE_CONFIG}",
        f"--defaults-group-suffix=@{instance.name}",
        "--verbose",
        "--help",
    ], timeout=30)
    wanted = set(instance.expected_options())
    found: dict[str, str] = {}
    for line in output.splitlines():
        parts = line.strip().split(None, 1)
        if len(parts) != 2:
            continue
        key = parts[0].replace("_", "-").lower()
        if key in wanted:
            found[key] = parts[1].strip()
    if set(found) != wanted:
        refuse("mysqld_resolved_option_missing")
    return found


def verify_paths(instance: Instance, *, for_init: bool = False) -> None:
    mysql_uid = pwd.getpwnam("mysql").pw_uid
    mysql_gid = grp.getgrnam("mysql").gr_gid
    check_directory(Path(instance.datadir), owner="mysql", group="mysql", mode=0o750)
    check_directory(Path(instance.tmpdir), owner="mysql", group="mysql", mode=0o700)
    check_directory(Path("/run/mysqld"), owner="mysql", group="mysql", mode=0o755)
    log_path = Path(instance.log_file)
    check_file(log_path, owner="mysql", group="mysql", mode=0o600)
    # Verify numeric ownership as well as names (the variables document the fixed contract).
    for target in (Path(instance.datadir), Path(instance.tmpdir)):
        st = target.lstat()
        if st.st_uid != mysql_uid or st.st_gid != mysql_gid:
            refuse("mysql_path_identity_mismatch")
    if for_init:
        try:
            if any(Path(instance.datadir).iterdir()):
                refuse("datadir_not_empty")
        except OSError:
            refuse("datadir_unreadable")


def verify_preflight(instance: Instance, *, for_init: bool = False, allow_running: bool = False) -> None:
    require_root()
    _verify_installed_bundle(instance)
    check_file(BASE_CONFIG, owner="root", group="root", mode=0o644)
    _check_vendor_boundary()
    if (not MYSQLD.is_file() or not MY_PRINT_DEFAULTS.is_file()
            or not BASE_CONFIG.is_file() or BASE_CONFIG.is_symlink()):
        refuse("mysql_84_tools_missing")
    verify_systemd_contract(instance)
    verify_apparmor(instance)
    verify_effective_config(instance)
    verify_paths(instance, for_init=for_init)
    if not allow_running:
        for runtime_path in (Path(instance.socket), Path(instance.pid_file)):
            if runtime_path.exists() or runtime_path.is_symlink():
                refuse("instance_runtime_marker_present")


def _systemd_inactive(instance: Instance) -> None:
    result = subprocess.run(
        [str(SYSTEMCTL), "is-active", instance.unit], stdout=subprocess.PIPE,
        stderr=subprocess.PIPE, text=True, encoding="ascii", errors="ignore", timeout=10,
        check=False,
    )
    try:
        validate_unit_inactive(result.returncode, result.stdout)
    except Refused as exc:
        refuse(str(exc))


def _port_free(instance: Instance) -> None:
    import socket

    sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    try:
        sock.bind(("127.0.0.1", instance.port))
    except OSError:
        validate_port_probe(False)
    finally:
        sock.close()
    validate_port_probe(True)


def initialize(instance: Instance) -> None:
    verify_preflight(instance, for_init=True)
    _systemd_inactive(instance)
    _port_free(instance)
    run([
        "/usr/sbin/runuser", "--user=mysql", "--",
        str(AA_EXEC), "-p", instance.apparmor_profile, "--",
        str(MYSQLD), f"--defaults-file={BASE_CONFIG}",
        f"--defaults-group-suffix=@{instance.name}",
        "--initialize",
    ], timeout=180)
    data = Path(instance.datadir)
    if not (data / "mysql").is_dir() or not (data / "auto.cnf").is_file():
        refuse("initialized_datadir_incomplete")
    check_directory(data, owner="mysql", group="mysql", mode=0o750)
    _verify_server_uuid_identity(instance)
    print(f"INITIALIZE=PASS;INSTANCE={instance.name};UNIT_NOT_STARTED=YES")


def _server_uuid_sha256(instance: Instance) -> str:
    path = Path(instance.datadir, "auto.cnf")
    fd = None
    try:
        fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
        st = os.fstat(fd)
        if not stat.S_ISREG(st.st_mode) or st.st_uid != pwd.getpwnam("mysql").pw_uid:
            refuse("server_uuid_metadata_identity_invalid")
        with os.fdopen(fd, "rb", closefd=True) as stream:
            fd = None
            data = stream.read(4097)
    except OSError:
        refuse("server_uuid_unavailable")
    finally:
        if fd is not None:
            os.close(fd)
    if len(data) > 4096:
        refuse("server_uuid_metadata_invalid")
    import re

    match = re.search(rb"(?m)^server-uuid=([0-9a-fA-F-]{36})\s*$", data)
    if not match:
        refuse("server_uuid_metadata_invalid")
    return hashlib.sha256(match.group(1).lower()).hexdigest()


def _verify_server_uuid_identity(instance: Instance) -> None:
    digest = _server_uuid_sha256(instance)
    if digest == LEGACY_MYSQL_SERVER_UUID_SHA256:
        refuse("mysql_instance_identity_matches_staging")
    for peer in INSTANCES.values():
        if peer.name == instance.name:
            continue
        peer_auto = Path(peer.datadir, "auto.cnf")
        try:
            peer_auto.lstat()
        except FileNotFoundError:
            continue
        except OSError:
            refuse("peer_server_uuid_unavailable")
        else:
            if _server_uuid_sha256(peer) == digest:
                refuse("mysql_instance_identity_collision")


def verify_running(instance: Instance) -> None:
    require_root()
    verify_preflight(instance, allow_running=True)
    active = run([str(SYSTEMCTL), "is-active", instance.unit]).strip()
    if active != "active":
        refuse("instance_not_active")
    main_pid = run([str(SYSTEMCTL), "show", instance.unit, "--property=MainPID", "--value"]).strip()
    if not main_pid.isdigit() or main_pid == "0":
        refuse("instance_pid_invalid")
    try:
        cmdline = Path(f"/proc/{main_pid}/cmdline").read_bytes().replace(b"\0", b" ").decode("ascii")
        apparmor = Path(f"/proc/{main_pid}/attr/current").read_text(encoding="ascii").strip()
    except (OSError, UnicodeError):
        refuse("instance_process_identity_unavailable")
    try:
        validate_process_arguments(instance, cmdline)
    except Refused as exc:
        refuse(str(exc))
    if not apparmor.startswith(instance.apparmor_profile + " (enforce)"):
        refuse("instance_process_apparmor_mismatch")
    _verify_server_uuid_identity(instance)
    import socket

    probe = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    probe.settimeout(2)
    try:
        probe.connect(("127.0.0.1", instance.port))
    except OSError:
        refuse("instance_listener_unavailable")
    finally:
        probe.close()
    print(f"RUNTIME=PASS;INSTANCE={instance.name};APPARMOR=ENFORCE;KEYRING_CONFIG=ABSENT;KEYRING_RUNTIME=PENDING_DBA_READONLY_CHECK")


def start(instance: Instance) -> None:
    require_root()
    verify_preflight(instance)
    data = Path(instance.datadir)
    if not (data / "mysql").is_dir() or not (data / "auto.cnf").is_file():
        refuse("instance_not_initialized")
    _systemd_inactive(instance)
    _port_free(instance)
    run([str(SYSTEMCTL), "start", instance.unit], timeout=120)
    # Bounded observation only. A failed start is preserved for inspection; no retry/stop.
    for _ in range(30):
        result = subprocess.run(
            [str(SYSTEMCTL), "is-active", instance.unit], stdout=subprocess.PIPE,
            stderr=subprocess.PIPE, text=True, encoding="ascii", errors="ignore", timeout=10,
            check=False,
        )
        if result.returncode == 0 and result.stdout.strip() == "active":
            try:
                verify_running(instance)
            except Exception:
                stopped = subprocess.run(
                    [str(SYSTEMCTL), "stop", instance.unit], stdout=subprocess.PIPE,
                    stderr=subprocess.PIPE, timeout=30, check=False,
                )
                if stopped.returncode != 0:
                    refuse("postcheck_failed_stop_unverified")
                refuse("postcheck_failed_instance_stopped_preserve_data")
            print(f"START=PASS;INSTANCE={instance.name}")
            return
        if result.stdout.strip() in {"failed", "inactive"}:
            refuse("instance_start_failed_preserve_forensics")
        time.sleep(1)
    refuse("instance_start_timeout_preserve_forensics")


def _source_bytes(name: str) -> bytes:
    if Path(__file__).is_symlink():
        refuse("source_artifact_symlink")
    path = Path(__file__).parent / name
    try:
        if path.is_symlink() or not path.is_file():
            refuse("source_artifact_unavailable")
        st = path.lstat()
        if st.st_uid != 0 or st.st_gid != 0 or stat.S_IMODE(st.st_mode) != 0o600:
            refuse("source_artifact_permissions_invalid")
        return path.read_bytes()
    except OSError:
        refuse("source_artifact_unavailable")


def _manifest_bytes(script: bytes, contract: bytes, generated: dict[str, bytes]) -> bytes:
    payload = {
        "schema": 1,
        "tool": MANAGED_VERSION,
        "sources": {
            "production-g1-mysql.py": hashlib.sha256(script).hexdigest(),
            "production_g1_mysql_contract.py": hashlib.sha256(contract).hexdigest(),
        },
        "installed_artifacts": {
            str(HELPER): hashlib.sha256(script).hexdigest(),
            str(CONTRACT): hashlib.sha256(contract).hexdigest(),
            **{path: hashlib.sha256(content).hexdigest() for path, content in generated.items()},
        },
    }
    return (json.dumps(payload, sort_keys=True, separators=(",", ":")) + "\n").encode("ascii")


def _verify_installed_bundle(instance: Instance | None = None) -> None:
    script = check_file(HELPER, owner="root", group="root", mode=0o755)
    contract = check_file(CONTRACT, owner="root", group="root", mode=0o644)
    manifest_raw = check_file(INSTALLED_MANIFEST, owner="root", group="root", mode=0o644)
    try:
        manifest = json.loads(manifest_raw.decode("ascii", "strict"))
    except (UnicodeError, ValueError):
        refuse("artifact_manifest_invalid")
    generated = artifact_files()
    expected_sources = {
        "production-g1-mysql.py": hashlib.sha256(script).hexdigest(),
        "production_g1_mysql_contract.py": hashlib.sha256(contract).hexdigest(),
    }
    expected_installed = {
        str(HELPER): hashlib.sha256(script).hexdigest(),
        str(CONTRACT): hashlib.sha256(contract).hexdigest(),
        **{path: hashlib.sha256(content).hexdigest() for path, content in generated.items()},
    }
    if (manifest.get("schema") != 1 or manifest.get("tool") != MANAGED_VERSION
            or manifest.get("sources") != expected_sources
            or manifest.get("installed_artifacts") != expected_installed):
        refuse("artifact_manifest_mismatch")
    for target, content in generated.items():
        if instance is not None:
            owned_targets = {
                instance.config_path,
                f"/etc/systemd/system/{instance.unit}.d/90-wpcaregrid-g1.conf",
                f"/etc/apparmor.d/{instance.apparmor_profile}",
            }
            if target not in owned_targets:
                continue
        check_file(Path(target), owner="root", group="root", mode=0o644, expected=content)


def _atomic_noreplace(path: Path, content: bytes, *, mode: int, owner: str, group: str) -> bool:
    """Create exact file atomically. Existing files are accepted only if identical."""
    if path.exists() or path.is_symlink():
        check_file(path, owner=owner, group=group, mode=mode, expected=content)
        return False
    import tempfile

    fd = None
    temp_name = None
    try:
        fd, temp_name = tempfile.mkstemp(prefix=".wpcg-g1-", dir=str(path.parent))
        os.fchown(fd, pwd.getpwnam(owner).pw_uid, grp.getgrnam(group).gr_gid)
        os.fchmod(fd, mode)
        with os.fdopen(fd, "wb", closefd=True) as stream:
            fd = None
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        # link() is atomic and refuses an existing destination (unlike replace/rename).
        os.link(temp_name, path, follow_symlinks=False)
        os.unlink(temp_name)
        dir_fd = os.open(path.parent, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
        try:
            os.fsync(dir_fd)
        finally:
            os.close(dir_fd)
        check_file(path, owner=owner, group=group, mode=mode, expected=content)
        return True
    except FileExistsError:
        check_file(path, owner=owner, group=group, mode=mode, expected=content)
        return False
    except Exception:
        if fd is not None:
            os.close(fd)
        if temp_name and os.path.exists(temp_name):
            os.unlink(temp_name)
        raise


def _ensure_directory(path: Path, *, owner: str, group: str, mode: int, created: list[Path]) -> None:
    if path.exists() or path.is_symlink():
        check_directory(path, owner=owner, group=group, mode=mode)
        return
    if path not in ALLOWED_CREATE_DIRS:
        refuse("directory_creation_not_allowlisted")
    _ensure_directory(path.parent, owner="root", group="root", mode=0o755, created=created)
    os.mkdir(path, mode)
    created.append(path)
    os.chown(path, pwd.getpwnam(owner).pw_uid, grp.getgrnam(group).gr_gid)
    os.chmod(path, mode)
    check_directory(path, owner=owner, group=group, mode=mode)


def _check_vendor_boundary() -> None:
    # A known factory template is required; its ExecStartPre is reset only in the
    # two exact new instance drop-ins. The base unit/vendor files are never edited.
    vendor = Path("/usr/lib/systemd/system/mysql@.service")
    text = check_file(vendor, owner="root", group="root", mode=0o644).decode("utf-8", "strict")
    if "ExecStartPre=+/usr/share/mysql-8.4/mysql-systemd-start pre @%I" not in text:
        refuse("vendor_template_unrecognized")
    try:
        validate_vendor_execstart(text)
    except Refused as exc:
        refuse(str(exc))
    if "ExecStartPost=" in text:
        refuse("vendor_post_hook_unrecognized")


def install() -> None:
    require_root()
    _check_vendor_boundary()
    if not Path(str(APPARMOR_PARSER)).is_file() or not Path(str(SYSTEMCTL)).is_file():
        refuse("provisioning_tools_missing")
    for instance in INSTANCES.values():
        _systemd_inactive(instance)
    source_dir = Path(__file__).parent
    check_directory(source_dir, owner="root", group="root", mode=0o700)
    script_bytes = _source_bytes(Path(__file__).name)
    contract_bytes = _source_bytes("production_g1_mysql_contract.py")
    source_manifest = _source_bytes("production-g1-mysql-manifest.json")
    generated = artifact_files()
    expected_source_manifest = _manifest_bytes(script_bytes, contract_bytes, generated)
    if source_manifest != expected_source_manifest:
        refuse("source_manifest_mismatch")
    manifest = {HELPER: script_bytes, CONTRACT: contract_bytes, INSTALLED_MANIFEST: source_manifest}
    manifest.update({Path(path): content for path, content in generated.items()})
    # Refuse unknown instance or template-level drop-ins; they can override
    # isolation directives even when our own no-overwrite file is correct.
    allowed_dropins = {
        Path(f"/etc/systemd/system/{item.unit}.d"): "90-wpcaregrid-g1.conf"
        for item in INSTANCES.values()
    }
    template_dropin = Path("/etc/systemd/system/mysql@.service.d")
    if template_dropin.exists() or template_dropin.is_symlink():
        _assert_parent_chain(template_dropin.parent)
        check_directory(template_dropin, owner="root", group="root", mode=0o755)
        if any(template_dropin.iterdir()):
            refuse("unexpected_template_dropin")
    for directory, allowed_name in allowed_dropins.items():
        if directory.exists() or directory.is_symlink():
            check_directory(directory, owner="root", group="root", mode=0o755)
            if any(item.name != allowed_name for item in directory.iterdir()):
                refuse("unexpected_instance_dropin")
    # A leftover #325/#327 `90-` file must be rolled back first; it would be a second,
    # differently ordered definition of the same instance group.
    for instance in INSTANCES.values():
        if os.path.lexists(instance.legacy_config_path):
            refuse("legacy_config_name_present")
    # Preflight all destinations before publishing any artifact.
    for path, content in manifest.items():
        _assert_parent_chain(path.parent)
        if path.exists() or path.is_symlink():
            check_file(path, owner="root", group="root", mode=0o755 if path == HELPER else 0o644, expected=content)
    for instance in INSTANCES.values():
        state = _apparmor_state(instance)
        profile_path = APPARMOR_DIR / instance.apparmor_profile
        if state != "absent" and not profile_path.exists():
            refuse("apparmor_loaded_profile_unmanaged")
        if state == "other":
            refuse("apparmor_existing_profile_not_enforced")

    created_dirs: list[Path] = []
    created_files: list[Path] = []
    loaded_profiles: list[Path] = []
    try:
        for directory in sorted({p.parent for p in manifest}, key=lambda p: len(p.parts)):
            # Only dedicated drop-in directories and the fixed libexec destination may be created.
            if directory == LIBEXEC_DIR or str(directory).startswith("/etc/systemd/system/mysql@wpcaregrid-production"):
                _ensure_directory(directory, owner="root", group="root", mode=0o755, created=created_dirs)
            else:
                check_directory(directory, owner="root", group="root", mode=0o755)
        for path, content in manifest.items():
            mode = 0o755 if path == HELPER else 0o644
            if _atomic_noreplace(path, content, mode=mode, owner="root", group="root"):
                created_files.append(path)
        # Syntax check without changing policy, then add only the two new named profiles.
        for instance in INSTANCES.values():
            profile = APPARMOR_DIR / instance.apparmor_profile
            run([str(APPARMOR_PARSER), "-Q", "-T", str(profile)])
            state = _apparmor_state(instance)
            if state == "absent":
                try:
                    run([str(APPARMOR_PARSER), "-a", str(profile)])
                    loaded_profiles.append(profile)
                except Exception:
                    if _apparmor_state(instance) == "enforce":
                        loaded_profiles.append(profile)
                    raise
            elif state != "enforce":
                refuse("apparmor_existing_profile_not_enforced")
        run([str(SYSTEMCTL), "daemon-reload"])
        verify_static_install()
        print("INSTALL=PASS;SERVICE_START=NOT_RUN;DATADIR_INITIALIZATION=NOT_RUN")
    except Exception:
        # Roll back only exact files/directories created in this invocation.
        # No preexisting path can be replaced or removed.
        rollback_failed = False
        for profile in reversed(loaded_profiles):
            result = subprocess.run([str(APPARMOR_PARSER), "-R", str(profile)],
                                    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                    timeout=15, check=False)
            if result.returncode != 0:
                rollback_failed = True
        if not rollback_failed:
            for path in reversed(created_files):
                try:
                    if path.is_file() and not path.is_symlink() and path.read_bytes() == manifest[path]:
                        path.unlink()
                except OSError:
                    rollback_failed = True
                    break
        if not rollback_failed:
            for path in reversed(created_dirs):
                try:
                    path.rmdir()
                except OSError:
                    rollback_failed = True
                    break
        daemon_reload = subprocess.run([str(SYSTEMCTL), "daemon-reload"], stdout=subprocess.DEVNULL,
                                       stderr=subprocess.DEVNULL, timeout=15, check=False)
        if daemon_reload.returncode != 0:
            rollback_failed = True
        if rollback_failed:
            refuse("install_rollback_incomplete_preserve_files")
        raise


def provision_paths() -> None:
    require_root()
    verify_static_install()
    mysql_uid = pwd.getpwnam("mysql").pw_uid
    mysql_gid = grp.getgrnam("mysql").gr_gid
    for instance in INSTANCES.values():
        _systemd_inactive(instance)
    requested = provisioning_path_specs()
    for path, owner, group, mode in (
        (Path("/var/lib"), "root", "root", 0o755),
        (Path("/var/log/mysql"), "mysql", "adm", 0o2750),
        (Path("/var/tmp"), "root", "root", 0o1777),
        (Path("/run/mysqld"), "mysql", "mysql", 0o755),
    ):
        check_directory(path, owner=owner, group=group, mode=mode)
    for raw_path, owner, group, mode, expected_kind, must_be_empty in requested:
        path = Path(raw_path)
        _assert_parent_chain(path.parent)
        if path.exists() or path.is_symlink():
            if expected_kind == "directory":
                check_directory(path, owner=owner, group=group, mode=mode)
                is_empty = not any(path.iterdir())
            else:
                check_file(path, owner=owner, group=group, mode=mode)
                is_empty = path.stat().st_size == 0
            if must_be_empty:
                validate_provisioning_resource_empty(is_empty)
    created: list[Path] = []
    try:
        for raw_path, owner, group, mode, expected_kind, must_be_empty in requested:
            path = Path(raw_path)
            if path.exists() or path.is_symlink():
                if expected_kind == "directory":
                    check_directory(path, owner=owner, group=group, mode=mode)
                    is_empty = not any(path.iterdir())
                else:
                    check_file(path, owner=owner, group=group, mode=mode)
                    is_empty = path.stat().st_size == 0
                if must_be_empty:
                    validate_provisioning_resource_empty(is_empty)
            else:
                parent = path.parent
                if not parent.is_dir() or parent.is_symlink():
                    refuse("resource_parent_unavailable")
                if expected_kind == "file":
                    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), mode)
                    created.append(path)
                    try:
                        os.fchown(fd, mysql_uid, grp.getgrnam(group).gr_gid)
                        os.fchmod(fd, mode)
                    finally:
                        os.close(fd)
                else:
                    os.mkdir(path, mode)
                    created.append(path)
                    os.chown(path, mysql_uid, mysql_gid)
                    os.chmod(path, mode)
        for raw_path, owner, group, mode, expected_kind, must_be_empty in requested:
            path = Path(raw_path)
            if expected_kind == "directory":
                check_directory(path, owner=owner, group=group, mode=mode)
                if must_be_empty:
                    validate_provisioning_resource_empty(not any(path.iterdir()))
            else:
                check_file(path, owner=owner, group=group, mode=mode)
                if must_be_empty:
                    validate_provisioning_resource_empty(path.stat().st_size == 0)
    except Exception:
        for path in reversed(created):
            try:
                if path.is_file() and path.stat().st_size == 0:
                    path.unlink()
                elif path.is_dir() and not any(path.iterdir()):
                    path.rmdir()
            except OSError:
                pass
        raise
    print("PATHS=PASS;MYSQL_INITIALIZATION=NOT_RUN;SERVICES=NOT_STARTED")


def verify_install() -> None:
    require_root()
    verify_static_install()
    for instance in INSTANCES.values():
        verify_effective_config(instance)
    print("INSTALL_VERIFY=PASS;MYSQL_3306=UNMODIFIED_BY_TOOL")


def verify_static_install() -> None:
    require_root()
    _verify_installed_bundle()
    for instance in INSTANCES.values():
        verify_systemd_contract(instance)
        verify_apparmor(instance)
        dropin = Path(f"/etc/systemd/system/{instance.unit}.d/90-wpcaregrid-g1.conf")
        show = run([str(SYSTEMCTL), "show", instance.unit, "--property=ExecStartPre", "--value"])
        if "wpcaregrid-g1-mysql.py preflight" not in show or "mysql-systemd-start" in show:
            refuse("effective_systemd_preflight_mismatch")
        if show.count("wpcaregrid-g1-mysql.py preflight") != 1:
            refuse("effective_systemd_preflight_ambiguous")
        exec_start = run([str(SYSTEMCTL), "show", instance.unit, "--property=ExecStart", "--value"])
        try:
            validate_effective_execstart(instance, exec_start)
        except Refused as exc:
            refuse(str(exc))
        state = run([str(SYSTEMCTL), "show", instance.unit, "--property=DropInPaths", "--value"])
        if set(state.split()) != {str(dropin)}:
            refuse("effective_systemd_dropin_missing")
    print("STATIC_INSTALL_VERIFY=PASS;MYSQL_3306=UNMODIFIED_BY_TOOL")


def emit(call: Callable[[], None]) -> int:
    try:
        call()
    except Refused as exc:
        print(f"G1_MYSQL=STOP;CODE={exc}")
        return 3
    except Exception:
        print("G1_MYSQL=STOP;CODE=internal_error")
        return 4
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("install")
    sub.add_parser("provision-paths")
    sub.add_parser("verify-install")
    for command in ("preflight", "initialize", "start", "verify-running"):
        cmd = sub.add_parser(command)
        cmd.add_argument("--instance", required=True, choices=tuple(INSTANCES))
    args = parser.parse_args(argv)
    try:
        if args.command == "install":
            action = install
        elif args.command == "provision-paths":
            action = provision_paths
        elif args.command == "verify-install":
            action = verify_install
        elif args.command == "preflight":
            action = lambda: (verify_preflight(get_instance(args.instance)), print(f"PREFLIGHT=PASS;INSTANCE={args.instance}"))
        elif args.command == "initialize":
            action = lambda: initialize(get_instance(args.instance))
        elif args.command == "start":
            action = lambda: start(get_instance(args.instance))
        else:
            action = lambda: verify_running(get_instance(args.instance))
    except Refused as exc:
        print(f"G1_MYSQL=STOP;CODE={exc}")
        return 3
    return emit(action)


if __name__ == "__main__":
    raise SystemExit(main())
