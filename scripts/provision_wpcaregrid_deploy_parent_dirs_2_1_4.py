#!/usr/bin/python3 -I
"""Provision only the four 2.1.4 production helper parent directories.

Scope is deliberately narrow. The confirmed installer
``scripts/install_production_helper_2_1_4.py`` requires exactly these existing
root-owned directories before it may publish anything:

    /var/lib/wpcaregrid-deploy            0755
    /usr/local/libexec/wpcaregrid-deploy  0755
    /etc/wpcaregrid-deploy                0755
    /etc/wpcaregrid-deploy/profiles       0755

This tool creates only paths proven absent at preflight. It never creates,
reads into, chmods or chowns any other path: not the staging tree below
``/var/lib/wpcaregrid-deploy``, not a helper, service, Nginx, FPM, database,
sudoers, user or group. An existing target directory is accepted only when it
is already an exact root:root 0755 directory; its contents are opaque and are
never inspected or changed.

Safety model:

* Real/effective root console only, never sudo/doas/pkexec and never an
  injected interpreter or loader environment. The running file must be a
  verified, root-owned 0700 copy whose SHA-256 matches ``--expected-sha256``.
* Every path is walked from ``/`` with ``openat``-style directory descriptors,
  ``O_DIRECTORY|O_NOFOLLOW`` and ``O_CLOEXEC``. For every component the
  descriptor identity (device, inode, owner, group, mode) is compared with the
  no-follow path entry before any write, and again before each mkdir.
* A durable, hash-chained journal is created with ``O_EXCL`` and fsynced before
  the first mkdir. Receipts are published with an atomic hard link and can
  never overwrite an existing file.
* Rollback and recovery remove only empty directories whose current identity
  equals the inode recorded by this run. Anything else is HOLD; nothing is
  repaired, replaced or guessed.
* SIGINT/SIGTERM/SIGHUP stop before the next mutation and roll back this run's
  exact inodes. SIGKILL cannot be caught; the journal makes the next invocation
  refuse with ``RECOVERY_REQUIRED`` until ``--recover`` proves and removes the
  run's own leftovers.
"""

from __future__ import annotations

import argparse
import errno
import fcntl
import hashlib
import json
import os
import re
import secrets
import signal
import stat
import sys
import time
from contextlib import contextmanager
from dataclasses import dataclass
from pathlib import Path
from typing import Callable, Iterator


VERSION = "2.1.4"
SCHEMA = 1
TARGET_MODE = 0o755
PARENT_MODE = 0o755
STATE_MODE = 0o700
FILE_MODE = 0o600
MAX_JOURNAL_BYTES = 1_048_576
MAX_SCRIPT_BYTES = 1_048_576
MAX_JOURNAL_RECORDS = 4096
RUN_ID_RE = re.compile(r"[0-9a-f]{32}\Z")
SHA256_RE = re.compile(r"[0-9a-f]{64}\Z")
JOURNAL_NAME_RE = re.compile(r"journal-([0-9a-f]{32})\.jsonl\Z")
RECEIPT_NAME_RE = re.compile(r"(plan|receipt|hold|recovery)-([0-9a-f]{32})\.json\Z")

# Exactly the confirmed installer's ``PRODUCTION.required_dirs``. Order is
# parental: a later target may treat an earlier target as its direct parent.
TARGETS = (
    "/var/lib/wpcaregrid-deploy",
    "/usr/local/libexec/wpcaregrid-deploy",
    "/etc/wpcaregrid-deploy",
    "/etc/wpcaregrid-deploy/profiles",
)

DELEGATION_VARIABLES = frozenset({
    "SUDO_UID", "SUDO_GID", "SUDO_USER", "SUDO_COMMAND", "SUDO_HOME",
    "PKEXEC_UID", "DOAS_USER",
})
UNSAFE_ENVIRONMENT_VARIABLES = frozenset({
    "PYTHONPATH", "PYTHONHOME", "PYTHONSTARTUP", "LD_PRELOAD", "LD_AUDIT",
    "LD_LIBRARY_PATH",
})

O_DIR = (
    os.O_RDONLY
    | getattr(os, "O_DIRECTORY", 0)
    | getattr(os, "O_NOFOLLOW", 0)
    | getattr(os, "O_CLOEXEC", 0)
)
O_FILE = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0)

_INTERRUPTED = False
_INTERRUPT_SIGNAL = ""


class ProvisionError(Exception):
    """A stable, safe refusal code."""

    def __init__(self, code: str):
        super().__init__(code)
        self.code = code


class Interrupted(ProvisionError):
    """A catchable interruption signal was observed between atomic steps."""


@dataclass(frozen=True)
class Layout:
    """Filesystem root; non-root values are used only by synthetic tests."""

    root: Path = Path("/")
    owner_uid: int = 0
    owner_gid: int = 0

    def physical(self, logical: str) -> Path:
        if not logical.startswith("/") or logical == "/":
            raise ProvisionError("TARGET_PATH_INVALID")
        return self.root / logical.lstrip("/")


@dataclass(frozen=True)
class Outcome:
    status: str
    code: str
    journal: str | None
    receipt: str | None
    created: tuple[str, ...] = ()
    existing: tuple[str, ...] = ()
    removed: tuple[str, ...] = ()


@dataclass
class TargetState:
    path: str
    state: str  # "existing" or "absent"
    identity: dict | None
    parent_chain: list[dict]
    planned_parent: str | None = None


def _fail(code: str) -> None:
    raise ProvisionError(code)


def _sha(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def _mode(info: os.stat_result) -> int:
    return stat.S_IMODE(info.st_mode)


def _special_bits(mode: int) -> int:
    return mode & (stat.S_ISUID | stat.S_ISGID | stat.S_ISVTX)


def _identity(info: os.stat_result) -> dict:
    if stat.S_ISDIR(info.st_mode):
        kind = "dir"
    elif stat.S_ISLNK(info.st_mode):
        kind = "symlink"
    elif stat.S_ISREG(info.st_mode):
        kind = "file"
    else:
        kind = "other"
    return {
        "dev": int(info.st_dev),
        "ino": int(info.st_ino),
        "uid": int(info.st_uid),
        "gid": int(info.st_gid),
        "mode": f"{_mode(info):04o}",
        "type": kind,
    }


def _same_identity(left: dict | None, right: dict | None) -> bool:
    if left is None or right is None:
        return False
    return all(left.get(key) == right.get(key) for key in
               ("dev", "ino", "uid", "gid", "mode", "type"))


def _write_all(fd: int, payload: bytes) -> None:
    view = memoryview(payload)
    while view:
        written = os.write(fd, view)
        if written <= 0:
            _fail("WRITE_FAILED")
        view = view[written:]


def _read_all(fd: int, maximum: int) -> bytes:
    chunks: list[bytes] = []
    total = 0
    while True:
        chunk = os.read(fd, min(65536, maximum + 1 - total))
        if not chunk:
            break
        total += len(chunk)
        if total > maximum:
            _fail("FILE_TOO_LARGE")
        chunks.append(chunk)
    return b"".join(chunks)


def _status_at(directory_fd: int, name: str) -> dict | None:
    try:
        info = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
    except FileNotFoundError:
        return None
    except OSError:
        _fail("PATH_STATE_UNKNOWN")
    return _identity(info)


def _open_at(directory_fd: int, name: str) -> int:
    if not name or name in (".", "..") or "/" in name or "\x00" in name:
        _fail("PATH_COMPONENT_INVALID")
    try:
        return os.open(name, O_DIR, dir_fd=directory_fd)
    except FileNotFoundError:
        raise
    except OSError as error:
        if error.errno == errno.ELOOP:
            _fail("SYMLINK_REFUSED")
        if error.errno == errno.ENOTDIR:
            # Linux reports ENOTDIR (not ELOOP) for O_NOFOLLOW|O_DIRECTORY on a
            # symlink; keep the operator-facing refusal specific.
            entry = _status_at(directory_fd, name)
            if entry is not None and entry["type"] == "symlink":
                _fail("SYMLINK_REFUSED")
        _fail("DIRECTORY_UNSAFE")


def _verify_fd(
    fd: int,
    logical: str,
    layout: Layout,
    *,
    exact_mode: int,
    require_empty: bool = False,
) -> os.stat_result:
    try:
        info = os.fstat(fd)
    except OSError:
        _fail("DIRECTORY_UNAVAILABLE")
    if not stat.S_ISDIR(info.st_mode):
        _fail("DIRECTORY_TYPE_INVALID")
    if info.st_uid != layout.owner_uid or info.st_gid != layout.owner_gid:
        _fail("DIRECTORY_OWNER_INVALID")
    if _mode(info) != exact_mode:
        _fail("DIRECTORY_MODE_INVALID")
    if _special_bits(info.st_mode):
        _fail("DIRECTORY_SPECIAL_BITS_INVALID")
    if require_empty:
        try:
            if os.listdir(fd):
                _fail("DIRECTORY_NOT_EMPTY")
        except ProvisionError:
            raise
        except OSError:
            _fail("DIRECTORY_UNAVAILABLE")
    return info


def _verify_fd_matches_entry(directory_fd: int, name: str, fd: int) -> None:
    opened = _identity(os.fstat(fd))
    entry = _status_at(directory_fd, name)
    if not _same_identity(opened, entry):
        _fail("INODE_IDENTITY_CHANGED")


def _open_root_fd(layout: Layout) -> int:
    try:
        info = os.lstat(layout.root)
    except OSError:
        _fail("ROOT_UNAVAILABLE")
    if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode):
        _fail("ROOT_UNSAFE")
    try:
        fd = os.open(str(layout.root), O_DIR)
    except OSError:
        _fail("ROOT_UNSAFE")
    try:
        opened = _verify_fd(fd, "/", layout, exact_mode=PARENT_MODE)
        if (opened.st_dev, opened.st_ino) != (info.st_dev, info.st_ino):
            _fail("ROOT_IDENTITY_CHANGED")
        return fd
    except BaseException:
        os.close(fd)
        raise


@contextmanager
def _open_parent_chain(
    layout: Layout,
    target: str,
    *,
    planned_targets: frozenset[str] = frozenset(),
) -> Iterator[tuple[int, list[dict], str | None]]:
    """Yield (parent dir fd, verified ancestor identities, first planned target).

    A missing component is accepted only when it is one of the targets declared
    earlier in ``TARGETS``; this is how ``/etc/wpcaregrid-deploy/profiles`` is
    preflighted before its parent exists. Any other missing component is a
    refusal: this tool never creates a base directory.
    """
    parts = Path(target).parts[1:]
    if not parts or not target.startswith("/"):
        _fail("TARGET_PATH_INVALID")
    parent_parts = parts[:-1]
    current = _open_root_fd(layout)
    try:
        root_info = os.fstat(current)
        chain = [{"path": "/", "identity": _identity(root_info)}]
        missing: str | None = None
        for index, component in enumerate(parent_parts):
            logical = "/" + "/".join(parent_parts[: index + 1])
            try:
                following = _open_at(current, component)
            except FileNotFoundError:
                if logical in planned_targets:
                    missing = logical
                    break
                _fail("PARENT_MISSING")
            try:
                _verify_fd(following, logical, layout, exact_mode=PARENT_MODE)
                _verify_fd_matches_entry(current, component, following)
                chain.append({
                    "path": logical,
                    "identity": _identity(os.fstat(following)),
                })
            except BaseException:
                os.close(following)
                raise
            os.close(current)
            current = following
        yield current, chain, missing
    finally:
        os.close(current)


def _inspect_target(layout: Layout, parent_fd: int, target: str) -> dict | None:
    name = Path(target).name
    entry = _status_at(parent_fd, name)
    if entry is None:
        return None
    try:
        fd = _open_at(parent_fd, name)
    except FileNotFoundError:
        _fail("TARGET_IDENTITY_CHANGED")
    try:
        info = _verify_fd(fd, target, layout, exact_mode=TARGET_MODE)
        if (info.st_dev, info.st_ino) != (entry["dev"], entry["ino"]):
            _fail("TARGET_IDENTITY_CHANGED")
        return _identity(info)
    finally:
        os.close(fd)


def _preflight(layout: Layout) -> list[TargetState]:
    states: list[TargetState] = []
    for index, target in enumerate(TARGETS):
        planned = frozenset(TARGETS[:index])
        with _open_parent_chain(layout, target, planned_targets=planned) as (
            parent_fd, chain, missing
        ):
            if missing is not None:
                states.append(TargetState(target, "absent", None, chain, missing))
                continue
            identity = _inspect_target(layout, parent_fd, target)
            states.append(TargetState(
                target, "existing" if identity else "absent", identity, chain, None))
    return states


def _expected_identities(prepared: list[TargetState],
                         created: dict[str, dict]) -> dict[str, dict]:
    expected: dict[str, dict] = {"/": prepared[0].parent_chain[0]["identity"]}
    for state in prepared:
        for entry in state.parent_chain:
            expected.setdefault(entry["path"], entry["identity"])
        if state.identity is not None:
            expected[state.path] = state.identity
    expected.update(created)
    return expected


@contextmanager
def _open_verified_parent(
    layout: Layout,
    target: str,
    expected: dict[str, dict],
) -> Iterator[tuple[int, str]]:
    parts = Path(target).parts[1:]
    parent_parts = parts[:-1]
    planned: frozenset[str] = frozenset()
    with _open_parent_chain(layout, target, planned_targets=planned) as (
        parent_fd, chain, missing
    ):
        if missing is not None:
            _fail("PARENT_MISSING")
        for entry in chain:
            wanted = expected.get(entry["path"])
            if wanted is None:
                _fail("PARENT_UNVERIFIED")
            if not _same_identity(entry["identity"], wanted):
                _fail("PARENT_IDENTITY_CHANGED")
        parent_logical = "/" + "/".join(parent_parts)
        yield parent_fd, parent_logical


def _checkpoint(callback: Callable[[str], None] | None, label: str) -> None:
    if callback is not None:
        callback(label)


def _check_interrupt() -> None:
    if _INTERRUPTED:
        raise Interrupted("interrupted")


def _open_state_dir(layout: Layout, state_dir: Path) -> tuple[int, Path]:
    """Open the operator-provided journal/receipt directory without creating it."""
    if not state_dir.is_absolute():
        _fail("STATE_DIRECTORY_INVALID")
    try:
        info = os.lstat(state_dir)
    except OSError:
        _fail("STATE_DIRECTORY_UNAVAILABLE")
    if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode):
        _fail("STATE_DIRECTORY_UNSAFE")
    try:
        fd = os.open(str(state_dir), O_DIR)
    except OSError:
        _fail("STATE_DIRECTORY_UNSAFE")
    try:
        opened = _verify_fd(fd, str(state_dir), layout, exact_mode=STATE_MODE)
        if (opened.st_dev, opened.st_ino) != (info.st_dev, info.st_ino):
            _fail("STATE_DIRECTORY_IDENTITY_CHANGED")
    except BaseException:
        os.close(fd)
        raise
    real = Path(os.path.realpath(state_dir))
    for target in TARGETS:
        physical = Path(os.path.realpath(layout.physical(target)))
        if (real == physical or real in physical.parents or physical in real.parents):
            os.close(fd)
            _fail("STATE_DIRECTORY_OVERLAPS_TARGET")
    return fd, state_dir


def _lock_state(state_fd: int, layout: Layout) -> int:
    try:
        fd = os.open("lock", os.O_CREAT | os.O_RDWR | getattr(os, "O_NOFOLLOW", 0)
                     | getattr(os, "O_CLOEXEC", 0), FILE_MODE, dir_fd=state_fd)
    except OSError:
        _fail("STATE_LOCK_UNSAFE")
    try:
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != layout.owner_uid
                or info.st_gid != layout.owner_gid or _mode(info) != FILE_MODE):
            _fail("STATE_LOCK_UNSAFE")
        _verify_fd_matches_entry(state_fd, "lock", fd)
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            _fail("PROVISION_BUSY")
        except OSError:
            _fail("STATE_LOCK_FAILED")
        return fd
    except BaseException:
        os.close(fd)
        raise


def _read_regular_at(state_fd: int, name: str, layout: Layout,
                     maximum: int) -> bytes:
    try:
        fd = os.open(name, O_FILE, dir_fd=state_fd)
    except OSError:
        _fail("STATE_FILE_UNSAFE")
    try:
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != layout.owner_uid
                or info.st_gid != layout.owner_gid or _mode(info) != FILE_MODE
                or info.st_nlink != 1):
            _fail("STATE_FILE_UNSAFE")
        _verify_fd_matches_entry(state_fd, name, fd)
        return _read_all(fd, maximum)
    finally:
        os.close(fd)


class Journal:
    """Append-only, hash-chained, fsynced run journal."""

    def __init__(self, state_fd: int, name: str, layout: Layout):
        if not JOURNAL_NAME_RE.fullmatch(name):
            _fail("JOURNAL_NAME_INVALID")
        self.state_fd = state_fd
        self.name = name
        self.layout = layout
        try:
            self.fd = os.open(
                name,
                os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_APPEND
                | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0),
                FILE_MODE,
                dir_fd=state_fd,
            )
        except FileExistsError:
            _fail("JOURNAL_EXISTS")
        except OSError:
            _fail("JOURNAL_CREATE_FAILED")
        self.prev = "0" * 64
        self.seq = 0
        self.raw = bytearray()
        self.closed = False
        try:
            info = os.fstat(self.fd)
            if (not stat.S_ISREG(info.st_mode) or info.st_uid != layout.owner_uid
                    or info.st_gid != layout.owner_gid or _mode(info) != FILE_MODE
                    or info.st_nlink != 1):
                _fail("JOURNAL_UNSAFE")
            _verify_fd_matches_entry(state_fd, name, self.fd)
            os.fsync(state_fd)
        except BaseException:
            os.close(self.fd)
            self.closed = True
            raise

    def append(self, record: dict) -> None:
        if self.closed:
            _fail("JOURNAL_CLOSED")
        self.seq += 1
        payload = dict(record)
        payload["schema"] = SCHEMA
        payload["version"] = VERSION
        payload["seq"] = self.seq
        payload["prev_sha256"] = self.prev
        line = (json.dumps(payload, sort_keys=True, separators=(",", ":")) + "\n").encode()
        if len(self.raw) + len(line) > MAX_JOURNAL_BYTES:
            _fail("JOURNAL_TOO_LARGE")
        _verify_fd_matches_entry(self.state_fd, self.name, self.fd)
        _write_all(self.fd, line)
        os.fsync(self.fd)
        self.prev = _sha(line)
        self.raw.extend(line)

    @property
    def digest(self) -> str:
        return _sha(bytes(self.raw))

    def close(self) -> None:
        if not self.closed:
            self.closed = True
            try:
                os.fsync(self.fd)
            finally:
                os.close(self.fd)


def _parse_journal(state_fd: int, name: str, layout: Layout) -> dict:
    match = JOURNAL_NAME_RE.fullmatch(name)
    if not match:
        _fail("JOURNAL_NAME_INVALID")
    raw = _read_regular_at(state_fd, name, layout, MAX_JOURNAL_BYTES)
    if not raw.endswith(b"\n"):
        _fail("JOURNAL_MALFORMED")
    records: list[dict] = []
    prev = "0" * 64
    for index, line in enumerate(raw.splitlines(keepends=True)):
        if index >= MAX_JOURNAL_RECORDS:
            _fail("JOURNAL_MALFORMED")
        try:
            record = json.loads(line)
        except (UnicodeError, ValueError):
            _fail("JOURNAL_MALFORMED")
        if not isinstance(record, dict):
            _fail("JOURNAL_MALFORMED")
        if record.get("schema") != SCHEMA or record.get("version") != VERSION:
            _fail("JOURNAL_VERSION_UNSUPPORTED")
        if record.get("seq") != index + 1 or record.get("prev_sha256") != prev:
            _fail("JOURNAL_CHAIN_INVALID")
        prev = _sha(line)
        records.append(record)
    if not records or records[0].get("record") != "prepared":
        _fail("JOURNAL_MALFORMED")
    prepared = records[0]
    if prepared.get("run_id") != match.group(1):
        _fail("JOURNAL_IDENTITY_INVALID")
    declared = prepared.get("targets")
    if (not isinstance(declared, list)
            or not all(isinstance(item, dict) for item in declared)
            or [item.get("path") for item in declared] != list(TARGETS)):
        _fail("JOURNAL_TARGET_SET_INVALID")
    for item in declared:
        if item.get("state") not in ("existing", "absent"):
            _fail("JOURNAL_MALFORMED")
        identity = item.get("identity")
        if identity is not None and not isinstance(identity, dict):
            _fail("JOURNAL_MALFORMED")
        chain = item.get("parent_chain")
        if not isinstance(chain, list) or not all(
                isinstance(entry, dict) and isinstance(entry.get("path"), str)
                and isinstance(entry.get("identity"), dict) for entry in chain):
            _fail("JOURNAL_MALFORMED")
    expected_ns = prepared.get("created_ns")
    if not isinstance(expected_ns, int) or expected_ns <= 0:
        _fail("JOURNAL_MALFORMED")
    created: dict[str, dict] = {}
    removed: set[str] = set()
    hold_code: str | None = None
    terminal: str | None = None
    for record in records[1:]:
        kind = record.get("record")
        if kind == "created":
            path = record.get("path")
            identity = record.get("identity")
            if path not in TARGETS or not isinstance(identity, dict):
                _fail("JOURNAL_MALFORMED")
            created[path] = identity
        elif kind == "removed":
            path = record.get("path")
            if path not in TARGETS:
                _fail("JOURNAL_MALFORMED")
            removed.add(path)
        elif kind == "hold":
            if not isinstance(record.get("code"), str):
                _fail("JOURNAL_MALFORMED")
            hold_code = record["code"]
        elif kind in ("complete", "rolled_back"):
            terminal = kind
        else:
            _fail("JOURNAL_MALFORMED")
    return {
        "name": name,
        "run_id": match.group(1),
        "records": records,
        "prepared": prepared,
        "created": created,
        "removed": removed,
        "hold": hold_code,
        "terminal": terminal,
        "sha256": _sha(raw),
    }


def _scan_journals(state_fd: int, layout: Layout) -> list[dict]:
    try:
        names = os.listdir(state_fd)
    except OSError:
        _fail("STATE_DIRECTORY_UNAVAILABLE")
    journals = []
    for name in sorted(names):
        if JOURNAL_NAME_RE.fullmatch(name):
            journals.append(_parse_journal(state_fd, name, layout))
    return journals


def _publish_atomic(state_fd: int, name: str, payload: bytes, layout: Layout) -> str:
    if not RECEIPT_NAME_RE.fullmatch(name):
        _fail("RECEIPT_NAME_INVALID")
    temporary = f".{name}.{secrets.token_hex(8)}.tmp"
    fd = -1
    try:
        fd = os.open(
            temporary,
            os.O_CREAT | os.O_EXCL | os.O_WRONLY
            | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0),
            FILE_MODE,
            dir_fd=state_fd,
        )
        _write_all(fd, payload)
        os.fsync(fd)
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != layout.owner_uid
                or info.st_gid != layout.owner_gid or _mode(info) != FILE_MODE):
            _fail("RECEIPT_UNSAFE")
        os.close(fd)
        fd = -1
        try:
            os.link(temporary, name, src_dir_fd=state_fd, dst_dir_fd=state_fd,
                    follow_symlinks=False)
        except FileExistsError:
            _fail("RECEIPT_EXISTS")
        except OSError:
            _fail("RECEIPT_PUBLISH_FAILED")
        os.fsync(state_fd)
        os.unlink(temporary, dir_fd=state_fd)
        _read_regular_at(state_fd, name, layout, MAX_JOURNAL_BYTES)
        return name
    except BaseException:
        if fd >= 0:
            os.close(fd)
        try:
            os.unlink(temporary, dir_fd=state_fd)
        except OSError:
            pass
        raise


def _receipt_payload(
    *,
    outcome: str,
    code: str,
    run_id: str,
    script_sha256: str,
    journal: dict | None,
    states: list[TargetState],
    created: dict[str, dict],
    removed: tuple[str, ...],
    now_ns: int,
) -> bytes:
    targets = []
    for state in states:
        identity = created.get(state.path, state.identity)
        targets.append({
            "path": state.path,
            "state": "created" if state.path in created else state.state,
            "identity": identity,
        })
    payload = {
        "schema": SCHEMA,
        "record": "receipt",
        "version": VERSION,
        "run_id": run_id,
        "outcome": outcome,
        "code": code,
        "script_sha256": script_sha256,
        "journal": journal["name"] if journal else None,
        "journal_sha256": journal["sha256"] if journal else None,
        "created_ns": now_ns,
        "targets": targets,
        "removed": list(removed),
    }
    return (json.dumps(payload, sort_keys=True, separators=(",", ":")) + "\n").encode()


def _publish_receipt(
    state_fd: int,
    prefix: str,
    run_id: str,
    layout: Layout,
    *,
    outcome: str,
    code: str,
    script_sha256: str,
    journal: dict | None,
    states: list[TargetState],
    created: dict[str, dict],
    removed: tuple[str, ...],
    now_ns: int,
) -> str:
    name = f"{prefix}-{run_id}.json"
    payload = _receipt_payload(
        outcome=outcome, code=code, run_id=run_id, script_sha256=script_sha256,
        journal=journal, states=states, created=created, removed=removed, now_ns=now_ns,
    )
    return _publish_atomic(state_fd, name, payload, layout)


def _verify_target_identity(
    layout: Layout,
    target: str,
    expected: dict[str, dict],
    *,
    require_empty: bool,
    allowed_children: frozenset[str] = frozenset(),
) -> None:
    with _open_verified_parent(layout, target, expected) as (parent_fd, _parent):
        identity = _inspect_target(layout, parent_fd, target)
        if identity is None:
            _fail("TARGET_MISSING")
        wanted = expected.get(target)
        if wanted is None or not _same_identity(identity, wanted):
            _fail("TARGET_IDENTITY_CHANGED")
        try:
            fd = _open_at(parent_fd, Path(target).name)
        except OSError:
            _fail("TARGET_UNAVAILABLE")
        try:
            _verify_fd(fd, target, layout, exact_mode=TARGET_MODE)
            if require_empty:
                try:
                    entries = set(os.listdir(fd))
                except OSError:
                    _fail("TARGET_UNAVAILABLE")
                if entries != set(allowed_children):
                    _fail("TARGET_CONTENT_INVALID")
        finally:
            os.close(fd)


def _create_target(
    layout: Layout,
    target: str,
    expected: dict[str, dict],
    created: dict[str, dict],
    created_order: list[str],
    journal: Journal,
    checkpoint: Callable[[str], None] | None,
) -> None:
    _check_interrupt()
    _checkpoint(checkpoint, f"before_mkdir:{target}")
    _check_interrupt()
    with _open_verified_parent(layout, target, expected) as (parent_fd, parent_logical):
        _verify_fd(parent_fd, parent_logical, layout, exact_mode=PARENT_MODE)
        wanted_parent = expected.get(parent_logical)
        if wanted_parent is None or not _same_identity(
                _identity(os.fstat(parent_fd)), wanted_parent):
            _fail("PARENT_IDENTITY_CHANGED")
        if _status_at(parent_fd, Path(target).name) is not None:
            _fail("TARGET_EXISTS_UNEXPECTED")
        try:
            os.mkdir(Path(target).name, TARGET_MODE, dir_fd=parent_fd)
        except FileExistsError:
            _fail("TARGET_EXISTS_UNEXPECTED")
        except OSError:
            _fail("TARGET_CREATE_FAILED")
        try:
            fd = _open_at(parent_fd, Path(target).name)
        except OSError:
            _fail("TARGET_CREATE_UNVERIFIED")
        try:
            info = _verify_fd(fd, target, layout, exact_mode=TARGET_MODE)
            _verify_fd_matches_entry(parent_fd, Path(target).name, fd)
            identity = _identity(info)
        finally:
            os.close(fd)
        created[target] = identity
        created_order.append(target)
        expected[target] = identity
        _checkpoint(checkpoint, f"created_pending:{target}")
        journal.append({
            "record": "created",
            "path": target,
            "identity": identity,
            "created_ns": time.time_ns(),
        })
        os.fsync(parent_fd)
    _checkpoint(checkpoint, f"after_create:{target}")
    # Re-prove the just-created inode after the hook and before moving on.
    _verify_target_identity(layout, target, expected, require_empty=True)


def _rollback_created(
    layout: Layout,
    states: list[TargetState],
    expected: dict[str, dict],
    created: dict[str, dict],
    created_order: list[str],
    journal: Journal,
) -> tuple[bool, str]:
    removed: list[str] = []
    for target in reversed(created_order):
        try:
            with _open_verified_parent(layout, target, expected) as (parent_fd, _parent):
                identity = _inspect_target(layout, parent_fd, target)
                if identity is None:
                    continue
                if not _same_identity(identity, created.get(target)):
                    return False, "ROLLBACK_IDENTITY_MISMATCH"
                try:
                    fd = _open_at(parent_fd, Path(target).name)
                except OSError:
                    return False, "ROLLBACK_TARGET_UNAVAILABLE"
                try:
                    _verify_fd(fd, target, layout, exact_mode=TARGET_MODE, require_empty=True)
                finally:
                    os.close(fd)
                try:
                    os.rmdir(Path(target).name, dir_fd=parent_fd)
                except OSError:
                    return False, "ROLLBACK_REMOVE_FAILED"
                os.fsync(parent_fd)
            removed.append(target)
            journal.append({"record": "removed", "path": target,
                            "removed_ns": time.time_ns()})
        except ProvisionError:
            return False, "ROLLBACK_IDENTITY_MISMATCH"
    return True, ",".join(removed)


def _finish_rollback(
    *,
    state_dir: Path,
    state_fd: int,
    layout: Layout,
    journal: Journal,
    states: list[TargetState],
    expected: dict[str, dict],
    created: dict[str, dict],
    created_order: list[str],
    script_sha256: str,
    run_id: str,
    reason: str,
) -> Outcome:
    ok, detail = _rollback_created(
        layout, states, expected, created, created_order, journal)
    if ok and _residual_unexplained(layout, states):
        ok, detail = False, "UNEXPLAINED_STATE"
    removed = tuple(item for item in detail.split(",") if item) if ok else ()
    code = reason if ok else detail
    try:
        if ok:
            journal.append({"record": "rolled_back", "reason": reason,
                            "removed": list(removed), "created_ns": time.time_ns()})
        else:
            journal.append({"record": "hold", "code": detail,
                            "created_ns": time.time_ns()})
        journal_sha = journal.digest
        journal_name = journal.name
    except ProvisionError:
        journal_sha = journal.digest
        journal_name = journal.name
    finally:
        journal.close()
    journal_info = {"name": journal_name, "sha256": journal_sha}
    prefix = "receipt" if ok else "hold"
    outcome = "ROLLBACK" if ok else "HOLD"
    receipt = None
    try:
        receipt = _publish_receipt(
            state_fd, prefix, run_id, layout,
            outcome=outcome, code=code, script_sha256=script_sha256,
            journal=journal_info, states=states, created=created,
            removed=removed, now_ns=time.time_ns(),
        )
    except ProvisionError:
        receipt = None
    return Outcome(
        outcome, code, journal_name, receipt,
        created=tuple(created_order), existing=tuple(
            state.path for state in states if state.state == "existing"),
        removed=removed,
    )


def provision(
    *,
    layout: Layout = Layout(),
    state_dir: Path,
    mode: str = "apply",
    script_sha256: str,
    run_id: str | None = None,
    checkpoint: Callable[[str], None] | None = None,
    now_ns: int | None = None,
) -> Outcome:
    """Plan or apply the four-directory provisioning. ``plan`` never mutates."""
    if mode not in ("plan", "apply"):
        _fail("MODE_INVALID")
    if not SHA256_RE.fullmatch(script_sha256):
        _fail("SCRIPT_HASH_INVALID")
    run_id = run_id or secrets.token_hex(16)
    if not RUN_ID_RE.fullmatch(run_id):
        _fail("RUN_ID_INVALID")
    if layout == Layout():
        enforce_root_console(
            os.getuid(), os.geteuid(), os.getgid(), os.getegid(), os.environ)
    state_fd, _state_path = _open_state_dir(layout, state_dir)
    lock_fd = -1
    journal: Journal | None = None
    states: list[TargetState] = []
    created: dict[str, dict] = {}
    created_order: list[str] = []
    expected: dict[str, dict] = {}
    committed = False
    try:
        lock_fd = _lock_state(state_fd, layout)
        existing_journals = _scan_journals(state_fd, layout)
        unfinished = [item for item in existing_journals if item["terminal"] is None]
        if unfinished:
            _fail("RECOVERY_REQUIRED")
        states = _preflight(layout)
        expected = _expected_identities(states, {})
        now = now_ns or time.time_ns()
        _check_interrupt()

        if mode == "plan":
            receipt = _publish_receipt(
                state_fd, "plan", run_id, layout,
                outcome="PLAN", code="preflight_ok", script_sha256=script_sha256,
                journal=None, states=states, created={}, removed=(), now_ns=now,
            )
            return Outcome(
                "PLAN", "preflight_ok", None, receipt,
                existing=tuple(state.path for state in states if state.state == "existing"),
            )

        if all(state.state == "existing" for state in states):
            receipt = _publish_receipt(
                state_fd, "receipt", run_id, layout,
                outcome="NO_OP", code="already_complete", script_sha256=script_sha256,
                journal=None, states=states, created={}, removed=(), now_ns=now,
            )
            return Outcome(
                "NO_OP", "already_complete", None, receipt,
                existing=tuple(state.path for state in states),
            )

        if _status_at(state_fd, f"receipt-{run_id}.json") is not None:
            _fail("RECEIPT_EXISTS")
        if _status_at(state_fd, f"journal-{run_id}.jsonl") is not None:
            _fail("JOURNAL_EXISTS")

        previous_umask = os.umask(0o022)
        try:
            journal = Journal(state_fd, f"journal-{run_id}.jsonl", layout)
            journal.append({
                "record": "prepared",
                "run_id": run_id,
                "created_ns": now,
                "script_sha256": script_sha256,
                "targets": [
                    {
                        "path": state.path,
                        "state": state.state,
                        "identity": state.identity,
                        "parent_chain": state.parent_chain,
                        "planned_parent": state.planned_parent,
                    }
                    for state in states
                ],
            })
            for state in states:
                _check_interrupt()
                if state.state == "existing":
                    _verify_target_identity(
                        layout, state.path, expected, require_empty=False)
                    continue
                _create_target(
                    layout, state.path, expected, created, created_order,
                    journal, checkpoint,
                )
            _check_interrupt()
            for state in states:
                if state.state == "existing":
                    _verify_target_identity(
                        layout, state.path, expected, require_empty=False)
            for target in created_order:
                children = frozenset(
                    Path(other).name for other in created_order
                    if str(Path(other).parent) == target)
                _verify_target_identity(
                    layout, target, expected, require_empty=True,
                    allowed_children=children)
            _check_interrupt()
            journal.append({
                "record": "complete",
                "outcome": "PASS",
                "created_ns": time.time_ns(),
            })
            journal_sha = journal.digest
            journal_name = journal.name
            journal.close()
            committed = True
            journal_info = {"name": journal_name, "sha256": journal_sha}
            receipt = _publish_receipt(
                state_fd, "receipt", run_id, layout,
                outcome="PASS", code="provisioned", script_sha256=script_sha256,
                journal=journal_info, states=states, created=created,
                removed=(), now_ns=time.time_ns(),
            )
            return Outcome(
                "PASS", "provisioned", journal_name, receipt,
                created=tuple(created_order), existing=tuple(
                    state.path for state in states if state.path not in created),
            )
        except Interrupted as error:
            if journal is None:
                return _preflight_hold(state_fd, run_id, layout, script_sha256,
                                       states, now, error.code)
            return _finish_rollback(
                state_dir=state_dir, state_fd=state_fd, layout=layout, journal=journal,
                states=states, expected=expected, created=created,
                created_order=created_order, script_sha256=script_sha256,
                run_id=run_id, reason=error.code,
            )
        except KeyboardInterrupt:
            if journal is None:
                return _preflight_hold(state_fd, run_id, layout, script_sha256,
                                       states, now, "interrupted")
            return _finish_rollback(
                state_dir=state_dir, state_fd=state_fd, layout=layout, journal=journal,
                states=states, expected=expected, created=created,
                created_order=created_order, script_sha256=script_sha256,
                run_id=run_id, reason="interrupted",
            )
        except ProvisionError as error:
            if journal is None:
                return _preflight_hold(state_fd, run_id, layout, script_sha256,
                                       states, now, error.code)
            if committed:
                return Outcome("HOLD", error.code, journal.name, None,
                               created=tuple(created_order))
            return _finish_rollback(
                state_dir=state_dir, state_fd=state_fd, layout=layout, journal=journal,
                states=states, expected=expected, created=created,
                created_order=created_order, script_sha256=script_sha256,
                run_id=run_id, reason=error.code,
            )
        except Exception:
            if journal is None:
                return _preflight_hold(state_fd, run_id, layout, script_sha256,
                                       states, now, "internal_error")
            if committed:
                return Outcome("HOLD", "internal_error", journal.name, None,
                               created=tuple(created_order))
            return _finish_rollback(
                state_dir=state_dir, state_fd=state_fd, layout=layout, journal=journal,
                states=states, expected=expected, created=created,
                created_order=created_order, script_sha256=script_sha256,
                run_id=run_id, reason="internal_error",
            )
        finally:
            os.umask(previous_umask)
    except ProvisionError as error:
        return _preflight_hold(state_fd, run_id, layout, script_sha256,
                               states, now_ns or time.time_ns(), error.code)
    finally:
        if journal is not None:
            journal.close()
        if lock_fd >= 0:
            os.close(lock_fd)
        os.close(state_fd)


def _preflight_hold(
    state_fd: int,
    run_id: str,
    layout: Layout,
    script_sha256: str,
    states: list[TargetState],
    now_ns: int,
    code: str,
) -> Outcome:
    """Record a refusal that happened before any mutation."""
    receipt = None
    try:
        receipt = _publish_receipt(
            state_fd, "hold", run_id, layout,
            outcome="HOLD", code=code, script_sha256=script_sha256,
            journal=None, states=states, created={}, removed=(), now_ns=now_ns,
        )
    except ProvisionError:
        receipt = None
    return Outcome("HOLD", code, None, receipt)


def _parent_target_absent(target: str, states: list[TargetState]) -> bool:
    parent = str(Path(target).parent)
    return any(state.path == parent and state.state == "absent" for state in states)


def _residual_unexplained(layout: Layout, states: list[TargetState]) -> bool:
    """True when rollback left an inode this run cannot prove it created.

    An existing target must still be the same inode; a planned-absent target
    must be genuinely gone (unless its planned-absent parent is gone too). Any
    presence is treated as unexplained and escalates the outcome to HOLD.
    """
    for state in states:
        physical = layout.physical(state.path)
        if state.state == "existing":
            try:
                info = os.lstat(physical)
            except OSError:
                return True
            if not _same_identity(_identity(info), state.identity):
                return True
            continue
        if _parent_target_absent(state.path, states):
            continue
        try:
            os.lstat(physical)
        except FileNotFoundError:
            continue
        except OSError:
            return True
        return True
    return False


def _recovery_identity(
    layout: Layout,
    state: TargetState,
    journal: dict,
    expected: dict[str, dict],
) -> tuple[str, dict | None]:
    """Return (action, identity) for one target: keep, remove-record, remove-candidate."""
    with _open_verified_parent(layout, state.path, expected) as (parent_fd, _parent):
        current = _inspect_target(layout, parent_fd, state.path)
        if current is None:
            return "absent", None
        if state.state == "existing":
            if not _same_identity(current, state.identity):
                _fail("EXISTING_TARGET_CHANGED")
            return "keep", current
        recorded = journal["created"].get(state.path)
        if recorded is not None:
            if not _same_identity(current, recorded):
                _fail("UNEXPLAINED_STATE")
            try:
                fd = _open_at(parent_fd, Path(state.path).name)
            except OSError:
                _fail("UNEXPLAINED_STATE")
            try:
                info = _verify_fd(fd, state.path, layout, exact_mode=TARGET_MODE,
                                  require_empty=True)
            finally:
                os.close(fd)
            if info.st_ctime_ns < journal["prepared"]["created_ns"]:
                _fail("UNEXPLAINED_STATE")
            return "remove", current
        # Crash window: mkdir succeeded but the created record did not. The
        # prepared journal proves absence, the parent identity proves location,
        # and the creation time proves this run made the empty inode.
        if current["mode"] != f"{TARGET_MODE:04o}":
            _fail("UNEXPLAINED_STATE")
        try:
            fd = _open_at(parent_fd, Path(state.path).name)
        except OSError:
            _fail("UNEXPLAINED_STATE")
        try:
            info = _verify_fd(fd, state.path, layout, exact_mode=TARGET_MODE,
                              require_empty=True)
        finally:
            os.close(fd)
        if info.st_ctime_ns < journal["prepared"]["created_ns"]:
            _fail("UNEXPLAINED_STATE")
        return "remove-candidate", current


def recover(
    *,
    layout: Layout = Layout(),
    state_dir: Path,
    script_sha256: str,
    run_id: str | None = None,
    checkpoint: Callable[[str], None] | None = None,
    now_ns: int | None = None,
) -> Outcome:
    """Finish a prior interrupted run by removing only its proven inodes."""
    if not SHA256_RE.fullmatch(script_sha256):
        _fail("SCRIPT_HASH_INVALID")
    run_id = run_id or secrets.token_hex(16)
    if not RUN_ID_RE.fullmatch(run_id):
        _fail("RUN_ID_INVALID")
    if layout == Layout():
        enforce_root_console(
            os.getuid(), os.geteuid(), os.getgid(), os.getegid(), os.environ)
    state_fd, _state_path = _open_state_dir(layout, state_dir)
    lock_fd = -1
    try:
        lock_fd = _lock_state(state_fd, layout)
        journals = _scan_journals(state_fd, layout)
        unfinished = [item for item in journals if item["terminal"] is None]
        now = now_ns or time.time_ns()
        if not unfinished:
            receipt = _publish_receipt(
                state_fd, "recovery", run_id, layout,
                outcome="NO_OP", code="no_unfinished_journal",
                script_sha256=script_sha256, journal=None, states=[],
                created={}, removed=(), now_ns=now,
            )
            return Outcome("NO_OP", "no_unfinished_journal", None, receipt)
        if len(unfinished) > 1:
            _fail("MULTIPLE_UNFINISHED_JOURNALS")
        journal = unfinished[0]
        prepared = journal["prepared"]
        states = [
            TargetState(
                item["path"], item["state"], item.get("identity"),
                item.get("parent_chain") or [],
                item.get("planned_parent"),
            )
            for item in prepared["targets"]
        ]
        expected = _expected_identities(states, journal["created"])
        removed: list[str] = []
        for state in reversed(states):
            _check_interrupt()
            _checkpoint(checkpoint, f"recover:{state.path}")
            try:
                action, identity = _recovery_identity(layout, state, journal, expected)
            except ProvisionError as error:
                if (error.code == "PARENT_MISSING"
                        and _parent_target_absent(state.path, states)):
                    continue
                raise
            if action in ("remove", "remove-candidate"):
                with _open_verified_parent(layout, state.path, expected) as (
                        parent_fd, _parent):
                    current = _inspect_target(layout, parent_fd, state.path)
                    if current is None:
                        continue
                    if not _same_identity(current, identity):
                        _fail("UNEXPLAINED_STATE")
                    try:
                        fd = _open_at(parent_fd, Path(state.path).name)
                    except OSError:
                        _fail("UNEXPLAINED_STATE")
                    try:
                        _verify_fd(fd, state.path, layout, exact_mode=TARGET_MODE,
                                   require_empty=True)
                    finally:
                        os.close(fd)
                    try:
                        os.rmdir(Path(state.path).name, dir_fd=parent_fd)
                    except OSError:
                        _fail("RECOVERY_REMOVE_FAILED")
                    os.fsync(parent_fd)
                removed.append(state.path)
        for state in reversed(states):
            if state.state == "existing":
                _verify_target_identity(
                    layout, state.path, expected, require_empty=False)
            else:
                if _parent_target_absent(state.path, states):
                    continue
                with _open_verified_parent(layout, state.path, expected) as (
                        parent_fd, _parent):
                    if _inspect_target(layout, parent_fd, state.path) is not None:
                        _fail("RECOVERY_INCOMPLETE")
        journal_fd = os.open(
            journal["name"],
            os.O_WRONLY | os.O_APPEND | getattr(os, "O_NOFOLLOW", 0)
            | getattr(os, "O_CLOEXEC", 0),
            dir_fd=state_fd,
        )
        try:
            info = os.fstat(journal_fd)
            if (not stat.S_ISREG(info.st_mode) or info.st_uid != layout.owner_uid
                    or info.st_gid != layout.owner_gid or _mode(info) != FILE_MODE
                    or info.st_nlink != 1):
                _fail("JOURNAL_UNSAFE")
            _verify_fd_matches_entry(state_fd, journal["name"], journal_fd)
            tail = {
                "record": "rolled_back", "schema": SCHEMA, "version": VERSION,
                "seq": len(journal["records"]) + 1,
                "prev_sha256": _sha_of_last_line(state_fd, journal["name"], layout),
                "reason": "recovery", "removed": removed,
                "created_ns": time.time_ns(),
            }
            line = (json.dumps(tail, sort_keys=True, separators=(",", ":")) + "\n").encode()
            _write_all(journal_fd, line)
            os.fsync(journal_fd)
            os.fsync(state_fd)
        finally:
            os.close(journal_fd)
        full = _read_regular_at(state_fd, journal["name"], layout, MAX_JOURNAL_BYTES)
        journal_info = {"name": journal["name"], "sha256": _sha(full)}
        receipt = _publish_receipt(
            state_fd, "recovery", run_id, layout,
            outcome="ROLLBACK", code="recovery_complete", script_sha256=script_sha256,
            journal=journal_info, states=states, created=journal["created"],
            removed=tuple(removed), now_ns=time.time_ns(),
        )
        return Outcome("ROLLBACK", "recovery_complete", journal["name"], receipt,
                       removed=tuple(removed))
    except ProvisionError as error:
        journal_info = None
        states = []
        if "journal" in locals() and journal is not None:
            states = [
                TargetState(item["path"], item["state"], item.get("identity"),
                            item.get("parent_chain") or [], item.get("planned_parent"))
                for item in journal["prepared"]["targets"]
            ]
            journal_info = {"name": journal["name"], "sha256": journal["sha256"]}
        receipt = None
        try:
            receipt = _publish_receipt(
                state_fd, "hold", run_id, layout,
                outcome="HOLD", code=error.code, script_sha256=script_sha256,
                journal=journal_info, states=states, created={}, removed=(),
                now_ns=now_ns or time.time_ns(),
            )
        except ProvisionError:
            receipt = None
        return Outcome("HOLD", error.code,
                       journal_info["name"] if journal_info else None, receipt)
    finally:
        if lock_fd >= 0:
            os.close(lock_fd)
        os.close(state_fd)


def _sha_of_last_line(state_fd: int, name: str, layout: Layout) -> str:
    raw = _read_regular_at(state_fd, name, layout, MAX_JOURNAL_BYTES)
    lines = raw.splitlines(keepends=True)
    if not lines:
        _fail("JOURNAL_MALFORMED")
    return _sha(lines[-1])


def enforce_root_console(
    uid: int,
    euid: int,
    gid: int,
    egid: int,
    environ: dict,
) -> None:
    """Refuse delegation, a setuid transition, or an injected interpreter."""
    if DELEGATION_VARIABLES & set(environ):
        _fail("DELEGATED_INVOCATION_REFUSED")
    if UNSAFE_ENVIRONMENT_VARIABLES & set(environ):
        _fail("UNSAFE_ENVIRONMENT_REFUSED")
    if uid != 0 or euid != 0 or gid != 0 or egid != 0:
        _fail("ROOT_REQUIRED")


def _install_signal_handlers() -> None:
    def handler(signum: int, _frame) -> None:
        global _INTERRUPTED, _INTERRUPT_SIGNAL
        _INTERRUPTED = True
        _INTERRUPT_SIGNAL = signal.Signals(signum).name

    for signum in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP):
        signal.signal(signum, handler)


def verify_script(
    path: Path,
    expected_sha256: str,
    *,
    layout: Layout = Layout(),
) -> str:
    """Authenticate the running root-owned 0700 copy before any filesystem work."""
    if not SHA256_RE.fullmatch(expected_sha256):
        _fail("SCRIPT_HASH_INVALID")
    if not path.is_absolute():
        _fail("SCRIPT_LOCATION_UNTRUSTED")
    parent = path.parent
    try:
        parent_info = os.lstat(parent)
    except OSError:
        _fail("SCRIPT_LOCATION_UNTRUSTED")
    if (not stat.S_ISDIR(parent_info.st_mode) or stat.S_ISLNK(parent_info.st_mode)
            or parent_info.st_uid != layout.owner_uid
            or parent_info.st_gid != layout.owner_gid
            or _mode(parent_info) != STATE_MODE or _special_bits(parent_info.st_mode)):
        _fail("SCRIPT_LOCATION_UNTRUSTED")
    if layout == Layout():
        cursor = Path("/")
        for component in parent.parts[1:]:
            cursor /= component
            if cursor == parent:
                break
            try:
                info = os.lstat(cursor)
            except OSError:
                _fail("SCRIPT_LOCATION_UNTRUSTED")
            if (not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode)
                    or info.st_uid != layout.owner_uid
                    or info.st_gid != layout.owner_gid
                    or _mode(info) & 0o022 or _special_bits(info.st_mode)):
                _fail("SCRIPT_LOCATION_UNTRUSTED")
    try:
        parent_fd = os.open(str(parent), O_DIR)
    except OSError:
        _fail("SCRIPT_LOCATION_UNTRUSTED")
    try:
        _verify_fd(parent_fd, str(parent), layout, exact_mode=STATE_MODE)
        try:
            fd = os.open(path.name, O_FILE, dir_fd=parent_fd)
        except OSError:
            _fail("SCRIPT_LOCATION_UNTRUSTED")
        try:
            info = os.fstat(fd)
            if (not stat.S_ISREG(info.st_mode) or info.st_uid != layout.owner_uid
                    or info.st_gid != layout.owner_gid or _mode(info) != STATE_MODE
                    or info.st_nlink != 1 or _special_bits(info.st_mode)
                    or info.st_size > MAX_SCRIPT_BYTES):
                _fail("SCRIPT_LOCATION_UNTRUSTED")
            _verify_fd_matches_entry(parent_fd, path.name, fd)
            raw = _read_all(fd, MAX_SCRIPT_BYTES)
        finally:
            os.close(fd)
    finally:
        os.close(parent_fd)
    digest = _sha(raw)
    if digest != expected_sha256:
        _fail("SCRIPT_HASH_MISMATCH")
    return digest


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Provision only the four 2.1.4 production helper parent directories.")
    action = parser.add_mutually_exclusive_group()
    action.add_argument("--plan", action="store_true",
                        help="read-only preflight; publish a plan receipt (default)")
    action.add_argument("--apply", action="store_true",
                        help="create only the target directories proven absent")
    action.add_argument("--recover", action="store_true",
                        help="identity-bound removal of an interrupted run's own directories")
    parser.add_argument("--state-dir", default="/root/wpcaregrid-deploy-parents-2.1.4",
                        type=Path)
    parser.add_argument("--expected-sha256", default="")
    parser.add_argument("--version", action="store_true")
    args = parser.parse_args(argv)
    if args.version:
        print(VERSION)
        return 0
    if not args.expected_sha256:
        print("PROVISION=HOLD code=SCRIPT_HASH_REQUIRED")
        return 1
    try:
        enforce_root_console(
            os.getuid(), os.geteuid(), os.getgid(), os.getegid(), os.environ)
        verify_script(Path(os.path.abspath(__file__)), args.expected_sha256)
        mode = "plan" if args.plan or not (args.apply or args.recover) else (
            "apply" if args.apply else None)
        if args.recover:
            outcome = recover(
                layout=Layout(), state_dir=args.state_dir,
                script_sha256=args.expected_sha256)
        else:
            outcome = provision(
                layout=Layout(), state_dir=args.state_dir, mode=mode,
                script_sha256=args.expected_sha256)
    except ProvisionError as error:
        print(f"PROVISION=HOLD code={error.code}")
        return 1
    except OSError:
        print("PROVISION=HOLD code=INTERNAL_ERROR")
        return 1
    line = f"PROVISION={outcome.status} code={outcome.code}"
    if outcome.journal:
        line += f" journal={outcome.journal}"
    if outcome.receipt:
        line += f" receipt={outcome.receipt}"
    print(line)
    if outcome.created:
        print("CREATED=" + ",".join(outcome.created))
    if outcome.removed:
        print("REMOVED=" + ",".join(outcome.removed))
    if outcome.status in ("PLAN", "PASS", "NO_OP"):
        return 0
    if outcome.status == "ROLLBACK" and args.recover:
        return 0
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
