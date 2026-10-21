#!/usr/bin/env python3
"""Root-owned, no-argument boundary for staging diagnostics installation.

The file is installed only from an exact reviewed Git blob. Its root-owned
JSON configuration pins the source checkout, commit, and public-key path.
Repository files are never executed until canonical blob bytes have been
copied into a root-owned immutable package and verified.
"""

from __future__ import annotations

import hashlib
import json
import os
import re
import shutil
import stat
import subprocess
import sys
from pathlib import Path


CONFIG = Path("/etc/wpcaregrid-staging-diagnostics/bootstrap.json")
BASE = Path("/var/lib/wpcaregrid-staging-diagnostics-provisioning")
PACKAGE_ROOT = BASE / "source"
PACKAGE_FILES = (
    "scripts/staging-diagnostics/dispatch.sh",
    "scripts/staging-diagnostics/provisioning/activation-check.sh",
    "scripts/staging-diagnostics/provisioning/diagnostics-shell.sh",
    "scripts/staging-diagnostics/read-m12-cache.php",
    "scripts/staging-diagnostics/provisioning/run-m12-readback.sh",
    "scripts/staging-diagnostics/provisioning/provision.py",
    "scripts/staging-diagnostics/provisioning/verify.py",
    "scripts/staging-diagnostics/provisioning/rollback.py",
    "scripts/staging-diagnostics/provisioning/bootstrap.py",
)
MAX_BLOB_BYTES = 2 * 1024 * 1024
MAX_PACKAGE_BYTES = 8 * 1024 * 1024
MAX_GIT_METADATA_ENTRIES = 500_000
FAIL = "BOOTSTRAP_FAILED"


class BootstrapError(Exception):
    """A fixed, non-sensitive bootstrap failure."""


def fail() -> None:
    raise BootstrapError(FAIL)


def _metadata(path: Path) -> os.stat_result:
    try:
        info = path.lstat()
    except OSError:
        fail()
    if stat.S_ISLNK(info.st_mode) or not stat.S_ISREG(info.st_mode):
        fail()
    return info


def _validate_parent_chain(path: Path) -> None:
    current = Path("/")
    for component in path.parts[1:-1]:
        current /= component
        try:
            info = current.lstat()
        except OSError:
            fail()
        if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode) or info.st_uid != 0:
            fail()
        if stat.S_IMODE(info.st_mode) & 0o022:
            fail()


def _unique_object(pairs: list[tuple[str, object]]) -> dict[str, object]:
    result: dict[str, object] = {}
    for key, value in pairs:
        if key in result:
            fail()
        result[key] = value
    return result


def _validate_git_metadata_tree(path: Path) -> None:
    entries = 0
    for directory, names, files in os.walk(path, topdown=True, followlinks=False):
        current = Path(directory)
        for name in [*names, *files]:
            entries += 1
            if entries > MAX_GIT_METADATA_ENTRIES:
                fail()
            child = current / name
            try:
                info = child.lstat()
            except OSError:
                fail()
            if info.st_uid != 0 or stat.S_ISLNK(info.st_mode) or info.st_mode & 0o022:
                fail()
            if name in names:
                if not stat.S_ISDIR(info.st_mode):
                    fail()
            elif not stat.S_ISREG(info.st_mode):
                fail()


def _read_config() -> dict[str, str]:
    if os.geteuid() != 0:
        fail()
    _validate_parent_chain(CONFIG)
    info = _metadata(CONFIG)
    if info.st_uid != 0 or info.st_gid != 0 or stat.S_IMODE(info.st_mode) != 0o600:
        fail()
    if info.st_size > 4096:
        fail()
    try:
        value = json.loads(CONFIG.read_text(encoding="utf-8"), object_pairs_hook=_unique_object)
    except (OSError, UnicodeError, json.JSONDecodeError):
        fail()
    if not isinstance(value, dict) or set(value) != {"repository", "commit", "public_key"}:
        fail()
    repository = value["repository"]
    commit = value["commit"]
    public_key = value["public_key"]
    if (
        not isinstance(repository, str)
        or re.fullmatch(r"/[A-Za-z0-9._/-]+", repository) is None
        or ".." in Path(repository).parts
    ):
        fail()
    if (
        not isinstance(public_key, str)
        or re.fullmatch(r"/[A-Za-z0-9._/-]+", public_key) is None
        or ".." in Path(public_key).parts
    ):
        fail()
    _validate_parent_chain(Path(repository) / ".git" / "config")
    _validate_parent_chain(Path(public_key))
    if not isinstance(commit, str) or re.fullmatch(r"[0-9a-f]{40}", commit) is None:
        fail()
    return {"repository": repository, "commit": commit, "public_key": public_key}


def _git(repository: Path, *args: str, binary: bool = False) -> str | bytes:
    env = {
        "PATH": "/usr/bin:/bin",
        "HOME": "/root",
        "GIT_CONFIG_NOSYSTEM": "1",
        "GIT_CONFIG_GLOBAL": "/dev/null",
        "GIT_NO_REPLACE_OBJECTS": "1",
        "GIT_NO_LAZY_FETCH": "1",
        "GIT_OPTIONAL_LOCKS": "0",
    }
    try:
        result = subprocess.run(
            ["/usr/bin/git", "--no-replace-objects", "-C", str(repository), *args],
            check=False,
            env=env,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            timeout=20,
        )
    except (OSError, subprocess.TimeoutExpired):
        fail()
    if result.returncode != 0:
        fail()
    if binary:
        return result.stdout
    try:
        return result.stdout.decode("utf-8").strip()
    except UnicodeError:
        fail()


def _validate_repo(repository: Path, commit: str) -> str:
    _validate_parent_chain(repository / ".git" / "config")
    if repository.is_symlink() or not repository.is_dir():
        fail()
    git_dir = repository / ".git"
    if git_dir.is_symlink() or not git_dir.is_dir():
        fail()
    for relative in (".git/config", ".git/HEAD", ".git/objects", ".git/objects/info"):
        path = repository / relative
        try:
            info = path.lstat()
        except OSError:
            fail()
        expected_directory = relative.endswith("objects") or relative.endswith("objects/info")
        if stat.S_ISLNK(info.st_mode) or (expected_directory and not stat.S_ISDIR(info.st_mode)):
            fail()
        if not expected_directory and not stat.S_ISREG(info.st_mode):
            fail()
        if info.st_uid != 0 or stat.S_IMODE(info.st_mode) & 0o022:
            fail()
        if relative == ".git/config" and info.st_size > 1_048_576:
            fail()
        if relative == ".git/HEAD" and info.st_size > 4096:
            fail()
    for relative in (
        ".git/commondir",
        ".git/objects/info/alternates",
        ".git/objects/info/http-alternates",
        ".git/objects/info/grafts",
        ".git/info/grafts",
    ):
        path = repository / relative
        if path.exists() or path.is_symlink():
            fail()
    _validate_git_metadata_tree(git_dir)
    config_keys = _git(repository, "config", "--local", "--no-includes", "--name-only", "--list")
    if not isinstance(config_keys, str):
        fail()
    forbidden = (
        "include.", "includeif.", "core.usereplacerefs", "core.alternaterefscommand",
        "core.worktree", "core.fsmonitor", "core.hookspath", "core.sparsecheckout",
        "extensions.partialclone", ".promisor",
    )
    if any(
        key.lower().startswith(("include.", "includeif."))
        or key.lower() in forbidden[2:-1]
        or key.lower().endswith(".promisor")
        for key in config_keys.splitlines()
    ):
        fail()
    if _git(repository, "rev-parse", "--show-object-format") != "sha1":
        fail()
    if _git(repository, "rev-parse", "--verify", "HEAD") != commit:
        fail()
    if _git(repository, "status", "--porcelain", "--untracked-files=all"):
        fail()
    if _git(repository, "rev-parse", "--verify", f"{commit}^{{commit}}") != commit:
        fail()
    refs = _git(repository, "for-each-ref", "--format=%(refname)", "refs/replace/")
    if refs:
        fail()
    tree = _git(repository, "rev-parse", "--verify", f"{commit}^{{tree}}")
    if not isinstance(tree, str) or re.fullmatch(r"[0-9a-f]{40}", tree) is None:
        fail()
    return tree


def _blob(repository: Path, commit: str, relative: str) -> tuple[str, bytes]:
    entry = _git(repository, "ls-tree", commit, "--", relative)
    if not isinstance(entry, str) or "\t" not in entry:
        fail()
    metadata, path = entry.split("\t", 1)
    fields = metadata.split()
    if len(fields) != 3 or path != relative:
        fail()
    mode, kind, oid = fields
    if mode not in {"100644", "100755"} or kind != "blob" or re.fullmatch(r"[0-9a-f]{40}", oid) is None:
        fail()
    declared_size = _git(repository, "cat-file", "-s", oid)
    if not isinstance(declared_size, str) or not declared_size.isdecimal() or int(declared_size) > MAX_BLOB_BYTES:
        fail()
    content = _git(repository, "cat-file", "blob", oid, binary=True)
    if (
        not isinstance(content, bytes)
        or len(content) > MAX_BLOB_BYTES
        or hashlib.sha1(b"blob " + str(len(content)).encode() + b"\0" + content).hexdigest() != oid
    ):
        fail()
    return oid, content


def _ensure_root_directory(path: Path, mode: int) -> None:
    path.mkdir(parents=True, exist_ok=True, mode=mode)
    info = path.lstat()
    if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode) or info.st_uid != 0 or info.st_gid != 0 or stat.S_IMODE(info.st_mode) != mode:
        fail()


def prepare(config: dict[str, str]) -> Path:
    repository = Path(config["repository"])
    commit = config["commit"]
    tree = _validate_repo(repository, commit)
    stage = PACKAGE_ROOT / commit
    _validate_parent_chain(BASE)
    _ensure_root_directory(BASE, 0o700)
    _ensure_root_directory(PACKAGE_ROOT, 0o700)
    if stage.exists() or stage.is_symlink():
        fail()
    stage.mkdir(mode=0o700)
    rows = [f"commit={commit}", f"tree={tree}"]
    total_bytes = 0
    try:
        for relative in PACKAGE_FILES:
            oid, content = _blob(repository, commit, relative)
            total_bytes += len(content)
            if total_bytes > MAX_PACKAGE_BYTES:
                fail()
            target = stage / relative
            target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
            if target.parent.is_symlink():
                fail()
            descriptor = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o444)
            with os.fdopen(descriptor, "wb") as stream:
                stream.write(content)
                stream.flush()
                os.fsync(stream.fileno())
            os.chown(target, 0, 0)
            os.chmod(target, 0o444)
            info = target.lstat()
            if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o444:
                fail()
            digest = hashlib.sha256(content).hexdigest()
            rows.append(f"{oid} {digest}  {relative}")
        manifest = stage / ".wpcaregrid-provenance"
        descriptor = os.open(manifest, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o444)
        with os.fdopen(descriptor, "w", encoding="ascii") as stream:
            stream.write("\n".join(rows) + "\n")
            stream.flush()
            os.fsync(stream.fileno())
        os.chown(manifest, 0, 0)
        os.chmod(manifest, 0o444)
        for directory, _, _ in os.walk(stage, topdown=False):
            os.chown(directory, 0, 0)
            os.chmod(directory, 0o700)
    except Exception:
        shutil.rmtree(stage, ignore_errors=True)
        raise
    return stage


def main() -> int:
    try:
        if len(sys.argv) != 1:
            fail()
        config = _read_config()
        stage = prepare(config)
        public_key = Path(config["public_key"])
        key_info = _metadata(public_key)
        if key_info.st_uid != 0 or key_info.st_gid != 0 or key_info.st_mode & 0o022 or key_info.st_size > 16384:
            fail()
        package = stage / "scripts/staging-diagnostics/provisioning"
        for name in ("provision.py", "verify.py"):
            result = subprocess.run(
                ["/usr/bin/python3", "-I", "-S", str(package / name), "--approved-source-sha", config["commit"], "--public-key-file", str(public_key)],
                check=False,
                env={"PATH": "/usr/bin:/bin", "HOME": "/root", "PYTHONDONTWRITEBYTECODE": "1"},
                stdin=subprocess.DEVNULL,
                timeout=45,
            )
            if result.returncode != 0:
                fail()
        print("STAGING_DIAGNOSTICS_BOOTSTRAP=PASS")
        return 0
    except (BootstrapError, OSError, subprocess.SubprocessError):
        print(FAIL, file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
