#!/usr/bin/env python3
"""Create and remove DAC-equivalent, non-secret canary fixtures for the live probe.

Why: an AppArmor denial is only evidence when the same access would succeed
without AppArmor. Production secrets do not exist yet and many control-plane
paths are unreadable by www-data, so the probe needs harmless files at the
protected paths with ownership and modes that let www-data read them.

Every fixture file contains only the constant canary text, never a secret.
Fail closed: creation refuses *before changing anything* if any target already
exists with other content (for example a real production ``.env``), and removal
only deletes paths this tool created, only while they still hold the canary.
Fixtures must be removed (``status`` must report none) before real production
secrets are provisioned.

Usage: fixtures.py <create|remove|status> <matrix.json> [--root DIR] [--receipt FILE]
"""
from __future__ import annotations

import json
import os
import stat
import sys

CANARY = "WPCG-FIXTURE-CANARY-v1\n"


class Refused(Exception):
    pass


def _target(root: str, path: str) -> str:
    if not isinstance(path, str) or not path.startswith("/") or "\x00" in path:
        raise Refused("refusing: fixture path is not absolute")
    parts = path.split("/")[1:]
    if any(part in ("", ".", "..") for part in parts):
        raise Refused("refusing: fixture path is not normalized")
    root = os.path.abspath(root)
    return os.path.join(root, *parts) if root != "/" else path


def _check_ancestors(path: str, root: str, *, include_leaf: bool = False) -> None:
    root = os.path.abspath(root)
    try:
        info = os.lstat(root)
    except OSError as error:
        raise Refused("refusing: fixture root is unavailable") from error
    if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode):
        raise Refused("refusing: fixture root is not a real directory")
    relative = os.path.relpath(path, root)
    if relative == os.pardir or relative.startswith(os.pardir + os.sep):
        raise Refused("refusing: fixture path escapes root")
    parts = [] if relative == "." else relative.split(os.sep)
    limit = len(parts) if include_leaf else max(0, len(parts) - 1)
    current = root
    for part in parts[:limit]:
        current = os.path.join(current, part)
        try:
            info = os.lstat(current)
        except FileNotFoundError:
            break
        except OSError as error:
            raise Refused("refusing: fixture ancestor cannot be inspected") from error
        if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode):
            raise Refused("refusing: fixture path has a symlink or non-directory ancestor")


def _open_parent(root: str, path: str) -> tuple[int, str]:
    """Open every parent by descriptor with O_NOFOLLOW; return pinned parent fd and leaf."""
    root = os.path.abspath(root)
    relative = os.path.relpath(path, root)
    if relative == os.pardir or relative.startswith(os.pardir + os.sep) or relative == ".":
        raise Refused("refusing: fixture path escapes root or names the root")
    parts = relative.split(os.sep)
    flags = os.O_RDONLY | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0)
    try:
        current = os.open(root, flags)
    except OSError as error:
        raise Refused("refusing: fixture root cannot be safely opened") from error
    try:
        for part in parts[:-1]:
            next_fd = os.open(part, flags, dir_fd=current)
            os.close(current)
            current = next_fd
        return current, parts[-1]
    except OSError as error:
        os.close(current)
        raise Refused("refusing: fixture ancestor changed or is not a real directory") from error


def _is_canary_at(parent_fd: int, leaf: str) -> bool:
    try:
        info = os.stat(leaf, dir_fd=parent_fd, follow_symlinks=False)
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size != len(CANARY):
            return False
        fd = os.open(leaf, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0), dir_fd=parent_fd)
        try:
            opened = os.fstat(fd)
            return ((opened.st_dev, opened.st_ino) == (info.st_dev, info.st_ino)
                    and os.read(fd, len(CANARY) + 1) == CANARY.encode())
        finally:
            os.close(fd)
    except OSError:
        return False


def preflight(fixtures: dict, root: str) -> None:
    for entry in fixtures["files"]:
        path = _target(root, entry["path"])
        _check_ancestors(path, root)
        if not os.path.lexists(path):
            continue
        parent, leaf = _open_parent(root, path)
        try:
            try:
                os.stat(leaf, dir_fd=parent, follow_symlinks=False)
                exists = True
            except FileNotFoundError:
                exists = False
            if exists and not _is_canary_at(parent, leaf):
                raise Refused(f"refusing: {entry['path']} exists and is not the fixture canary (real data present)")
        finally:
            os.close(parent)
    for entry in fixtures["symlinks"]:
        path = _target(root, entry["path"])
        _check_ancestors(path, root)
        if not os.path.lexists(path):
            continue
        parent, leaf = _open_parent(root, path)
        try:
            try:
                info = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
                exists = True
            except FileNotFoundError:
                exists = False
                info = None
            if exists and (not stat.S_ISLNK(info.st_mode) or os.readlink(leaf, dir_fd=parent) != _link(root, entry)):
                raise Refused(f"refusing: {entry['path']} exists and is not the fixture symlink")
        finally:
            os.close(parent)
    for entry in fixtures["mkdirs"]:
        path = _target(root, entry["path"])
        _check_ancestors(path, root)
        if not os.path.lexists(path):
            continue
        parent, leaf = _open_parent(root, path)
        try:
            try:
                info = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
                exists = True
            except FileNotFoundError:
                exists = False
                info = None
            if exists and (stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode)):
                raise Refused(f"refusing: {entry['path']} exists and is not a directory")
        finally:
            os.close(parent)


def _link(root: str, entry: dict) -> str:
    target = entry["target"]
    if not isinstance(target, str) or "\x00" in target:
        raise Refused("refusing: fixture symlink target is invalid")
    if target.startswith("/"):
        resolved = _target(root, target)
        _check_ancestors(resolved, root)
        return resolved
    if any(part == ".." for part in target.split("/")):
        raise Refused("refusing: fixture symlink target escapes root")
    return target


def _receipt_data(fixtures: dict, receipt: str) -> dict:
    allowed = {
        "dirs": {entry["path"] for entry in fixtures["mkdirs"]},
        "files": {entry["path"] for entry in fixtures["files"]},
        "symlinks": {entry["path"] for entry in fixtures["symlinks"]},
    }
    try:
        info = os.lstat(receipt)
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
            raise Refused("refusing: fixture receipt is not a regular single-link file")
        fd = os.open(receipt, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
        try:
            opened = os.fstat(fd)
            if (opened.st_dev, opened.st_ino) != (info.st_dev, info.st_ino):
                raise Refused("refusing: fixture receipt changed during read")
            raw = b""
            while len(raw) <= 65536:
                chunk = os.read(fd, 8192)
                if not chunk:
                    break
                raw += chunk
        finally:
            os.close(fd)
        if len(raw) > 65536:
            raise Refused("refusing: fixture receipt is too large")
        value = json.loads(raw.decode("ascii"))
    except Refused:
        raise
    except (OSError, UnicodeError, ValueError) as error:
        raise Refused("refusing: fixture receipt is missing or invalid") from error
    if not isinstance(value, dict) or set(value) != set(allowed):
        raise Refused("refusing: fixture receipt schema is invalid")
    for kind, names in allowed.items():
        items = value[kind]
        if not isinstance(items, list) or any(not isinstance(path, str) or path not in names for path in items) \
                or len(items) != len(set(items)):
            raise Refused("refusing: fixture receipt contains an unapproved or duplicate path")
    return value


def _write_receipt(receipt: str, value: dict) -> None:
    parent = os.path.dirname(os.path.abspath(receipt))
    current = os.path.sep
    for part in parent.strip(os.path.sep).split(os.path.sep):
        current = os.path.join(current, part)
        info = os.lstat(current)
        if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode):
            raise Refused("refusing: fixture receipt parent is not a real directory")
    fd = os.open(receipt, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | getattr(os, "O_NOFOLLOW", 0), 0o600)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
            raise Refused("refusing: fixture receipt is not a regular single-link file")
        payload = json.dumps(value, sort_keys=True).encode("ascii")
        while payload:
            count = os.write(fd, payload)
            if count <= 0:
                raise Refused("refusing: fixture receipt write was incomplete")
            payload = payload[count:]
    finally:
        os.close(fd)


def _validate_receipt_target(receipt: str) -> None:
    parent = os.path.dirname(os.path.abspath(receipt))
    current = os.path.sep
    for part in parent.strip(os.path.sep).split(os.path.sep):
        current = os.path.join(current, part)
        try:
            info = os.lstat(current)
        except OSError as error:
            raise Refused("refusing: fixture receipt parent is unavailable") from error
        if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode):
            raise Refused("refusing: fixture receipt parent is not a real directory")
    try:
        info = os.lstat(receipt)
    except FileNotFoundError:
        return
    except OSError as error:
        raise Refused("refusing: fixture receipt cannot be inspected") from error
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
        raise Refused("refusing: fixture receipt is not a regular single-link file")


def create(fixtures: dict, root: str, receipt: str, chown: bool) -> dict:
    preflight(fixtures, root)
    _validate_receipt_target(receipt)
    earlier = _receipt_data(fixtures, receipt) if os.path.lexists(receipt) else {"dirs": [], "files": [], "symlinks": []}
    made: dict = {"dirs": [], "files": [], "symlinks": []}

    for entry in fixtures["mkdirs"]:
        path = _target(root, entry["path"])
        parent_fd, leaf = _open_parent(root, path)
        try:
            try:
                os.mkdir(leaf, 0o700, dir_fd=parent_fd)
                created = True
            except FileExistsError:
                created = False
            if created:
                child_fd = os.open(leaf, os.O_RDONLY | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0), dir_fd=parent_fd)
                try:
                    if chown:
                        os.fchown(child_fd, entry["uid"], entry["gid"])
                    os.fchmod(child_fd, int(entry["mode"], 8))
                finally:
                    os.close(child_fd)
                made["dirs"].append(entry["path"])
        finally:
            os.close(parent_fd)
    for entry in fixtures["files"]:
        path = _target(root, entry["path"])
        parent_fd, leaf = _open_parent(root, path)
        try:
            try:
                fd = os.open(leaf, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o600,
                             dir_fd=parent_fd)
                created = True
            except FileExistsError:
                created = False
            if created:
                try:
                    if chown:
                        os.fchown(fd, entry["uid"], entry["gid"])
                    os.fchmod(fd, int(entry["mode"], 8))
                    payload = CANARY.encode()
                    while payload:
                        count = os.write(fd, payload)
                        if count <= 0:
                            raise Refused("refusing: incomplete fixture write")
                        payload = payload[count:]
                finally:
                    os.close(fd)
                made["files"].append(entry["path"])
        finally:
            os.close(parent_fd)
    # Recover an interrupted prior create and record exact canaries even when a prior
    # invocation created the file but stopped before writing its receipt.
    for entry in fixtures["files"]:
        path = _target(root, entry["path"])
        _check_ancestors(path, root)
        if os.path.lexists(path):
            parent_fd, leaf = _open_parent(root, path)
            try:
                if _is_canary_at(parent_fd, leaf) and entry["path"] not in made["files"]:
                    made["files"].append(entry["path"])
            finally:
                os.close(parent_fd)
    for entry in fixtures["symlinks"]:
        path = _target(root, entry["path"])
        _check_ancestors(path, root)
        if os.path.lexists(path):
            parent_fd, leaf = _open_parent(root, path)
            try:
                info = os.stat(leaf, dir_fd=parent_fd, follow_symlinks=False)
                if stat.S_ISLNK(info.st_mode) and os.readlink(leaf, dir_fd=parent_fd) == _link(root, entry) \
                        and entry["path"] not in made["symlinks"]:
                    made["symlinks"].append(entry["path"])
            finally:
                os.close(parent_fd)
    for entry in fixtures["symlinks"]:
        path = _target(root, entry["path"])
        parent_fd, leaf = _open_parent(root, path)
        try:
            try:
                os.symlink(_link(root, entry), leaf, dir_fd=parent_fd)
                made["symlinks"].append(entry["path"])
            except FileExistsError:
                pass
        finally:
            os.close(parent_fd)
    # An idempotent re-run must not forget what an earlier run created: merge into any existing receipt.
    for kind in made:
        made[kind] = earlier[kind] + [p for p in made[kind] if p not in earlier[kind]]
    _write_receipt(receipt, made)
    return made


def remove(fixtures: dict, root: str, receipt: str) -> dict:
    made = _receipt_data(fixtures, receipt)
    for kind in ("dirs", "files", "symlinks"):
        for path in made[kind]:
            _check_ancestors(_target(root, path), root)
    gone: dict = {"removed": [], "left": []}
    links = {e["path"]: e for e in fixtures["symlinks"]}
    for path in made["symlinks"]:
        real = _target(root, path)
        _check_ancestors(real, root)
        parent, leaf = _open_parent(root, real)
        try:
            try:
                info = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
                if stat.S_ISLNK(info.st_mode) and os.readlink(leaf, dir_fd=parent) == _link(root, links[path]):
                    os.unlink(leaf, dir_fd=parent)
                    gone["removed"].append(path)
                else:
                    gone["left"].append(path)
            except FileNotFoundError:
                gone["left"].append(path)
        finally:
            os.close(parent)
    for path in made["files"]:
        real = _target(root, path)
        _check_ancestors(real, root)
        parent, leaf = _open_parent(root, real)
        try:
            if _is_canary_at(parent, leaf):
                os.unlink(leaf, dir_fd=parent)
                gone["removed"].append(path)
            else:
                gone["left"].append(path)
        finally:
            os.close(parent)
    for path in reversed(made["dirs"]):
        real = _target(root, path)
        _check_ancestors(real, root)
        parent, leaf = _open_parent(root, real)
        try:
            os.rmdir(leaf, dir_fd=parent)
            gone["removed"].append(path)
        except OSError:
            gone["left"].append(path)
        finally:
            os.close(parent)
    return gone


def status(fixtures: dict, root: str) -> list:
    """Fixture paths that still exist (must be empty before real production secrets)."""
    found = []
    for entry in fixtures["files"]:
        path = _target(root, entry["path"])
        _check_ancestors(path, root)
        if not os.path.lexists(path):
            continue
        parent, leaf = _open_parent(root, path)
        try:
            if _is_canary_at(parent, leaf):
                found.append(entry["path"])
        finally:
            os.close(parent)
    for entry in fixtures["symlinks"]:
        path = _target(root, entry["path"])
        _check_ancestors(path, root)
        if not os.path.lexists(path):
            continue
        parent, leaf = _open_parent(root, path)
        try:
            info = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
            if stat.S_ISLNK(info.st_mode) and os.readlink(leaf, dir_fd=parent) == _link(root, entry):
                found.append(entry["path"])
        finally:
            os.close(parent)
    return found


def main(argv: list) -> int:
    args = list(argv)
    root, receipt = "/", "/run/wpcg-fixtures-receipt.json"
    for flag in ("--root", "--receipt"):
        if flag in args:
            index = args.index(flag)
            value = args[index + 1]
            del args[index:index + 2]
            root, receipt = (value, receipt) if flag == "--root" else (root, value)
    if len(args) != 2 or args[0] not in ("create", "remove", "status"):
        print(__doc__, file=sys.stderr)
        return 2
    chown = True
    if os.geteuid() != 0:
        if os.environ.get("WPCG_FIXTURES_TEST_ONLY") != "1" or root == "/":
            print("REFUSED: must run as root (test mode needs WPCG_FIXTURES_TEST_ONLY=1 and a --root other than /)",
                  file=sys.stderr)
            return 2
        chown = False
    with open(args[1], encoding="utf-8") as handle:
        matrix = json.load(handle)
    if matrix.get("canary") != CANARY:
        print("REFUSED: matrix canary differs from this tool", file=sys.stderr)
        return 2
    try:
        if args[0] == "create":
            print(json.dumps(create(matrix["fixtures"], root, receipt, chown), sort_keys=True))
        elif args[0] == "remove":
            result = remove(matrix["fixtures"], root, receipt)
            print(json.dumps(result, sort_keys=True))
            return 1 if result["left"] else 0
        else:
            found = status(matrix["fixtures"], root)
            print(json.dumps(found))
            return 1 if found else 0
    except Refused as error:
        print(str(error), file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
