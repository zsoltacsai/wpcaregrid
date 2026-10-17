#!/usr/bin/env python3
"""Verify and safely extract the single reviewed Gitleaks executable."""
import hashlib
import os
import re
import sys
import tarfile
from pathlib import Path, PurePosixPath

VERSION = "8.30.0"
SHA256 = "79a3ab579b53f71efd634f3aaf7e04a0fa0cf206b7ed434638d1547a2470a66e"
MAX_ARCHIVE_BYTES = 20_000_000
MAX_BINARY_BYTES = 25_000_000
ALLOWED_MEMBERS = {"LICENSE", "README.md", "gitleaks"}


def verify_and_extract(archive: Path, destination: Path, expected_sha256: str = SHA256) -> None:
    if archive.stat().st_size > MAX_ARCHIVE_BYTES:
        raise ValueError("Gitleaks archive exceeds size limit")
    digest = hashlib.sha256()
    with archive.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    if not re.fullmatch(r"[0-9a-f]{64}", expected_sha256) or digest.hexdigest() != expected_sha256:
        raise ValueError("Gitleaks archive checksum mismatch")

    with tarfile.open(archive, mode="r:gz") as bundle:
        members = bundle.getmembers()
        names = [member.name for member in members]
        if len(names) != len(set(names)) or set(names) != ALLOWED_MEMBERS:
            raise ValueError("unexpected Gitleaks archive members")
        for member in members:
            path = PurePosixPath(member.name)
            if path.is_absolute() or ".." in path.parts or not member.isfile() or member.size > MAX_BINARY_BYTES:
                raise ValueError("unsafe Gitleaks archive member")
        executable = next(member for member in members if member.name == "gitleaks")
        source = bundle.extractfile(executable)
        if source is None:
            raise ValueError("Gitleaks executable is missing")
        destination.parent.mkdir(parents=True, exist_ok=True)
        flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL
        if hasattr(os, "O_NOFOLLOW"):
            flags |= os.O_NOFOLLOW
        fd = os.open(destination, flags, 0o700)
        try:
            written = 0
            with os.fdopen(fd, "wb", closefd=False) as output:
                while chunk := source.read(1024 * 1024):
                    written += len(chunk)
                    if written > MAX_BINARY_BYTES:
                        raise ValueError("Gitleaks executable exceeds size limit")
                    output.write(chunk)
            os.fchmod(fd, 0o700)
        except BaseException:
            destination.unlink(missing_ok=True)
            raise
        finally:
            os.close(fd)
        if written != executable.size:
            destination.unlink(missing_ok=True)
            raise ValueError("truncated Gitleaks executable")


def main() -> int:
    if len(sys.argv) != 3:
        print("usage: verify-gitleaks-archive.py ARCHIVE DESTINATION", file=sys.stderr)
        return 2
    try:
        verify_and_extract(Path(sys.argv[1]), Path(sys.argv[2]))
    except (OSError, tarfile.TarError, ValueError, EOFError):
        print("Gitleaks integrity verification or safe extraction failed.", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
