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
import stat
import sys

TARGET_UID = 33
TARGET_GID = 33
ACL_XATTRS = ("system.posix_acl_access", "system.posix_acl_default")


def _real(root: str, path: str) -> str:
    return os.path.join(root, path.lstrip("/")) if root != "/" else path


def _virtual(root: str, path: str) -> str:
    return "/" + os.path.relpath(path, root) if root != "/" else path


def exposed(info, *, uid: int = TARGET_UID, gid: int = TARGET_GID) -> bool:
    """True when UID/GID 33 can read, write or search this inode through ownership, group or other bits."""
    if stat.S_ISLNK(info.st_mode):
        return False  # a symlink's own mode is irrelevant; its target is walked in its own right
    mode = info.st_mode & 0o777
    return bool((info.st_uid == uid and mode & 0o700) or (info.st_gid == gid and mode & 0o070) or mode & 0o007)


def has_acl(path: str) -> bool:
    try:
        return any(name in ACL_XATTRS for name in os.listxattr(path, follow_symlinks=False))
    except OSError:
        return False


def entries(matrix: dict, root: str):
    """Yield (virtual path, real path, is_dir, keep_traversable) for every non-public production path."""
    fence = matrix["production_fence"]
    public, keep = fence["public_exempt"], set(fence["keep_traversable"])
    for base in fence["roots"]:
        top = _real(root, base)
        if not os.path.lexists(top):
            continue
        for current, dirs, files in os.walk(top, followlinks=False):
            virtual = _virtual(root, current)
            if fnmatch.fnmatch(virtual, public) or fnmatch.fnmatch(virtual, public + "/*"):
                dirs[:] = []
                continue
            # `*` in fnmatch also crosses `/`, so count path depth: only releases/<id> itself stays traversable.
            is_release = (base == "/var/www/wpcaregrid-production" and virtual.startswith(base + "/releases/")
                          and virtual.count("/") == base.count("/") + 2)
            yield virtual, current, True, virtual in keep or is_release
            for name in files:
                yield _virtual(root, os.path.join(current, name)), os.path.join(current, name), False, False


def status(matrix: dict, root: str, *, uid: int = TARGET_UID, gid: int = TARGET_GID, require_root_owner: bool = True) -> list:
    found = []
    for virtual, real, is_dir, keep in entries(matrix, root):
        try:
            info = os.lstat(real)
        except OSError:
            found.append(virtual)  # cannot be inspected: never assume it is fenced
            continue
        if has_acl(real):
            found.append(virtual)
        elif exposed(info, uid=uid, gid=gid) and not (
                keep and (info.st_mode & 0o777) == 0o755 and (not require_root_owner or (info.st_uid == 0 and info.st_gid == 0))):
            found.append(virtual)
    return sorted(found)


def fence(matrix: dict, root: str, receipt: str, *, chown: bool = True) -> int:
    recorded = []
    if os.path.exists(receipt):
        with open(receipt, encoding="ascii") as handle:
            recorded = json.load(handle)
    known = {item["path"] for item in recorded}
    for virtual, real, is_dir, keep in entries(matrix, root):
        info = os.lstat(real)
        acl = {}
        for name in ACL_XATTRS:
            try:
                acl[name] = base64.b64encode(os.getxattr(real, name, follow_symlinks=False)).decode("ascii")
            except OSError:
                pass
        if virtual not in known:
            recorded.append({"path": virtual, "uid": info.st_uid, "gid": info.st_gid, "mode": stat.S_IMODE(info.st_mode), "acl": acl})
            known.add(virtual)
            with open(receipt, "w", encoding="ascii") as handle:  # write before changing: an interrupted run stays reversible
                json.dump(recorded, handle, sort_keys=True)
        if chown:
            os.lchown(real, 0, 0)
        if stat.S_ISLNK(info.st_mode):
            continue
        for name in acl:
            try:
                os.removexattr(real, name, follow_symlinks=False)
            except OSError:
                pass
        os.chmod(real, 0o755 if (is_dir and keep) else (0o700 if is_dir else 0o600))
    return len(recorded)


def restore(root: str, receipt: str, *, chown: bool = True) -> int:
    with open(receipt, encoding="ascii") as handle:
        recorded = json.load(handle)
    for item in reversed(recorded):
        real = _real(root, item["path"])
        if not os.path.lexists(real):
            continue
        if chown:
            os.lchown(real, item["uid"], item["gid"])
        if not os.path.islink(real):
            os.chmod(real, item["mode"])
            for name, value in item["acl"].items():
                try:
                    os.setxattr(real, name, base64.b64decode(value), follow_symlinks=False)
                except OSError:
                    pass
    return len(recorded)


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
        print(json.dumps({"fenced": fence(matrix, root, receipt, chown=chown)}))
        return 0
    if args[0] == "restore":
        print(json.dumps({"restored": restore(root, receipt, chown=chown)}))
        return 0
    found = status(matrix, root)
    print(json.dumps({"exposed": found}))
    return 1 if found else 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
