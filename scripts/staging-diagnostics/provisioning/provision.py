#!/usr/bin/env python3
"""Fail-closed installer for the fixed staging diagnostics operation."""

from __future__ import annotations

import argparse
import base64
import binascii
try:
    import fcntl
except ImportError:  # pragma: no cover - installer only runs on Linux
    fcntl = None
import grp
import hashlib
import os
import pwd
import re
import shutil
import stat
import subprocess
import sys
import tempfile
from dataclasses import dataclass
from pathlib import Path
from typing import Callable


DIAGNOSTICS_USER = "caregrid-diagnostics"
DIAGNOSTICS_GROUP = "caregrid-diagnostics"
DIAGNOSTICS_HOME = "/var/lib/caregrid-diagnostics"
DISPATCH_PATH = "/usr/local/libexec/wpcaregrid-staging-diagnostics-dispatch"
SHELL_PATH = "/usr/local/libexec/wpcaregrid-staging-diagnostics-shell"
HELPER_PATH = "/usr/local/libexec/wpcaregrid-staging-m12-readback.php"
RUNNER_PATH = "/usr/local/sbin/wpcaregrid-staging-m12-readback"
SUDOERS_PATH = "/etc/sudoers.d/wpcaregrid-staging-diagnostics"
STATE_PATH = "/var/lib/wpcaregrid-staging-diagnostics"
PENDING_PATH = f"{STATE_PATH}/provisioning.pending"
LOCK_PATH = "/run/lock/wpcaregrid-staging-diagnostics.lock"
APP_CACHE = "/var/www/wpcaregrid/bootstrap/cache/config.php"
APP_ENV = "/var/www/wpcaregrid/.env"

SOURCE_FILES = {
    DISPATCH_PATH: "scripts/staging-diagnostics/dispatch.sh",
    SHELL_PATH: "scripts/staging-diagnostics/provisioning/diagnostics-shell.sh",
    HELPER_PATH: "scripts/staging-diagnostics/read-m12-cache.php",
    RUNNER_PATH: "scripts/staging-diagnostics/provisioning/run-m12-readback.sh",
}

SUDOERS_CONTENT = (
    "Defaults:caregrid-diagnostics env_reset, !setenv\n"
    "caregrid-diagnostics ALL=(root) NOPASSWD: "
    "/usr/local/sbin/wpcaregrid-staging-m12-readback \"\"\n"
)

FAIL = "PROVISIONING_FAILED"


class ProvisionError(RuntimeError):
    """A fixed, safe provisioning failure."""


@dataclass(frozen=True)
class Layout:
    """Filesystem root; non-root values are used only by synthetic tests."""

    root: Path = Path("/")
    owner_uid: int = 0
    owner_gid: int = 0

    def path(self, absolute: str) -> Path:
        return self.root / absolute.lstrip("/")


def _key_blob_is_ed25519(encoded: str) -> bool:
    try:
        blob = base64.b64decode(encoded, validate=True)
    except (binascii.Error, ValueError):
        return False

    if len(blob) < 4:
        return False

    name_length = int.from_bytes(blob[:4], "big")
    name_end = 4 + name_length
    if name_end + 4 > len(blob) or blob[4:name_end] != b"ssh-ed25519":
        return False

    key_length = int.from_bytes(blob[name_end : name_end + 4], "big")
    return key_length == 32 and name_end + 4 + key_length == len(blob)


def parse_public_key(text: str) -> str:
    """Return a normalized single ED25519 key without its optional comment."""
    rows = text.splitlines()
    if len(rows) != 1:
        raise ProvisionError("PUBLIC_KEY_INVALID")

    fields = rows[0].strip().split()
    if len(fields) not in (2, 3) or fields[0] != "ssh-ed25519":
        raise ProvisionError("PUBLIC_KEY_INVALID")
    if not _key_blob_is_ed25519(fields[1]):
        raise ProvisionError("PUBLIC_KEY_INVALID")

    return f"ssh-ed25519 {fields[1]}"


def authorized_key_line(public_key: str) -> str:
    normalized = parse_public_key(public_key)
    return (
        'restrict,command="/usr/local/libexec/'
        'wpcaregrid-staging-diagnostics-dispatch" '
        f"{normalized} wpcaregrid-staging-diagnostics"
    )


def source_hashes(source_root: Path) -> dict[str, str]:
    result: dict[str, str] = {}
    for target, relative in SOURCE_FILES.items():
        content = (source_root / relative).read_bytes()
        result[target] = hashlib.sha256(content).hexdigest()
    result[SUDOERS_PATH] = hashlib.sha256(SUDOERS_CONTENT.encode()).hexdigest()
    return result


def verify_source_provenance(source_root: Path, approved_sha: str) -> dict[str, str]:
    if not re.fullmatch(r"[0-9a-f]{40}", approved_sha):
        raise ProvisionError("SOURCE_SHA_INVALID")

    def git(*args: str) -> str:
        completed = subprocess.run(
            ["git", "-C", str(source_root), *args],
            check=False,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            text=True,
        )
        if completed.returncode != 0:
            raise ProvisionError("SOURCE_PROVENANCE_INVALID")
        return completed.stdout.strip()

    if git("rev-parse", "--verify", "HEAD") != approved_sha:
        raise ProvisionError("SOURCE_SHA_MISMATCH")
    if git("status", "--porcelain", "--untracked-files=all"):
        raise ProvisionError("SOURCE_TREE_DIRTY")

    for relative in SOURCE_FILES.values():
        git("cat-file", "-e", f"{approved_sha}:{relative}")
        committed = subprocess.run(
            ["git", "-C", str(source_root), "show", f"{approved_sha}:{relative}"],
            check=False,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
        )
        working = (source_root / relative).read_bytes()
        if committed.returncode != 0 or committed.stdout != working:
            raise ProvisionError("SOURCE_FILE_MISMATCH")

    return source_hashes(source_root)


def _lstat(path: Path) -> os.stat_result | None:
    try:
        return path.lstat()
    except FileNotFoundError:
        return None


def validate_directory(path: Path, *, owner: int = 0, mode: int | None = None) -> None:
    info = _lstat(path)
    if info is None or not stat.S_ISDIR(info.st_mode) or info.st_uid != owner:
        raise ProvisionError("DIRECTORY_UNSAFE")
    if info.st_mode & 0o022:
        raise ProvisionError("DIRECTORY_WRITABLE_BY_UNTRUSTED_GROUP")
    if mode is not None and stat.S_IMODE(info.st_mode) != mode:
        raise ProvisionError("DIRECTORY_MODE_INVALID")


def atomic_write(
    path: Path,
    content: bytes,
    *,
    mode: int,
    uid: int = 0,
    gid: int = 0,
    replace: bool = False,
) -> None:
    """Atomically create or replace a regular file under a verified directory."""
    parent = path.parent
    validate_directory(parent, owner=uid)
    existing = _lstat(path)
    if existing is not None:
        if not stat.S_ISREG(existing.st_mode) or existing.st_uid != uid or existing.st_gid != gid:
            raise ProvisionError("FILE_CONFLICT")
        if not replace:
            if (
                existing.st_size == len(content)
                and path.read_bytes() == content
                and stat.S_IMODE(existing.st_mode) == mode
            ):
                return
            raise ProvisionError("FILE_CONFLICT")

    descriptor, temp_name = tempfile.mkstemp(prefix=".wpcg-diag-", dir=parent)
    temp_path = Path(temp_name)
    try:
        os.fchmod(descriptor, mode)
        os.fchown(descriptor, uid, gid)
        with os.fdopen(descriptor, "wb", closefd=True) as handle:
            handle.write(content)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temp_path, path)
        directory_fd = os.open(parent, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
        try:
            os.fsync(directory_fd)
        finally:
            os.close(directory_fd)
    except Exception:
        try:
            temp_path.unlink(missing_ok=True)
        except OSError:
            pass
        raise


def _read_os_release(path: Path = Path("/etc/os-release")) -> dict[str, str]:
    values: dict[str, str] = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key] = value.strip().strip('"').strip("'")
    if values.get("ID") not in {"debian", "ubuntu"}:
        raise ProvisionError("OPERATING_SYSTEM_UNSUPPORTED")
    return values


def _run_checked(command: list[str], *, input_text: str | None = None) -> str:
    completed = subprocess.run(
        command,
        input=input_text,
        check=False,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        text=True,
        env={**os.environ, "LC_ALL": "C"},
    )
    if completed.returncode != 0:
        raise ProvisionError("PREREQUISITE_CHECK_FAILED")
    return completed.stdout


def _check_sshd_effective_config() -> None:
    output = _run_checked(
        [
            "/usr/sbin/sshd",
            "-T",
            "-C",
            "user=caregrid-diagnostics,host=stg1.wpcaregrid.com,addr=127.0.0.1",
        ]
    )
    config = dict(line.split(None, 1) for line in output.splitlines() if " " in line)
    required = {
        "pubkeyauthentication": "yes",
        "passwordauthentication": "no",
        "kbdinteractiveauthentication": "no",
        "gssapiauthentication": "no",
        "hostbasedauthentication": "no",
        "authorizedkeyscommand": "none",
        "trustedusercakeys": "none",
        "forcecommand": "none",
    }
    if any(config.get(key) != value for key, value in required.items()):
        raise ProvisionError("SSHD_AUTH_POLICY_UNSAFE")
    if ".ssh/authorized_keys" not in config.get("authorizedkeysfile", "").split():
        raise ProvisionError("SSHD_AUTHORIZED_KEYS_PATH_UNSUPPORTED")
    if config.get("subsystem", "").lower().endswith("internal-sftp"):
        raise ProvisionError("SSHD_INTERNAL_SFTP_UNSAFE")


def _check_prerequisites() -> None:
    _read_os_release()
    required = (
        "/usr/bin/php",
        "/usr/bin/sudo",
        "/usr/bin/ssh-keygen",
        "/usr/bin/python3",
        "/usr/sbin/sshd",
        "/usr/sbin/useradd",
        "/usr/sbin/groupadd",
        "/usr/sbin/usermod",
        "/usr/sbin/visudo",
        "/usr/bin/getent",
        "/usr/bin/id",
    )
    if any(not Path(item).is_file() or not os.access(item, os.X_OK) for item in required):
        raise ProvisionError("PREREQUISITE_MISSING")
    _check_sshd_effective_config()
    fingerprint = _run_checked(
        [
            "/usr/bin/ssh-keygen",
            "-lf",
            "/etc/ssh/ssh_host_ed25519_key.pub",
            "-E",
            "sha256",
        ]
    ).split()
    if (
        len(fingerprint) < 2
        or fingerprint[1] != "SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ"
    ):
        raise ProvisionError("HOST_IDENTITY_MISMATCH")


def _check_application_cache_metadata() -> None:
    """Check only metadata; never read the Laravel cache or .env."""
    deploy = pwd.getpwnam("deploy")
    cache_path = Path(APP_CACHE)
    if cache_path.is_symlink() or os.path.realpath(cache_path) != str(cache_path):
        raise ProvisionError("CACHE_PATH_UNSAFE")
    cache = _lstat(cache_path)
    if (
        cache is None
        or not stat.S_ISREG(cache.st_mode)
        or cache.st_uid != deploy.pw_uid
        or cache.st_mode & 0o077
        or cache.st_size > 1_048_576
    ):
        raise ProvisionError("CACHE_METADATA_INVALID")


def _validate_key_file(key_path: Path) -> str:
    descriptor = -1
    try:
        descriptor = os.open(
            key_path,
            os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0),
        )
        info = os.fstat(descriptor)
        if (
            not stat.S_ISREG(info.st_mode)
            or info.st_uid != os.geteuid()
            or info.st_mode & 0o022
            or info.st_size > 16_384
        ):
            raise ProvisionError("PUBLIC_KEY_FILE_UNSAFE")
        with os.fdopen(descriptor, "rb", closefd=True) as handle:
            descriptor = -1
            content = handle.read(16_385)
        if len(content) > 16_384:
            raise ProvisionError("PUBLIC_KEY_FILE_UNSAFE")
        return parse_public_key(content.decode("ascii"))
    except ProvisionError:
        raise
    except (OSError, UnicodeError) as error:
        raise ProvisionError("PUBLIC_KEY_INVALID") from error
    finally:
        if descriptor >= 0:
            os.close(descriptor)


def _account_state() -> tuple[bool, int | None, int | None]:
    try:
        group = grp.getgrnam(DIAGNOSTICS_GROUP)
    except KeyError:
        group = None
    try:
        account = pwd.getpwnam(DIAGNOSTICS_USER)
    except KeyError:
        account = None

    if group is None and account is not None:
        raise ProvisionError("ACCOUNT_CONFLICT")
    if account is None:
        return False, group.gr_gid if group else None, None
    groups = _run_checked(["/usr/bin/id", "-G", DIAGNOSTICS_USER]).split()
    status = _run_checked(["/usr/bin/passwd", "-S", DIAGNOSTICS_USER]).split()
    validate_account_fields(
        group_gid=group.gr_gid if group else None,
        account_gid=account.pw_gid,
        home=account.pw_dir,
        shell=account.pw_shell,
        supplementary_groups={int(value) for value in groups},
        password_locked=len(status) >= 2 and status[1] == "L",
    )
    return True, group.gr_gid, account.pw_uid


def validate_account_fields(
    *,
    group_gid: int | None,
    account_gid: int,
    home: str,
    shell: str,
    supplementary_groups: set[int],
    password_locked: bool,
) -> None:
    if (
        group_gid is None
        or account_gid != group_gid
        or home != DIAGNOSTICS_HOME
        or shell != SHELL_PATH
    ):
        raise ProvisionError("ACCOUNT_CONFLICT")
    if supplementary_groups != {group_gid}:
        raise ProvisionError("ACCOUNT_GROUPS_UNSAFE")
    if not password_locked:
        raise ProvisionError("ACCOUNT_PASSWORD_NOT_LOCKED")


def _ensure_safe_target_directories(layout: Layout) -> None:
    paths = (
        "/usr/local/libexec",
        "/usr/local/sbin",
        "/etc/sudoers.d",
        "/var/lib",
        "/run",
        "/run/lock",
    )
    for item in paths:
        validate_directory(layout.path(item), owner=layout.owner_uid)


def _install_account(
    layout: Layout,
    group_exists: bool,
    uid: int | None,
    runner: Callable[[list[str]], str],
) -> None:
    home = layout.path(DIAGNOSTICS_HOME)
    if layout.root != Path("/"):
        home.mkdir(mode=0o755, parents=True, exist_ok=True)
        if home.is_symlink() or not home.is_dir():
            raise ProvisionError("ACCOUNT_HOME_UNSAFE")
        os.chown(home, layout.owner_uid, layout.owner_gid)
        os.chmod(home, 0o755)
        ssh_dir = home / ".ssh"
        ssh_dir.mkdir(mode=0o700, exist_ok=True)
        if ssh_dir.is_symlink() or not ssh_dir.is_dir():
            raise ProvisionError("SSH_DIRECTORY_UNSAFE")
        os.chown(ssh_dir, layout.owner_uid, layout.owner_gid)
        os.chmod(ssh_dir, 0o700)
        return
    home_info = _lstat(home)
    ssh_dir = home / ".ssh"
    ssh_info = _lstat(ssh_dir)
    if uid is None and home_info is not None:
        raise ProvisionError("ACCOUNT_HOME_CONFLICT")
    if uid is not None and (
        home_info is None
        or not stat.S_ISDIR(home_info.st_mode)
        or home_info.st_uid != 0
        or stat.S_IMODE(home_info.st_mode) != 0o755
        or (ssh_info is not None and (
            not stat.S_ISDIR(ssh_info.st_mode)
            or ssh_info.st_uid != 0
            or stat.S_IMODE(ssh_info.st_mode) != 0o700
        ))
    ):
        raise ProvisionError("ACCOUNT_HOME_UNSAFE")
    if not group_exists:
        runner(["/usr/sbin/groupadd", "--system", DIAGNOSTICS_GROUP])
    if uid is None:
        runner(
            [
                "/usr/sbin/useradd",
                "--system",
                "--gid",
                DIAGNOSTICS_GROUP,
                "--home-dir",
                DIAGNOSTICS_HOME,
                "--create-home",
                "--shell",
                SHELL_PATH,
                "--password",
                "!",
                DIAGNOSTICS_USER,
            ]
        )
        runner(["/usr/sbin/usermod", "--lock", DIAGNOSTICS_USER])
    if not home.is_dir() or home.is_symlink():
        raise ProvisionError("ACCOUNT_HOME_UNSAFE")
    os.chown(home, 0, 0)
    os.chmod(home, 0o755)
    ssh_dir.mkdir(mode=0o700, exist_ok=True)
    if ssh_dir.is_symlink() or not ssh_dir.is_dir():
        raise ProvisionError("SSH_DIRECTORY_UNSAFE")
    os.chown(ssh_dir, 0, 0)
    os.chmod(ssh_dir, 0o700)


def _check_parent_path(path: Path, layout: Layout) -> None:
    current = path.parent
    boundary = layout.root.resolve()
    while current != boundary:
        info = _lstat(current)
        if (
            info is None
            or not stat.S_ISDIR(info.st_mode)
            or info.st_uid != layout.owner_uid
        ):
            raise ProvisionError("DIRECTORY_UNSAFE")
        if info.st_mode & 0o022:
            raise ProvisionError("DIRECTORY_WRITABLE_BY_UNTRUSTED_GROUP")
        current = current.parent


def _write_install_file(path: Path, content: bytes, mode: int, layout: Layout) -> None:
    _check_parent_path(path, layout)
    atomic_write(
        path,
        content,
        mode=mode,
        uid=layout.owner_uid,
        gid=layout.owner_gid,
    )


def _validate_sudoers(
    path: Path, runner: Callable[[list[str]], str] = _run_checked
) -> None:
    runner(["/usr/sbin/visudo", "-cf", str(path)])


def validate_effective_sudoers_output(output: str) -> None:
    lines = [line.strip() for line in output.splitlines() if line.strip()]
    headers = [
        line for line in lines
        if line.startswith(f"User {DIAGNOSTICS_USER} may run the following commands on ")
    ]
    entries = [line for line in lines if line.startswith("(")]
    expected = '(root) NOPASSWD: /usr/local/sbin/wpcaregrid-staging-m12-readback ""'
    if len(headers) != 1 or entries != [expected]:
        raise ProvisionError("SUDOERS_EFFECTIVE_POLICY_UNSAFE")


def _check_effective_sudoers(runner: Callable[[list[str]], str] = _run_checked) -> None:
    output = runner(["/usr/bin/sudo", "-l", "-U", DIAGNOSTICS_USER])
    validate_effective_sudoers_output(output)


def _write_pending_marker(layout: Layout, approved_sha: str) -> None:
    state_dir = layout.path(STATE_PATH)
    if _lstat(state_dir) is None:
        state_dir.mkdir(mode=0o700)
        os.chown(state_dir, layout.owner_uid, layout.owner_gid)
    validate_directory(state_dir, owner=layout.owner_uid, mode=0o700)
    marker = layout.path(PENDING_PATH)
    if _lstat(marker) is not None:
        raise ProvisionError("RECOVERY_REQUIRED")
    atomic_write(
        marker,
        f"source={approved_sha}\n".encode(),
        mode=0o600,
        uid=layout.owner_uid,
        gid=layout.owner_gid,
    )


def _file_matches(
    path: Path, content: bytes, mode: int, owner_uid: int = 0, owner_gid: int = 0
) -> bool:
    info = _lstat(path)
    return bool(
        info
        and stat.S_ISREG(info.st_mode)
        and info.st_uid == owner_uid
        and info.st_gid == owner_gid
        and stat.S_IMODE(info.st_mode) == mode
        and info.st_size == len(content)
        and path.read_bytes() == content
    )


def _complete_install_exists(layout: Layout, files: dict[str, bytes], key_line: str) -> bool:
    install_files = {
        DISPATCH_PATH: (files[DISPATCH_PATH], 0o755),
        SHELL_PATH: (files[SHELL_PATH], 0o755),
        HELPER_PATH: (files[HELPER_PATH], 0o644),
        RUNNER_PATH: (files[RUNNER_PATH], 0o755),
        SUDOERS_PATH: (SUDOERS_CONTENT.encode(), 0o440),
    }
    if not all(
        _file_matches(
            layout.path(path), data, mode, layout.owner_uid, layout.owner_gid
        )
        for path, (data, mode) in install_files.items()
    ):
        return False
    home = layout.path(DIAGNOSTICS_HOME)
    ssh_dir = home / ".ssh"
    home_info = _lstat(home)
    ssh_info = _lstat(ssh_dir)
    if (
        home_info is None
        or ssh_info is None
        or not stat.S_ISDIR(home_info.st_mode)
        or not stat.S_ISDIR(ssh_info.st_mode)
        or home_info.st_uid != layout.owner_uid
        or ssh_info.st_uid != layout.owner_uid
        or stat.S_IMODE(home_info.st_mode) != 0o755
        or stat.S_IMODE(ssh_info.st_mode) != 0o700
    ):
        return False
    auth_path = ssh_dir / "authorized_keys"
    return _file_matches(
        auth_path,
        f"{key_line}\n".encode(),
        0o600,
        layout.owner_uid,
        layout.owner_gid,
    )


def install(
    *,
    layout: Layout,
    source_root: Path,
    approved_sha: str,
    public_key: str,
    runner: Callable[[list[str]], str] = _run_checked,
) -> None:
    if os.geteuid() != 0 and layout.root == Path("/"):
        raise ProvisionError("ROOT_REQUIRED")

    hashes = verify_source_provenance(source_root, approved_sha)
    files = {target: (source_root / relative).read_bytes() for target, relative in SOURCE_FILES.items()}
    if source_hashes(source_root) != hashes:
        raise ProvisionError("SOURCE_FILE_MISMATCH")

    key_line = authorized_key_line(public_key)
    _ensure_safe_target_directories(layout)
    if layout.root == Path("/"):
        _check_prerequisites()
        _check_application_cache_metadata()
        account_exists, gid, uid = _account_state()
    else:
        account_exists, gid, uid = False, None, None
    group_exists = gid is not None

    auth_path = layout.path(f"{DIAGNOSTICS_HOME}/.ssh/authorized_keys")
    existing_auth = _lstat(auth_path)
    if existing_auth and layout.root == Path("/") and not _file_matches(
        auth_path,
        f"{key_line}\n".encode(),
        0o600,
        layout.owner_uid,
        layout.owner_gid,
    ):
        raise ProvisionError("AUTHORIZED_KEYS_CONFLICT")

    if _complete_install_exists(layout, files, key_line):
        if _lstat(layout.path(PENDING_PATH)):
            raise ProvisionError("RECOVERY_REQUIRED")
        _validate_sudoers(layout.path(SUDOERS_PATH), runner)
        runner(["/usr/sbin/visudo", "-c"])
        return

    if layout.root != Path("/") and fcntl is None:
        raise ProvisionError("LOCKING_UNAVAILABLE")
    lock_file = layout.path(LOCK_PATH)
    with lock_file.open("a+b") as lock:
        if fcntl is not None:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        _write_pending_marker(layout, approved_sha)
        try:
            _write_install_file(layout.path(DISPATCH_PATH), files[DISPATCH_PATH], 0o755, layout)
            _write_install_file(layout.path(SHELL_PATH), files[SHELL_PATH], 0o755, layout)
            _write_install_file(layout.path(HELPER_PATH), files[HELPER_PATH], 0o644, layout)
            _write_install_file(layout.path(RUNNER_PATH), files[RUNNER_PATH], 0o755, layout)

            stage_dir = layout.path("/run")
            descriptor, staged_sudoers = tempfile.mkstemp(prefix="wpcg-sudoers-", dir=stage_dir)
            staged_path = Path(staged_sudoers)
            try:
                with os.fdopen(descriptor, "wb") as handle:
                    handle.write(SUDOERS_CONTENT.encode())
                    handle.flush()
                    os.fsync(handle.fileno())
                os.chmod(staged_path, 0o440)
                os.chown(staged_path, layout.owner_uid, layout.owner_gid)
                _validate_sudoers(staged_path, runner)
                sudoers = layout.path(SUDOERS_PATH)
                if _lstat(sudoers) is None:
                    os.replace(staged_path, sudoers)
                elif not _file_matches(
                    sudoers,
                    SUDOERS_CONTENT.encode(),
                    0o440,
                    layout.owner_uid,
                    layout.owner_gid,
                ):
                    raise ProvisionError("SUDOERS_CONFLICT")
            finally:
                staged_path.unlink(missing_ok=True)

            _install_account(layout, group_exists, uid, runner)
            if not account_exists and layout.root == Path("/"):
                # Re-read identity and insist the new account has the intended
                # primary group, shell, home, no supplementary groups, and a locked password.
                _account_state()

            _validate_sudoers(layout.path(SUDOERS_PATH), runner)
            if layout.root == Path("/"):
                _check_effective_sudoers()
            _write_install_file(auth_path, f"{key_line}\n".encode(), 0o600, layout)

            if not _complete_install_exists(layout, files, key_line):
                raise ProvisionError("POST_INSTALL_VERIFY_FAILED")
            runner(["/usr/sbin/visudo", "-c"])

            layout.path(PENDING_PATH).unlink()
        except Exception:
            # Leave the root-only journal for an explicit rollback/recovery.
            raise


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--approved-source-sha", required=True)
    parser.add_argument("--public-key-file", required=True, type=Path)
    args = parser.parse_args()

    try:
        source_root = Path(__file__).resolve().parents[3]
        key = _validate_key_file(args.public_key_file)
        install(
            layout=Layout(),
            source_root=source_root,
            approved_sha=args.approved_source_sha,
            public_key=key,
        )
    except ProvisionError as error:
        print(f"DIAGNOSTICS_PROVISIONING=FAILED code={error}")
        return 1
    except (KeyError, OSError, subprocess.SubprocessError):
        print(f"DIAGNOSTICS_PROVISIONING=FAILED code={FAIL}")
        return 1

    print("DIAGNOSTICS_PROVISIONING=INSTALLED_OR_ALREADY_VALID")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
