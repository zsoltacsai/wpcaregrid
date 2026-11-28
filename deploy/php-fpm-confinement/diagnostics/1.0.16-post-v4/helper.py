#!/usr/bin/env python3
"""Read-only, one-run diagnostic summary for the 2026-11-28 post-io-v4 HOLD.

This is not an acceptance verifier. It reads only the pinned package inputs and
the allowlisted direct children of one exact evidence directory. It emits one
redacted JSON object to stdout and does not write files, caches, or diagnostics.
Run with ``python3 -B`` after this file has passed versioned review and CI.
"""
from __future__ import annotations

import sys
sys.dont_write_bytecode = True

import hashlib
import json
import os
import re
import stat
import types

_PROGRESS_FD: int | None = None
_PROGRESS_STAGES = {
    "PIN_LOAD", "EVIDENCE_ENUMERATE", "EVIDENCE_READ", "JSON_PARSE",
    "TRACEBACK_PARSE", "AUDIT_SUMMARY", "OUTPUT",
}


def _progress(stage: str) -> None:
    """Send one allowlisted token to the watchdog's inherited progress pipe."""
    if _PROGRESS_FD is None or stage not in _PROGRESS_STAGES:
        return
    try:
        os.write(_PROGRESS_FD, (stage + "\n").encode("ascii"))
    except OSError:
        # Progress is advisory; the parent watchdog still enforces the deadline.
        pass

PACKAGE_DIR = "/root/wpcg-gate2.ujob3I/live-acceptance.j9lqdz/package-stage-1.0.16-442fa9ab-modes2/package-1.0.16"
ENVELOPE_DIR = "/root/wpcg-gate2-post-20261128T130445Z-v4-7q3m"
RUN_DIR_NAME = "acceptance-1.0.16-post-20261128T130846Z-d424f9"
MAX_FILE_BYTES = 24_000_000
MAX_TOTAL_EVIDENCE_BYTES = 48_000_000
MAX_CHILDREN = 256
MAX_RUN_SECONDS = 1800

PINNED_PACKAGE_FILES = {
    "SHA256SUMS": "5a141648ee771e6ceaa4ff296720857bb52ecd9f1c995d6e58b7e6016a50e622",
    "binding.json": "41d10ad9d8d0c3d03e852bed449b3783aef387a5e55be1e9b9077cd37632633d",
    "acceptance/matrix.json": "3ea81dd061e77b27f89b410103b98ba36c5c8b6ab2ed881d4907d4af7bcfcb5a",
    "acceptance/probe.py": "5cde081b25341f804f828f18615bc868b30bba1c70454dc1cbf6bc09ac9ff0af",
    "acceptance/derive-probe-profile.py": "22503809027074cce467ab8651531b1d9b5d75732af165be7f8cafcc83202a8a",
    "acceptance/verify-acceptance.py": "b821e435ab42ea9f06c2fbe57b0496f1e16e47bc625c3d3f6d51c448586f8c24",
}
# Exact Git tree modes for the pinned blobs above. Executable bits are part of
# the package pin contract; treating every blob as 0644 rejects the two
# executable probe/profile scripts in the 1.0.16 tree.
PINNED_PACKAGE_MODES = {
    "SHA256SUMS": 0o644,
    "binding.json": 0o644,
    "acceptance/matrix.json": 0o644,
    "acceptance/probe.py": 0o755,
    "acceptance/derive-probe-profile.py": 0o755,
    "acceptance/verify-acceptance.py": 0o644,
}
EVIDENCE_ALLOWLIST = {
    "preflight-summary.json",
    "wpcaregrid-nginx-inventory-before.json",
    "wpcaregrid-nginx-baseline.json",
    "wpcaregrid-nginx-baseline.stderr",
    "wpcaregrid-nginx-baseline.stderr.systemd-run",
    "wpcaregrid-nginx-confined.json",
    "wpcaregrid-nginx-confined.stderr",
    "wpcaregrid-nginx-confined.stderr.systemd-run",
    "wpcaregrid-nginx-derive.stderr",
    "wpcaregrid-nginx-audit.jsonl",
    "wpcaregrid-nginx-audit.stderr",
    "wpcaregrid-nginx-inventory-before.stderr",
    "wpcaregrid-nginx-verifier.stdout",
    "wpcaregrid-nginx-verifier.stderr",
}
PROBE_REQUIRED_KEYS = {
    "schema", "mode", "profile", "label", "pid", "starttime_ticks",
    "kernel_start_ns", "started_ns", "ended_ns", "boot_id", "matrix_sha256",
    "peers", "results", "fd0", "scm", "scm_socket",
}
SAFE_TOKEN = re.compile(r"[A-Za-z0-9_.:-]{1,100}\Z")
BOOT_ID = re.compile(r"[0-9a-fA-F-]{32,36}\Z")
KNOWN_EVIDENCE_NAMES = EVIDENCE_ALLOWLIST | {"preflight-summary.json"}
RESULT_STATES = {"fstat-failed", "not-canary-file", "read", "channel-failed", "no-descriptor", "received"}
RESULT_STAGES = {"connect", "recv", "read"}
PREFLIGHT_PIN_KEYS = {
    "package_tree", "sums", "binding", "matrix", "collector", "derive", "probe",
    "sender", "listener", "verifier", "fixtures",
}
PREFLIGHT_SERVICE_KEYS = {"nginx", "production_fpm", "php84_active", "stock_php85_active"}
AUDIT_OPERATION_TOKENS = {
    "bind", "connect", "create", "exec", "file_perm", "getattr", "mmap",
    "open", "ptrace", "readlink", "receive", "sendmsg", "signal",
    "socket_bind", "socket_connect", "socket_create", "unlink",
}


class DiagnosticHold(Exception):
    def __init__(self, code: str):
        super().__init__(code)
        self.code = code


def _kind(mode: int) -> str:
    if stat.S_ISREG(mode):
        return "regular"
    if stat.S_ISDIR(mode):
        return "directory"
    if stat.S_ISLNK(mode):
        return "symlink"
    return "special"


def _safe_name(name: str) -> tuple[str, str | None]:
    if name in KNOWN_EVIDENCE_NAMES:
        return name, None
    return "UNKNOWN_BASENAME", hashlib.sha256(name.encode("utf-8", "replace")).hexdigest()


def _meta(name: str, info: os.stat_result) -> dict:
    shown_name, name_digest = _safe_name(name)
    return {
        "basename": shown_name,
        "basename_sha256": name_digest,
        "type": _kind(info.st_mode),
        "mode": format(stat.S_IMODE(info.st_mode), "04o"),
        "uid": info.st_uid,
        "gid": info.st_gid,
        "nlink": info.st_nlink,
        "size": info.st_size,
        "mtime_ns": info.st_mtime_ns,
    }


def _same_file(a: os.stat_result, b: os.stat_result) -> bool:
    return (
        stat.S_ISREG(a.st_mode)
        and stat.S_ISREG(b.st_mode)
        and (a.st_dev, a.st_ino, a.st_uid, a.st_gid, stat.S_IMODE(a.st_mode), a.st_nlink, a.st_size,
             a.st_mtime_ns, a.st_ctime_ns)
        == (b.st_dev, b.st_ino, b.st_uid, b.st_gid, stat.S_IMODE(b.st_mode), b.st_nlink, b.st_size,
            b.st_mtime_ns, b.st_ctime_ns)
    )


def _same_directory(a: os.stat_result, b: os.stat_result) -> bool:
    return (
        stat.S_ISDIR(a.st_mode)
        and stat.S_ISDIR(b.st_mode)
        and (a.st_dev, a.st_ino, a.st_uid, a.st_gid, stat.S_IMODE(a.st_mode), a.st_nlink)
        == (b.st_dev, b.st_ino, b.st_uid, b.st_gid, stat.S_IMODE(b.st_mode), b.st_nlink)
    )


def _directory_mode(info: os.stat_result, expected_mode: int | None = None) -> bool:
    mode = stat.S_IMODE(info.st_mode)
    return (
        stat.S_ISDIR(info.st_mode)
        and info.st_uid == 0
        and info.st_gid == 0
        and not mode & 0o022
        and (expected_mode is None or mode == expected_mode)
    )


def _directory_flags() -> int:
    nofollow = getattr(os, "O_NOFOLLOW", None)
    directory = getattr(os, "O_DIRECTORY", None)
    if nofollow is None or directory is None:
        raise DiagnosticHold("nofollow-directory-unavailable")
    return os.O_RDONLY | nofollow | directory | getattr(os, "O_CLOEXEC", 0)


def _open_dir_at(parent_fd: int, name: str, *, expected_mode: int | None = None) -> int:
    """Open and verify a root-owned directory relative to an already pinned parent fd."""
    if not name or name in (".", "..") or "/" in name or "\\x00" in name:
        raise DiagnosticHold("directory-component-invalid")
    try:
        before = os.stat(name, dir_fd=parent_fd, follow_symlinks=False)
    except OSError:
        raise DiagnosticHold("input-unavailable") from None
    if not _directory_mode(before, expected_mode):
        raise DiagnosticHold("directory-metadata")
    fd = None
    try:
        fd = os.open(name, _directory_flags(), dir_fd=parent_fd)
        opened = os.fstat(fd)
        if not _directory_mode(opened, expected_mode) or not _same_directory(before, opened):
            raise DiagnosticHold("directory-raced")
        return fd
    except DiagnosticHold:
        if fd is not None:
            os.close(fd)
        raise
    except OSError:
        if fd is not None:
            os.close(fd)
        raise DiagnosticHold("directory-open-failed") from None


def _open_absolute_directory(path: str, *, expected_mode: int) -> int:
    """Walk from / using dirfds so no parent path component can be swapped after validation."""
    if not os.path.isabs(path) or os.path.normpath(path) != path or path == "/":
        raise DiagnosticHold("directory-path-noncanonical")
    flags = _directory_flags()
    try:
        current_fd = os.open("/", flags)
    except OSError:
        raise DiagnosticHold("root-directory-open-failed") from None
    try:
        root_info = os.fstat(current_fd)
        if not _directory_mode(root_info, 0o755):
            raise DiagnosticHold("root-directory-metadata")
        components = path.strip("/").split("/")
        for index, component in enumerate(components):
            next_fd = _open_dir_at(current_fd, component,
                                   expected_mode=expected_mode if index == len(components) - 1 else None)
            os.close(current_fd)
            current_fd = next_fd
        return current_fd
    except DiagnosticHold:
        os.close(current_fd)
        raise
    except OSError:
        os.close(current_fd)
        raise DiagnosticHold("directory-walk-failed") from None


def _read_regular_at(directory_fd: int, name: str, expected_sha256: str | None = None,
                     *, expected_mode: int | None = None, display_name: str | None = None) -> tuple[bytes, dict]:
    """Read one file by basename relative to a pinned directory fd; never follow links."""
    if not name or name in (".", "..") or "/" in name or "\\x00" in name:
        raise DiagnosticHold("input-name-invalid")
    try:
        before = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
    except OSError:
        raise DiagnosticHold("input-unavailable") from None
    if not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or before.st_gid != 0 or before.st_nlink != 1:
        raise DiagnosticHold("input-metadata")
    if expected_mode is not None and stat.S_IMODE(before.st_mode) != expected_mode:
        raise DiagnosticHold("input-mode")
    if before.st_size < 0 or before.st_size > MAX_FILE_BYTES:
        raise DiagnosticHold("input-size-bound")
    nofollow = getattr(os, "O_NOFOLLOW", None)
    if nofollow is None:
        raise DiagnosticHold("nofollow-unavailable")
    nonblock = getattr(os, "O_NONBLOCK", None)
    if nonblock is None:
        raise DiagnosticHold("nonblock-unavailable")
    flags = os.O_RDONLY | nofollow | nonblock | getattr(os, "O_CLOEXEC", 0)
    try:
        fd = os.open(name, flags, dir_fd=directory_fd)
        try:
            opened = os.fstat(fd)
            if not _same_file(before, opened):
                raise DiagnosticHold("input-raced")
            chunks, total = [], 0
            while True:
                chunk = os.read(fd, min(65536, MAX_FILE_BYTES + 1 - total))
                if not chunk:
                    break
                chunks.append(chunk)
                total += len(chunk)
                if total > MAX_FILE_BYTES:
                    raise DiagnosticHold("input-size-bound")
            after = os.fstat(fd)
            data = b"".join(chunks)
            if len(data) != before.st_size or not _same_file(opened, after):
                raise DiagnosticHold("input-raced")
        finally:
            os.close(fd)
    except DiagnosticHold:
        raise
    except OSError:
        raise DiagnosticHold("input-read-failed") from None
    digest = hashlib.sha256(data).hexdigest()
    if expected_sha256 is not None and digest != expected_sha256:
        raise DiagnosticHold("pinned-input-digest-mismatch")
    return data, {**_meta(display_name or name, before), "sha256": digest}


def _read_pinned_relative(root_fd: int, relative: str, digest: str) -> tuple[bytes, dict]:
    components = relative.split("/")
    if any(not component or component in (".", "..") for component in components):
        raise DiagnosticHold("package-path-invalid")
    expected_mode = PINNED_PACKAGE_MODES.get(relative)
    if expected_mode is None or relative not in PINNED_PACKAGE_FILES:
        raise DiagnosticHold("package-path-unpinned")
    current_fd = root_fd
    owned_fd = None
    try:
        for component in components[:-1]:
            next_fd = _open_dir_at(current_fd, component, expected_mode=0o755)
            if owned_fd is not None:
                os.close(owned_fd)
            current_fd = owned_fd = next_fd
        return _read_regular_at(current_fd, components[-1], digest, expected_mode=expected_mode,
                                display_name=relative)
    finally:
        if owned_fd is not None:
            os.close(owned_fd)


def _load_pins(package_dir: str) -> tuple[dict, types.ModuleType]:
    package_fd = _open_absolute_directory(package_dir, expected_mode=0o755)
    pin_bytes = {}
    pin_metadata = {}
    try:
        for relative, digest in PINNED_PACKAGE_FILES.items():
            data, metadata = _read_pinned_relative(package_fd, relative, digest)
            pin_bytes[relative] = data
            pin_metadata[relative] = metadata
    finally:
        os.close(package_fd)
    expected_manifest = {}
    try:
        for line in pin_bytes["SHA256SUMS"].decode("ascii").splitlines():
            match = re.fullmatch(r"([0-9a-f]{64})  ([A-Za-z0-9_./-]+)", line)
            if not match:
                raise DiagnosticHold("package-manifest-format")
            expected_manifest[match.group(2)] = match.group(1)
    except UnicodeDecodeError:
        raise DiagnosticHold("package-manifest-format") from None
    for relative in PINNED_PACKAGE_FILES:
        if relative == "SHA256SUMS":
            continue
        if expected_manifest.get(relative) != hashlib.sha256(pin_bytes[relative]).hexdigest():
            raise DiagnosticHold("package-manifest-mismatch")
    try:
        binding = json.loads(pin_bytes["binding.json"])
    except (ValueError, json.JSONDecodeError):
        raise DiagnosticHold("package-binding-parse") from None
    if not isinstance(binding, dict) or binding.get("package_version") != "1.0.16":
        raise DiagnosticHold("package-binding-version")
    module = types.ModuleType("wpcg_pinned_post_verifier")
    module.__file__ = "<pinned-1.0.16-verify-acceptance.py>"
    try:
        exec(compile(pin_bytes["acceptance/verify-acceptance.py"], module.__file__, "exec"), module.__dict__)
        matrix = json.loads(pin_bytes["acceptance/matrix.json"])
    except Exception:
        raise DiagnosticHold("pinned-code-load") from None
    if not isinstance(matrix, dict) or "wpcaregrid-nginx" not in matrix.get("profiles", {}):
        raise DiagnosticHold("pinned-matrix-schema")
    return {"files": list(pin_metadata.values()), "matrix": matrix, "matrix_sha256": PINNED_PACKAGE_FILES["acceptance/matrix.json"]}, module


def _run_start_ns() -> int:
    match = re.fullmatch(r"acceptance-1\.0\.16-post-(\d{8}T\d{6}Z)-[0-9a-f]{6}", RUN_DIR_NAME)
    if not match:
        raise DiagnosticHold("run-id-format")
    import datetime
    parsed = datetime.datetime.strptime(match.group(1), "%Y%m%dT%H%M%SZ").replace(tzinfo=datetime.timezone.utc)
    return int(parsed.timestamp()) * 1_000_000_000


def _evidence_manifest(run_dir: str) -> tuple[list[dict], dict[str, bytes], set[str]]:
    if os.path.abspath(os.path.dirname(run_dir)) != os.path.abspath(ENVELOPE_DIR):
        raise DiagnosticHold("run-dir-not-direct-child")
    if os.path.basename(run_dir) != RUN_DIR_NAME:
        raise DiagnosticHold("run-id-mismatch")
    start = _run_start_ns()
    envelope_fd = _open_absolute_directory(ENVELOPE_DIR, expected_mode=0o700)
    _progress("EVIDENCE_ENUMERATE")
    try:
        run_fd = _open_dir_at(envelope_fd, RUN_DIR_NAME, expected_mode=0o700)
    finally:
        os.close(envelope_fd)
    try:
        names = sorted(os.listdir(run_fd))
    except OSError:
        os.close(run_fd)
        raise DiagnosticHold("evidence-list-failed") from None
    if len(names) > MAX_CHILDREN:
        os.close(run_fd)
        raise DiagnosticHold("evidence-child-bound")
    manifest, selected, unknown = [], {}, set()
    selected_bytes = 0
    for name in names:
        if name in (".", "..") or os.path.basename(name) != name or "\x00" in name:
            manifest.append({"basename": "<invalid-name>", "origin": "UNKNOWN", "sha256": None})
            continue
        try:
            info = os.stat(name, dir_fd=run_fd, follow_symlinks=False)
        except OSError:
            shown_name, name_digest = _safe_name(name)
            manifest.append({"basename": shown_name, "basename_sha256": name_digest,
                             "type": "unavailable", "origin": "UNKNOWN", "sha256": None})
            continue
        item = _meta(name, info)
        if not stat.S_ISREG(info.st_mode):
            item.update({"origin": "UNKNOWN", "sha256": None, "reason_code": "not-regular"})
            manifest.append(item)
            unknown.add(name)
            continue
        if name not in EVIDENCE_ALLOWLIST:
            item.update({"origin": "UNKNOWN", "sha256": None, "reason_code": "filename-not-allowlisted"})
            manifest.append(item)
            unknown.add(name)
            continue
        if selected_bytes + info.st_size > MAX_TOTAL_EVIDENCE_BYTES:
            item.update({"origin": "UNKNOWN", "sha256": None, "reason_code": "aggregate-size-bound"})
            manifest.append(item)
            unknown.add(name)
            continue
        trusted_meta = (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode), info.st_nlink) == (0, 0, 0o600, 1)
        in_run_window = start <= info.st_mtime_ns <= start + MAX_RUN_SECONDS * 1_000_000_000 and start <= info.st_ctime_ns <= start + MAX_RUN_SECONDS * 1_000_000_000
        if not trusted_meta or not in_run_window or info.st_size > MAX_FILE_BYTES:
            item.update({"origin": "UNKNOWN", "sha256": None, "reason_code": "metadata-or-time-window"})
            manifest.append(item)
            unknown.add(name)
            continue
        try:
            _progress("EVIDENCE_READ")
            data, secure = _read_regular_at(run_fd, name, expected_mode=0o600)
        except DiagnosticHold as exc:
            item.update({"origin": "UNKNOWN", "sha256": None, "reason_code": exc.code})
            manifest.append(item)
            unknown.add(name)
            continue
        item.update({"origin": "BOUND_TO_RUN_DIR_AND_TIME", "sha256": secure["sha256"]})
        manifest.append(item)
        selected[name] = data
        selected_bytes += len(data)
    os.close(run_fd)
    return manifest, selected, unknown


def _bounded_token(value, allowed: set[str] | None = None) -> str:
    if isinstance(value, str) and SAFE_TOKEN.fullmatch(value) and (allowed is None or value in allowed):
        return value
    return "UNKNOWN"


def _probe_summary(name: str, data: bytes | None, matrix: dict, expected_mode: str, verifier: types.ModuleType) -> tuple[dict, dict | None, str]:
    if data is None:
        return {"filename": name, "status": "ABSENT_OR_UNBOUND"}, None, "unavailable"
    if not data:
        return {"filename": name, "status": "EMPTY"}, None, "empty"
    try:
        _progress("JSON_PARSE")
        doc = json.loads(data)
    except (ValueError, json.JSONDecodeError):
        return {"filename": name, "status": "INVALID_JSON"}, None, "invalid-json"
    if not isinstance(doc, dict):
        return {"filename": name, "status": "INVALID_SCHEMA"}, None, "invalid-schema"
    profile = "wpcaregrid-nginx"
    profile_spec = matrix.get("profiles", {}).get(profile, {})
    allowed_roles = set(profile_spec.get("peer_roles", []))
    raw_peers = doc.get("peers")
    if not isinstance(raw_peers, dict) or set(raw_peers) - allowed_roles:
        return {"filename": name, "status": "INVALID_SCHEMA"}, None, "invalid-schema"
    peers, peer_count = {}, 0
    for role, pids in raw_peers.items():
        if not isinstance(pids, list) or len(pids) > 128 or any(type(pid) is not int or pid <= 0 for pid in pids):
            return {"filename": name, "status": "INVALID_SCHEMA"}, None, "invalid-schema"
        peer_count += len(pids)
        if peer_count > 512:
            return {"filename": name, "status": "INVALID_SCHEMA"}, None, "invalid-schema"
        peers[role] = pids
    expected_label = "unconfined" if expected_mode == "baseline" else profile + "-probe (enforce)"
    actual_label = doc.get("label")
    label_ok = actual_label == expected_label if expected_mode == "baseline" else actual_label in (expected_label, "unconfined//&" + expected_label)
    result_ids = set()
    try:
        expected, _targets = verifier.expected_checks(matrix, profile, peers, "post")
        result_ids = set(expected)
    except Exception:
        expected = {}
    results = doc.get("results")
    if not isinstance(results, list) or len(results) > 2048:
        return {"filename": name, "status": "INVALID_SCHEMA"}, None, "invalid-schema"
    safe_results = []
    seen = set()
    duplicate_ids = False
    for row in results:
        if not isinstance(row, dict):
            continue
        raw_id = row.get("id")
        id_safe = raw_id if isinstance(raw_id, str) and raw_id in result_ids else "UNKNOWN_RESULT"
        if id_safe != "UNKNOWN_RESULT" and id_safe in seen:
            duplicate_ids = True
        if id_safe != "UNKNOWN_RESULT":
            seen.add(id_safe)
        safe_results.append({
            "id": _verifier_failure_id(raw_id, result_ids, matrix) if id_safe != "UNKNOWN_RESULT" else id_safe,
            "state": _bounded_token(row.get("state"), RESULT_STATES),
            "stage": _bounded_token(row.get("stage"), RESULT_STAGES),
            "errno": row.get("errno") if type(row.get("errno")) is int else None,
            "pid": row.get("pid") if type(row.get("pid")) is int else None,
            "t0": row.get("t0") if type(row.get("t0")) is int else None,
            "t1": row.get("t1") if type(row.get("t1")) is int else None,
            "child": row.get("child") if type(row.get("child")) is int else None,
        })
    boot = doc.get("boot_id")
    boot_ok = isinstance(boot, str) and BOOT_ID.fullmatch(boot) is not None
    started_ns, ended_ns = doc.get("started_ns"), doc.get("ended_ns")
    window_ok = type(started_ns) is int and type(ended_ns) is int and 0 < started_ns <= ended_ns
    pid_ok = type(doc.get("pid")) is int and doc["pid"] > 0
    starttime_ok = type(doc.get("starttime_ticks")) is int and doc["starttime_ticks"] > 0
    expected_matrix = PINNED_PACKAGE_FILES["acceptance/matrix.json"]
    matrix_match = doc.get("matrix_sha256") == expected_matrix
    schema_ok = set(doc) == PROBE_REQUIRED_KEYS and type(doc.get("schema")) is int and doc.get("schema") == 2
    mode_ok = doc.get("mode") == expected_mode
    profile_ok = doc.get("profile") == profile
    audit_eligible = all((schema_ok, mode_ok, profile_ok, label_ok, matrix_match,
                          boot_ok, window_ok, pid_ok, starttime_ok))
    identity = {
        "filename": name,
        "status": "PARSED",
        "schema": doc.get("schema") if type(doc.get("schema")) is int else None,
        "schema_ok": schema_ok,
        "mode": expected_mode if mode_ok else "UNKNOWN",
        "mode_match": mode_ok,
        "profile": profile if profile_ok else "UNKNOWN",
        "profile_match": profile_ok,
        "label_class": ("expected-baseline" if expected_mode == "baseline" else "expected-confined") if label_ok else "unexpected-redacted",
        "label_match": label_ok,
        "pid": doc.get("pid") if type(doc.get("pid")) is int else None,
        "starttime_ticks": doc.get("starttime_ticks") if type(doc.get("starttime_ticks")) is int else None,
        "kernel_start_ns": doc.get("kernel_start_ns") if type(doc.get("kernel_start_ns")) is int else None,
        "started_ns": started_ns if type(started_ns) is int else None,
        "ended_ns": ended_ns if type(ended_ns) is int else None,
        "matrix_sha256": doc.get("matrix_sha256") if isinstance(doc.get("matrix_sha256"), str) and re.fullmatch(r"[0-9a-f]{64}", doc.get("matrix_sha256")) else None,
        "matrix_match": matrix_match,
        "boot_id_valid": boot_ok,
        "window_valid": window_ok,
        "pid_valid": pid_ok,
        "starttime_valid": starttime_ok,
        "audit_correlation_eligible": audit_eligible,
        "audit_correlation_reason": "IDENTITY_AND_WINDOW_MATCH" if audit_eligible else "IDENTITY_OR_WINDOW_MISMATCH",
        "boot_id_sha256": hashlib.sha256(boot.encode()).hexdigest() if boot_ok else None,
        "results_count": len(results),
        "duplicate_result_ids": duplicate_ids,
        "results": safe_results,
        "fd0": _state_fields(doc.get("fd0")),
        "scm": _state_fields(doc.get("scm")),
    }
    return identity, doc, "parsed"


def _state_fields(value: object) -> dict:
    if not isinstance(value, dict):
        return {"state": "UNKNOWN", "errno": None, "t0": None, "t1": None}
    return {
        "state": _bounded_token(value.get("state"), RESULT_STATES),
        "errno": value.get("errno") if type(value.get("errno")) is int else None,
        "t0": value.get("t0") if type(value.get("t0")) is int else None,
        "t1": value.get("t1") if type(value.get("t1")) is int else None,
    }


def _stderr_class(data: bytes | None) -> str:
    if data is None or not data:
        return "empty"
    text = data.decode("utf-8", "replace").lower()
    if "label" in text and ("refus" in text or "mismatch" in text or "expected" in text):
        return "label-refusal"
    if "fixture" in text and ("refus" in text or "mismatch" in text or "does not match" in text):
        return "fixture-refusal"
    if "traceback (most recent call last)" in text or "exception" in text or "error:" in text:
        return "traceback"
    return "other"


_TRACEBACK_FRAME = re.compile(r'^\s+File "([^"\r\n]{1,4096})", line ([0-9]{1,8}), in ([A-Za-z0-9_<>() .-]{1,128})\s*$')
_TRACEBACK_EXCEPTION = re.compile(r'^([A-Za-z_][A-Za-z0-9_.]{0,127})(?::.*)?$')
_TRACEBACK_CLASSES = {
    "AssertionError", "AttributeError", "BaseException", "BrokenPipeError", "ChildProcessError",
    "FileNotFoundError", "IndexError", "KeyError", "MemoryError", "NameError", "OSError",
    "PermissionError", "ProcessLookupError", "RuntimeError", "SystemError", "TimeoutError",
    "TypeError", "UnicodeDecodeError", "ValueError", "ExceptionGroup",
}
_TRACEBACK_BASES = {"probe.py", "subprocess.py", "<string>", "runpy.py", "site.py"}
_TRACEBACK_HEADER = "Traceback (most recent call last):"
_TRACEBACK_CHAIN_MARKERS = {
    "During handling of the above exception, another exception occurred:",
    "The above exception was the direct cause of the following exception:",
}
_TRACEBACK_NEXT_LINE_CATEGORIES = {
    "EXACT_CHAIN_MARKER", "EXACT_TRACEBACK_HEADER", "VALID_EXCEPTION_SUMMARY",
    "EXCEPTION_IGNORED_PREFIX", "FRAME_LIKE_LINE", "INDENTED_LINE",
    "GENERIC_ERROR_PREFIX", "OTHER_NONBLANK", "MALFORMED_UTF8", "OVER_BOUND",
}
_MAX_TRACEBACK_BYTES = 65_536
_MAX_TRACEBACK_FRAMES = 128
_MAX_TRACEBACK_CONTEXT_LINES_PER_FRAME = 2
_TRACEBACK_PARSE_FAILURE_STAGES = {
    "OVER_BOUND",
    "INVALID_ENCODING",
    "NO_EXACT_TRACEBACK_HEADER",
    "PREFIX_BEFORE_TRACEBACK",
    "FIRST_HEADER_SUFFIX_INVALID",
    "PREFIX_EXCEPTION_SUMMARY_BLOCKED_HEADER",
}
_TRACEBACK_PARSE_SUBSTAGES = {
    "NO_FRAME_AFTER_HEADER",
    "MALFORMED_FRAME_LINE",
    "UNEXPECTED_LINE_AFTER_HEADER",
    "MISSING_EXCEPTION_SUMMARY",
    "MALFORMED_EXCEPTION_SUMMARY",
    "EXPECTED_SEPARATOR_AFTER_EXCEPTION",
    "EXPECTED_CHAIN_MARKER",
    "EXPECTED_SEPARATOR_AFTER_CHAIN_MARKER",
    "EXPECTED_NEXT_TRACEBACK_HEADER",
    "SOURCE_CONTEXT_OVER_BOUND",
    "OVER_FRAME_BOUND",
    "UNSUPPORTED_EXCEPTION_FORMAT",
}

_VERIFIER_REASON_PATTERNS = (
    ("no AppArmor DENIED record binds to this call", "AUDIT_BINDING_MISSING"),
    ("no AppArmor DENIED record for this exact stage", "SCM_AUDIT_BINDING_MISSING"),
    ("confined access not refused", "CONFINED_ACCESS_NOT_REFUSED"),
    ("baseline could not reach the target", "BASELINE_UNREACHABLE"),
    ("child process_vm_readv calibration did not prove", "CALIBRATION_FAILURE"),
    ("unexplained descriptor-transfer outcome", "SCM_UNEXPECTED_OUTCOME"),
    ("confined process read a descriptor handed over", "SCM_CONFINED_ACCESS_ALLOWED"),
    ("baseline did not receive and read the canary descriptor", "SCM_BASELINE_FAILURE"),
    ("which is not the probe or one of its children", "UNEXPECTED_DENIAL"),
    ("something no check expected", "UNEXPECTED_DENIAL"),
)
_VERIFIER_STATIC_IDS = {
    "calibration", "scm-rights", "unexplained-denial", "identity", "phase",
    "run", "inventory", "peers", "results", "audit", "fd0",
    "inventory:post", "fd-inventory:post",
}
_PEER_ENTRIES = {
    "auxv", "cmdline", "cwd/", "environ", "exe", "fd/", "fdinfo/",
    "maps", "mem", "ns/mnt", "root/", "signal", "process_vm_readv",
}


def _traceback_failure(status: str, stage: str, frame_count: int = 0,
                       substage: str | None = None) -> dict:
    """Return only allowlisted parse state; never attach source or input text."""
    if stage not in _TRACEBACK_PARSE_FAILURE_STAGES:
        stage = "FIRST_HEADER_SUFFIX_INVALID"
    result = {
        "status": status,
        "parse_failure_stage": stage,
        "frame_count": min(max(frame_count, 0), _MAX_TRACEBACK_FRAMES),
    }
    if substage in _TRACEBACK_PARSE_SUBSTAGES:
        result["parse_failure_substage"] = substage
    return result


def _traceback_suffix_failure(status: str, substage: str) -> dict:
    """Collapse suffix failures to a safe stage and allowlisted branch label."""
    return _traceback_failure(status, "FIRST_HEADER_SUFFIX_INVALID", substage=substage)


def _safe_frame_summary(frame: tuple[str, int, str]) -> dict:
    """Reduce one already-parsed traceback frame to fixed, non-path metadata."""
    basename, line_number, function = frame
    safe_function = function if re.fullmatch(r"[A-Za-z0-9_<>() .-]{1,128}", function) else "UNKNOWN"
    return {
        "basename": basename if basename in _TRACEBACK_BASES else "OTHER_BASENAME",
        "function": safe_function,
        "line": line_number,
    }


def _traceback_next_line_category(line: str) -> str:
    """Classify only the structural shape of one rejected nonblank line."""
    if line in _TRACEBACK_CHAIN_MARKERS:
        return "EXACT_CHAIN_MARKER"
    if line == _TRACEBACK_HEADER:
        return "EXACT_TRACEBACK_HEADER"
    if _looks_like_exception_summary(line):
        return "VALID_EXCEPTION_SUMMARY"
    if line.startswith("Exception ignored in:"):
        return "EXCEPTION_IGNORED_PREFIX"
    if _looks_like_frame_line(line):
        return "FRAME_LIKE_LINE"
    if line.startswith(("    ", "\t")):
        return "INDENTED_LINE"
    if line.startswith("ERROR:"):
        return "GENERIC_ERROR_PREFIX"
    return "OTHER_NONBLANK"


def _looks_like_frame_line(line: str) -> bool:
    return line.lstrip(" \t").startswith("File ")


def _looks_like_exception_summary(line: str) -> bool:
    match = _TRACEBACK_EXCEPTION.fullmatch(line)
    if not match:
        return False
    name = match.group(1).rsplit(".", 1)[-1]
    # Require a known or conventionally named exception class. A generic
    # launcher line such as ``ERROR: ...`` is ordinary prefix text, not a
    # Python exception summary.
    return name in _TRACEBACK_CLASSES or name.endswith(("Error", "Exception", "Group"))


def _traceback_summary(data: bytes | None) -> dict:
    """Parse only standard traceback structure; never return source, message, args, or full paths."""
    if data is None:
        return {"status": "ABSENT_OR_UNKNOWN"}
    if not data:
        return {"status": "EMPTY"}
    if len(data) > _MAX_TRACEBACK_BYTES:
        result = _traceback_failure("OVER_BOUND", "OVER_BOUND")
        result["next_line_category"] = "OVER_BOUND"
        return result
    try:
        _progress("TRACEBACK_PARSE")
        text = data.decode("utf-8", "strict")
    except UnicodeDecodeError:
        result = _traceback_failure("UNPARSEABLE", "INVALID_ENCODING")
        result["next_line_category"] = "MALFORMED_UTF8"
        return result
    lines = text.splitlines()
    first_nonempty = next((i for i, line in enumerate(lines) if line.strip()), None)
    header_indexes = [i for i, line in enumerate(lines) if line == _TRACEBACK_HEADER]
    if not header_indexes:
        return _traceback_failure("UNPARSEABLE", "NO_EXACT_TRACEBACK_HEADER")

    first_header = header_indexes[0]
    has_prefix = first_header != first_nonempty
    if has_prefix:
        # A multiline exception summary may contain an exact header line.
        # Treat that as ambiguous prefix data and fail closed rather than
        # reinterpreting the message as a new traceback.
        if any(_looks_like_exception_summary(line)
               for line in lines[first_nonempty:first_header]):
            return _traceback_failure("UNPARSEABLE", "PREFIX_EXCEPTION_SUMMARY_BLOCKED_HEADER")

    suffix = _parse_traceback_lines(lines[first_header:])
    if suffix.get("status") == "PARSED":
        if has_prefix:
            suffix["parse_failure_stage"] = "PREFIX_BEFORE_TRACEBACK"
        return suffix
    return suffix


def _parse_traceback_lines(lines: list[str]) -> dict:
    """Parse a complete traceback suffix starting at its exact header line."""
    index = 0
    total_frames = 0
    final_frame = None
    final_exception = None
    blocks = 0
    while index < len(lines):
        if lines[index] != _TRACEBACK_HEADER:
            return _traceback_suffix_failure("UNPARSEABLE", "EXPECTED_NEXT_TRACEBACK_HEADER")
        index += 1
        block_frames = []
        while index < len(lines):
            frame_match = _TRACEBACK_FRAME.fullmatch(lines[index])
            if not frame_match:
                break
            raw_path, line_number, function = frame_match.groups()
            basename = re.split(r"[\\/]", raw_path)[-1]
            block_frames.append((basename, int(line_number), function.strip()))
            total_frames += 1
            if total_frames > _MAX_TRACEBACK_FRAMES:
                return _traceback_suffix_failure("OVER_FRAME_BOUND", "OVER_FRAME_BOUND")
            index += 1
            context_count = 0
            while index < len(lines) and not _TRACEBACK_FRAME.fullmatch(lines[index]) and (
                lines[index].startswith("    ") or lines[index].startswith("\t")
            ):
                context_count += 1
                if context_count > _MAX_TRACEBACK_CONTEXT_LINES_PER_FRAME:
                    return _traceback_suffix_failure("UNPARSEABLE", "SOURCE_CONTEXT_OVER_BOUND")
                index += 1
        if not block_frames:
            if index >= len(lines):
                return _traceback_suffix_failure("UNPARSEABLE", "NO_FRAME_AFTER_HEADER")
            if _looks_like_frame_line(lines[index]):
                return _traceback_suffix_failure("UNPARSEABLE", "MALFORMED_FRAME_LINE")
            if _TRACEBACK_EXCEPTION.fullmatch(lines[index]):
                return _traceback_suffix_failure("UNPARSEABLE", "NO_FRAME_AFTER_HEADER")
            return _traceback_suffix_failure("UNPARSEABLE", "UNEXPECTED_LINE_AFTER_HEADER")
        if index >= len(lines):
            return _traceback_suffix_failure("UNPARSEABLE", "MISSING_EXCEPTION_SUMMARY")
        exception_match = _TRACEBACK_EXCEPTION.fullmatch(lines[index])
        if not exception_match:
            substage = "MALFORMED_FRAME_LINE" if _looks_like_frame_line(lines[index]) else "MALFORMED_EXCEPTION_SUMMARY"
            return _traceback_suffix_failure("UNPARSEABLE", substage)
        exception_name = exception_match.group(1).rsplit(".", 1)[-1]
        if exception_name in {"ExceptionGroup", "BaseExceptionGroup"}:
            return _traceback_suffix_failure("UNSUPPORTED_EXCEPTION_FORMAT", "UNSUPPORTED_EXCEPTION_FORMAT")
        final_exception = exception_name if exception_name in _TRACEBACK_CLASSES else "OTHER_EXCEPTION"
        final_frame = block_frames[-1]
        blocks += 1
        index += 1
        if index == len(lines):
            break
        # Python's formatter puts exactly one blank separator before and
        # after the exception-chain marker. Do not skip arbitrary lines.
        if lines[index] != "":
            failure = _traceback_suffix_failure("UNPARSEABLE", "EXPECTED_SEPARATOR_AFTER_EXCEPTION")
            failure["exception_class"] = (
                exception_name if exception_name in _TRACEBACK_CLASSES else "OTHER_EXCEPTION"
            )
            # This is the last frame fully parsed before the separator failure,
            # not a claim that it is necessarily the causal throw site. Keep the
            # failure frame_count at zero and expose no raw path/source/message.
            failure["last_valid_frame"] = _safe_frame_summary(final_frame)
            category = _traceback_next_line_category(lines[index])
            if category in _TRACEBACK_NEXT_LINE_CATEGORIES:
                failure["next_line_category"] = category
            return failure
        index += 1
        if index >= len(lines) or lines[index] not in _TRACEBACK_CHAIN_MARKERS:
            return _traceback_suffix_failure("UNPARSEABLE", "EXPECTED_CHAIN_MARKER")
        index += 1
        if index >= len(lines) or lines[index] != "":
            return _traceback_suffix_failure("UNPARSEABLE", "EXPECTED_SEPARATOR_AFTER_CHAIN_MARKER")
        index += 1
        if index >= len(lines) or lines[index] != _TRACEBACK_HEADER:
            return _traceback_suffix_failure("UNPARSEABLE", "EXPECTED_NEXT_TRACEBACK_HEADER")

    if not blocks or final_frame is None or final_exception is None:
        return _traceback_suffix_failure("UNPARSEABLE", "NO_FRAME_AFTER_HEADER")
    safe_frame = _safe_frame_summary(final_frame)
    return {
        "status": "PARSED",
        "exception_class": final_exception,
        "frame_count": total_frames,
        "thrower_basename": safe_frame["basename"],
        "thrower_function": safe_frame["function"],
        "thrower_line": safe_frame["line"],
    }


def _stderr_summary(data: bytes | None) -> dict:
    """Return bounded stderr report fields without retaining or echoing input bytes."""
    return {
        "status": "BOUND" if data is not None else "ABSENT_OR_UNKNOWN",
        "reason_class": _stderr_class(data),
        "traceback": _traceback_summary(data),
        "raw_text_emitted": False,
    }


def _preflight_summary(data: bytes | None) -> dict:
    if data is None:
        return {"status": "ABSENT_OR_UNBOUND"}
    try:
        _progress("JSON_PARSE")
        doc = json.loads(data)
    except (ValueError, json.JSONDecodeError):
        return {"status": "INVALID_JSON"}
    if not isinstance(doc, dict):
        return {"status": "INVALID_SCHEMA"}
    checks = doc.get("pin_checks", {})
    rcs = doc.get("service_rcs", {})
    route = doc.get("route_closure", {})
    return {
        "status": "PARSED",
        "pin_checks": {k: checks[k] is True for k in sorted(PREFLIGHT_PIN_KEYS) if k in checks} if isinstance(checks, dict) else {},
        "service_rcs": {k: rcs[k] for k in sorted(PREFLIGHT_SERVICE_KEYS) if k in rcs and type(rcs[k]) is int} if isinstance(rcs, dict) else {},
        "fixture_count": doc.get("fixture_count") if type(doc.get("fixture_count")) is int else None,
        "parent_ok": doc.get("parent_ok") is True,
        "socket_absent": doc.get("socket_absent") is True,
        "parser_ok": doc.get("parser_ok") is True,
        "route_closure_attested": route.get("attested") is True if isinstance(route, dict) else False,
        "origin_app_upstreams": route.get("origin_app_upstreams") if isinstance(route, dict) and type(route.get("origin_app_upstreams")) is int else None,
        "attestation_sha256": route.get("attestation_sha256") if isinstance(route, dict) and isinstance(route.get("attestation_sha256"), str) and re.fullmatch(r"[0-9a-f]{64}", route.get("attestation_sha256")) else None,
    }


def _inventory_summary(data: bytes | None, baseline: dict | None, confined: dict | None) -> dict:
    if data is None:
        return {"status": "ABSENT_OR_UNBOUND"}
    try:
        _progress("JSON_PARSE")
        doc = json.loads(data)
    except (ValueError, json.JSONDecodeError):
        return {"status": "INVALID_JSON"}
    if not isinstance(doc, dict):
        return {"status": "INVALID_SCHEMA"}
    processes, units = doc.get("processes"), doc.get("units")
    boot = doc.get("boot_id")
    baseline_boot = baseline.get("boot_id") if isinstance(baseline, dict) else None
    confined_boot = confined.get("boot_id") if isinstance(confined, dict) else None
    return {
        "status": "PARSED",
        "schema": doc.get("schema") if type(doc.get("schema")) is int else None,
        "complete": doc.get("complete") is True,
        "scans": doc.get("scans") if type(doc.get("scans")) is int else None,
        "process_count": len(processes) if isinstance(processes, list) else None,
        "unit_count": len(units) if isinstance(units, dict) else None,
        "matrix_match": doc.get("matrix_sha256") == PINNED_PACKAGE_FILES["acceptance/matrix.json"],
        "boot_id_valid": isinstance(boot, str) and BOOT_ID.fullmatch(boot) is not None,
        "baseline_boot_match": isinstance(baseline_boot, str) and boot == baseline_boot,
        "confined_boot_match": isinstance(confined_boot, str) and boot == confined_boot,
        "collected_start_ns": doc.get("collected_start_ns") if type(doc.get("collected_start_ns")) is int else None,
        "collected_end_ns": doc.get("collected_end_ns") if type(doc.get("collected_end_ns")) is int else None,
        "reason_count": len(doc.get("reasons", [])) if isinstance(doc.get("reasons"), list) else None,
    }


def _ops_target(verifier: types.ModuleType, matrix: dict, ident: str, kind: str, item: dict, result: dict):
    profile = "wpcaregrid-nginx"
    role_profile = matrix.get("role_profiles", {})
    if kind in ("file", "symlink"):
        return verifier.FILE_OPS.get(item.get("access"), set()), {"name": item.get("path")}
    if kind == "socket":
        return {"connect", "open", "file_perm", "sendmsg"}, {"name": item.get("path")}
    if kind == "abstract":
        return {"connect", "sendmsg"}, {"mention": "wpcg-probe-"}
    if kind == "net":
        family = {"AF_INET": "inet", "AF_INET6": "inet6", "AF_UNIX": "unix", "AF_NETLINK": "netlink"}.get(item.get("family"))
        sock_type = str(item.get("type", "")).removeprefix("SOCK_").lower()
        ops = {"create": {"create", "socket_create"}, "bind": {"bind", "socket_bind"}, "connect": {"connect", "socket_connect"}}.get(item.get("action"), set())
        return ops, {"fields": {"family": family, "sock_type": sock_type}}
    if kind == "peer":
        target = f"/proc/{item['pid']}/{item['entry']}"
        return {"open", "readlink", "file_perm", "getattr"}, {"name": {target, target.rstrip("/"), target.rstrip("/") + "/"}}
    if kind == "peer-signal":
        return {"signal"}, {"peer": role_profile.get(item.get("role"), "")}
    if kind == "peer-vm":
        return {"ptrace"}, {"peer": role_profile.get(item.get("role"), "")}
    if ident == "calibration:child-environ":
        child = result.get("child")
        return {"open"}, {"name": f"/proc/{child}/environ"}
    if ident == "calibration:child-maps":
        child = result.get("child")
        return {"ptrace", "open"}, {"name_or_peer": (f"/proc/{child}/maps", matrix.get("profiles", {}).get(profile, {}).get("profile", ""))}
    return {"ptrace"}, {"peer": profile + "-probe"}


def _unconsumed_profile_summary(records: list, consumed: set, confined: dict | None,
                                probe_profile: str, epsilon_ns: int) -> dict:
    """Classify unused probe-profile audit rows without exposing record fields."""
    if not isinstance(confined, dict) or type(confined.get("pid")) is not int:
        return {"status": "UNKNOWN", "record_count": None, "groups": []}
    start, end = confined.get("started_ns"), confined.get("ended_ns")
    if type(start) is not int or type(end) is not int or start > end:
        return {"status": "UNKNOWN", "record_count": None, "groups": []}
    probe_pid = confined.get("pid")
    child_pids = {
        row.get("child") for row in confined.get("results", [])
        if isinstance(row, dict) and type(row.get("child")) is int
    }
    groups: dict[tuple[str, str, str], int] = {}
    count = 0
    for index, record in enumerate(records):
        if index in consumed or record.get("profile") != probe_profile:
            continue
        count += 1
        pid = record.get("pid")
        pid_class = "PROBE" if pid == probe_pid else "CHILD" if pid in child_pids else "OTHER_PID"
        timestamp = record.get("_ts_ns")
        if type(timestamp) is not int:
            time_class = "UNKNOWN_TIME"
        elif timestamp < start - epsilon_ns:
            time_class = "BEFORE_PROBE_WINDOW"
        elif timestamp > end + epsilon_ns:
            time_class = "AFTER_PROBE_WINDOW"
        else:
            time_class = "IN_PROBE_WINDOW"
        operation = record.get("operation")
        operation_class = operation if isinstance(operation, str) and operation in AUDIT_OPERATION_TOKENS else "OTHER_OPERATION"
        key = (pid_class, time_class, operation_class)
        groups[key] = groups.get(key, 0) + 1
    return {
        "status": "PARSED",
        "record_count": count,
        "groups": [
            {"pid_class": pid_class, "time_class": time_class,
             "operation_class": operation, "count": n}
            for (pid_class, time_class, operation), n in sorted(groups.items())
        ],
    }


def _audit_summary(data: bytes | None, baseline: dict | None, confined: dict | None, matrix: dict, verifier: types.ModuleType,
                   *, unavailable_reason: str = "confined-json-unavailable") -> dict:
    if data is None:
        return {"status": "ABSENT_OR_UNBOUND", "denied_record_count": None, "check_correlation": "UNKNOWN",
                "unconsumed_profile_records": {"status": "UNKNOWN", "record_count": None, "groups": []},
                "pid_profile_window": {"status": "UNKNOWN", "reason_code": "audit-absent"}, "reason_code": "audit-absent"}
    try:
        _progress("AUDIT_SUMMARY")
        records, problems = verifier.parse_audit(data.decode("utf-8"))
    except Exception:
        return {"status": "INVALID_AUDIT", "denied_record_count": None, "check_correlation": "UNKNOWN",
                "unconsumed_profile_records": {"status": "UNKNOWN", "record_count": None, "groups": []},
                "pid_profile_window": {"status": "UNKNOWN", "reason_code": "audit-parse-failed"}, "reason_code": "audit-parse-failed"}
    reasons = set()
    for problem in problems:
        if "repeated audit serial" in problem:
            reasons.add("audit-repeat-serial")
        elif "DENIED record without pid or audit stamp" in problem:
            reasons.add("audit-denied-fields-missing")
        else:
            reasons.add("audit-record-invalid")
    denied_count = len(records)
    if not isinstance(confined, dict) or not isinstance(confined.get("results"), list):
        probe_profile = "wpcaregrid-nginx-probe"
        profile_records = [record for record in records if record.get("profile") == probe_profile]
        run_start = _run_start_ns()
        run_end = run_start + MAX_RUN_SECONDS * 1_000_000_000
        run_envelope_count = sum(
            type(record.get("_ts_ns")) is int
            and run_start - 1_000_000_000 <= record["_ts_ns"] <= run_end + 1_000_000_000
            for record in profile_records
        )
        return {"status": "PARSED", "denied_record_count": denied_count, "probe_profile_record_count": None,
                "same_boot_count": None, "run_lifetime_count": None, "check_correlation": "UNKNOWN",
                "unconsumed_profile_records": {"status": "UNKNOWN", "record_count": None, "groups": []},
                "pid_profile_window": {
                    "status": "PARTIAL_RUN_ENVELOPE_ONLY",
                    "profile_record_count": len(profile_records),
                    "records_with_pid": sum(type(record.get("pid")) is int and record["pid"] > 0 for record in profile_records),
                    "run_envelope_time_count": run_envelope_count,
                    "probe_pid_correlation": "UNKNOWN_CONFINED_JSON_UNAVAILABLE",
                    "probe_window_correlation": "UNKNOWN_CONFINED_JSON_UNAVAILABLE",
                },
                "reason_codes": sorted(reasons | {unavailable_reason})}
    probe_profile = "wpcaregrid-nginx-probe"
    boot = str(confined.get("boot_id", "")).replace("-", "")
    start, end = confined.get("started_ns"), confined.get("ended_ns")
    lifetime_ok = type(start) is int and type(end) is int and start <= end
    profile_records = [r for r in records if r.get("profile") == probe_profile]
    same_boot = sum(r.get("_boot") == boot for r in profile_records) if boot else 0
    in_lifetime = sum(lifetime_ok and start - verifier.EPS_NS <= r.get("_ts_ns", -1) <= end + verifier.EPS_NS for r in profile_records)
    journal_sane = sum(abs(r.get("_ts_ns", 0) - r.get("_journal_ns", 0)) <= 60_000_000_000 for r in profile_records)
    if profile_records and same_boot != len(profile_records):
        reasons.add("audit-other-boot")
    if profile_records and in_lifetime != len(profile_records):
        reasons.add("audit-stale-or-outside-run")
    if profile_records and journal_sane != len(profile_records):
        reasons.add("audit-journal-time-mismatch")
    probe_pids = {confined.get("pid")} if type(confined.get("pid")) is int and confined.get("pid") > 0 else set()
    probe_pids.update(
        result.get(key) for result in confined.get("results", []) if isinstance(result, dict)
        for key in ("pid", "child") if type(result.get(key)) is int and result.get(key) > 0
    )
    correlated_pid_count = sum(record.get("pid") in probe_pids for record in profile_records) if probe_pids else 0
    try:
        expected, _targets = verifier.expected_checks(matrix, "wpcaregrid-nginx", confined.get("peers", {}), "post")
    except Exception:
        expected = {}
        reasons.add("expected-checks-unavailable")
    conf_by_id = {r.get("id"): r for r in confined["results"] if isinstance(r, dict) and isinstance(r.get("id"), str)}
    base_by_id = {r.get("id"): r for r in baseline.get("results", []) if isinstance(r, dict) and isinstance(r.get("id"), str)} if isinstance(baseline, dict) and isinstance(baseline.get("results"), list) else {}
    consumed = set()
    checks = []
    duplicate_ids = len(conf_by_id) != len([r for r in confined["results"] if isinstance(r, dict) and isinstance(r.get("id"), str)])
    if duplicate_ids:
        reasons.add("duplicate-result-id")
    for result in confined["results"]:
        if not isinstance(result, dict):
            continue
        ident = result.get("id")
        if not isinstance(ident, str) or ident not in expected:
            checks.append({"id": "UNKNOWN_RESULT", "status": "UNKNOWN", "reason_code": "result-id-not-in-matrix"})
            continue
        kind, item = expected[ident]
        if kind == "control":
            checks.append({"id": _verifier_failure_id(ident, set(expected), matrix), "status": "NOT_A_DENIAL_CHECK", "reason_code": "verifier-control-check"})
            continue
        pid = result.get("pid")
        child = result.get("child")
        t0, t1 = result.get("t0"), result.get("t1")
        ids = [ident]
        rclass = "expected"
        if type(pid) is not int or type(t0) is not int or type(t1) is not int or t0 > t1:
            checks.append({"id": _verifier_failure_id(ident, set(expected), matrix), "status": "UNKNOWN", "reason_code": "result-window-or-pid-missing"})
            continue
        ops, target = _ops_target(verifier, matrix, ident, kind, item, result)
        if not ops:
            checks.append({"id": _verifier_failure_id(ident, set(expected), matrix), "status": "UNKNOWN", "reason_code": "operation-set-unknown"})
            continue
        expected_pids = {pid}
        if type(child) is int:
            expected_pids.add(child)
        candidates = []
        dimension_counts = {"profile": 0, "pid": 0, "operation": 0, "target": 0, "window": 0, "boot": 0, "journal_time": 0}
        for index, record in enumerate(records):
            matches = {
                "profile": record.get("profile") == probe_profile,
                "pid": record.get("pid") in expected_pids,
                "operation": record.get("operation") in ops,
                "target": False,
                "window": verifier.EPS_NS >= 0 and t0 - verifier.EPS_NS <= record.get("_ts_ns", -1) <= t1 + verifier.EPS_NS,
                "boot": bool(boot) and record.get("_boot") == boot,
                "journal_time": abs(record.get("_ts_ns", 0) - record.get("_journal_ns", 0)) <= 60_000_000_000,
            }
            if "name" in target:
                val = target["name"]
                matches["target"] = record.get("name") in (val if isinstance(val, set) else {val})
            elif "mention" in target:
                matches["target"] = target["mention"] in " ".join(str(x) for x in record.values())
            elif "peer" in target:
                matches["target"] = bool(target["peer"]) and str(record.get("peer", "")).startswith(target["peer"])
            elif "fields" in target:
                matches["target"] = all(record.get(k) == v for k, v in target["fields"].items())
            elif "name_or_peer" in target:
                path, peer = target["name_or_peer"]
                matches["target"] = record.get("name") == path or str(record.get("peer", "")).startswith(peer)
            for key, yes in matches.items():
                dimension_counts[key] += int(yes)
            if all(matches.values()):
                candidates.append((index, record))
        if kind == "peer":
            peer = matrix.get("role_profiles", {}).get(item.get("role"), "")
            peer_candidates = [(i, r) for i, r in enumerate(records)
                               if r.get("profile") == probe_profile and r.get("pid") in expected_pids
                               and r.get("operation") == "ptrace" and str(r.get("peer", "")).startswith(peer)
                               and t0 - verifier.EPS_NS <= r.get("_ts_ns", -1) <= t1 + verifier.EPS_NS
                               and r.get("_boot") == boot]
            candidates.extend(peer_candidates)
        used_match = None
        try:
            args = (records, consumed, probe_profile, boot, pid, t0, t1)
            if kind in ("file", "symlink"):
                used_match = verifier.window_hit(*args, verifier.FILE_OPS[item["access"]], name=item["path"])
            elif kind == "socket":
                used_match = verifier.window_hit(*args, {"connect", "open", "file_perm", "sendmsg"}, name=item["path"])
            elif kind == "abstract":
                used_match = verifier.window_hit(*args, {"connect", "sendmsg"}, mention="wpcg-probe-")
            elif kind == "net":
                fam = {"AF_INET": "inet", "AF_INET6": "inet6", "AF_UNIX": "unix", "AF_NETLINK": "netlink"}.get(item.get("family"))
                typ = str(item.get("type", "")).removeprefix("SOCK_").lower()
                ops2 = {"create": {"create", "socket_create"}, "bind": {"bind", "socket_bind"}, "connect": {"connect", "socket_connect"}}.get(item.get("action"), set())
                used_match = verifier.window_hit(*args, ops2, fields={"family": fam, "sock_type": typ})
            elif kind == "peer":
                targ = f"/proc/{item['pid']}/{item['entry']}"
                used_match = verifier.window_hit(*args, {"open", "readlink", "file_perm", "getattr"}, name={targ, targ.rstrip("/"), targ.rstrip("/") + "/"})
                if used_match is None:
                    used_match = verifier.window_hit(*args, {"ptrace"}, peer=matrix.get("role_profiles", {}).get(item["role"], ""))
            elif kind == "peer-signal":
                used_match = verifier.window_hit(*args, {"signal"}, peer=matrix.get("role_profiles", {}).get(item["role"], ""))
            elif kind == "peer-vm":
                used_match = verifier.window_hit(*args, {"ptrace"}, peer=matrix.get("role_profiles", {}).get(item["role"], ""))
            elif ident == "calibration:child-environ":
                used_match = verifier.window_hit(*args, {"open"}, name=f"/proc/{child}/environ")
            elif ident == "calibration:child-maps":
                used_match = verifier.window_hit(*args, {"ptrace"}, peer=probe_profile) or verifier.window_hit(*args, {"open"}, name=f"/proc/{child}/maps")
            else:
                used_match = verifier.window_hit(*args, {"ptrace"}, peer=probe_profile)
            if used_match is None and type(child) is int and child > 0 and child != pid:
                child_args = (records, consumed, probe_profile, boot, child, t0, t1)
                if kind in ("file", "symlink"):
                    used_match = verifier.window_hit(*child_args, verifier.FILE_OPS[item["access"]], name=item["path"])
                elif kind == "socket":
                    used_match = verifier.window_hit(*child_args, {"connect", "open", "file_perm", "sendmsg"}, name=item["path"])
                elif kind == "abstract":
                    used_match = verifier.window_hit(*child_args, {"connect", "sendmsg"}, mention="wpcg-probe-")
                elif kind == "net":
                    used_match = verifier.window_hit(*child_args, ops2, fields={"family": fam, "sock_type": typ})
                elif kind == "peer":
                    used_match = verifier.window_hit(*child_args, {"open", "readlink", "file_perm", "getattr"}, name={targ, targ.rstrip("/"), targ.rstrip("/") + "/"})
                    if used_match is None:
                        used_match = verifier.window_hit(*child_args, {"ptrace"}, peer=matrix.get("role_profiles", {}).get(item["role"], ""))
                elif kind == "peer-signal":
                    used_match = verifier.window_hit(*child_args, {"signal"}, peer=matrix.get("role_profiles", {}).get(item["role"], ""))
                elif kind == "peer-vm":
                    used_match = verifier.window_hit(*child_args, {"ptrace"}, peer=matrix.get("role_profiles", {}).get(item["role"], ""))
                elif ident == "calibration:child-environ":
                    used_match = verifier.window_hit(*child_args, {"open"}, name=f"/proc/{child}/environ")
                elif ident == "calibration:child-maps":
                    used_match = verifier.window_hit(*child_args, {"ptrace"}, peer=probe_profile) or verifier.window_hit(*child_args, {"open"}, name=f"/proc/{child}/maps")
                else:
                    used_match = verifier.window_hit(*child_args, {"ptrace"}, peer=probe_profile)
        except Exception:
            reasons.add("audit-match-evaluation-failed")
        if used_match is not None:
            consumed.add(used_match)
        checks.append({
            "id": _verifier_failure_id(ident, set(expected), matrix),
            "confined_errno": result.get("errno") if type(result.get("errno")) is int else None,
            "pid_or_child_present": bool(expected_pids),
            "denied_record_count_all_dimensions": len(candidates),
            "profile_match": dimension_counts["profile"] > 0,
            "pid_match": dimension_counts["pid"] > 0,
            "operation_match": dimension_counts["operation"] > 0,
            "target_match": dimension_counts["target"] > 0,
            "time_window_match": dimension_counts["window"] > 0,
            "boot_match": dimension_counts["boot"] > 0,
            "journal_time_match": dimension_counts["journal_time"] > 0,
            "verifier_window_hit": used_match is not None,
            "verifier_window_hit_scope": "PARENT_OR_CHILD_DIAGNOSTIC",
            "baseline_result_present": ident in base_by_id,
        })
    return {
        "status": "PARSED",
        "denied_record_count": denied_count,
        "probe_profile_record_count": len(profile_records),
        "same_boot_count": same_boot,
        "run_lifetime_count": in_lifetime,
        "journal_timestamp_sane_count": journal_sane,
        "check_correlation": "DIAGNOSTIC_ONLY",
        "pid_profile_window": {
            "status": "CORRELATED" if probe_pids else "UNKNOWN_PROBE_PID",
            "profile_record_count": len(profile_records),
            "records_with_probe_pid": correlated_pid_count,
            "same_boot_count": same_boot,
            "probe_window_count": in_lifetime,
            "probe_pid_correlation": "MATCH" if correlated_pid_count else "NO_MATCH" if probe_pids else "UNKNOWN",
            "probe_window_correlation": "MATCH" if in_lifetime else "NO_MATCH" if lifetime_ok else "UNKNOWN",
        },
        "unconsumed_profile_records": _unconsumed_profile_summary(
            records, consumed, confined, probe_profile, verifier.EPS_NS
        ),
        "reason_codes": sorted(reasons),
        "checks": checks,
    }


def _json_file(data: bytes | None) -> dict | None:
    if data is None:
        return None
    try:
        parsed = json.loads(data)
    except (ValueError, json.JSONDecodeError):
        return None
    return parsed if isinstance(parsed, dict) else None


def _verifier_failure_id(raw_id: str, expected_ids: set[str], matrix: dict) -> str:
    """Return only a pinned check id, normalized peer id, or a fixed digest label."""
    match = re.fullmatch(r"peer:([A-Za-z0-9_-]{1,64}):([0-9]{1,10}):([A-Za-z0-9_./-]{1,64})", raw_id)
    if match:
        role, _pid, entry = match.groups()
        if (role in matrix.get("role_profiles", {}) and entry in _PEER_ENTRIES
                and raw_id in expected_ids):
            return f"peer:{role}:PID:{entry}"
    if raw_id in expected_ids or raw_id in _VERIFIER_STATIC_IDS:
        return raw_id
    # Never echo a verifier-supplied identifier that is not in the pinned matrix.
    return "UNKNOWN_CHECK:" + hashlib.sha256(raw_id.encode("utf-8", "replace")).hexdigest()[:16]


def _verifier_reason(detail: str) -> str:
    lowered = detail.lower()
    for needle, reason in _VERIFIER_REASON_PATTERNS:
        if needle.lower() in lowered:
            return reason
    if "baseline and confined" in lowered:
        return "RUN_IDENTITY_FAILURE"
    if "inventory" in lowered:
        return "INVENTORY_FAILURE"
    if "peer" in lowered or "process" in lowered:
        return "PEER_FAILURE"
    return "OTHER_FAILURE"


def _verifier_summary(stdout: bytes | None, stderr: bytes | None, matrix: dict,
                      confined: dict | None, verifier: types.ModuleType) -> dict:
    """Summarize existing verifier output only; never execute verify() or expose ACK data."""
    if stdout is None or stderr is None:
        return {"status": "ABSENT_OR_UNBOUND", "fail_count": None, "failures": [],
                "unproven_count": None, "ack_required": None, "raw_output_emitted": False}
    try:
        out_text = stdout.decode("utf-8", "strict")
        err_text = stderr.decode("utf-8", "strict")
    except UnicodeDecodeError:
        return {"status": "INVALID_ENCODING", "fail_count": None, "failures": [],
                "unproven_count": None, "ack_required": None, "raw_output_emitted": False}
    if len(stdout) > MAX_FILE_BYTES or len(stderr) > MAX_FILE_BYTES:
        return {"status": "OVER_BOUND", "fail_count": None, "failures": [],
                "unproven_count": None, "ack_required": None, "raw_output_emitted": False}
    if confined is None:
        return {"status": "CONFINED_JSON_UNAVAILABLE", "fail_count": None, "failures": [],
                "unproven_count": None, "ack_required": None, "raw_output_emitted": False}
    try:
        expected, _targets = verifier.expected_checks(
            matrix, "wpcaregrid-nginx", confined.get("peers", {}), "post")
        expected_ids = set(expected)
    except Exception:
        return {"status": "PINNED_CHECK_LIST_UNAVAILABLE", "fail_count": None, "failures": [],
                "unproven_count": None, "ack_required": None, "raw_output_emitted": False}
    failures = []
    unproven_count = None
    for line in out_text.splitlines():
        if line.startswith("FAIL "):
            match = re.fullmatch(r"FAIL ([A-Za-z0-9_./:-]{1,256}): (.{0,8192})", line)
            if not match:
                return {"status": "INVALID_FORMAT", "fail_count": None, "failures": [],
                        "unproven_count": None, "ack_required": None, "raw_output_emitted": False}
            raw_id, detail = match.groups()
            failures.append({"check_id": _verifier_failure_id(raw_id, expected_ids, matrix),
                             "reason_code": _verifier_reason(detail)})
            if len(failures) > 2048:
                return {"status": "OVER_BOUND", "fail_count": None, "failures": [],
                        "unproven_count": None, "ack_required": None, "raw_output_emitted": False}
        elif line.startswith("UNPROVEN_COUNT="):
            match = re.fullmatch(r"UNPROVEN_COUNT=([0-9]{1,6})", line)
            if not match or unproven_count is not None:
                return {"status": "INVALID_FORMAT", "fail_count": None, "failures": [],
                        "unproven_count": None, "ack_required": None, "raw_output_emitted": False}
            unproven_count = int(match.group(1))
    ack_required = "ACCEPTANCE=HOLD;REASON=owner_unproven_ack_required" in err_text
    ack_mismatch = "ACCEPTANCE=HOLD;REASON=unproven_ack_mismatch" in err_text
    ack_without_ids = "ACCEPTANCE=HOLD;REASON=unproven_ack_without_unproven_ids" in err_text
    rejected_match = re.search(r"(?m)^REJECTED: ([0-9]{1,6}) failure\(s\)$", err_text)
    out_lines = [line for line in out_text.splitlines() if line]
    err_lines = [line for line in err_text.splitlines() if line]
    consistent_marker = (
        "EVIDENCE-CONSISTENT for this run only: kernel enforcement is shown by the PASS lines above, not by this tool."
    )
    consistent = bool(out_lines) and out_lines[-1] == consistent_marker
    stderr_known = all(
        line in {
            "ACCEPTANCE=HOLD;REASON=owner_unproven_ack_required",
            "ACCEPTANCE=HOLD;REASON=unproven_ack_mismatch",
            "ACCEPTANCE=HOLD;REASON=unproven_ack_without_unproven_ids",
        }
        or re.fullmatch(r"ACK_REQUIRED=--ack-unproven=[A-Za-z0-9:,_./-]{1,8192}", line) is not None
        or re.fullmatch(r"REJECTED: [0-9]{1,6} failure\(s\)", line) is not None
        for line in err_lines
    )
    if not stderr_known:
        return {"status": "INVALID_FORMAT", "fail_count": None, "failures": [],
                "unproven_count": None, "ack_required": None, "raw_output_emitted": False}
    # A parse is useful only when the pinned verifier's terminal output marker is
    # present and agrees with the records. Empty, partial, crash-only, and
    # unrecognized output must never resemble a zero-failure summary.
    rejected_count = int(rejected_match.group(1)) if rejected_match else None
    terminal_ok = False
    if rejected_match and err_lines and err_lines[-1] == rejected_match.group(0):
        terminal_ok = rejected_count == len(failures) and rejected_count > 0
    elif consistent:
        terminal_ok = not failures and not ack_required and not ack_mismatch and not ack_without_ids
    elif ack_required and not failures:
        terminal_ok = (unproven_count is not None and unproven_count > 0
                       and len(err_lines) == 2 and err_lines[-1].startswith("ACK_REQUIRED="))
    elif ack_mismatch and not failures:
        terminal_ok = len(err_lines) == 1 and err_lines[-1] == "ACCEPTANCE=HOLD;REASON=unproven_ack_mismatch"
    elif ack_without_ids and not failures:
        terminal_ok = len(err_lines) == 1 and err_lines[-1] == "ACCEPTANCE=HOLD;REASON=unproven_ack_without_unproven_ids"
    if not terminal_ok:
        return {"status": "UNRECOGNIZED_OUTPUT", "fail_count": None, "failures": [],
                "unproven_count": None, "ack_required": None, "raw_output_emitted": False}
    return {
        "status": "PARSED",
        "fail_count": len(failures),
        "failures": failures,
        "unproven_count": unproven_count,
        "ack_required": ack_required,
        "ack_mismatch": ack_mismatch,
        "reason_code": (
            "FAILURES_AND_ACK_REQUIRED" if failures and ack_required else
            "FAILURES_AND_ACK_MISMATCH" if failures and ack_mismatch else
            "FAILURES_AND_ACK_WITHOUT_IDS" if failures and ack_without_ids else
            "ACK_REQUIRED" if ack_required else "ACK_MISMATCH" if ack_mismatch else
            "ACK_WITHOUT_IDS" if ack_without_ids else
            "EVIDENCE_CONSISTENT" if consistent else
            "REJECTED" if failures else "UNKNOWN"
        ),
        "raw_output_emitted": False,
    }


def summarize(package_dir: str = PACKAGE_DIR, envelope_dir: str = ENVELOPE_DIR) -> dict:
    if os.geteuid() != 0:
        raise DiagnosticHold("root-read-only-context-required")
    if os.path.basename(envelope_dir) != os.path.basename(ENVELOPE_DIR):
        raise DiagnosticHold("envelope-identity")
    _progress("PIN_LOAD")
    package, verifier = _load_pins(package_dir)
    run_dir = os.path.join(envelope_dir, RUN_DIR_NAME)
    manifest, selected, unknown = _evidence_manifest(run_dir)
    baseline, baseline_doc, baseline_status = _probe_summary(
        "wpcaregrid-nginx-baseline.json", selected.get("wpcaregrid-nginx-baseline.json"), package["matrix"], "baseline", verifier)
    confined, confined_doc, confined_status = _probe_summary(
        "wpcaregrid-nginx-confined.json", selected.get("wpcaregrid-nginx-confined.json"), package["matrix"], "confined", verifier)
    if baseline_doc and confined_doc:
        baseline["boot_id_match"] = baseline_doc.get("boot_id") == confined_doc.get("boot_id")
    else:
        baseline["boot_id_match"] = "UNKNOWN"
    stderr = {}
    for filename in (
        "wpcaregrid-nginx-baseline.stderr",
        "wpcaregrid-nginx-baseline.stderr.systemd-run",
        "wpcaregrid-nginx-confined.stderr",
        "wpcaregrid-nginx-confined.stderr.systemd-run",
        "wpcaregrid-nginx-derive.stderr",
        "wpcaregrid-nginx-audit.stderr",
        "wpcaregrid-nginx-inventory-before.stderr",
    ):
        data = selected.get(filename)
        stderr[filename] = _stderr_summary(data)
    conf_audit_eligible = confined_status == "parsed" and confined.get("audit_correlation_eligible") is True
    conf_for_audit = confined_doc if conf_audit_eligible else None
    unavailable_reason = (
        "confined-identity-or-window-invalid" if confined_status == "parsed"
        else "confined-json-unavailable"
    )
    audit = _audit_summary(
        selected.get("wpcaregrid-nginx-audit.jsonl"), baseline_doc, conf_for_audit,
        package["matrix"], verifier, unavailable_reason=unavailable_reason,
    )
    if not conf_audit_eligible:
        audit["check_correlation"] = "UNKNOWN"
        if unavailable_reason not in audit.get("reason_codes", []):
            audit.setdefault("reason_codes", []).append(unavailable_reason)
            audit["reason_codes"].sort()
        audit.pop("checks", None)
    diagnosis = "INSUFFICIENT_BOUND_EVIDENCE"
    stderr_class = stderr["wpcaregrid-nginx-confined.stderr"]["reason_class"]
    if confined_status == "parsed":
        diagnosis = "JSON_EMITTED"
    elif confined_status == "empty" and stderr_class in ("label-refusal", "fixture-refusal"):
        diagnosis = "PROBE_REFUSAL_BEFORE_JSON"
    elif confined_status == "empty" and stderr_class == "traceback":
        diagnosis = "PRE_JSON_EXCEPTION_INDICATED"
    elif confined_status == "empty" and stderr_class == "other":
        diagnosis = "PRE_JSON_FAILURE_UNCLASSIFIED"
    preflight = _preflight_summary(selected.get("preflight-summary.json"))
    inventory = _inventory_summary(selected.get("wpcaregrid-nginx-inventory-before.json"), baseline_doc, confined_doc)
    verifier_diagnostic = _verifier_summary(
        selected.get("wpcaregrid-nginx-verifier.stdout"),
        selected.get("wpcaregrid-nginx-verifier.stderr"), package["matrix"], confined_doc, verifier)
    return {
        "schema": 1,
        "diagnostic_only": True,
        "acceptance_verdict": "NOT_RUN",
        "fixture_cleanup": "NOT_RUN",
        "run_id": RUN_DIR_NAME,
        "package_pins": package["files"],
        "package_tree_expected": "442fa9ab4e82652a70f997238ad5d98e11ade5b9",
        "run_manifest": manifest,
        "untrusted_or_unknown_files": sorted(
            name if name in KNOWN_EVIDENCE_NAMES else "sha256:" + hashlib.sha256(name.encode("utf-8", "replace")).hexdigest()
            for name in unknown
        ),
        "preflight": preflight,
        "inventory_before": inventory,
        "baseline": baseline,
        "confined": confined,
        "stderr": stderr,
        "audit": audit,
        "verifier_diagnostic": verifier_diagnostic,
        "diagnostic_class": diagnosis,
        "bridge_report_file": "ABSENT_OR_UNKNOWN",
        "verifier_executed": False,
        "raw_stderr_emitted": False,
        "raw_audit_emitted": False,
        "raw_config_emitted": False,
    }


def main() -> int:
    global _PROGRESS_FD
    if (len(sys.argv) == 3 and sys.argv[1] == "--progress-fd"
            and sys.argv[2].isascii() and sys.argv[2].isdecimal()):
        candidate_fd = int(sys.argv[2])
        try:
            valid_pipe = candidate_fd >= 3 and stat.S_ISFIFO(os.fstat(candidate_fd).st_mode)
        except OSError:
            valid_pipe = False
        if not valid_pipe:
            print(json.dumps({"schema": 1, "diagnostic_only": True, "acceptance_verdict": "NOT_RUN", "reason_code": "unexpected-arguments"}, sort_keys=True, separators=(",", ":")))
            return 2
        _PROGRESS_FD = candidate_fd
    elif len(sys.argv) != 1:
        print(json.dumps({"schema": 1, "diagnostic_only": True, "acceptance_verdict": "NOT_RUN", "reason_code": "unexpected-arguments"}, sort_keys=True, separators=(",", ":")))
        return 2
    try:
        report = summarize()
        _progress("OUTPUT")
        print(json.dumps(report, sort_keys=True, separators=(",", ":")))
        return 0
    except DiagnosticHold as exc:
        print(json.dumps({"schema": 1, "diagnostic_only": True, "acceptance_verdict": "NOT_RUN", "reason_code": exc.code}, sort_keys=True, separators=(",", ":")))
        return 2
    except Exception:
        print(json.dumps({"schema": 1, "diagnostic_only": True, "acceptance_verdict": "NOT_RUN", "reason_code": "internal-diagnostic-error"}, sort_keys=True, separators=(",", ":")))
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
