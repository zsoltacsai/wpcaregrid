#!/usr/bin/env python3
"""Require built Vite output to match regular blobs in the committed HEAD tree.

This binds build output to reviewed source bytes. It does not prove that build
dependencies or lifecycle scripts are benign; production rebuilds the exact
candidate source independently before packaging.
"""

from __future__ import annotations

import hashlib
import os
import stat
import subprocess
import sys
from pathlib import Path


def verify(root: Path) -> str | None:
    build = root / "public" / "build"
    if not build.is_dir() or build.is_symlink():
        return "build_directory_invalid"
    result = subprocess.run(
        ["git", "-C", str(root), "ls-tree", "-rz", "HEAD", "--", "public/build"],
        stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=False,
    )
    if result.returncode != 0:
        return "git_head_tree_unavailable"
    tracked: dict[str, tuple[str, str]] = {}
    try:
        for record in result.stdout.split(b"\0"):
            if not record:
                continue
            metadata, raw_name = record.split(b"\t", 1)
            mode, kind, blob = metadata.decode("ascii").split()
            name = os.fsdecode(raw_name)
            if kind != "blob" or mode != "100644" or name in tracked:
                raise ValueError
            tracked[name] = (mode, blob)
    except (ValueError, UnicodeError):
        return "git_head_tree_entry_invalid"
    actual: set[str] = set()
    for current, directories, files in os.walk(build, followlinks=False):
        current_path = Path(current)
        for name in list(directories):
            if (current_path / name).is_symlink():
                return "symlink_rejected"
        for name in files:
            path = current_path / name
            if path.is_symlink() or not stat.S_ISREG(path.lstat().st_mode):
                return "file_type_invalid"
            actual.add(path.relative_to(root).as_posix())
    if not tracked or actual != set(tracked) or "public/build/manifest.json" not in tracked:
        return "file_set_mismatch"
    for name, (_mode, expected) in tracked.items():
        raw = (root / name).read_bytes()
        actual_blob = hashlib.sha1(b"blob " + str(len(raw)).encode("ascii") + b"\0" + raw).hexdigest()
        if actual_blob != expected:
            return "content_mismatch"
    return None


def main() -> int:
    root = Path(__file__).resolve().parents[2]
    code = verify(root)
    if code:
        print(f"VITE_BUILD=STOP;CODE={code}")
        return 1
    count = sum(1 for _ in (root / "public/build").rglob("*") if _.is_file())
    print(f"VITE_BUILD=PASS;FILES={count}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
