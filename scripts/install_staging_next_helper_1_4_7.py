#!/usr/bin/env python3
"""No-overwrite root installer for the pinned staging-next helper 1.4.7."""

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
import types
from pathlib import Path


VERSION = "1.4.7"
CONTRACT = "wpcaregrid-staging-next-deploy-v11"
SOURCE_DIR = Path("/home/deploy/.private/wpcg-staging-next-helper-1.4.7")
INSTALLER_NAME = "install_staging_next_helper_1_4_7.py"
ENTRY_NAME = "wpcaregrid-staging-next-deploy-priv-1_4_7.py"
BACKEND_NAME = "staging_next_deploy_backend_1_4_7.py"
TRANSACTION_NAME = "staging_next_db_transaction_1_4_7.py"
ENTRY_SHA256 = "7eecc1876c52c187229187db00d410cb9a80d6f1d0df7ad91a5344f71b45fb2b"
BACKEND_SHA256 = "6e5a6559eed5fcc5dbcb079d89afb15edc5c7e4982d46440aa4499c489b0b52c"
TRANSACTION_SHA256 = "89b5505ec7d05c58ed30d87d7e9b07d44fa0286894b346eeeb9948dbfacfa71a"
ARTIFACT_SHA256 = "d38a3c21c611e84d0741c2ab39401da31f60f17659902aa33e503a4bbae58837"
EXPECTED_DEPLOY_UID = 996
MYSQL_UUID_SHA256 = "bc35682d80f0894c2c2c2879552fb8fe13dc2a530868d79d817a6280e31bde1c"
PHP_FPM_UID = 33
PHP_FPM_GID = 33
APP_DIR = "/var/www/wpcaregrid-staging-new"
DATABASE = "wpcg_staging_next"
HOST = "staging-next.wpcaregrid.com"
SOCKET = "/run/php/php8.5-fpm.sock"
ENTRYPOINT = Path(
    "/usr/local/libexec/wpcaregrid-deploy/staging-next/1.4.7/"
    "wpcaregrid-deploy-staging-next-priv")
BACKEND = ENTRYPOINT.parent / BACKEND_NAME
TRANSACTION = ENTRYPOINT.parent / TRANSACTION_NAME
POLICY = Path("/etc/wpcaregrid-deploy/profiles/staging-next-1.4.7.json")
SUDOERS = Path("/etc/sudoers.d/wpcaregrid-staging-next-1.4.7")
PHASE_B_POLICY = Path("/etc/wpcaregrid-deploy/profiles/staging-next-phase-b-1.4.7.json")
SHA_RE = re.compile(r"[0-9a-f]{64}\Z")


class InstallError(Exception):
    pass


def _fail(code: str):
    raise InstallError(code)


def _sha(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def _check_directory(path: Path, *, require_mode: int | None = None):
    try:
        info = os.lstat(path)
    except OSError:
        _fail("required_directory_missing")
    if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode) or info.st_uid != 0:
        _fail("directory_metadata_invalid")
    if stat.S_IMODE(info.st_mode) & 0o022:
        _fail("directory_writable_by_group_or_other")
    if require_mode is not None and stat.S_IMODE(info.st_mode) != require_mode:
        _fail("directory_mode_invalid")


def _check_directory_chain(path: Path, *, terminal_mode: int | None = None):
    cursor = Path("/")
    _check_directory(cursor)
    for index, part in enumerate(path.parts[1:]):
        cursor /= part
        _check_directory(cursor, require_mode=terminal_mode if index == len(path.parts[1:]) - 1 else None)


def _check_source_directory_chain(path: Path, deploy_uid: int):
    cursor = Path("/")
    for index, part in enumerate(path.parts[1:]):
        cursor /= part
        try:
            info = os.lstat(cursor)
        except OSError:
            _fail("source_parent_unavailable")
        if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode):
            _fail("source_parent_metadata_invalid")
        if index < len(path.parts[1:]) - 1 and stat.S_IMODE(info.st_mode) & 0o022:
            _fail("source_parent_writable_by_group_or_other")
        if index == len(path.parts[1:]) - 1 and (
                info.st_uid != deploy_uid or stat.S_IMODE(info.st_mode) != 0o700):
            _fail("source_directory_metadata_invalid")


def _check_absent(path: Path):
    try:
        os.lstat(path)
    except FileNotFoundError:
        return
    except OSError:
        _fail("destination_state_unknown")
    _fail("destination_already_exists")


def _verify_phase_b_disabled(path: Path = PHASE_B_POLICY):
    if path.exists() or path.is_symlink():
        _fail("phase_b_policy_must_remain_disabled")


def _read_source(path: Path, *, expected_sha: str, maximum: int = 2_000_000) -> bytes:
    try:
        info = os.lstat(path)
        if (not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode)
                or info.st_uid != pwd.getpwnam("deploy").pw_uid
                or stat.S_IMODE(info.st_mode) != 0o600 or info.st_size > maximum):
            _fail("source_metadata_invalid")
        flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0)
        fd = os.open(path, flags)
        try:
            opened = os.fstat(fd)
            if (opened.st_dev != info.st_dev or opened.st_ino != info.st_ino
                    or opened.st_size != info.st_size or not stat.S_ISREG(opened.st_mode)):
                _fail("source_changed_during_open")
            with os.fdopen(fd, "rb", closefd=False) as stream:
                raw = stream.read(maximum + 1)
        finally:
            os.close(fd)
    except InstallError:
        raise
    except (OSError, KeyError):
        _fail("source_unavailable")
    if len(raw) != info.st_size or _sha(raw) != expected_sha:
        _fail("source_hash_mismatch")
    return raw


def _verify_source_directory_contents():
    expected = {ENTRY_NAME, BACKEND_NAME, TRANSACTION_NAME}
    try:
        entries = list(os.scandir(SOURCE_DIR))
    except OSError:
        _fail("source_directory_unavailable")
    if {entry.name for entry in entries} != expected:
        _fail("source_directory_contents_invalid")
    if any(not entry.is_file(follow_symlinks=False) for entry in entries):
        _fail("source_artifact_type_invalid")


def _sha256_file_nofollow(path: Path, *, expected_device: int, expected_inode: int,
                          expected_uid: int, expected_gid: int, expected_mode: int) -> str:
    flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0)
    fd = os.open(path, flags)
    try:
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_size > 2_000_000
                or info.st_dev != expected_device or info.st_ino != expected_inode
                or info.st_uid != expected_uid or info.st_gid != expected_gid
                or stat.S_IMODE(info.st_mode) != expected_mode):
            _fail("rollback_target_metadata_invalid")
        digest = hashlib.sha256()
        with os.fdopen(fd, "rb", closefd=False) as stream:
            for chunk in iter(lambda: stream.read(64 * 1024), b""):
                digest.update(chunk)
        return digest.hexdigest()
    finally:
        os.close(fd)


def _fsync_directory(path: Path):
    directory_fd = os.open(path, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
    try:
        os.fsync(directory_fd)
    finally:
        os.close(directory_fd)


def _publish(path: Path, data: bytes, mode: int,
             created: list[tuple[Path, int, int, int, int, int, str]],
             *, owner_uid: int = 0, owner_gid: int = 0):
    parent = path.parent
    fd, temporary = tempfile.mkstemp(prefix=f".{path.name}.tmp.", dir=parent)
    temp_path = Path(temporary)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
            os.fchown(stream.fileno(), owner_uid, owner_gid)
        os.chmod(temp_path, mode)
        sync_fd = os.open(temp_path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
        try:
            os.fsync(sync_fd)
        finally:
            os.close(sync_fd)
        _check_absent(path)
        temp_info = os.lstat(temp_path)
        os.link(temp_path, path, follow_symlinks=False)
        # Record ownership of the newly linked inode immediately. Any later
        # validation error can then remove only this exact file during rollback.
        created.append((path, temp_info.st_dev, temp_info.st_ino,
                        owner_uid, owner_gid, mode, _sha(data)))
        info = os.lstat(path)
        if (info.st_dev != temp_info.st_dev or info.st_ino != temp_info.st_ino
                or not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode)
                or info.st_uid != owner_uid or info.st_gid != owner_gid
                or stat.S_IMODE(info.st_mode) != mode):
            _fail("published_file_metadata_invalid")
        digest = _sha256_file_nofollow(
            path, expected_device=temp_info.st_dev, expected_inode=temp_info.st_ino,
            expected_uid=owner_uid, expected_gid=owner_gid, expected_mode=mode)
        if digest != _sha(data):
            _fail("published_file_hash_mismatch")
        _fsync_directory(parent)
    finally:
        try:
            os.unlink(temp_path)
        except FileNotFoundError:
            pass


def _policy(uid: int) -> bytes:
    value = {
        "schema": 1, "profile": "staging-next", "enabled": True,
        "allowed_uid": uid, "app_dir": APP_DIR, "database": DATABASE, "host": HOST,
        "mysql_server_uuid_sha256": MYSQL_UUID_SHA256,
        "helper_version": VERSION, "compatibility_contract": CONTRACT,
        "php_fpm_uid": PHP_FPM_UID, "php_fpm_gid": PHP_FPM_GID,
        "entrypoint_sha256": ENTRY_SHA256, "backend_sha256": BACKEND_SHA256,
        "transaction_module_sha256": TRANSACTION_SHA256,
        "phase_b_policy_file": str(PHASE_B_POLICY), "phase_b_policy_sha256": None,
        "helper_artifact_sha256": ARTIFACT_SHA256,
        "operations": ["authorize", "deploy", "preflight", "stage"],
    }
    return (json.dumps(value, sort_keys=True, separators=(",", ":")) + "\n").encode("utf-8")


def _sudoers(uid_name: str) -> bytes:
    path = str(ENTRYPOINT)
    rows = [
        f"{uid_name} ALL=(root) NOPASSWD: NOSETENV: {path} preflight",
        f'{uid_name} ALL=(root) NOPASSWD: NOSETENV: {path} authorize "^[0-9a-f]{{40}}$"',
        f'{uid_name} ALL=(root) NOPASSWD: NOSETENV: {path} stage "^[0-9a-f]{{40}} [0-9a-f]{{64}}$"',
        f'{uid_name} ALL=(root) NOPASSWD: NOSETENV: {path} deploy "^[0-9a-f]{{40}} [0-9a-f]{{64}}$"',
    ]
    return ("\n".join(rows) + "\n").encode("ascii")


def _run(argv: list[str], *, maximum: int = 64_000, timeout: int = 15,
         extra_env: dict[str, str] | None = None):
    env = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C"}
    if extra_env:
        env.update(extra_env)
    try:
        result = subprocess.run(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, timeout=timeout, check=False,
                                env=env)
    except (OSError, subprocess.TimeoutExpired):
        _fail("required_command_unavailable")
    if len(result.stdout) > maximum or len(result.stderr) > maximum:
        _fail("command_output_invalid")
    return result


def _verify_nginx(backend_raw: bytes, transaction_raw: bytes):
    result = _run(["/usr/sbin/nginx", "-t"])
    if result.returncode != 0:
        _fail("nginx_config_test_failed")
    result = _run(["/usr/sbin/nginx", "-T"], maximum=2_000_000)
    if result.returncode != 0:
        _fail("nginx_config_dump_failed")
    module_name = "_wpcg_installer_backend"
    transaction_name = "_wpcg_staging_next_db_transaction_v1"
    module = types.ModuleType(module_name)
    module.__file__ = str(BACKEND)
    tx_module = types.ModuleType(transaction_name)
    tx_module.__file__ = str(TRANSACTION)
    # Execute only the byte buffers already authenticated by _read_source.
    # The backend accepts this preloaded transaction module so it never reads
    # code from the deploy-writable staging source directory as root.
    sys.modules[transaction_name] = tx_module
    sys.modules[module_name] = module
    try:
        exec(compile(transaction_raw, str(TRANSACTION), "exec", dont_inherit=True), tx_module.__dict__)
        exec(compile(backend_raw, str(BACKEND), "exec", dont_inherit=True), module.__dict__)
        module._verify_staging_next_nginx_route(result.stdout)
    except Exception:
        _fail("nginx_staging_next_route_invalid")
    finally:
        sys.modules.pop(module_name, None)
        sys.modules.pop(transaction_name, None)


def _rollback(created: list[tuple[Path, int, int, int, int, int, str]], directory_identity):
    try:
        for path, device, inode, file_uid, file_gid, file_mode, digest in reversed(created):
            info = os.lstat(path)
            if (info.st_dev != device or info.st_ino != inode or info.st_uid != file_uid
                    or info.st_gid != file_gid or not stat.S_ISREG(info.st_mode)
                    or stat.S_ISLNK(info.st_mode) or stat.S_IMODE(info.st_mode) != file_mode
                    or _sha256_file_nofollow(
                        path, expected_device=device, expected_inode=inode,
                        expected_uid=file_uid, expected_gid=file_gid,
                        expected_mode=file_mode) != digest):
                return False
            os.unlink(path)
            _fsync_directory(path.parent)
        if directory_identity is not None:
            path, device, inode, directory_uid, directory_gid, directory_mode = directory_identity
            info = os.lstat(path)
            if (info.st_dev != device or info.st_ino != inode or info.st_uid != directory_uid
                    or info.st_gid != directory_gid or not stat.S_ISDIR(info.st_mode)
                    or stat.S_ISLNK(info.st_mode) or os.listdir(path)):
                return False
            if stat.S_IMODE(info.st_mode) != directory_mode:
                return False
            os.rmdir(path)
            _fsync_directory(path.parent)
        return True
    except (OSError, InstallError):
        return False


def install() -> None:
    if os.geteuid() != 0:
        _fail("root_required")
    source_info = os.lstat(SOURCE_DIR)
    deploy_uid = pwd.getpwnam("deploy").pw_uid
    if (not stat.S_ISDIR(source_info.st_mode) or stat.S_ISLNK(source_info.st_mode)
            or source_info.st_uid != deploy_uid or stat.S_IMODE(source_info.st_mode) != 0o700):
        _fail("source_directory_metadata_invalid")
    if pwd.getpwnam("deploy-staging-next").pw_uid != EXPECTED_DEPLOY_UID:
        _fail("deploy_identity_mismatch")
    if not Path(APP_DIR).is_dir() or Path(APP_DIR).is_symlink():
        _fail("staging_next_application_directory_invalid")
    socket = os.lstat(SOCKET)
    if not stat.S_ISSOCK(socket.st_mode) or socket.st_uid != PHP_FPM_UID or socket.st_gid != PHP_FPM_GID:
        _fail("php_fpm_socket_identity_invalid")
    _check_source_directory_chain(SOURCE_DIR, deploy_uid)
    for directory in (ENTRYPOINT.parent.parent, Path("/etc/wpcaregrid-deploy"), POLICY.parent,
                      SUDOERS.parent):
        _check_directory_chain(directory)
    _verify_source_directory_contents()
    entry_raw = _read_source(SOURCE_DIR / ENTRY_NAME, expected_sha=ENTRY_SHA256)
    backend_raw = _read_source(SOURCE_DIR / BACKEND_NAME, expected_sha=BACKEND_SHA256)
    transaction_raw = _read_source(SOURCE_DIR / TRANSACTION_NAME, expected_sha=TRANSACTION_SHA256)
    _verify_nginx(backend_raw, transaction_raw)
    uid_name = pwd.getpwuid(EXPECTED_DEPLOY_UID).pw_name
    if uid_name != "deploy-staging-next":
        _fail("deploy_identity_name_mismatch")
    for destination in (ENTRYPOINT.parent, ENTRYPOINT, BACKEND, TRANSACTION, POLICY, SUDOERS):
        _check_absent(destination)
    _verify_phase_b_disabled()
    version_parent = ENTRYPOINT.parent.parent
    _check_directory_chain(version_parent, terminal_mode=0o755)
    policy_parent = POLICY.parent
    _check_directory_chain(policy_parent, terminal_mode=0o755)
    created = []
    directory_identity = None
    try:
        os.mkdir(ENTRYPOINT.parent, 0o755)
        info = os.lstat(ENTRYPOINT.parent)
        directory_identity = (ENTRYPOINT.parent, info.st_dev, info.st_ino,
                              info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode))
        os.chown(ENTRYPOINT.parent, 0, 0)
        info = os.lstat(ENTRYPOINT.parent)
        directory_identity = (ENTRYPOINT.parent, info.st_dev, info.st_ino,
                              info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode))
        os.chmod(ENTRYPOINT.parent, 0o755)
        info = os.lstat(ENTRYPOINT.parent)
        directory_identity = (ENTRYPOINT.parent, info.st_dev, info.st_ino,
                              info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode))
        _fsync_directory(version_parent)
        _publish(ENTRYPOINT, entry_raw, 0o755, created)
        _publish(BACKEND, backend_raw, 0o644, created)
        _publish(TRANSACTION, transaction_raw, 0o644, created)
        _publish(POLICY, _policy(EXPECTED_DEPLOY_UID), 0o644, created)
        sudoers_raw = _sudoers(uid_name)
        result = _run(["/usr/sbin/visudo", "-c"], maximum=4096)
        if result.returncode != 0:
            _fail("existing_sudoers_invalid")
        _run_sudoers_validation(sudoers_raw)
        _negative_authorization_tests()
        _positive_installation_probe()
        # Sudoers is the activation commit point. All artifact, policy, syntax,
        # authorization-contract and read-only probe checks have completed.
        # No service preflight (which can reconcile an interrupted release) is
        # executed by this installer.
        _publish(SUDOERS, sudoers_raw, 0o440, created)
        print("INSTALL=PASS")
        print(f"HELPER_VERSION={VERSION}")
        print(f"HELPER_ARTIFACT_SHA256={ARTIFACT_SHA256}")
        print("PHASE_B_POLICY=DISABLED")
        print("NEGATIVE_AUTHORIZATION=PASS")
        print("INSTALLATION_PROBE=PASS")
    except BaseException as error:
        ok = _rollback(created, directory_identity)
        if isinstance(error, InstallError):
            code = error.code
        elif isinstance(error, (KeyboardInterrupt, SystemExit)):
            code = "interrupted"
        else:
            code = "internal_error"
        print(f"INSTALL=FAIL;CODE={code}")
        print(f"ROLLBACK={'PASS' if ok else 'HOLD'}")
        raise SystemExit(1)


def _run_sudoers_validation(raw: bytes):
    fd, temporary = tempfile.mkstemp(prefix=".wpcaregrid-staging-next-1.4.7.", dir=SUDOERS.parent)
    path = Path(temporary)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(raw)
            stream.flush()
            os.fsync(stream.fileno())
            os.fchown(stream.fileno(), 0, 0)
        os.chmod(path, 0o440)
        result = _run(["/usr/sbin/visudo", "-cf", str(path)], maximum=4096)
        if result.returncode != 0:
            _fail("sudoers_syntax_invalid")
    finally:
        try:
            os.unlink(path)
        except FileNotFoundError:
            pass


def _negative_authorization_tests():
    # The actual sudoers file is validated before publication. These probes
    # exercise the installed helper's own fail-closed parser without granting
    # a command: one wrong caller and one forbidden operation must both fail.
    denied = (
        ((str(ENTRYPOINT), "verify-install"), {"SUDO_UID": str(EXPECTED_DEPLOY_UID + 1)}),
        ((str(ENTRYPOINT), "production"), {"SUDO_UID": str(EXPECTED_DEPLOY_UID)}),
        ((str(ENTRYPOINT), "deploy", "invalid", "invalid"),
         {"SUDO_UID": str(EXPECTED_DEPLOY_UID)}),
    )
    for args, environment in denied:
        result = _run(["/usr/bin/python3", "-I", *args], maximum=4096, extra_env=environment)
        if result.returncode == 0 or b"DEPLOYMENT=HOLD;CODE=" not in result.stderr:
            _fail("negative_authorization_test_failed")


def _positive_installation_probe():
    # This is deliberately verify-install, not preflight. preflight can reconcile
    # an unreceipted active release, create a maintenance gate or reload FPM.
    result = _run(["/usr/bin/python3", "-I", str(ENTRYPOINT), "verify-install"], maximum=4096,
                  extra_env={"SUDO_UID": str(EXPECTED_DEPLOY_UID)})
    if result.returncode != 0 or b'"result":"PASS"' not in result.stdout:
        _fail("positive_installation_probe_failed")
    try:
        payload = json.loads(result.stdout.decode("utf-8"))
    except (UnicodeError, ValueError):
        _fail("positive_installation_probe_response_invalid")
    if not isinstance(payload, dict) or payload.get("result") != "PASS" \
            or payload.get("profile") != "staging-next" or payload.get("helper_version") != VERSION \
            or payload.get("helper_contract") != CONTRACT \
            or payload.get("helper_artifact_sha256") != ARTIFACT_SHA256:
        _fail("positive_installation_probe_response_invalid")


if __name__ == "__main__":
    try:
        install()
    except InstallError as error:
        print(f"INSTALL=FAIL;CODE={error}")
        raise SystemExit(1)
