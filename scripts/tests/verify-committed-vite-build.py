#!/usr/bin/env python3
"""Require the built Vite output to exactly match regular committed Git blobs."""

from __future__ import annotations

import hashlib
import os
import stat
import subprocess
import sys
from pathlib import Path


def main() -> int:
    root = Path(__file__).resolve().parents[2]
    build = root / "public" / "build"
    if not build.is_dir() or build.is_symlink():
        print("VITE_BUILD=STOP;CODE=build_directory_invalid")
        return 1
    result = subprocess.run(
        ["git", "-C", str(root), "ls-files", "-s", "-z", "--", "public/build"],
        stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=False,
    )
    if result.returncode != 0:
        print("VITE_BUILD=STOP;CODE=git_index_unavailable")
        return 1
    tracked: dict[str, tuple[str, str]] = {}
    try:
        for record in result.stdout.split(b"\0"):
            if not record:
                continue
            metadata, raw_name = record.split(b"\t", 1)
            mode, blob, stage = metadata.decode("ascii").split()
            name = os.fsdecode(raw_name)
            if stage != "0" or mode != "100644" or name in tracked:
                raise ValueError
            tracked[name] = (mode, blob)
    except (ValueError, UnicodeError):
        print("VITE_BUILD=STOP;CODE=git_index_entry_invalid")
        return 1
    actual: set[str] = set()
    for current, directories, files in os.walk(build, followlinks=False):
        current_path = Path(current)
        for name in list(directories):
            path = current_path / name
            if path.is_symlink():
                print("VITE_BUILD=STOP;CODE=symlink_rejected")
                return 1
        for name in files:
            path = current_path / name
            if path.is_symlink() or not stat.S_ISREG(path.lstat().st_mode):
                print("VITE_BUILD=STOP;CODE=file_type_invalid")
                return 1
            rel = path.relative_to(root).as_posix()
            actual.add(rel)
    if not tracked or actual != set(tracked) or "public/build/manifest.json" not in tracked:
        print("VITE_BUILD=STOP;CODE=file_set_mismatch")
        return 1
    for name, (_mode, expected) in tracked.items():
        raw = (root / name).read_bytes()
        actual_blob = hashlib.sha1(b"blob " + str(len(raw)).encode("ascii") + b"\0" + raw).hexdigest()
        if actual_blob != expected:
            print("VITE_BUILD=STOP;CODE=content_mismatch")
            return 1
    print(f"VITE_BUILD=PASS;FILES={len(tracked)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
