#!/usr/bin/env python3
"""Verify the installed diagnostics boundary without reading the app cache."""

from __future__ import annotations

import argparse
import grp
import os
import pwd
import stat
import subprocess
import sys
from pathlib import Path

# With Python -I -S, import only from this root-owned package directory.
_PACKAGE_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(_PACKAGE_DIR))
import provision
from provision import (
    DIAGNOSTICS_GROUP,
    DIAGNOSTICS_HOME,
    DIAGNOSTICS_USER,
    HELPER_PATH,
    _activation_is_valid,
    PENDING_PATH,
    RUNNER_PATH,
    SHELL_PATH,
    SOURCE_FILES,
    SUDOERS_CONTENT,
    SUDOERS_PATH,
    Layout,
    ProvisionError,
    _check_sshd_effective_config,
    _lstat,
    _run_checked,
    authorized_key_line,
    parse_public_key,
    source_hashes,
    validate_effective_sudoers_output,
    verify_source_provenance,
)


def _require_file(path: Path, expected: bytes, mode: int) -> None:
    info = _lstat(path)
    if (
        info is None
        or not stat.S_ISREG(info.st_mode)
        or info.st_uid != 0
        or info.st_gid != 0
        or stat.S_IMODE(info.st_mode) != mode
        or info.st_size != len(expected)
        or path.read_bytes() != expected
    ):
        raise ProvisionError("INSTALLED_FILE_INVALID")


def _require_rooted_directory(path: Path) -> None:
    current = path
    while current != current.parent:
        info = _lstat(current)
        if (
            info is None
            or not stat.S_ISDIR(info.st_mode)
            or info.st_uid != 0
            or info.st_mode & 0o022
        ):
            raise ProvisionError("DIRECTORY_UNSAFE")
        current = current.parent


def _require_cache_metadata() -> None:
    deploy = pwd.getpwnam("deploy")
    cache_path = Path(provision.APP_CACHE)
    cache = _lstat(cache_path)
    if (
        cache is None
        or cache_path.is_symlink()
        or os.path.realpath(cache_path) != str(cache_path)
        or not stat.S_ISREG(cache.st_mode)
        or cache.st_uid != deploy.pw_uid
        or cache.st_mode & 0o077
        or cache.st_size > 1_048_576
    ):
        raise ProvisionError("CACHE_METADATA_INVALID")

    # Check the Laravel environment file's metadata only. Never open it.
    env_path = Path(provision.APP_ENV)
    env_file = _lstat(env_path)
    if (
        env_file is None
        or env_path.is_symlink()
        or os.path.realpath(env_path) != str(env_path)
        or not stat.S_ISREG(env_file.st_mode)
        or env_file.st_uid != deploy.pw_uid
        or env_file.st_mode & 0o077
    ):
        raise ProvisionError("APPLICATION_SECRET_PERMISSIONS_UNSAFE")


def _verify_effective_sudoers() -> None:
    """Require sudo's effective listing to contain exactly the fixed no-arg rule."""
    completed = subprocess.run(
        ["/usr/bin/sudo", "-l", "-U", DIAGNOSTICS_USER],
        check=False,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        text=True,
        env={**os.environ, "LC_ALL": "C"},
    )
    if completed.returncode != 0:
        raise ProvisionError("SUDOERS_EFFECTIVE_POLICY_UNAVAILABLE")
    provision.validate_effective_sudoers_output(completed.stdout)


def verify(source_root: Path, approved_sha: str, public_key: str) -> None:
    if os.geteuid() != 0:
        raise ProvisionError("ROOT_REQUIRED")

    hashes = verify_source_provenance(source_root, approved_sha, trusted_execution=True)
    if source_hashes(source_root) != hashes:
        raise ProvisionError("SOURCE_FILE_MISMATCH")

    user = pwd.getpwnam(DIAGNOSTICS_USER)
    group = grp.getgrnam(DIAGNOSTICS_GROUP)
    if user.pw_gid != group.gr_gid or user.pw_dir != DIAGNOSTICS_HOME or user.pw_shell != SHELL_PATH:
        raise ProvisionError("ACCOUNT_IDENTITY_INVALID")
    groups = _run_checked(["/usr/bin/id", "-G", DIAGNOSTICS_USER]).split()
    if groups != [str(group.gr_gid)]:
        raise ProvisionError("ACCOUNT_GROUPS_UNSAFE")
    password = _run_checked(["/usr/bin/passwd", "-S", DIAGNOSTICS_USER]).split()
    if len(password) < 2 or password[1] != "L":
        raise ProvisionError("ACCOUNT_PASSWORD_NOT_LOCKED")

    home = Path(DIAGNOSTICS_HOME)
    ssh_directory = home / ".ssh"
    _require_rooted_directory(home)
    _require_rooted_directory(ssh_directory)
    if stat.S_IMODE(home.stat().st_mode) != 0o755 or stat.S_IMODE(ssh_directory.stat().st_mode) != 0o711:
        raise ProvisionError("ACCOUNT_HOME_PERMISSIONS_INVALID")

    key_line = authorized_key_line(parse_public_key(public_key))
    _require_file(ssh_directory / "authorized_keys", f"{key_line}\n".encode(), 0o444)

    for target, relative in SOURCE_FILES.items():
        source = (source_root / relative).read_bytes()
        path = Path(target)
        mode = 0o644 if target == HELPER_PATH else 0o755
        _require_rooted_directory(path.parent)
        _require_file(path, source, mode)

    _require_file(Path(SUDOERS_PATH), SUDOERS_CONTENT.encode(), 0o440)
    _run_checked(["/usr/sbin/visudo", "-cf", SUDOERS_PATH])
    _run_checked(["/usr/sbin/visudo", "-c"])
    _verify_effective_sudoers()
    _check_sshd_effective_config()

    deploy = pwd.getpwnam("deploy")
    if deploy.pw_gid == group.gr_gid or group.gr_gid in {
        int(item) for item in _run_checked(["/usr/bin/id", "-G", "deploy"]).split()
    }:
        raise ProvisionError("DIAGNOSTICS_GROUP_SHARED")

    _require_cache_metadata()

    host_key = Path("/etc/ssh/ssh_host_ed25519_key.pub")
    fingerprint = _run_checked(["/usr/bin/ssh-keygen", "-lf", str(host_key), "-E", "sha256"]).split()
    if len(fingerprint) < 2 or fingerprint[1] != "SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ":
        raise ProvisionError("HOST_IDENTITY_MISMATCH")

    pending = Path(PENDING_PATH)
    if _lstat(pending) is not None or not _activation_is_valid(Layout(), approved_sha):
        raise ProvisionError("RECOVERY_REQUIRED")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--approved-source-sha", required=True)
    parser.add_argument("--public-key-file", required=True, type=Path)
    args = parser.parse_args()
    try:
        source_root = Path(__file__).resolve().parents[3]
        public_key = provision._validate_key_file(args.public_key_file)
        verify(source_root, args.approved_source_sha, public_key)
    except ProvisionError as error:
        print(f"DIAGNOSTICS_VERIFY=FAILED code={error}")
        return 1
    except (OSError, subprocess.SubprocessError, KeyError):
        print("DIAGNOSTICS_VERIFY=FAILED code=VERIFY_FAILED")
        return 1

    print("DIAGNOSTICS_VERIFY=PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
