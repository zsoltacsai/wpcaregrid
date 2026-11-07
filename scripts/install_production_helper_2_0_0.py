#!/usr/bin/env python3
"""Install the pinned production helper without enabling its profile.

This installer is deliberately separate from Laravel releases. It creates a
disabled production policy, installs only versioned root-owned artifacts, and
publishes the narrowly scoped sudoers file last. It never creates application
or database resources and never runs helper operations.
"""

from __future__ import annotations

import hashlib
import json
import os
import pwd
import re
import stat
import subprocess
import sys
import tempfile
from pathlib import Path
from typing import Callable


VERSION = "2.0.0"
CONTRACT = "wpcaregrid-deployment-profile-v2"
SOURCE_DIR = Path("/home/deploy/.private/wpcg-production-helper-2.0.0-cf2a944c")

ENTRY_SOURCE = "wpcaregrid-deploy-production-priv.py"
BACKEND_SOURCE = "deployment_backend_v2.py"
TRANSACTION_SOURCE = "deployment_transaction_v2.py"
ENVIRONMENT_SOURCE = "deployment_environment_contract.py"
CONTROLLER_SOURCE = "production_deploy_controller.py"
SUDOERS_SOURCE = "wpcaregrid-production-v2.0.0"

ENTRY_SHA256 = "dde7ee7370e4968284cc10fed8dae8cf33786d93830f9f65c7157e19bbf572a1"
BACKEND_SHA256 = "d1cbde99eef1168e42797268ce170e451b28051d7d980876a6167fb19bf85576"
TRANSACTION_SHA256 = "ba4c9fd3ea821cb8a1aa5581a74da8c1477b5e30484fc2e09546a26fe60f2d00"
ENVIRONMENT_SHA256 = "f22bc2ca1d570d932413f91e7c9dbddf7e2e613d56540418e5ca47dafe72caa1"
HELPER_ARTIFACT_SHA256 = "0e50791ee708de571dbcaa5420bd06b31698e3b7a8683f7501bee6880ead96fc"
CONTROLLER_SHA256 = "a94865d3e6318c7278b6d72e797fc94d5274faeca0ea2073ecc84fad74166283"
SUDOERS_SHA256 = "fb7716132c9d48a6a55c305ecff932a1bd518a575e7dd974162bc0d4f89fc963"

ENTRYPOINT = Path(
    "/usr/local/libexec/wpcaregrid-deploy/production/2.0.0/"
    "wpcaregrid-deploy-production-priv")
BACKEND = ENTRYPOINT.parent / "deployment_backend_v2.py"
TRANSACTION = ENTRYPOINT.parent / "deployment_transaction_v2.py"
ENVIRONMENT = ENTRYPOINT.parent / "deployment_environment_contract.py"
CONTROLLER = ENTRYPOINT.parent / "production_deploy_controller.py"
POLICY = Path("/etc/wpcaregrid-deploy/profiles/production-v2.0.0.json")
SUDOERS = Path("/etc/sudoers.d/wpcaregrid-production-v2.0.0")
PHASE_B_POLICY = Path("/etc/wpcaregrid-deploy/profiles/production-phase-b-2.0.0.json")
DATABASE_CREDENTIALS = Path("/etc/wpcaregrid-deploy/credentials/production-database-2.0.0.json")

DEPLOY_USER = "deploy-production"
OPERATIONS = ("authorize", "deploy", "preflight", "stage", "verify-install")
SHA256_RE = re.compile(r"[0-9a-f]{64}\Z")
MAX_SOURCE_BYTES = 2_000_000

# Exact immutable configuration schema. Values remain null until a separately
# approved environment profile is prepared and installed.
ENVIRONMENT_FIELDS = (
    "app_root", "application_link", "release_root", "incoming_root", "inbox_root",
    "state_root", "backup_root", "shared_env_file", "shared_storage_root", "shared_vendor_root",
    "database", "database_host", "database_port", "database_server_uuid_sha256",
    "isolated_database", "isolated_database_host", "isolated_database_port",
    "isolated_database_uuid_sha256", "host", "mysql_version", "deploy_user", "deploy_uid",
    "php_version", "php_fpm_socket", "php_fpm_unit", "php_fpm_uid", "php_fpm_gid",
    "worker_programs", "scheduler_unit", "maintenance_marker", "worker_fence_marker",
    "scheduler_fence_marker",
)
POLICY_KEYS = frozenset({
    "schema", "profile", "enabled", *ENVIRONMENT_FIELDS,
    "entrypoint_sha256", "backend_sha256", "transaction_module_sha256",
    "environment_contract_sha256", "helper_artifact_sha256", "phase_b_policy_sha256",
    "operations",
})

INSTALL_DIRS = (
    Path("/usr/local/libexec/wpcaregrid-deploy/production"),
    ENTRYPOINT.parent,
)
REQUIRED_EXISTING_DIRS = (
    Path("/usr/local/libexec/wpcaregrid-deploy"),
    Path("/etc/wpcaregrid-deploy"),
    POLICY.parent,
    SUDOERS.parent,
)


class InstallError(Exception):
    """A safe, stable installation refusal code."""


def _fail(code: str) -> None:
    raise InstallError(code)


def _sha(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def _mode(info: os.stat_result) -> int:
    return stat.S_IMODE(info.st_mode)


def _check_directory(path: Path, *, expected_mode: int | None = None) -> os.stat_result:
    try:
        info = os.lstat(path)
    except OSError:
        _fail("directory_unavailable")
    if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode) or info.st_uid != 0:
        _fail("directory_metadata_invalid")
    if _mode(info) & 0o022:
        _fail("directory_writable_by_group_or_other")
    if expected_mode is not None and _mode(info) != expected_mode:
        _fail("directory_mode_invalid")
    return info


def _check_existing_parent_chain(path: Path) -> None:
    cursor = Path("/")
    _check_directory(cursor)
    for component in path.parts[1:]:
        cursor /= component
        _check_directory(cursor)


def _check_source_directory(deploy_uid: int) -> None:
    try:
        info = os.lstat(SOURCE_DIR)
    except OSError:
        _fail("source_directory_unavailable")
    if (not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode)
            or info.st_uid != deploy_uid or _mode(info) != 0o700):
        _fail("source_directory_metadata_invalid")
    cursor = Path("/")
    for index, component in enumerate(SOURCE_DIR.parts[1:]):
        cursor /= component
        parent_info = os.lstat(cursor)
        if not stat.S_ISDIR(parent_info.st_mode) or stat.S_ISLNK(parent_info.st_mode):
            _fail("source_parent_metadata_invalid")
        if index < len(SOURCE_DIR.parts[1:]) - 1 and _mode(parent_info) & 0o022:
            _fail("source_parent_writable_by_group_or_other")


def _read_source(path: Path, expected_sha: str, *, deploy_uid: int) -> bytes:
    try:
        before = os.lstat(path)
        if (not stat.S_ISREG(before.st_mode) or stat.S_ISLNK(before.st_mode)
                or before.st_uid != deploy_uid or _mode(before) != 0o600
                or before.st_size > MAX_SOURCE_BYTES):
            _fail("source_file_metadata_invalid")
        fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0)
                     | getattr(os, "O_CLOEXEC", 0))
        try:
            opened = os.fstat(fd)
            if (not stat.S_ISREG(opened.st_mode) or opened.st_dev != before.st_dev
                    or opened.st_ino != before.st_ino or opened.st_uid != deploy_uid
                    or _mode(opened) != 0o600 or opened.st_size != before.st_size):
                _fail("source_changed_during_open")
            with os.fdopen(fd, "rb", closefd=False) as stream:
                raw = stream.read(MAX_SOURCE_BYTES + 1)
        finally:
            os.close(fd)
    except InstallError:
        raise
    except OSError:
        _fail("source_unavailable")
    if len(raw) != before.st_size or _sha(raw) != expected_sha:
        _fail("source_hash_mismatch")
    return raw


SOURCE_HASHES = {
    ENTRY_SOURCE: ENTRY_SHA256,
    BACKEND_SOURCE: BACKEND_SHA256,
    TRANSACTION_SOURCE: TRANSACTION_SHA256,
    ENVIRONMENT_SOURCE: ENVIRONMENT_SHA256,
    CONTROLLER_SOURCE: CONTROLLER_SHA256,
    SUDOERS_SOURCE: SUDOERS_SHA256,
}


def _read_sources(deploy_uid: int) -> dict[str, bytes]:
    _check_source_directory(deploy_uid)
    try:
        names = {entry.name for entry in os.scandir(SOURCE_DIR)}
    except OSError:
        _fail("source_directory_unavailable")
    if names != set(SOURCE_HASHES):
        _fail("source_directory_contents_invalid")
    data = {}
    for name, expected in SOURCE_HASHES.items():
        data[name] = _read_source(SOURCE_DIR / name, expected, deploy_uid=deploy_uid)
    return data


def _disabled_policy() -> bytes:
    values: dict[str, object] = {
        "schema": 1,
        "profile": "production",
        "enabled": False,
        **{key: None for key in ENVIRONMENT_FIELDS},
        "entrypoint_sha256": ENTRY_SHA256,
        "backend_sha256": BACKEND_SHA256,
        "transaction_module_sha256": TRANSACTION_SHA256,
        "environment_contract_sha256": ENVIRONMENT_SHA256,
        "helper_artifact_sha256": HELPER_ARTIFACT_SHA256,
        "phase_b_policy_sha256": None,
        "operations": list(OPERATIONS),
    }
    if set(values) != POLICY_KEYS or values["enabled"] is not False:
        _fail("disabled_policy_schema_invalid")
    return (json.dumps(values, sort_keys=True, separators=(",", ":")) + "\n").encode("utf-8")


def _verify_sudoers_source(raw: bytes) -> None:
    expected = (
        f"{DEPLOY_USER} ALL=(root) NOPASSWD: NOSETENV: {ENTRYPOINT} preflight\n"
        f"{DEPLOY_USER} ALL=(root) NOPASSWD: NOSETENV: {ENTRYPOINT} verify-install\n"
        f'{DEPLOY_USER} ALL=(root) NOPASSWD: NOSETENV: {ENTRYPOINT} authorize "^[0-9a-f]{{40}}$"\n'
        f'{DEPLOY_USER} ALL=(root) NOPASSWD: NOSETENV: {ENTRYPOINT} stage "^[0-9a-f]{{40}} [0-9a-f]{{64}}$"\n'
        f'{DEPLOY_USER} ALL=(root) NOPASSWD: NOSETENV: {ENTRYPOINT} deploy "^[0-9a-f]{{40}} [0-9a-f]{{64}}$"\n'
    ).encode("ascii")
    try:
        active = "\n".join(
            line.strip() for line in raw.decode("ascii").splitlines()
            if line.strip() and not line.lstrip().startswith("#")
        ) + "\n"
    except UnicodeError:
        _fail("sudoers_template_contract_mismatch")
    if active.encode("ascii") != expected:
        _fail("sudoers_template_contract_mismatch")


def _check_absent(path: Path) -> None:
    try:
        os.lstat(path)
    except FileNotFoundError:
        return
    except OSError:
        _fail("destination_state_unknown")
    _fail("destination_already_exists")


def _ensure_dir(path: Path, created_dirs: list[tuple[Path, int, int, int, int, int]]) -> None:
    try:
        info = os.lstat(path)
    except FileNotFoundError:
        try:
            os.mkdir(path, 0o755)
        except OSError:
            _fail("directory_create_failed")
        info = os.lstat(path)
        if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode):
            _fail("created_directory_type_invalid")
        created_dirs.append((path, info.st_dev, info.st_ino, info.st_uid, info.st_gid, _mode(info)))
        os.chown(path, 0, 0)
        info = os.lstat(path)
        created_dirs[-1] = (path, info.st_dev, info.st_ino, info.st_uid, info.st_gid, _mode(info))
        os.chmod(path, 0o755)
        info = os.lstat(path)
        created_dirs[-1] = (path, info.st_dev, info.st_ino, info.st_uid, info.st_gid, _mode(info))
        _fsync_directory(path.parent)
    except OSError:
        _fail("directory_create_failed")
    _check_directory(path, expected_mode=0o755)


def _fsync_directory(path: Path) -> None:
    fd = os.open(path, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def _publish(path: Path, raw: bytes, mode: int,
             created: list[tuple[Path, int, int, int, int, int, str]], *,
             owner_uid: int = 0, owner_gid: int = 0) -> None:
    _check_absent(path)
    fd, temp_name = tempfile.mkstemp(prefix=f".{path.name}.tmp.", dir=path.parent)
    temp_path = Path(temp_name)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(raw)
            stream.flush()
            os.fsync(stream.fileno())
            os.fchown(stream.fileno(), owner_uid, owner_gid)
        os.chmod(temp_path, mode)
        _fsync_file(temp_path)
        temp_info = os.lstat(temp_path)
        _check_absent(path)
        os.link(temp_path, path, follow_symlinks=False)
        created.append((path, temp_info.st_dev, temp_info.st_ino,
                        owner_uid, owner_gid, mode, _sha(raw)))
        info = os.lstat(path)
        if (not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode)
                or info.st_dev != temp_info.st_dev or info.st_ino != temp_info.st_ino
                or info.st_uid != owner_uid or info.st_gid != owner_gid or _mode(info) != mode):
            _fail("published_file_metadata_invalid")
        if _hash_path(path, mode=mode, owner_uid=owner_uid, owner_gid=owner_gid) != _sha(raw):
            _fail("published_file_hash_mismatch")
        _fsync_directory(path.parent)
    except InstallError:
        raise
    except OSError:
        _fail("atomic_publish_failed")
    finally:
        try:
            os.unlink(temp_path)
        except FileNotFoundError:
            pass


def _fsync_file(path: Path) -> None:
    fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def _hash_path(path: Path, *, mode: int, expected_inode: int | None = None,
               expected_device: int | None = None,
               owner_uid: int = 0, owner_gid: int = 0) -> str:
    flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0)
    fd = os.open(path, flags)
    try:
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != owner_uid or info.st_gid != owner_gid
                or _mode(info) != mode or info.st_size > MAX_SOURCE_BYTES
                or (expected_inode is not None and info.st_ino != expected_inode)
                or (expected_device is not None and info.st_dev != expected_device)):
            _fail("installed_file_metadata_invalid")
        digest = hashlib.sha256()
        with os.fdopen(fd, "rb", closefd=False) as stream:
            for chunk in iter(lambda: stream.read(64 * 1024), b""):
                digest.update(chunk)
        return digest.hexdigest()
    finally:
        os.close(fd)


def _run(argv: list[str], *, timeout: int = 15, maximum: int = 32_000,
         runner: Callable[..., subprocess.CompletedProcess] = subprocess.run):
    env = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C"}
    try:
        result = runner(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                        timeout=timeout, check=False, env=env)
    except (OSError, subprocess.SubprocessError):
        _fail("required_command_unavailable")
    if len(result.stdout) > maximum or len(result.stderr) > maximum:
        _fail("command_output_invalid")
    return result


def _validate_sudoers(raw: bytes, *, runner: Callable[..., subprocess.CompletedProcess] = subprocess.run,
                      sudoers_dir: Path | None = None) -> None:
    directory = sudoers_dir or SUDOERS.parent
    fd, temp_name = tempfile.mkstemp(prefix=".wpcaregrid-production-v2.0.0.", dir=directory)
    path = Path(temp_name)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(raw)
            stream.flush()
            os.fsync(stream.fileno())
            os.fchown(stream.fileno(), 0, 0)
        os.chmod(path, 0o440)
        result = _run(["/usr/sbin/visudo", "-cf", str(path)], maximum=4096, runner=runner)
        if result.returncode != 0:
            _fail("sudoers_syntax_invalid")
    finally:
        try:
            os.unlink(path)
        except FileNotFoundError:
            pass


def _parse_sudo_list(raw: bytes) -> tuple[str, ...]:
    try:
        text = raw.decode("utf-8")
    except UnicodeError:
        _fail("sudo_list_response_invalid")
    lines = []
    for line in text.splitlines():
        stripped = line.strip()
        if stripped.startswith("("):
            lines.append(stripped)
    return tuple(lines)


def _expected_sudo_list() -> tuple[str, ...]:
    prefix = f"(root) NOPASSWD: NOSETENV: {ENTRYPOINT}"
    return (
        f"{prefix} preflight", f"{prefix} verify-install",
        f'{prefix} authorize "^[0-9a-f]{{40}}$"',
        f'{prefix} stage "^[0-9a-f]{{40}} [0-9a-f]{{64}}$"',
        f'{prefix} deploy "^[0-9a-f]{{40}} [0-9a-f]{{64}}$"',
    )


def _check_clean_privilege_baseline(user: str,
                                    runner: Callable[..., subprocess.CompletedProcess]) -> None:
    result = _run(["/usr/bin/sudo", "-n", "-l", "-U", user], maximum=16_000, runner=runner)
    if result.returncode not in {0, 1}:
        _fail("sudo_privilege_baseline_unavailable")
    rules = _parse_sudo_list(result.stdout + b"\n" + result.stderr)
    if rules:
        _fail("production_identity_has_existing_sudo_rules")


def _verify_existing_sudoers(runner: Callable[..., subprocess.CompletedProcess]) -> None:
    result = _run(["/usr/sbin/visudo", "-c"], maximum=4096, runner=runner)
    if result.returncode != 0:
        _fail("existing_sudoers_invalid")


def _verify_installed_sudoers(user: str,
                              runner: Callable[..., subprocess.CompletedProcess]) -> None:
    result = _run(["/usr/bin/sudo", "-n", "-l", "-U", user], maximum=16_000, runner=runner)
    actual = _parse_sudo_list(result.stdout + b"\n" + result.stderr)
    prefix = f"(root) NOPASSWD: NOSETENV: {ENTRYPOINT} "
    operations = []
    for rule in actual:
        if not rule.startswith(prefix):
            _fail("installed_sudoers_allowlist_mismatch")
        operation = rule[len(prefix):].split(maxsplit=1)[0].strip('"')
        operations.append(operation)
    if (result.returncode != 0 or len(actual) != len(OPERATIONS)
            or sorted(operations) != sorted(OPERATIONS)):
        _fail("installed_sudoers_allowlist_mismatch")


def _negative_authorization_tests(user: str,
                                 runner: Callable[..., subprocess.CompletedProcess]) -> None:
    forbidden = [
        ["/usr/sbin/runuser", "-u", "deploy", "--", "/usr/bin/sudo", "-n", str(ENTRYPOINT), "preflight"],
        ["/usr/sbin/runuser", "-u", user, "--", "/usr/bin/sudo", "-n", str(ENTRYPOINT), "production"],
        ["/usr/sbin/runuser", "-u", user, "--", "/usr/bin/sudo", "-n", str(ENTRYPOINT), "deploy", "invalid", "invalid"],
    ]
    for argv in forbidden:
        result = _run(argv, maximum=4096, runner=runner)
        if result.returncode == 0:
            _fail("negative_authorization_test_failed")


def _readback(files: tuple[tuple[Path, int, str], ...]) -> None:
    for path, mode, digest in files:
        try:
            info = os.lstat(path)
        except OSError:
            _fail("installed_file_unavailable")
        if (not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode)
                or info.st_uid != 0 or info.st_gid != 0 or _mode(info) != mode):
            _fail("installed_file_metadata_invalid")
        if _hash_path(path, mode=mode, expected_inode=info.st_ino, expected_device=info.st_dev) != digest:
            _fail("installed_file_hash_mismatch")


def _rollback(created_files: list[tuple[Path, int, int, int, int, int, str]],
              created_dirs: list[tuple[Path, int, int, int, int, int]]) -> bool:
    try:
        for path, device, inode, uid, gid, mode, digest in reversed(created_files):
            info = os.lstat(path)
            if (info.st_dev != device or info.st_ino != inode or info.st_uid != uid
                    or info.st_gid != gid or not stat.S_ISREG(info.st_mode)
                    or stat.S_ISLNK(info.st_mode) or _mode(info) != mode
                    or _hash_path(path, mode=mode, expected_inode=inode, expected_device=device,
                                  owner_uid=uid, owner_gid=gid) != digest):
                return False
            os.unlink(path)
            _fsync_directory(path.parent)
        for path, device, inode, uid, gid, mode in reversed(created_dirs):
            info = os.lstat(path)
            if (info.st_dev != device or info.st_ino != inode or info.st_uid != uid
                    or info.st_gid != gid or not stat.S_ISDIR(info.st_mode)
                    or stat.S_ISLNK(info.st_mode) or _mode(info) != mode or os.listdir(path)):
                return False
            os.rmdir(path)
            _fsync_directory(path.parent)
        return True
    except (OSError, InstallError):
        return False


def _preflight(*, runner: Callable[..., subprocess.CompletedProcess] = subprocess.run,
               deploy_user: str = DEPLOY_USER) -> tuple[int, dict[str, bytes]]:
    if os.geteuid() != 0:
        _fail("root_required")
    try:
        deploy_uid = pwd.getpwnam("deploy").pw_uid
        target = pwd.getpwnam(deploy_user)
    except KeyError:
        _fail("required_identity_missing")
    if target.pw_uid <= 0 or target.pw_uid == deploy_uid:
        _fail("production_deploy_identity_invalid")
    try:
        staging_uid = pwd.getpwnam("deploy-staging-next").pw_uid
    except KeyError:
        staging_uid = None
    if target.pw_uid == staging_uid:
        _fail("production_deploy_identity_overlaps_staging")

    for path in REQUIRED_EXISTING_DIRS:
        _check_existing_parent_chain(path)
    for directory in INSTALL_DIRS:
        # Verify every existing ancestor now. Absent install directories are
        # created by the transaction only after every artifact is validated.
        cursor = Path("/")
        for component in directory.parts[1:]:
            cursor /= component
            if cursor.exists() or cursor.is_symlink():
                _check_directory(cursor)
            else:
                break

    existing = (ENTRYPOINT.parent, ENTRYPOINT, BACKEND, TRANSACTION, ENVIRONMENT,
                CONTROLLER, POLICY, SUDOERS)
    for path in existing:
        _check_absent(path)
    if PHASE_B_POLICY.exists() or PHASE_B_POLICY.is_symlink():
        _fail("phase_b_policy_must_remain_unconfigured")
    if DATABASE_CREDENTIALS.exists() or DATABASE_CREDENTIALS.is_symlink():
        _fail("database_credentials_must_remain_unconfigured")

    _check_clean_privilege_baseline(deploy_user, runner)
    _verify_existing_sudoers(runner)
    data = _read_sources(deploy_uid)
    _verify_sudoers_source(data[SUDOERS_SOURCE])
    return target.pw_uid, data


def install(*, runner: Callable[..., subprocess.CompletedProcess] = subprocess.run,
            effective_uid: int | None = None, deploy_user: str = DEPLOY_USER) -> dict[str, object]:
    if (os.geteuid() if effective_uid is None else effective_uid) != 0:
        _fail("root_required")
    target_uid, sources = _preflight(runner=runner, deploy_user=deploy_user)
    _ = target_uid  # The fixed sudoers user is verified above; no UID is embedded in it.
    _verify_sudoers_source(sources[SUDOERS_SOURCE])
    policy_raw = _disabled_policy()
    try:
        parsed_policy = json.loads(policy_raw.decode("utf-8"))
    except (UnicodeError, ValueError):
        _fail("disabled_policy_encoding_invalid")
    if (set(parsed_policy) != POLICY_KEYS or parsed_policy.get("profile") != "production"
            or parsed_policy.get("enabled") is not False):
        _fail("disabled_policy_schema_invalid")

    # Root-owned parent directories are created only when absent. Existing
    # directory contents, ownership and permissions are never rewritten.
    created_files: list[tuple[Path, int, int, int, int, int, str]] = []
    created_dirs: list[tuple[Path, int, int, int, int, int]] = []
    try:
        for directory in INSTALL_DIRS:
            _ensure_dir(directory, created_dirs)
        _validate_sudoers(sources[SUDOERS_SOURCE], runner=runner)

        _publish(ENTRYPOINT, sources[ENTRY_SOURCE], 0o755, created_files)
        _publish(BACKEND, sources[BACKEND_SOURCE], 0o644, created_files)
        _publish(TRANSACTION, sources[TRANSACTION_SOURCE], 0o644, created_files)
        _publish(ENVIRONMENT, sources[ENVIRONMENT_SOURCE], 0o644, created_files)
        _publish(CONTROLLER, sources[CONTROLLER_SOURCE], 0o644, created_files)
        _publish(POLICY, policy_raw, 0o644, created_files)

        expected_files = (
            (ENTRYPOINT, 0o755, ENTRY_SHA256), (BACKEND, 0o644, BACKEND_SHA256),
            (TRANSACTION, 0o644, TRANSACTION_SHA256), (ENVIRONMENT, 0o644, ENVIRONMENT_SHA256),
            (CONTROLLER, 0o644, CONTROLLER_SHA256), (POLICY, 0o644, _sha(policy_raw)),
        )
        _readback(expected_files)
        # The sudoers fragment is the final activation boundary. Its candidate
        # has already passed visudo; post-publication enumeration must match
        # exactly the five versioned commands and no additional command rules.
        _publish(SUDOERS, sources[SUDOERS_SOURCE], 0o440, created_files)
        _readback(((SUDOERS, 0o440, SUDOERS_SHA256),))
        _verify_installed_sudoers(deploy_user, runner)
        _negative_authorization_tests(deploy_user, runner)

        receipt = {
            "result": "PASS",
            "helper_version": VERSION,
            "compatibility_contract": CONTRACT,
            "helper_artifact_sha256": HELPER_ARTIFACT_SHA256,
            "controller_sha256": CONTROLLER_SHA256,
            "profile": "production",
            "profile_enabled": False,
            "phase_b_policy": "DISABLED",
            "installed_file_sha256": {str(path): digest for path, _mode_value, digest in expected_files},
            "sudoers_sha256": SUDOERS_SHA256,
            "sudoers_operations": list(OPERATIONS),
        }
        print("INSTALL=PASS")
        print("PRODUCTION_PROFILE=DISABLED")
        print("PHASE_B_POLICY=DISABLED")
        print("SUDOERS_ALLOWLIST=PASS")
        return receipt
    except BaseException as error:
        rollback_ok = _rollback(created_files, created_dirs)
        if isinstance(error, InstallError):
            reason = str(error)
        elif isinstance(error, (KeyboardInterrupt, SystemExit)):
            reason = "interrupted"
        else:
            reason = "internal_error"
        print(f"INSTALL=FAIL;CODE={reason}")
        print(f"ROLLBACK={'PASS' if rollback_ok else 'HOLD'}")
        raise SystemExit(1)


def main(argv: list[str] | None = None) -> int:
    args = list(sys.argv[1:] if argv is None else argv)
    if args:
        print("INSTALL=HOLD;CODE=arguments_not_allowed")
        return 3
    try:
        install()
        return 0
    except InstallError as error:
        print(f"INSTALL=HOLD;CODE={error}")
        return 3


if __name__ == "__main__":
    raise SystemExit(main())
