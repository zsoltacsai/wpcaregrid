#!/usr/bin/env python3
"""No-overwrite provisioner for staging-next runtime directories.

This tool deliberately does not create an application link, .env, vendor tree,
release, database backup, marker, or deployment lock.  A successful result is a
receipt for the empty directory skeleton only.  The existing application tree
is inspected and preserved.

Production use is root-only. ``--test-root`` maps absolute contract paths into
a temporary directory and substitutes the current UID/GID for service
identities; it is intended only for isolated tests.
"""

from __future__ import annotations

import argparse
import datetime as dt
import grp
import hashlib
import json
import os
import pwd
import stat
import sys
from dataclasses import dataclass
from pathlib import Path, PurePosixPath
from typing import Callable


APP_ROOT = "/var/www/wpcaregrid-staging-new"
ACTIVE_LINK = "/var/www/wpcaregrid-staging-next-current"
DEPLOY_ROOT = "/var/lib/wpcaregrid-deploy"
STAGING_ROOT = f"{DEPLOY_ROOT}/staging-next"


class LayoutError(RuntimeError):
    pass


@dataclass(frozen=True)
class Identities:
    root_uid: int
    root_gid: int
    app_uid: int
    app_gid: int
    deploy_uid: int
    deploy_gid: int
    fpm_uid: int
    fpm_gid: int


@dataclass(frozen=True)
class DirectorySpec:
    path: str
    uid: int
    gid: int
    mode: int


def resolve_identities() -> Identities:
    root = pwd.getpwuid(0)
    app = pwd.getpwuid(1000)  # Owner-verified existing application owner.
    deploy = pwd.getpwnam("deploy-staging-next")
    fpm = pwd.getpwnam("www-data")
    if deploy.pw_uid != 996 or fpm.pw_uid != 33 or fpm.pw_gid != 33:
        raise LayoutError("service_identity_mismatch")
    return Identities(0, root.pw_gid, app.pw_uid, app.pw_gid,
                      deploy.pw_uid, deploy.pw_gid, fpm.pw_uid, fpm.pw_gid)


def _specs(ids: Identities) -> tuple[DirectorySpec, ...]:
    return (
        DirectorySpec(DEPLOY_ROOT, ids.root_uid, ids.root_gid, 0o755),
        DirectorySpec(STAGING_ROOT, ids.root_uid, ids.root_gid, 0o755),
        DirectorySpec(f"{STAGING_ROOT}/releases", ids.root_uid, ids.root_gid, 0o755),
        DirectorySpec(f"{STAGING_ROOT}/source", ids.deploy_uid, ids.deploy_gid, 0o700),
        DirectorySpec(f"{STAGING_ROOT}/incoming", ids.deploy_uid, ids.deploy_gid, 0o700),
        DirectorySpec(f"{STAGING_ROOT}/inbox", ids.root_uid, ids.root_gid, 0o755),
        DirectorySpec(f"{STAGING_ROOT}/state", ids.root_uid, ids.root_gid, 0o700),
        DirectorySpec(f"{STAGING_ROOT}/receipts", ids.root_uid, ids.root_gid, 0o700),
        DirectorySpec(f"{STAGING_ROOT}/database-backups", ids.root_uid, ids.root_gid, 0o700),
        DirectorySpec(f"{STAGING_ROOT}/shared", ids.root_uid, ids.root_gid, 0o755),
        DirectorySpec(f"{STAGING_ROOT}/shared/storage", ids.fpm_uid, ids.fpm_gid, 0o2770),
        DirectorySpec("/usr/local/libexec/wpcaregrid-deploy/staging-next", ids.root_uid, ids.root_gid, 0o755),
        DirectorySpec("/etc/wpcaregrid-deploy/profiles", ids.root_uid, ids.root_gid, 0o755),
    )


def _physical(root: Path, logical: str) -> Path:
    posix = PurePosixPath(logical)
    if not posix.is_absolute() or str(posix) != logical or ".." in posix.parts:
        raise LayoutError("contract_path_invalid")
    return root.joinpath(*posix.parts[1:])


def _lstat_or_none(path: Path):
    try:
        return os.lstat(path)
    except FileNotFoundError:
        return None
    except OSError:
        raise LayoutError("path_metadata_unavailable") from None


def _verify_dir(path: Path, uid: int, gid: int, mode: int, *, exact: bool = True) -> os.stat_result:
    info = _lstat_or_none(path)
    if info is None:
        raise LayoutError("required_directory_missing")
    if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode):
        raise LayoutError("path_not_real_directory")
    actual_mode = stat.S_IMODE(info.st_mode)
    if info.st_uid != uid or info.st_gid != gid:
        raise LayoutError("directory_owner_mismatch")
    if (actual_mode != mode) if exact else bool(actual_mode & 0o022):
        raise LayoutError("directory_mode_mismatch")
    return info


def _verify_existing_chain(root: Path, logical: str, ids: Identities) -> None:
    physical = _physical(root, logical)
    relative = physical.relative_to(root)
    cursor = root
    # The temporary mapping root is owned by the test user, not expected to be uid 0.
    # The caller validates the terminal path against its role; only ancestors
    # are required to be root-owned here (the app root and incoming directory
    # intentionally have different owners).
    for component in relative.parts[:-1]:
        cursor /= component
        info = _lstat_or_none(cursor)
        if info is None:
            return
        if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode):
            raise LayoutError("path_component_not_real_directory")
        if info.st_uid != ids.root_uid or info.st_gid != ids.root_gid:
            raise LayoutError("parent_directory_owner_mismatch")
        if stat.S_IMODE(info.st_mode) & 0o022:
            raise LayoutError("parent_directory_writable_by_group_or_other")


def _verify_app_and_link(root: Path, ids: Identities) -> dict[str, object]:
    for logical in ("/var", "/var/www"):
        _verify_existing_chain(root, logical, ids)
        _verify_dir(_physical(root, logical), ids.root_uid, ids.root_gid, 0o755, exact=False)
    app_path = _physical(root, APP_ROOT)
    _verify_existing_chain(root, APP_ROOT, ids)
    app_info = _lstat_or_none(app_path)
    if app_info is None or not stat.S_ISDIR(app_info.st_mode) or stat.S_ISLNK(app_info.st_mode):
        raise LayoutError("application_root_not_real_directory")
    if app_info.st_uid != ids.app_uid or app_info.st_gid != ids.app_gid:
        raise LayoutError("application_root_owner_mismatch")
    link = _physical(root, ACTIVE_LINK)
    link_info = _lstat_or_none(link)
    if link_info is not None:
        raise LayoutError("active_link_path_already_exists")
    return {"application_root": {"uid": app_info.st_uid, "gid": app_info.st_gid,
                                  "mode": stat.S_IMODE(app_info.st_mode), "preserved": True},
            "active_link": {"path": ACTIVE_LINK, "state": "absent_preserved_for_release_procedure"}}


def _check_staging_children(root: Path) -> None:
    staging = _physical(root, STAGING_ROOT)
    info = _lstat_or_none(staging)
    if info is None:
        return
    if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode):
        raise LayoutError("staging_root_not_real_directory")
    expected = {"releases", "source", "incoming", "inbox", "state", "receipts", "database-backups", "shared"}
    try:
        names = {entry.name for entry in os.scandir(staging)}
    except OSError:
        raise LayoutError("staging_root_unreadable") from None
    if not names <= expected:
        raise LayoutError("unexpected_staging_root_content")
    for dirname in names:
        child = staging / dirname
        child_info = _lstat_or_none(child)
        if child_info is None or not stat.S_ISDIR(child_info.st_mode) or stat.S_ISLNK(child_info.st_mode):
            raise LayoutError("unexpected_staging_root_entry")
    # Provisioning is permitted only before release/runtime data is placed here.
    for logical in ("releases", "incoming", "inbox", "state", "receipts", "database-backups"):
        candidate = staging / logical
        if _lstat_or_none(candidate) is not None and any(os.scandir(candidate)):
            raise LayoutError("existing_runtime_data_requires_review")
    shared = staging / "shared"
    if _lstat_or_none(shared) is not None:
        try:
            shared_names = {entry.name for entry in os.scandir(shared)}
        except OSError:
            raise LayoutError("shared_directory_unreadable") from None
        if not shared_names <= {"storage"}:
            raise LayoutError("unexpected_shared_content")
        storage = shared / "storage"
        if _lstat_or_none(storage) is not None and any(os.scandir(storage)):
            raise LayoutError("existing_shared_storage_requires_review")


def _rollback(created: list[tuple[Path, int, int, int, int, int]]) -> bool:
    clean = True
    for path, dev, ino, uid, gid, mode in reversed(created):
        info = _lstat_or_none(path)
        if info is None:
            continue
        try:
            if (not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode)
                    or info.st_dev != dev or info.st_ino != ino
                    or info.st_uid != uid or info.st_gid != gid
                    or stat.S_IMODE(info.st_mode) != mode or any(os.scandir(path))):
                clean = False
                continue
            os.rmdir(path)
        except OSError:
            clean = False
    return clean


def provision(root: Path, ids: Identities, *, apply: bool) -> dict[str, object]:
    if not root.is_absolute():
        raise LayoutError("mapping_root_invalid")
    root_info = _lstat_or_none(root)
    if root_info is None or not stat.S_ISDIR(root_info.st_mode) or stat.S_ISLNK(root_info.st_mode):
        raise LayoutError("mapping_root_invalid")
    preserved = _verify_app_and_link(root, ids)
    _verify_existing_chain(root, "/var/lib", ids)
    lib = _physical(root, "/var/lib")
    _verify_dir(lib, ids.root_uid, ids.root_gid, 0o755, exact=False)
    _check_staging_children(root)

    specs = _specs(ids)
    created: list[tuple[Path, int, int, int, int, int]] = []
    observed: list[dict[str, object]] = []
    try:
        for spec in specs:
            path = _physical(root, spec.path)
            _verify_existing_chain(root, spec.path, ids)
            info = _lstat_or_none(path)
            if info is None:
                if not apply:
                    observed.append({"path": spec.path, "state": "missing"})
                    continue
                parent = path.parent
                if _lstat_or_none(parent) is None:
                    raise LayoutError("parent_directory_missing")
                try:
                    os.mkdir(path, spec.mode)
                except FileExistsError:
                    raise LayoutError("concurrent_path_creation") from None
                except OSError:
                    raise LayoutError("directory_creation_failed") from None
                info = os.lstat(path)
                created.append((path, info.st_dev, info.st_ino, spec.uid, spec.gid, spec.mode))
                os.chown(path, spec.uid, spec.gid, follow_symlinks=False)
                os.chmod(path, spec.mode, follow_symlinks=False)
                info = os.lstat(path)
            _verify_dir(path, spec.uid, spec.gid, spec.mode)
            observed.append({"path": spec.path, "state": "created" if any(x[0] == path for x in created)
                             else "existing_exact", "uid": info.st_uid, "gid": info.st_gid,
                             "mode": stat.S_IMODE(info.st_mode), "dev": info.st_dev, "inode": info.st_ino})
        if apply and any(x["state"] == "missing" for x in observed):
            raise LayoutError("layout_incomplete")
    except BaseException:
        rollback_ok = _rollback(created)
        if not rollback_ok:
            raise LayoutError("provision_failed_rollback_hold") from None
        raise

    body = {"schema": 1, "result": "PASS" if apply or not any(x["state"] == "missing" for x in observed)
            else "HOLD", "mode": "apply" if apply else "check", "timestamp_utc":
            dt.datetime.now(dt.timezone.utc).isoformat().replace("+00:00", "Z"),
            "paths": observed, "preserved": preserved,
            "not_created": ["active_link", "shared/.env", "shared/vendor", "release_contents",
                            "deployment_lock", "maintenance_markers", "database_objects"]}
    body["scope_note"] = (
        "Only empty shared/storage directory metadata was provisioned or verified; "
        "this does not preserve, migrate, or validate any existing application storage data."
    )
    canonical = json.dumps(body, sort_keys=True, separators=(",", ":")).encode()
    body["receipt_sha256"] = hashlib.sha256(canonical).hexdigest()
    return body


def _test_identities() -> Identities:
    return Identities(os.getuid(), os.getgid(), os.getuid(), os.getgid(),
                      os.getuid(), os.getgid(), os.getuid(), os.getgid())


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--apply", action="store_true", help="create absent runtime directories")
    parser.add_argument("--test-root", type=Path, help=argparse.SUPPRESS)
    args = parser.parse_args(argv)
    try:
        if args.test_root is not None:
            if args.test_root.resolve() == Path("/"):
                raise LayoutError("test_root_must_not_be_system_root")
            ids = _test_identities()
            root = args.test_root
        else:
            if os.geteuid() != 0:
                raise LayoutError("root_required")
            ids = resolve_identities()
            root = Path("/")
        result = provision(root, ids, apply=args.apply)
        print(json.dumps(result, sort_keys=True, separators=(",", ":")))
        return 0 if result["result"] == "PASS" else 2
    except LayoutError as exc:
        print(json.dumps({"result": "STOP", "reason": str(exc)}, sort_keys=True), file=sys.stderr)
        return 1
    except OSError:
        print(json.dumps({"result": "STOP", "reason": "filesystem_operation_failed"}, sort_keys=True),
              file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
