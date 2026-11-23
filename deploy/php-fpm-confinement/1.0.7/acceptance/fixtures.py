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
    return os.path.join(root, path.lstrip("/")) if root != "/" else path


def _is_canary(path: str) -> bool:
    try:
        info = os.lstat(path)
        if not stat.S_ISREG(info.st_mode) or info.st_size != len(CANARY):
            return False
        with open(path, "rb") as handle:
            return handle.read() == CANARY.encode()
    except OSError:
        return False


def preflight(fixtures: dict, root: str) -> None:
    for entry in fixtures["files"]:
        path = _target(root, entry["path"])
        if os.path.lexists(path) and not _is_canary(path):
            raise Refused(f"refusing: {entry['path']} exists and is not the fixture canary (real data present)")
    for entry in fixtures["symlinks"]:
        path = _target(root, entry["path"])
        if os.path.lexists(path) and not (os.path.islink(path) and os.readlink(path) == _link(root, entry)):
            raise Refused(f"refusing: {entry['path']} exists and is not the fixture symlink")
    for entry in fixtures["mkdirs"]:
        path = _target(root, entry["path"])
        if os.path.lexists(path) and (os.path.islink(path) or not os.path.isdir(path)):
            raise Refused(f"refusing: {entry['path']} exists and is not a directory")


def _link(root: str, entry: dict) -> str:
    target = entry["target"]
    return _target(root, target) if target.startswith("/") else target


def create(fixtures: dict, root: str, receipt: str, chown: bool) -> dict:
    preflight(fixtures, root)
    made: dict = {"dirs": [], "files": [], "symlinks": []}

    def own(path: str, entry: dict) -> None:
        if chown:
            os.chown(path, entry["uid"], entry["gid"], follow_symlinks=False)
        os.chmod(path, int(entry["mode"], 8))

    for entry in fixtures["mkdirs"]:
        path = _target(root, entry["path"])
        if not os.path.lexists(path):
            parent = os.path.dirname(path)
            if not os.path.isdir(parent):
                raise Refused(f"refusing: parent of {entry['path']} is missing (list parents first)")
            os.mkdir(path)
            own(path, entry)
            made["dirs"].append(entry["path"])
    for entry in fixtures["files"]:
        path = _target(root, entry["path"])
        if not os.path.lexists(path):
            if not os.path.isdir(os.path.dirname(path)):
                raise Refused(f"refusing: parent of {entry['path']} is missing")
            with open(path, "x", encoding="ascii") as handle:
                handle.write(CANARY)
            own(path, entry)
            made["files"].append(entry["path"])
    for entry in fixtures["symlinks"]:
        path = _target(root, entry["path"])
        if not os.path.lexists(path):
            os.symlink(_link(root, entry), path)
            made["symlinks"].append(entry["path"])
    # An idempotent re-run must not forget what an earlier run created: merge into any existing receipt.
    if os.path.exists(receipt):
        with open(receipt, encoding="ascii") as handle:
            earlier = json.load(handle)
        for kind in made:
            made[kind] = earlier.get(kind, []) + [p for p in made[kind] if p not in earlier.get(kind, [])]
    with open(receipt, "w", encoding="ascii") as handle:
        json.dump(made, handle, sort_keys=True)
    return made


def remove(fixtures: dict, root: str, receipt: str) -> dict:
    with open(receipt, encoding="ascii") as handle:
        made = json.load(handle)
    gone: dict = {"removed": [], "left": []}
    links = {e["path"]: e for e in fixtures["symlinks"]}
    for path in made["symlinks"]:
        real = _target(root, path)
        if os.path.islink(real) and os.readlink(real) == _link(root, links[path]):
            os.unlink(real)
            gone["removed"].append(path)
        else:
            gone["left"].append(path)
    for path in made["files"]:
        real = _target(root, path)
        if _is_canary(real):
            os.unlink(real)
            gone["removed"].append(path)
        else:
            gone["left"].append(path)  # content changed: not ours any more; never delete
    for path in reversed(made["dirs"]):
        real = _target(root, path)
        try:
            os.rmdir(real)
            gone["removed"].append(path)
        except OSError:
            gone["left"].append(path)
    return gone


def status(fixtures: dict, root: str) -> list:
    """Fixture paths that still exist (must be empty before real production secrets)."""
    found = [e["path"] for e in fixtures["files"] if _is_canary(_target(root, e["path"]))]
    found += [e["path"] for e in fixtures["symlinks"]
              if os.path.islink(_target(root, e["path"])) and os.readlink(_target(root, e["path"])) == _link(root, e)]
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
