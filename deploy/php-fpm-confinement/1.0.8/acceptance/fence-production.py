#!/usr/bin/env python3
"""Fence every UID-33-readable production secret-bearing path; used before ANY peer is returned to unconfined.

Production group-readable secrets are only safe while every same-UID peer is confined. Rolling a peer back
to unconfined therefore needs the whole production tree unreadable first, not just ``shared/.env`` and
``shared/storage``: also each release's ``bootstrap/cache/config.php`` (the config cache holds the same
secrets), release ``.env`` files, production logs, state and anything else under the production roots.

``fence`` makes every path under the roots in ``matrix.json`` (production_fence) unreadable to www-data
except public web roots (``releases/*/public``) and the traversal-only directories nginx must pass: files
become ``root:root 0600``, other directories ``root:root 0700``, kept directories ``root:root 0755``;
ACL xattrs are removed. Prior owner/mode/ACL of every changed path is written to a receipt so ``restore``
can undo it. ``status`` lists every path still readable, writable or searchable by UID/GID 33 (owner,
group, other bits, or any ACL xattr) and exits non-zero if there is one; an empty production tree is fenced.

It never follows symlinks and never reads file content. Usage:
  fence-production.py <fence|status|restore> <matrix.json> [--root DIR] [--receipt FILE]
"""
from __future__ import annotations

import base64
import fnmatch
import json
import os
import posixpath
import secrets
import stat
import sys

TARGET_UID = 33
TARGET_GID = 33
ACL_XATTRS = ("system.posix_acl_access", "system.posix_acl_default")
MAX_RECEIPT_BYTES = 16 * 1024 * 1024


class Refused(RuntimeError):
    pass


def _real(root: str, path: str) -> str:
    return os.path.join(root, path.lstrip("/")) if root != "/" else path


def _virtual(root: str, path: str) -> str:
    return "/" + os.path.relpath(path, root) if root != "/" else path


def component_glob_match(path: str, pattern: str) -> bool:
    """Match a path glob without allowing wildcards to cross `/` boundaries."""
    path_parts = path.strip("/").split("/") if path != "/" else []
    pattern_parts = pattern.strip("/").split("/") if pattern != "/" else []
    return len(path_parts) == len(pattern_parts) and all(
        fnmatch.fnmatchcase(part, component)
        for part, component in zip(path_parts, pattern_parts)
    )


def in_public_tree(path: str, public_pattern: str) -> bool:
    """True for the public root or descendants, with `*` confined to one component."""
    parts = path.strip("/").split("/") if path != "/" else []
    pattern_parts = public_pattern.strip("/").split("/") if public_pattern != "/" else []
    return any(
        component_glob_match("/" + "/".join(parts[:count]), public_pattern)
        for count in range(len(pattern_parts), len(parts) + 1)
    )


def exposed(info, *, uid: int = TARGET_UID, gid: int = TARGET_GID) -> bool:
    """True when UID/GID 33 can read, write or search this inode through ownership, group or other bits."""
    if stat.S_ISLNK(info.st_mode):
        return False  # a symlink's own mode is irrelevant; its target is walked in its own right
    mode = info.st_mode & 0o777
    return bool((info.st_uid == uid and mode & 0o700) or (info.st_gid == gid and mode & 0o070) or mode & 0o007)


def has_acl(path: str) -> bool:
    return any(name in ACL_XATTRS for name in os.listxattr(path, follow_symlinks=False))


def entries(matrix: dict, root: str):
    """Yield (virtual path, real path, is_dir, keep_traversable) for every non-public production path."""
    fence = matrix["production_fence"]
    public, keep = fence["public_exempt"], set(fence["keep_traversable"])
    for base in fence["roots"]:
        top = _real(root, base)
        try:
            root_parent, root_leaf, root_fd, root_info = _open_entry(root, base)
        except FileNotFoundError:
            continue
        try:
            if root_fd is None or not stat.S_ISDIR(root_info.st_mode):
                raise Refused(f"production root is not a real directory: {base}")
        finally:
            if root_fd is not None:
                os.close(root_fd)
            os.close(root_parent)

        def walk_error(error):
            # os.walk otherwise suppresses scandir failures and can report a
            # partial tree as if the inventory were complete.
            raise Refused(f"production tree walk failed at {error.filename}: {error}")

        for current, dirs, files in os.walk(top, followlinks=False, onerror=walk_error):
            virtual = _virtual(root, current)
            if in_public_tree(virtual, public):
                dirs[:] = []
                continue
            # os.walk reports directory symlinks in `dirs` but does not descend into them.
            # Inventory each link explicitly; its resolved target is checked by status().
            for name in list(dirs):
                link = os.path.join(current, name)
                if os.path.islink(link):
                    dirs.remove(name)
                    yield _virtual(root, link), link, False, False
            # Only releases/<one-component-id> stays traversable.
            is_release = (base == "/var/www/wpcaregrid-production" and virtual.startswith(base + "/releases/")
                          and virtual.count("/") == base.count("/") + 2)
            yield virtual, current, True, virtual in keep or is_release
            for name in files:
                yield _virtual(root, os.path.join(current, name)), os.path.join(current, name), False, False


def status(matrix: dict, root: str, *, uid: int = TARGET_UID, gid: int = TARGET_GID, require_root_owner: bool = True) -> list:
    found = []
    protected_roots = matrix["production_fence"]["roots"]
    public = matrix["production_fence"]["public_exempt"]
    for virtual, _real_path, is_dir, keep in entries(matrix, root):
        try:
            parent_fd, leaf, fd, info = _open_entry(root, virtual)
        except OSError:
            found.append(virtual)  # cannot be inspected: never assume it is fenced
            continue
        try:
            if fd is None:
                target_text = os.readlink(leaf, dir_fd=parent_fd)
                current_real = _real(root, virtual)
                target_real = posixpath.normpath(
                    target_text if posixpath.isabs(target_text)
                    else posixpath.join(posixpath.dirname(current_real), target_text)
                )
                target_virtual = _virtual(root, target_real)
                scoped = any(target_virtual == base or target_virtual.startswith(base.rstrip("/") + "/")
                             for base in protected_roots)
                if not scoped:
                    found.append(virtual)
                elif not in_public_tree(target_virtual, public):
                    target_parent, target_leaf, target_fd, target_info = _open_entry(root, target_virtual)
                    try:
                        if target_fd is None:
                            found.append(virtual)  # nested symlink chains fail closed
                        elif any(name in ACL_XATTRS for name in os.listxattr(target_fd)) or exposed(target_info, uid=uid, gid=gid):
                            found.append(virtual)
                    finally:
                        if target_fd is not None:
                            os.close(target_fd)
                        os.close(target_parent)
            elif any(name in ACL_XATTRS for name in os.listxattr(fd)) or (
                    exposed(info, uid=uid, gid=gid) and not (
                        keep and (info.st_mode & 0o777) == 0o755
                        and (not require_root_owner or (info.st_uid == 0 and info.st_gid == 0)))):
                found.append(virtual)
        except (OSError, ValueError, Refused):
            found.append(virtual)  # unreadable or unstable entries are not evidence of a clean fence
        finally:
            if fd is not None:
                os.close(fd)
            os.close(parent_fd)
    return sorted(found)


def _open_parent(root: str, virtual: str) -> tuple[int, str]:
    """Open every parent component without following symlinks; return (parent fd, leaf)."""
    if not virtual.startswith("/") or posixpath.normpath(virtual) != virtual or "\x00" in virtual:
        raise Refused(f"unsafe non-canonical path: {virtual!r}")
    parts = virtual.strip("/").split("/")
    if not parts or any(part in ("", ".", "..") for part in parts):
        raise Refused(f"unsafe path components: {virtual!r}")
    flags = os.O_RDONLY | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0)
    if not os.path.isabs(root) or posixpath.normpath(root) != root:
        raise Refused("root must be an absolute canonical path")
    # Pin root component-by-component from `/`; opening root in one call only
    # protects its final component and could follow a symlink in an ancestor.
    fd = os.open("/", flags)
    try:
        for part in root.strip("/").split("/") if root != "/" else ():
            if part in ("", ".", ".."):
                raise Refused(f"unsafe root component in {root!r}")
            before = os.stat(part, dir_fd=fd, follow_symlinks=False)
            if not stat.S_ISDIR(before.st_mode):
                raise Refused(f"non-directory or symlink root component in {root!r}")
            child = os.open(part, flags, dir_fd=fd)
            after = os.fstat(child)
            if (before.st_dev, before.st_ino) != (after.st_dev, after.st_ino):
                os.close(child)
                raise Refused(f"root component changed during open: {root!r}")
            os.close(fd)
            fd = child
        for part in parts[:-1]:
            before = os.stat(part, dir_fd=fd, follow_symlinks=False)
            if not stat.S_ISDIR(before.st_mode):
                raise Refused(f"non-directory or symlink path component in {virtual}")
            child = os.open(part, flags, dir_fd=fd)
            after = os.fstat(child)
            if (before.st_dev, before.st_ino) != (after.st_dev, after.st_ino):
                os.close(child)
                raise Refused(f"path component changed during open: {virtual}")
            os.close(fd)
            fd = child
        return fd, parts[-1]
    except BaseException:
        os.close(fd)
        raise


def _open_entry(root: str, virtual: str) -> tuple[int, str, int | None, os.stat_result]:
    """Open an entry relative to a pinned parent; symlink leaves are returned unopened."""
    parent_fd, leaf = _open_parent(root, virtual)
    try:
        before = os.stat(leaf, dir_fd=parent_fd, follow_symlinks=False)
        if stat.S_ISLNK(before.st_mode):
            return parent_fd, leaf, None, before
        if not (stat.S_ISDIR(before.st_mode) or stat.S_ISREG(before.st_mode)):
            raise Refused(f"unsupported special object: {virtual}")
        flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0)
        if stat.S_ISDIR(before.st_mode):
            flags |= os.O_DIRECTORY
        fd = os.open(leaf, flags, dir_fd=parent_fd)
        after = os.fstat(fd)
        if (before.st_dev, before.st_ino, stat.S_IFMT(before.st_mode)) != (
                after.st_dev, after.st_ino, stat.S_IFMT(after.st_mode)):
            os.close(fd)
            raise Refused(f"entry changed during open: {virtual}")
        return parent_fd, leaf, fd, after
    except BaseException:
        os.close(parent_fd)
        raise


def _same_entry(parent_fd: int, leaf: str, info: os.stat_result, virtual: str) -> None:
    current = os.stat(leaf, dir_fd=parent_fd, follow_symlinks=False)
    if (current.st_dev, current.st_ino, stat.S_IFMT(current.st_mode)) != (
            info.st_dev, info.st_ino, stat.S_IFMT(info.st_mode)):
        raise Refused(f"entry changed during operation: {virtual}")


def _receipt_parent(receipt: str) -> tuple[int, str]:
    if not os.path.isabs(receipt) or posixpath.normpath(receipt) != receipt or "\x00" in receipt:
        raise Refused("receipt path must be absolute and canonical")
    parts = receipt.strip("/").split("/")
    if not parts or any(part in ("", ".", "..") for part in parts):
        raise Refused("unsafe receipt path components")
    fd = os.open("/", os.O_RDONLY | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0))
    try:
        for part in parts[:-1]:
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0), dir_fd=fd)
            info = os.fstat(child)
            sticky_safe = bool(info.st_mode & stat.S_ISVTX) and info.st_uid == 0
            if info.st_uid != os.geteuid() or ((info.st_mode & 0o022) and not sticky_safe):
                os.close(child)
                raise Refused("receipt parent must be owned by the effective user and not group/other writable")
            os.close(fd)
            fd = child
        return fd, parts[-1]
    except BaseException:
        os.close(fd)
        raise


def _read_receipt(parent_fd: int, leaf: str) -> list:
    fd = os.open(leaf, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0), dir_fd=parent_fd)
    try:
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != os.geteuid()
                or (info.st_mode & 0o077)):
            raise Refused("receipt must be owned, single-link, regular, and mode 0600 or stricter")
        chunks, size = [], 0
        while True:
            chunk = os.read(fd, min(65536, MAX_RECEIPT_BYTES + 1 - size))
            if not chunk:
                break
            chunks.append(chunk)
            size += len(chunk)
            if size > MAX_RECEIPT_BYTES:
                raise Refused("receipt exceeds size limit")
        result = json.loads(b"".join(chunks).decode("ascii"))
        if not isinstance(result, list):
            raise Refused("receipt schema must be a list")
        return result
    finally:
        os.close(fd)


def _validate_receipt(recorded: list, matrix: dict) -> None:
    roots = matrix["production_fence"]["roots"]
    public = matrix["production_fence"]["public_exempt"]
    seen = set()
    for item in recorded:
        if not isinstance(item, dict):
            raise Refused("invalid receipt entry")
        path = item.get("path")
        if not isinstance(path, str) or not path.startswith("/") or posixpath.normpath(path) != path or "\x00" in path:
            raise Refused("receipt contains a non-canonical path")
        if path in seen:
            raise Refused("receipt contains a duplicate path")
        seen.add(path)
        if not any(path == base or path.startswith(base.rstrip("/") + "/") for base in roots):
            raise Refused("receipt path is outside production roots")
        if in_public_tree(path, public):
            raise Refused("receipt path is in the public exemption")
        if (not isinstance(item.get("uid"), int) or not isinstance(item.get("gid"), int)
                or not isinstance(item.get("mode"), int) or not 0 <= item["mode"] <= 0o7777
                or not isinstance(item.get("dev"), int) or not isinstance(item.get("ino"), int)
                or not isinstance(item.get("acl"), dict) or set(item["acl"]) - set(ACL_XATTRS)):
            raise Refused("receipt entry has invalid metadata")
        for value in item["acl"].values():
            try:
                base64.b64decode(value, validate=True)
            except (ValueError, TypeError):
                raise Refused("receipt contains invalid ACL data") from None


def _write_receipt(parent_fd: int, leaf: str, recorded: list) -> None:
    payload = json.dumps(recorded, sort_keys=True, separators=(",", ":")).encode("ascii")
    temporary = f".{leaf}.{os.getpid()}.{secrets.token_hex(8)}.tmp"
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0),
                 0o600, dir_fd=parent_fd)
    try:
        offset = 0
        while offset < len(payload):
            offset += os.write(fd, payload[offset:])
        os.fsync(fd)
        os.replace(temporary, leaf, src_dir_fd=parent_fd, dst_dir_fd=parent_fd)
        os.fsync(parent_fd)
    except BaseException:
        try:
            os.unlink(temporary, dir_fd=parent_fd)
        except OSError:
            pass
        raise
    finally:
        os.close(fd)


def fence(matrix: dict, root: str, receipt: str, *, chown: bool = True) -> int:
    receipt_fd, receipt_leaf = _receipt_parent(receipt)
    try:
        try:
            recorded = _read_receipt(receipt_fd, receipt_leaf)
        except FileNotFoundError:
            recorded = []
        _validate_receipt(recorded, matrix)
        known = {item["path"]: item for item in recorded}
        changed = 0
        # Complete enumeration before changing any inode. If a subtree cannot
        # be read, refuse without returning success or leaving a partial fence.
        all_entries = list(entries(matrix, root))
        for virtual, _real_path, is_dir, keep in all_entries:
            parent_fd, leaf, fd, info = _open_entry(root, virtual)
            try:
                if fd is None:
                    # Link leaves are not chmod/chown targets; status() validates their resolved targets.
                    continue
                xattrs = os.listxattr(fd)
                acl = {name: base64.b64encode(os.getxattr(fd, name)).decode("ascii")
                       for name in ACL_XATTRS if name in xattrs}
                prior = known.get(virtual)
                if prior is not None:
                    if (prior["dev"], prior["ino"]) != (info.st_dev, info.st_ino):
                        raise Refused(f"receipt inode does not match current tree entry: {virtual}")
                else:
                    prior = {"path": virtual, "uid": info.st_uid, "gid": info.st_gid,
                             "mode": stat.S_IMODE(info.st_mode), "dev": info.st_dev, "ino": info.st_ino, "acl": acl}
                    recorded.append(prior)
                    known[virtual] = prior
                    _validate_receipt(recorded, matrix)
                    _write_receipt(receipt_fd, receipt_leaf, recorded)
                _same_entry(parent_fd, leaf, info, virtual)
                if chown:
                    os.fchown(fd, 0, 0)
                for name in acl:
                    os.removexattr(fd, name)
                os.fchmod(fd, 0o755 if (is_dir and keep) else (0o700 if is_dir else 0o600))
                _same_entry(parent_fd, leaf, info, virtual)
                changed += 1
            finally:
                if fd is not None:
                    os.close(fd)
                os.close(parent_fd)
        return changed
    finally:
        os.close(receipt_fd)


def restore(root: str, receipt: str, matrix: dict, *, chown: bool = True) -> int:
    receipt_fd, receipt_leaf = _receipt_parent(receipt)
    try:
        recorded = _read_receipt(receipt_fd, receipt_leaf)
        _validate_receipt(recorded, matrix)
        restored = 0
        for item in reversed(recorded):
            parent_fd, leaf, fd, info = _open_entry(root, item["path"])
            try:
                if fd is None or (info.st_dev, info.st_ino) != (item["dev"], item["ino"]):
                    raise Refused(f"restore target is not the receipted inode: {item['path']}")
                _same_entry(parent_fd, leaf, info, item["path"])
                if chown:
                    os.fchown(fd, item["uid"], item["gid"])
                os.fchmod(fd, item["mode"])
                for name, value in item["acl"].items():
                    os.setxattr(fd, name, base64.b64decode(value, validate=True))
                _same_entry(parent_fd, leaf, info, item["path"])
                restored += 1
            finally:
                if fd is not None:
                    os.close(fd)
                os.close(parent_fd)
        return restored
    finally:
        os.close(receipt_fd)


def main(argv: list) -> int:
    args = list(argv)
    root, receipt = "/", "/run/wpcg-fence-receipt.json"
    for flag in ("--root", "--receipt"):
        if flag in args:
            index = args.index(flag)
            value = args[index + 1]
            del args[index:index + 2]
            root, receipt = (value, receipt) if flag == "--root" else (root, value)
    if len(args) != 2 or args[0] not in ("fence", "status", "restore"):
        print(__doc__, file=sys.stderr)
        return 2
    chown = os.geteuid() == 0
    if not chown and args[0] != "status" and (os.environ.get("WPCG_FENCE_TEST_ONLY") != "1" or root == "/"):
        print("REFUSED: must run as root (test mode needs WPCG_FENCE_TEST_ONLY=1 and a --root other than /)", file=sys.stderr)
        return 2
    with open(args[1], encoding="utf-8") as handle:
        matrix = json.load(handle)
    if args[0] == "fence":
        try:
            print(json.dumps({"fenced": fence(matrix, root, receipt, chown=chown)}))
            return 0
        except (OSError, ValueError, Refused) as error:
            print(f"REFUSED: fence failed closed: {error}", file=sys.stderr)
            return 1
    if args[0] == "restore":
        try:
            print(json.dumps({"restored": restore(root, receipt, matrix, chown=chown)}))
            return 0
        except (OSError, ValueError, Refused) as error:
            print(f"REFUSED: restore failed closed: {error}", file=sys.stderr)
            return 1
    try:
        found = status(matrix, root)
    except (OSError, ValueError, Refused) as error:
        print(f"REFUSED: status failed closed: {error}", file=sys.stderr)
        return 1
    print(json.dumps({"exposed": found}))
    return 1 if found else 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
