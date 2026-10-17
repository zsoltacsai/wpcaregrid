#!/usr/bin/env python3
"""Offline integrity and extraction tests for the pinned Gitleaks archive."""
import hashlib
import importlib.util
import io
import tarfile
import tempfile
import os
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("gitleaks_verify", ROOT / "scripts/verify-gitleaks-archive.py")
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


def archive(path, entries):
    with tarfile.open(path, "w:gz") as bundle:
        for name, data, kind in entries:
            item = tarfile.TarInfo(name)
            item.size = len(data)
            if kind == "symlink":
                item.type = tarfile.SYMTYPE
                item.linkname = "../../outside"
                item.size = 0
                bundle.addfile(item)
            else:
                bundle.addfile(item, io.BytesIO(data))


def main():
    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        good = root / "good.tar.gz"
        expected = [("LICENSE", b"license", "file"), ("README.md", b"readme", "file"), ("gitleaks", b"binary fixture", "file")]
        archive(good, expected)
        output = root / "bin/gitleaks"
        MODULE.verify_and_extract(good, output, hashlib.sha256(good.read_bytes()).hexdigest())
        assert output.read_bytes() == b"binary fixture"
        if os.name == "posix":
            assert output.stat().st_mode & 0o777 == 0o700

        oversized = root / "oversized.tar.gz"
        with oversized.open("wb") as stream:
            stream.truncate(MODULE.MAX_ARCHIVE_BYTES + 1)
        try:
            MODULE.verify_and_extract(oversized, root / "oversized-out", hashlib.sha256(b"").hexdigest())
        except ValueError:
            pass
        else:
            raise AssertionError("oversized archive accepted")

        for bad_digest in ("0" * 64, "bad"):
            try:
                MODULE.verify_and_extract(good, root / ("bad-" + bad_digest), bad_digest)
            except ValueError:
                pass
            else:
                raise AssertionError("wrong checksum accepted")

        truncated = root / "truncated.tar.gz"
        truncated.write_bytes(good.read_bytes()[:20])
        try:
            MODULE.verify_and_extract(truncated, root / "truncated-out", hashlib.sha256(truncated.read_bytes()).hexdigest())
        except (ValueError, tarfile.TarError, OSError, EOFError):
            pass
        else:
            raise AssertionError("truncated archive accepted")

        traversal = root / "traversal.tar.gz"
        archive(traversal, expected + [("../escape", b"x", "file")])
        try:
            MODULE.verify_and_extract(traversal, root / "traversal-out", hashlib.sha256(traversal.read_bytes()).hexdigest())
        except ValueError:
            pass
        else:
            raise AssertionError("traversal member accepted")

        linked = root / "linked.tar.gz"
        archive(linked, expected[:2] + [("gitleaks", b"", "symlink")])
        try:
            MODULE.verify_and_extract(linked, root / "linked-out", hashlib.sha256(linked.read_bytes()).hexdigest())
        except ValueError:
            pass
        else:
            raise AssertionError("symlink member accepted")


if __name__ == "__main__":
    main()
