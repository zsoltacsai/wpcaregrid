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
import glob
import hashlib
import os
import pwd
import re
import shutil
import shlex
import stat
import subprocess
import sys
import tempfile
from dataclasses import dataclass
from contextlib import contextmanager
from functools import wraps
from pathlib import Path
from threading import local
from typing import Callable, Iterator


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
BOOTSTRAP_PENDING_PATH = f"{STATE_PATH}/provisioning.bootstrap-pending"
ACTIVE_PATH = f"{STATE_PATH}/provisioning.active"
LOCK_DIR_PATH = "/run/wpcaregrid-staging-diagnostics-lock"
LOCK_PATH = f"{LOCK_DIR_PATH}/lifecycle.lock"
APP_CACHE = "/var/www/wpcaregrid/bootstrap/cache/config.php"
APP_ENV = "/var/www/wpcaregrid/.env"
TRUSTED_SOURCE_PARENT = Path("/var/lib/wpcaregrid-staging-diagnostics-provisioning/source")
PROVENANCE_PATH = ".wpcaregrid-provenance"

# This is the exact helper shipped by the previously installed P3 source
# revision c63537186d932c9f1a736a19a7ecaecef01b25d7. Permit only this
# root-owned, correctly-moded legacy helper to be atomically upgraded;
# unexpected files at managed paths remain conflicts.
LEGACY_INSTALL_FILE_SHA256 = {
    HELPER_PATH: frozenset(
        {"0bc2a28f2df132c06de1de269cc3996c60625e1d758bc7554ea630e2e9bd4dce"}
    ),
}

ACTIVATION_CHECK_PATH = "/usr/local/libexec/wpcaregrid-staging-diagnostics-activation-check"

SOURCE_FILES = {
    DISPATCH_PATH: "scripts/staging-diagnostics/dispatch.sh",
    ACTIVATION_CHECK_PATH: "scripts/staging-diagnostics/provisioning/activation-check.sh",
    SHELL_PATH: "scripts/staging-diagnostics/provisioning/diagnostics-shell.sh",
    HELPER_PATH: "scripts/staging-diagnostics/read-m12-cache.php",
    RUNNER_PATH: "scripts/staging-diagnostics/provisioning/run-m12-readback.sh",
}

TRUSTED_PACKAGE_FILES = tuple(dict.fromkeys((
    *SOURCE_FILES.values(),
    "scripts/staging-diagnostics/provisioning/provision.py",
    "scripts/staging-diagnostics/provisioning/verify.py",
    "scripts/staging-diagnostics/provisioning/rollback.py",
    "scripts/staging-diagnostics/provisioning/bootstrap.py",
)))

SUDOERS_CONTENT = (
    "Defaults:caregrid-diagnostics env_reset, !setenv\n"
    "caregrid-diagnostics ALL=(root) NOPASSWD: "
    "/usr/local/sbin/wpcaregrid-staging-m12-readback \"\"\n"
)

FAIL = "PROVISIONING_FAILED"
_LIFECYCLE_STATE = local()


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


def verify_source_provenance(
    source_root: Path, approved_sha: str, *, trusted_execution: bool = False
) -> dict[str, str]:
    if not re.fullmatch(r"[0-9a-f]{40}", approved_sha):
        raise ProvisionError("SOURCE_SHA_INVALID")
    if trusted_execution:
        return _verify_trusted_source_package(source_root, approved_sha)

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


def _verify_trusted_source_package(
    source_root: Path,
    approved_sha: str,
    *,
    owner_uid: int = 0,
    owner_gid: int = 0,
    enforce_system_path: bool = True,
) -> dict[str, str]:
    """Verify an immutable root-owned package before installation mutations."""
    expected_root = TRUSTED_SOURCE_PARENT / approved_sha
    if source_root != expected_root:
        raise ProvisionError("TRUSTED_SOURCE_PATH_UNSAFE")

    current = source_root
    while current != Path("/"):
        info = _lstat(current)
        if (
            info is None
            or not stat.S_ISDIR(info.st_mode)
            or info.st_uid != owner_uid
            or info.st_gid != owner_gid
            or info.st_mode & 0o022
        ):
            raise ProvisionError("TRUSTED_SOURCE_DIRECTORY_UNSAFE")
        if not enforce_system_path and current == TRUSTED_SOURCE_PARENT.parent:
            break
        current = current.parent

    manifest_path = source_root / PROVENANCE_PATH
    manifest_info = _lstat(manifest_path)
    if (
        manifest_info is None
        or not stat.S_ISREG(manifest_info.st_mode)
        or manifest_info.st_uid != owner_uid
        or manifest_info.st_gid != owner_gid
        or stat.S_IMODE(manifest_info.st_mode) != 0o444
        or manifest_info.st_size > 4096
    ):
        raise ProvisionError("TRUSTED_SOURCE_MANIFEST_UNSAFE")

    try:
        lines = manifest_path.read_text(encoding="ascii").splitlines()
    except (OSError, UnicodeError):
        raise ProvisionError("TRUSTED_SOURCE_MANIFEST_INVALID") from None
    if not lines or lines[0] != f"commit={approved_sha}":
        raise ProvisionError("SOURCE_SHA_MISMATCH")
    if (
        len(lines) != len(TRUSTED_PACKAGE_FILES) + 2
        or not re.fullmatch(r"tree=[0-9a-f]{40}", lines[1])
    ):
        raise ProvisionError("TRUSTED_SOURCE_MANIFEST_INVALID")

    hashes: dict[str, str] = {}
    for line, relative in zip(lines[2:], TRUSTED_PACKAGE_FILES, strict=True):
        match = re.fullmatch(
            r"([0-9a-f]{40}) ([0-9a-f]{64})  ([A-Za-z0-9_./-]+)", line
        )
        if (
            not match
            or match.group(3) != relative
            or relative.startswith("/")
            or ".." in Path(relative).parts
        ):
            raise ProvisionError("TRUSTED_SOURCE_MANIFEST_INVALID")
        path = source_root / relative
        info = _lstat(path)
        parents = [path.parent]
        while parents[-1] != source_root:
            parents.append(parents[-1].parent)
        for parent in parents:
            parent_info = _lstat(parent)
            if (
                parent_info is None
                or not stat.S_ISDIR(parent_info.st_mode)
                or parent_info.st_uid != owner_uid
                or parent_info.st_gid != owner_gid
                or parent_info.st_mode & 0o022
            ):
                raise ProvisionError("TRUSTED_SOURCE_DIRECTORY_UNSAFE")
        if (
            info is None
            or not stat.S_ISREG(info.st_mode)
            or info.st_uid != owner_uid
            or info.st_gid != owner_gid
            or info.st_mode & 0o022
            or path.resolve() != path
        ):
            raise ProvisionError("TRUSTED_SOURCE_FILE_UNSAFE")
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
        if digest != match.group(2):
            raise ProvisionError("SOURCE_FILE_MISMATCH")
        hashes[relative] = digest

    return {
        target: hashes[relative] for target, relative in SOURCE_FILES.items()
    } | {SUDOERS_PATH: hashlib.sha256(SUDOERS_CONTENT.encode()).hexdigest()}


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


@contextmanager
def lifecycle_lock(
    layout: Layout, *, blocking: bool, shared: bool = False
) -> Iterator[None]:
    """Serialize provisioning lifecycle operations in a private runtime directory."""
    depth = getattr(_LIFECYCLE_STATE, "depth", 0)
    if depth:
        identity = (layout.root, layout.owner_uid, layout.owner_gid)
        if identity != getattr(_LIFECYCLE_STATE, "identity", None):
            raise ProvisionError("LIFECYCLE_LOCK_REENTRANT_SCOPE")
        if getattr(_LIFECYCLE_STATE, "shared", False) and not shared:
            raise ProvisionError("LIFECYCLE_LOCK_REENTRANT_SCOPE")
        _LIFECYCLE_STATE.depth = depth + 1
        try:
            yield
        finally:
            _LIFECYCLE_STATE.depth -= 1
        return

    if fcntl is None:
        raise ProvisionError("LOCKING_UNAVAILABLE")

    run_dir = layout.path("/run")
    validate_directory(run_dir, owner=layout.owner_uid)
    for parent in (run_dir, layout.root):
        parent_info = _lstat(parent)
        if (
            parent_info is None
            or not stat.S_ISDIR(parent_info.st_mode)
            or parent_info.st_uid != layout.owner_uid
            or parent_info.st_gid != layout.owner_gid
            or parent_info.st_mode & 0o022
        ):
            raise ProvisionError("LIFECYCLE_LOCK_PARENT_UNSAFE")
    lock_dir = layout.path(LOCK_DIR_PATH)
    info = _lstat(lock_dir)
    if info is None:
        try:
            lock_dir.mkdir(mode=0o700)
            os.chown(lock_dir, layout.owner_uid, layout.owner_gid)
            os.chmod(lock_dir, 0o700)
        except FileExistsError:
            pass
        except OSError:
            raise ProvisionError("LIFECYCLE_LOCK_DIRECTORY_UNSAFE") from None
    lock_dir_info = _lstat(lock_dir)
    if (
        lock_dir_info is None
        or not stat.S_ISDIR(lock_dir_info.st_mode)
        or lock_dir_info.st_uid != layout.owner_uid
        or lock_dir_info.st_gid != layout.owner_gid
        or stat.S_IMODE(lock_dir_info.st_mode) != 0o700
    ):
        raise ProvisionError("LIFECYCLE_LOCK_DIRECTORY_UNSAFE")
    lock_path = layout.path(LOCK_PATH)
    flags = os.O_CREAT | os.O_RDWR | getattr(os, "O_CLOEXEC", 0) | getattr(os, "O_NOFOLLOW", 0)
    try:
        descriptor = os.open(lock_path, flags, 0o600)
    except OSError:
        raise ProvisionError("LIFECYCLE_LOCK_UNSAFE") from None
    try:
        lock_info = os.fstat(descriptor)
        path_info = _lstat(lock_path)
        if (
            not stat.S_ISREG(lock_info.st_mode)
            or lock_info.st_uid != layout.owner_uid
            or lock_info.st_gid != layout.owner_gid
            or stat.S_IMODE(lock_info.st_mode) != 0o600
            or path_info is None
            or not stat.S_ISREG(path_info.st_mode)
            or path_info.st_dev != lock_info.st_dev
            or path_info.st_ino != lock_info.st_ino
        ):
            raise ProvisionError("LIFECYCLE_LOCK_UNSAFE")
        operation = fcntl.LOCK_SH if shared else fcntl.LOCK_EX
        if not blocking:
            operation |= fcntl.LOCK_NB
        try:
            fcntl.flock(descriptor, operation)
        except BlockingIOError:
            raise ProvisionError("LIFECYCLE_LOCK_BUSY") from None
        except OSError:
            raise ProvisionError("LIFECYCLE_LOCK_FAILED") from None
        _LIFECYCLE_STATE.identity = (layout.root, layout.owner_uid, layout.owner_gid)
        _LIFECYCLE_STATE.shared = shared
        _LIFECYCLE_STATE.depth = 1
        yield
    finally:
        _LIFECYCLE_STATE.depth = 0
        _LIFECYCLE_STATE.identity = None
        _LIFECYCLE_STATE.shared = False
        os.close(descriptor)


def serialized_lifecycle(*, blocking: bool, shared: bool = False):
    """Hold the common lock for a full installer, verifier, or rollback call."""
    def decorate(function):
        @wraps(function)
        def wrapped(*args, **kwargs):
            layout = kwargs.get("layout")
            if layout is None and args and isinstance(args[0], Layout):
                layout = args[0]
            if layout is None:
                layout = Layout()
            with lifecycle_lock(layout, blocking=blocking, shared=shared):
                return function(*args, **kwargs)
        return wrapped
    return decorate


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


def _sshd_config_has_unsafe_match(
    root_config: Path = Path("/etc/ssh/sshd_config"),
    *,
    owner_uid: int = 0,
    owner_gid: int = 0,
) -> bool:
    """Reject Match variation after recursively inspecting every Include."""
    ssh_root = root_config.parent.resolve()
    visited: set[Path] = set()

    def inspect(path: Path) -> bool:
        absolute = path.absolute()
        if absolute in visited:
            return False
        if not absolute.is_relative_to(ssh_root):
            raise ProvisionError("SSHD_INCLUDE_PATH_UNSAFE")
        visited.add(absolute)
        try:
            info = absolute.lstat()
            if (
                not stat.S_ISREG(info.st_mode)
                or info.st_uid != owner_uid
                or info.st_gid != owner_gid
                or info.st_mode & 0o022
                or info.st_size > 1_048_576
                or absolute.resolve(strict=True) != absolute
            ):
                raise ProvisionError("SSHD_CONFIG_FILE_UNSAFE")
            text = absolute.read_text(encoding="utf-8")
        except ProvisionError:
            raise
        except (OSError, UnicodeError):
            raise ProvisionError("SSHD_CONFIG_READ_FAILED") from None

        for raw_line in text.splitlines():
            try:
                fields = shlex.split(raw_line, comments=True, posix=True)
            except ValueError:
                raise ProvisionError("SSHD_CONFIG_SYNTAX_UNSUPPORTED") from None
            if not fields:
                continue
            directive = fields[0].lower()
            if directive == "match":
                # Runner source addresses are dynamic; any Match block is refused.
                return True
            if directive != "include":
                continue
            if len(fields) < 2:
                raise ProvisionError("SSHD_INCLUDE_UNRESOLVED")
            for pattern in fields[1:]:
                include_pattern = Path(pattern)
                if not include_pattern.is_absolute():
                    include_pattern = ssh_root / include_pattern
                if ".." in include_pattern.parts or not include_pattern.absolute().is_relative_to(ssh_root):
                    raise ProvisionError("SSHD_INCLUDE_PATH_UNSAFE")
                matches = sorted(Path(item) for item in glob.glob(str(include_pattern)))
                if not matches:
                    raise ProvisionError("SSHD_INCLUDE_UNRESOLVED")
                for match in matches:
                    try:
                        if match.resolve(strict=True) != match.absolute():
                            raise ProvisionError("SSHD_INCLUDE_PATH_UNSAFE")
                    except OSError:
                        raise ProvisionError("SSHD_INCLUDE_UNRESOLVED") from None
                    if inspect(match):
                        return True
        return False

    return inspect(root_config)


def _check_sshd_effective_config() -> None:
    if _sshd_config_has_unsafe_match():
        raise ProvisionError("SSHD_MATCH_POLICY_UNPROVABLE")
    output = _run_checked(
        [
            "/usr/sbin/sshd",
            "-T",
            "-C",
            "user=caregrid-diagnostics,host=stg1.wpcaregrid.com,addr=198.51.100.10",
        ]
    )
    validate_sshd_effective_config(output)


def validate_sshd_effective_config(output: str) -> None:
    config = dict(line.split(None, 1) for line in output.splitlines() if " " in line)
    required = {
        "usepam": "yes",
        "pubkeyauthentication": "yes",
        "passwordauthentication": "no",
        "kbdinteractiveauthentication": "no",
        "gssapiauthentication": "no",
        "hostbasedauthentication": "no",
        "authorizedkeyscommand": "none",
        "trustedusercakeys": "none",
        "forcecommand": "none",
        "permituserenvironment": "no",
    }
    if any(config.get(key) != value for key, value in required.items()):
        raise ProvisionError("SSHD_AUTH_POLICY_UNSAFE")
    if ".ssh/authorized_keys" not in config.get("authorizedkeysfile", "").split():
        raise ProvisionError("SSHD_AUTHORIZED_KEYS_PATH_UNSUPPORTED")
    if config.get("subsystem", "").lower().endswith("internal-sftp"):
        raise ProvisionError("SSHD_INTERNAL_SFTP_UNSAFE")

    # sshd's Allow*/Deny* directives are part of authentication, not channel
    # restrictions.  A successful installation must not report readiness when
    # the diagnostic account is excluded by an administrator's access list.
    # Only literal principals are accepted here: host-qualified, wildcard and
    # negated patterns depend on connection context or OpenSSH pattern
    # precedence and cannot be proven for the GitHub runner's changing egress.
    access_lists = {
        "allowusers": (DIAGNOSTICS_USER,),
        "allowgroups": (DIAGNOSTICS_GROUP,),
        "denyusers": (DIAGNOSTICS_USER,),
        "denygroups": (DIAGNOSTICS_GROUP,),
    }
    for directive, principals in access_lists.items():
        configured = config.get(directive, "").split()
        for pattern in configured:
            if (
                pattern.startswith("!")
                or "@" in pattern
                or any(character in pattern for character in "*?[]")
            ):
                raise ProvisionError("SSHD_ACCESS_POLICY_UNPROVABLE")
        if directive.startswith("allow") and configured and not any(
            principal in configured for principal in principals
        ):
            raise ProvisionError("SSHD_ACCESS_POLICY_DENIES_ACCOUNT")
        if directive.startswith("deny") and any(
            principal in configured for principal in principals
        ):
            raise ProvisionError("SSHD_ACCESS_POLICY_DENIES_ACCOUNT")


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
        "/usr/bin/getfacl",
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


def _validate_application_cache_acl(mode: int, output: str) -> None:
    """Allow only owner-only access or the exact PHP-FPM named-user ACL."""
    entries = tuple(line for line in output.splitlines() if line)
    owner_only = ("user::rw-", "group::---", "other::---")
    php_fpm_read = (
        "user::rw-",
        "user:www-data:r--",
        "group::---",
        "mask::r--",
        "other::---",
    )
    permissions = stat.S_IMODE(mode)
    if (permissions == 0o600 and entries == owner_only) or (
        permissions == 0o640 and entries == php_fpm_read
    ):
        return
    raise ProvisionError("CACHE_METADATA_INVALID")


def _read_acl_for_descriptor(descriptor: int, mode: int) -> None:
    try:
        result = subprocess.run(
            ["/usr/bin/getfacl", "-cp", "--", f"/proc/self/fd/{descriptor}"],
            check=False,
            capture_output=True,
            text=True,
            timeout=5,
            env={"LC_ALL": "C", "PATH": "/usr/bin:/bin"},
            pass_fds=(descriptor,),
        )
    except (OSError, subprocess.SubprocessError, UnicodeError):
        raise ProvisionError("CACHE_METADATA_INVALID") from None
    if result.returncode != 0 or result.stderr or len(result.stdout) > 512:
        raise ProvisionError("CACHE_METADATA_INVALID")
    _validate_application_cache_acl(mode, result.stdout)


def _check_application_cache_metadata() -> None:
    """Check cache path, ownership, size and ACL without reading cache data."""
    deploy = pwd.getpwnam("deploy")
    cache_path = Path(APP_CACHE)
    if cache_path.is_symlink() or os.path.realpath(cache_path) != str(cache_path):
        raise ProvisionError("CACHE_PATH_UNSAFE")
    try:
        descriptor = os.open(
            cache_path,
            os.O_RDONLY | getattr(os, "O_CLOEXEC", 0) | getattr(os, "O_NOFOLLOW", 0),
        )
    except OSError:
        raise ProvisionError("CACHE_METADATA_INVALID") from None
    try:
        cache = os.fstat(descriptor)
        current_path = _lstat(cache_path)
        if (
            current_path is None
            or not stat.S_ISREG(cache.st_mode)
            or not stat.S_ISREG(current_path.st_mode)
            or current_path.st_dev != cache.st_dev
            or current_path.st_ino != cache.st_ino
            or cache.st_uid != deploy.pw_uid
            or cache.st_gid != deploy.pw_gid
            or not cache.st_mode & 0o400
            or cache.st_mode & 0o007
            or cache.st_size > 1_048_576
        ):
            raise ProvisionError("CACHE_METADATA_INVALID")
        _read_acl_for_descriptor(descriptor, cache.st_mode)
    finally:
        os.close(descriptor)


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
        ssh_dir.mkdir(mode=0o711, exist_ok=True)
        if ssh_dir.is_symlink() or not ssh_dir.is_dir():
            raise ProvisionError("SSH_DIRECTORY_UNSAFE")
        os.chown(ssh_dir, layout.owner_uid, layout.owner_gid)
        os.chmod(ssh_dir, 0o711)
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
            or stat.S_IMODE(ssh_info.st_mode) != 0o711
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
    ssh_dir.mkdir(mode=0o711, exist_ok=True)
    if ssh_dir.is_symlink() or not ssh_dir.is_dir():
        raise ProvisionError("SSH_DIRECTORY_UNSAFE")
    os.chown(ssh_dir, 0, 0)
    os.chmod(ssh_dir, 0o711)


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
    replace_legacy = False
    existing = _lstat(path)
    if existing is not None:
        try:
            managed_path = "/" + path.relative_to(layout.root).as_posix()
        except ValueError:
            managed_path = ""
        accepted_hashes = LEGACY_INSTALL_FILE_SHA256.get(managed_path, frozenset())
        if (
            stat.S_ISREG(existing.st_mode)
            and existing.st_uid == layout.owner_uid
            and existing.st_gid == layout.owner_gid
            and stat.S_IMODE(existing.st_mode) == mode
        ):
            try:
                existing_hash = hashlib.sha256(path.read_bytes()).hexdigest()
            except OSError:
                existing_hash = ""
            replace_legacy = existing_hash in accepted_hashes
    atomic_write(
        path,
        content,
        mode=mode,
        uid=layout.owner_uid,
        gid=layout.owner_gid,
        replace=replace_legacy,
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


def _ensure_state_directory(layout: Layout) -> Path:
    state_dir = layout.path(STATE_PATH)
    created = _lstat(state_dir) is None
    if created:
        state_dir.mkdir(mode=0o711)
        os.chown(state_dir, layout.owner_uid, layout.owner_gid)
        os.chmod(state_dir, 0o711)
    boundary = layout.root.resolve()
    current = state_dir
    state_info = None
    while True:
        info = _lstat(current)
        if (
            info is None
            or not stat.S_ISDIR(info.st_mode)
            or info.st_uid != layout.owner_uid
            or info.st_gid != layout.owner_gid
            or info.st_mode & 0o022
        ):
            raise ProvisionError("ACTIVATION_PARENT_UNSAFE")
        if current == state_dir:
            state_info = info
        if current == boundary:
            break
        current = current.parent
    if state_info is not None and stat.S_IMODE(state_info.st_mode) != 0o711:
        os.chmod(state_dir, 0o711)
    return state_dir


def _write_pending_marker(layout: Layout, approved_sha: str) -> None:
    state_dir = _ensure_state_directory(layout)
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


def _activation_content(approved_sha: str) -> bytes:
    if not re.fullmatch(r"[0-9a-f]{40}", approved_sha):
        raise ProvisionError("SOURCE_SHA_INVALID")
    return f"active={approved_sha}\n".encode("ascii")


def _activation_is_valid(layout: Layout, approved_sha: str) -> bool:
    try:
        _ensure_state_directory(layout)
    except (ProvisionError, OSError):
        return False
    if _lstat(layout.path(PENDING_PATH)) is not None:
        return False
    return _file_matches(
        layout.path(ACTIVE_PATH),
        _activation_content(approved_sha),
        0o444,
        layout.owner_uid,
        layout.owner_gid,
    )


def _deactivate(layout: Layout) -> None:
    _ensure_state_directory(layout)
    activation = layout.path(ACTIVE_PATH)
    # unlink removes a symlink itself without following it. The parent is root-owned.
    if _lstat(activation) is not None:
        activation.unlink()


def _activate(layout: Layout, approved_sha: str) -> None:
    _ensure_state_directory(layout)
    atomic_write(
        layout.path(ACTIVE_PATH),
        _activation_content(approved_sha),
        mode=0o444,
        uid=layout.owner_uid,
        gid=layout.owner_gid,
        replace=True,
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
        ACTIVATION_CHECK_PATH: (files[ACTIVATION_CHECK_PATH], 0o755),
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
        or stat.S_IMODE(ssh_info.st_mode) != 0o711
    ):
        return False
    auth_path = ssh_dir / "authorized_keys"
    return _file_matches(
        auth_path,
        f"{key_line}\n".encode(),
        0o444,
        layout.owner_uid,
        layout.owner_gid,
    )


@serialized_lifecycle(blocking=False)
def install(
    *,
    layout: Layout,
    source_root: Path,
    approved_sha: str,
    public_key: str,
    runner: Callable[[list[str]], str] = _run_checked,
    checkpoint: Callable[[str], None] | None = None,
) -> None:
    if os.geteuid() != 0 and layout.root == Path("/"):
        raise ProvisionError("ROOT_REQUIRED")
    hashes = verify_source_provenance(
        source_root,
        approved_sha,
        trusted_execution=os.geteuid() == 0 and layout.root == Path("/"),
    )
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
        0o444,
        layout.owner_uid,
        layout.owner_gid,
    ):
        raise ProvisionError("AUTHORIZED_KEYS_CONFLICT")

    if _complete_install_exists(layout, files, key_line):
        if _lstat(layout.path(PENDING_PATH)) or not _activation_is_valid(layout, approved_sha):
            raise ProvisionError("RECOVERY_REQUIRED")
        _validate_sudoers(layout.path(SUDOERS_PATH), runner)
        runner(["/usr/sbin/visudo", "-c"])
        _check_effective_sudoers(runner)
        return

    if layout.root != Path("/") and fcntl is None:
        raise ProvisionError("LOCKING_UNAVAILABLE")
    with lifecycle_lock(layout, blocking=False):
        _write_pending_marker(layout, approved_sha)
        try:
            _deactivate(layout)
            _write_install_file(layout.path(DISPATCH_PATH), files[DISPATCH_PATH], 0o755, layout)
            _write_install_file(layout.path(ACTIVATION_CHECK_PATH), files[ACTIVATION_CHECK_PATH], 0o755, layout)
            _write_install_file(layout.path(SHELL_PATH), files[SHELL_PATH], 0o755, layout)
            _write_install_file(layout.path(HELPER_PATH), files[HELPER_PATH], 0o644, layout)
            _write_install_file(layout.path(RUNNER_PATH), files[RUNNER_PATH], 0o755, layout)

            sudoers = layout.path(SUDOERS_PATH)
            # Keep the temporary file on the destination filesystem.  On the
            # staging hosts /run is a separate tmpfs, so replacing from there
            # into /etc/sudoers.d fails with EXDEV (cross-device rename).
            stage_dir = sudoers.parent
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
                if _lstat(sudoers) is None:
                    try:
                        os.replace(staged_path, sudoers)
                    except OSError:
                        raise ProvisionError("SUDOERS_INSTALL_FAILED") from None
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

            if checkpoint:
                checkpoint("after_sudoers_installation")
            _install_account(layout, group_exists, uid, runner)
            if not account_exists and layout.root == Path("/"):
                # Re-read identity and insist the new account has the intended
                # primary group, shell, home, no supplementary groups, and a locked password.
                _account_state()

            _validate_sudoers(layout.path(SUDOERS_PATH), runner)
            _check_effective_sudoers(runner)
            _write_install_file(auth_path, f"{key_line}\n".encode(), 0o444, layout)
            if checkpoint:
                checkpoint("after_authorized_keys_installation")

            if not _complete_install_exists(layout, files, key_line):
                raise ProvisionError("POST_INSTALL_VERIFY_FAILED")
            runner(["/usr/sbin/visudo", "-c"])
            if checkpoint:
                checkpoint("after_final_verification")
                checkpoint("before_activation_preparation")

            _activate(layout, approved_sha)
            if checkpoint:
                checkpoint("after_activation_preparation")
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
