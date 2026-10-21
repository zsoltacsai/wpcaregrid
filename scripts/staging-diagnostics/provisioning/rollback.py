#!/usr/bin/env python3
"""Disable diagnostics access without touching the application or SSH config."""

from __future__ import annotations

import argparse
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
    DIAGNOSTICS_HOME,
    DIAGNOSTICS_USER,
    BOOTSTRAP_PENDING_PATH,
    PENDING_PATH,
    _deactivate,
    SUDOERS_CONTENT,
    SUDOERS_PATH,
    Layout,
    ProvisionError,
    _lstat,
    _run_checked,
    authorized_key_line,
    verify_source_provenance,
)


@provision.serialized_lifecycle(blocking=True)
def rollback(source_root: Path, approved_sha: str, public_key: str) -> None:
    if os.geteuid() != 0:
        raise ProvisionError("ROOT_REQUIRED")
    verify_source_provenance(source_root, approved_sha, trusted_execution=True)

    rollback_filesystem(Layout(), approved_sha, public_key)
    try:
        pwd.getpwnam(DIAGNOSTICS_USER)
    except KeyError:
        pass
    else:
        _run_checked(["/usr/sbin/usermod", "--lock", DIAGNOSTICS_USER])


@provision.serialized_lifecycle(blocking=True)
def rollback_filesystem(layout: Layout, approved_sha: str, public_key: str) -> None:
    _deactivate(layout)
    auth_path = layout.path(f"{DIAGNOSTICS_HOME}/.ssh/authorized_keys")
    auth_info = _lstat(auth_path)
    if auth_info is not None:
        expected = f"{authorized_key_line(public_key)}\n".encode()
        if (
            not stat.S_ISREG(auth_info.st_mode)
            or auth_info.st_uid != layout.owner_uid
            or auth_info.st_gid != layout.owner_gid
            or stat.S_IMODE(auth_info.st_mode) != 0o444
            or auth_path.read_bytes() != expected
        ):
            raise ProvisionError("AUTHORIZED_KEYS_CONFLICT")

    sudoers_path = layout.path(SUDOERS_PATH)
    sudoers_info = _lstat(sudoers_path)
    if sudoers_info is not None and (
        not stat.S_ISREG(sudoers_info.st_mode)
        or sudoers_info.st_uid != layout.owner_uid
        or sudoers_info.st_gid != layout.owner_gid
        or stat.S_IMODE(sudoers_info.st_mode) != 0o440
        or sudoers_path.read_text(encoding="utf-8") != SUDOERS_CONTENT
    ):
        raise ProvisionError("SUDOERS_CONFLICT")

    if auth_info is not None:
        auth_path.unlink()
    if sudoers_info is not None:
        sudoers_path.unlink()
        if layout.root == Path("/"):
            _run_checked(["/usr/sbin/visudo", "-c"])

    marker = layout.path(PENDING_PATH)
    marker_info = _lstat(marker)
    if marker_info is not None:
        expected_marker = f"source={approved_sha}\n"
        if (
            not stat.S_ISREG(marker_info.st_mode)
            or marker_info.st_uid != layout.owner_uid
            or marker_info.st_gid != layout.owner_gid
            or stat.S_IMODE(marker_info.st_mode) != 0o600
            or marker.read_text(encoding="ascii") != expected_marker
        ):
            raise ProvisionError("RECOVERY_MARKER_CONFLICT")
        marker.unlink()

    bootstrap_marker = layout.path(BOOTSTRAP_PENDING_PATH)
    bootstrap_info = _lstat(bootstrap_marker)
    if bootstrap_info is not None:
        expected_bootstrap_marker = f"source={approved_sha}\n"
        if (
            not stat.S_ISREG(bootstrap_info.st_mode)
            or bootstrap_info.st_uid != layout.owner_uid
            or bootstrap_info.st_gid != layout.owner_gid
            or stat.S_IMODE(bootstrap_info.st_mode) != 0o600
            or bootstrap_marker.read_text(encoding="ascii") != expected_bootstrap_marker
        ):
            raise ProvisionError("BOOTSTRAP_JOURNAL_CONFLICT")
        bootstrap_marker.unlink()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--approved-source-sha", required=True)
    parser.add_argument("--public-key-file", required=True, type=Path)
    args = parser.parse_args()
    try:
        source_root = Path(__file__).resolve().parents[3]
        key = provision._validate_key_file(args.public_key_file)
        rollback(source_root, args.approved_source_sha, key)
    except ProvisionError as error:
        print(f"DIAGNOSTICS_ROLLBACK=FAILED code={error}")
        return 1
    except (OSError, subprocess.SubprocessError, KeyError):
        print("DIAGNOSTICS_ROLLBACK=FAILED code=ROLLBACK_FAILED")
        return 1

    print("DIAGNOSTICS_ROLLBACK=ACCESS_DISABLED")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
