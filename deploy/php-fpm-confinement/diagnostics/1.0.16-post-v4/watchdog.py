#!/usr/bin/env python3
"""Bounded process supervisor for the read-only 1.0.16-post evidence helper."""
from __future__ import annotations

import json
import fcntl
import hashlib
import os
import selectors
import signal
import stat
import subprocess
import sys
import time

TRUSTED_BUNDLE_DIR = "/usr/local/lib/wpcaregrid/php-fpm-confinement/1.0.16-post-watchdog-8"
WATCHDOG_NAME = "watchdog.py"
HELPER_NAME = "helper.py"
MANIFEST_NAME = "bundle-manifest.json"
BUNDLE_VERSION = "1.0.16-post-watchdog-8"
TRUSTED_FILE_MODE = 0o444
MAX_BUNDLE_FILE_BYTES = 128_000
HARD_DEADLINE_SECONDS = 90.0
TERM_GRACE_SECONDS = 1.0
REAP_GRACE_SECONDS = 1.0
MAX_STDOUT_BYTES = 2_000_000
MAX_STDERR_BYTES = 65_536
MAX_PROGRESS_BYTES = 4_096
MAX_PROGRESS_LINE_BYTES = 64
PROGRESS_STAGES = frozenset({
    "PIN_LOAD", "EVIDENCE_ENUMERATE", "EVIDENCE_READ", "JSON_PARSE",
    "TRACEBACK_PARSE", "AUDIT_SUMMARY", "OUTPUT",
})
SUCCESS_KEYS = frozenset({
    "schema", "diagnostic_only", "acceptance_verdict", "fixture_cleanup",
    "run_id", "package_pins", "package_tree_expected", "run_manifest",
    "untrusted_or_unknown_files", "preflight", "inventory_before", "baseline",
    "confined", "stderr", "audit", "diagnostic_class", "bridge_report_file",
    "verifier_executed", "raw_stderr_emitted", "raw_audit_emitted", "raw_config_emitted",
    "verifier_diagnostic",
})
ELAPSED_BUCKETS = ((5, "LT_5S"), (15, "S5_15"), (30, "S15_30"),
                   (60, "S30_60"), (90, "S60_90"), (float("inf"), "GE_90S"))


def _elapsed_bucket(seconds: float) -> str:
    for upper, label in ELAPSED_BUCKETS:
        if seconds < upper:
            return label
    return "GE_90S"


def _json_line(value: dict) -> bytes:
    return (json.dumps(value, sort_keys=True, separators=(",", ":")) + "\n").encode("ascii")


def _failure(reason: str, *, status: str = "FAILED", last_stage: str = "UNKNOWN",
             elapsed: float = 0.0, child_reaped: bool = True) -> bytes:
    return _json_line({
        "schema": 1,
        "diagnostic_only": True,
        "acceptance_verdict": "NOT_RUN",
        "fixture_cleanup": "NOT_RUN",
        "watchdog": {
            "status": status,
            "reason_code": reason,
            "last_stage": last_stage,
            "elapsed_bucket": _elapsed_bucket(elapsed),
            "child_reaped": child_reaped,
        },
    })


def _same_file(left, right) -> bool:
    return (left.st_dev, left.st_ino, left.st_uid, left.st_gid, left.st_mode,
            left.st_nlink, left.st_size) == (
            right.st_dev, right.st_ino, right.st_uid, right.st_gid, right.st_mode,
            right.st_nlink, right.st_size)


def _directory_mode_safe(mode: int, *, bundle_root: bool) -> bool:
    permissions = stat.S_IMODE(mode)
    if bundle_root:
        return permissions == 0o555
    return (permissions & 0o022) == 0


def _open_bundle_directory(path: str, *, expected_uid: int, expected_gid: int,
                           require_trusted_path: bool) -> int:
    absolute = os.path.abspath(path)
    if require_trusted_path and absolute != TRUSTED_BUNDLE_DIR:
        raise ValueError("bundle-path")
    directory_flags = os.O_RDONLY | getattr(os, "O_DIRECTORY", 0) | os.O_NOFOLLOW | os.O_CLOEXEC
    if not require_trusted_path:
        fd = os.open(absolute, directory_flags)
        info = os.fstat(fd)
        if (not stat.S_ISDIR(info.st_mode) or info.st_uid != expected_uid
                or info.st_gid != expected_gid or stat.S_IMODE(info.st_mode) != 0o555):
            os.close(fd)
            raise ValueError("bundle-directory-metadata")
        return fd

    fd = os.open("/", directory_flags)
    try:
        current = "/"
        for component in absolute.split(os.sep)[1:]:
            if not component:
                continue
            before = os.stat(component, dir_fd=fd, follow_symlinks=False)
            child_path = os.path.join(current, component)
            is_bundle_root = child_path == absolute
            if (not stat.S_ISDIR(before.st_mode) or before.st_uid != expected_uid
                    or before.st_gid != expected_gid
                    or not _directory_mode_safe(before.st_mode, bundle_root=is_bundle_root)):
                raise ValueError("bundle-path-metadata")
            child_fd = os.open(component, directory_flags, dir_fd=fd)
            opened = os.fstat(child_fd)
            if not _same_file(before, opened):
                os.close(child_fd)
                raise ValueError("bundle-path-raced")
            os.close(fd)
            fd = child_fd
            current = child_path
        if current != absolute:
            raise ValueError("bundle-path")
        return fd
    except Exception:
        os.close(fd)
        raise


def _read_bundle_file(directory_fd: int, name: str, *, expected_uid: int,
                      expected_gid: int, expected_mode: int = TRUSTED_FILE_MODE) -> bytes:
    if name not in {MANIFEST_NAME, WATCHDOG_NAME, HELPER_NAME}:
        raise ValueError("bundle-name")
    before = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
    if (not stat.S_ISREG(before.st_mode) or before.st_uid != expected_uid
            or before.st_gid != expected_gid or before.st_nlink != 1
            or stat.S_IMODE(before.st_mode) != expected_mode
            or before.st_size < 0 or before.st_size > MAX_BUNDLE_FILE_BYTES):
        raise ValueError("bundle-file-metadata")
    flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
    fd = os.open(name, flags, dir_fd=directory_fd)
    try:
        opened = os.fstat(fd)
        if not _same_file(before, opened):
            raise ValueError("bundle-file-raced")
        chunks, total = [], 0
        while True:
            chunk = os.read(fd, min(65536, MAX_BUNDLE_FILE_BYTES + 1 - total))
            if not chunk:
                break
            chunks.append(chunk)
            total += len(chunk)
            if total > MAX_BUNDLE_FILE_BYTES:
                raise ValueError("bundle-file-size")
        after = os.fstat(fd)
        current = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
        data = b"".join(chunks)
        if len(data) != before.st_size or not _same_file(opened, after) or not _same_file(opened, current):
            raise ValueError("bundle-file-raced")
        return data
    finally:
        os.close(fd)


def _load_bundle(bundle_dir: str = TRUSTED_BUNDLE_DIR, *, expected_uid: int = 0,
                 expected_gid: int = 0, require_trusted_path: bool = True) -> bytes:
    """Verify the installed immutable bundle and return the helper's bound bytes."""
    if require_trusted_path and os.path.abspath(__file__) != os.path.join(bundle_dir, WATCHDOG_NAME):
        raise ValueError("watchdog-path")
    directory_fd = _open_bundle_directory(
        bundle_dir, expected_uid=expected_uid, expected_gid=expected_gid,
        require_trusted_path=require_trusted_path,
    )
    try:
        manifest_bytes = _read_bundle_file(directory_fd, MANIFEST_NAME,
                                          expected_uid=expected_uid, expected_gid=expected_gid)
        watchdog_bytes = _read_bundle_file(directory_fd, WATCHDOG_NAME,
                                          expected_uid=expected_uid, expected_gid=expected_gid)
        helper_bytes = _read_bundle_file(directory_fd, HELPER_NAME,
                                         expected_uid=expected_uid, expected_gid=expected_gid)
    finally:
        os.close(directory_fd)
    manifest = json.loads(manifest_bytes.decode("utf-8", "strict"))
    if (not isinstance(manifest, dict)
            or set(manifest) != {"schema", "bundle_version", "watchdog_sha256", "helper_sha256"}
            or manifest.get("schema") != 1
            or manifest.get("bundle_version") != BUNDLE_VERSION
            or manifest.get("watchdog_sha256") != hashlib.sha256(watchdog_bytes).hexdigest()
            or manifest.get("helper_sha256") != hashlib.sha256(helper_bytes).hexdigest()):
        raise ValueError("bundle-pin-mismatch")
    return helper_bytes


def _sealed_source_fd(source: bytes) -> int:
    """Create an immutable anonymous file containing exactly the verified source."""
    if not hasattr(os, "memfd_create"):
        raise OSError("memfd-unavailable")
    fd = os.memfd_create("wpcg-gate2-diagnostic-helper", os.MFD_ALLOW_SEALING)
    try:
        offset = 0
        while offset < len(source):
            offset += os.write(fd, source[offset:offset + 65536])
        seals = (fcntl.F_SEAL_SEAL | fcntl.F_SEAL_SHRINK
                 | fcntl.F_SEAL_GROW | fcntl.F_SEAL_WRITE)
        fcntl.fcntl(fd, fcntl.F_ADD_SEALS, seals)
        return fd
    except Exception:
        os.close(fd)
        raise


def supervise(command_factory=None, *, helper_source: bytes | None = None,
              timeout_seconds: float = HARD_DEADLINE_SECONDS,
              term_grace_seconds: float = TERM_GRACE_SECONDS,
              reap_grace_seconds: float = REAP_GRACE_SECONDS) -> tuple[bytes, int]:
    """Run one child, cap every output channel, and never return raw stderr."""
    started = time.monotonic()
    progress_r, progress_w = os.pipe()
    child = None
    source_fd = -1
    selector = selectors.DefaultSelector()
    channels = {}
    buffers = {"stdout": bytearray(), "stderr": bytearray(), "progress": bytearray()}
    limits = {"stdout": MAX_STDOUT_BYTES, "stderr": MAX_STDERR_BYTES,
              "progress": MAX_PROGRESS_BYTES}
    last_stage = "UNKNOWN"
    progress_partial = bytearray()
    failure = None

    try:
        if helper_source is not None:
            source_fd = _sealed_source_fd(helper_source)
            command = [sys.executable, "-B", f"/proc/self/fd/{source_fd}",
                       "--progress-fd", str(progress_w)]
            inherited_fds = (progress_w, source_fd)
        elif command_factory is not None:
            command = command_factory(progress_w)
            inherited_fds = (progress_w,)
        else:
            raise ValueError("child-source-required")
        child = subprocess.Popen(
            command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
            stderr=subprocess.PIPE, close_fds=True, pass_fds=inherited_fds,
            start_new_session=True,
        )
        os.close(progress_w)
        progress_w = -1
        if source_fd >= 0:
            os.close(source_fd)
            source_fd = -1
        for name, stream in (("stdout", child.stdout), ("stderr", child.stderr)):
            os.set_blocking(stream.fileno(), False)
            selector.register(stream, selectors.EVENT_READ, name)
            channels[name] = stream
        os.set_blocking(progress_r, False)
        progress_stream = os.fdopen(progress_r, "rb", buffering=0)
        progress_r = -1
        selector.register(progress_stream, selectors.EVENT_READ, "progress")
        channels["progress"] = progress_stream
        deadline = started + timeout_seconds

        while (channels or (child is not None and child.poll() is None)) and failure is None:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                failure = "deadline-exceeded"
                break
            events = selector.select(min(remaining, 0.1)) if channels else []
            if not channels and child.poll() is None:
                time.sleep(min(remaining, 0.05))
                continue
            for key, _mask in events:
                name = key.data
                try:
                    chunk = os.read(key.fileobj.fileno(), 4096)
                except BlockingIOError:
                    continue
                except OSError:
                    failure = "channel-read-failed"
                    break
                if not chunk:
                    selector.unregister(key.fileobj)
                    channels.pop(name, None)
                    key.fileobj.close()
                    continue
                buffers[name].extend(chunk)
                if len(buffers[name]) > limits[name]:
                    failure = "output-bound-exceeded"
                    break
                if name == "progress":
                    progress_partial.extend(chunk)
                    while b"\n" in progress_partial:
                        line, _, rest = progress_partial.partition(b"\n")
                        progress_partial[:] = rest
                        try:
                            token = line.decode("ascii", "strict")
                        except UnicodeDecodeError:
                            failure = "progress-invalid"
                            break
                        if token not in PROGRESS_STAGES:
                            failure = "progress-invalid"
                            break
                        last_stage = token
                    if len(progress_partial) > MAX_PROGRESS_LINE_BYTES:
                        failure = "progress-invalid"
                    if failure:
                        break

        if failure is None and progress_partial:
            failure = "progress-invalid"
        if failure is None and child.poll() is None:
            failure = "deadline-exceeded"
        if failure is None:
            try:
                child.wait(timeout=max(0.0, deadline - time.monotonic()))
            except subprocess.TimeoutExpired:
                failure = "deadline-exceeded"

        if failure is not None:
            _terminate_and_reap(child, term_grace_seconds, reap_grace_seconds)
            reaped = child.poll() is not None
            elapsed = time.monotonic() - started
            if failure == "deadline-exceeded":
                return _failure("deadline-exceeded", status="TIMEOUT", last_stage=last_stage,
                                elapsed=elapsed, child_reaped=reaped), 124 if reaped else 125
            return _failure(failure, last_stage=last_stage, elapsed=elapsed,
                            child_reaped=reaped), 2 if reaped else 125

        reaped = child.poll() is not None
        if not reaped:
            _terminate_and_reap(child, term_grace_seconds, reap_grace_seconds)
            return _failure("child-reap-unconfirmed", last_stage=last_stage,
                            elapsed=time.monotonic() - started, child_reaped=False), 125
        if child.returncode != 0 or buffers["stderr"]:
            return _failure("child-failed", last_stage=last_stage,
                            elapsed=time.monotonic() - started), 2
        raw = bytes(buffers["stdout"])
        if not raw.endswith(b"\n") or raw.count(b"\n") != 1:
            return _failure("child-output-invalid", last_stage=last_stage,
                            elapsed=time.monotonic() - started), 2
        try:
            report = json.loads(raw[:-1].decode("utf-8", "strict"))
        except (UnicodeDecodeError, ValueError):
            return _failure("child-output-invalid", last_stage=last_stage,
                            elapsed=time.monotonic() - started), 2
        if (not isinstance(report, dict) or set(report) != SUCCESS_KEYS
                or report.get("schema") != 1
                or report.get("diagnostic_only") is not True
                or report.get("acceptance_verdict") != "NOT_RUN"
                or report.get("fixture_cleanup") != "NOT_RUN"
                or report.get("run_id") != "acceptance-1.0.16-post-20261128T130846Z-d424f9"
                or report.get("verifier_executed") is not False
                or report.get("raw_stderr_emitted") is not False
                or report.get("raw_audit_emitted") is not False
                or report.get("raw_config_emitted") is not False):
            return _failure("child-output-invalid", last_stage=last_stage,
                            elapsed=time.monotonic() - started), 2
        return raw, 0
    except Exception:
        if child is not None and child.poll() is None:
            _terminate_and_reap(child, term_grace_seconds, reap_grace_seconds)
        reaped = child is None or child.poll() is not None
        return _failure("watchdog-internal-error", elapsed=time.monotonic() - started,
                        child_reaped=reaped), 2 if reaped else 125
    finally:
        if progress_w >= 0:
            os.close(progress_w)
        if progress_r >= 0:
            os.close(progress_r)
        if source_fd >= 0:
            os.close(source_fd)
        for stream in list(channels.values()):
            try:
                selector.unregister(stream)
            except Exception:
                pass
            try:
                stream.close()
            except OSError:
                pass
        selector.close()


def _terminate_and_reap(child: subprocess.Popen, term_grace: float, reap_grace: float) -> None:
    """Terminate the entire new session and make only bounded reap attempts."""
    try:
        os.killpg(child.pid, signal.SIGTERM)
    except ProcessLookupError:
        pass
    except OSError:
        pass
    try:
        child.wait(timeout=term_grace)
    except subprocess.TimeoutExpired:
        pass
    # Always kill the remainder of the private process group after the grace,
    # even if the direct child exited on TERM while a descendant retained pipes.
    try:
        os.killpg(child.pid, signal.SIGKILL)
    except ProcessLookupError:
        pass
    except OSError:
        pass
    if child.poll() is None:
        try:
            child.wait(timeout=reap_grace)
        except subprocess.TimeoutExpired:
            pass


def main() -> int:
    if len(sys.argv) != 1:
        sys.stdout.buffer.write(_failure("unexpected-arguments"))
        return 2
    try:
        if os.geteuid() != 0:
            raise ValueError("root-required")
        helper_source = _load_bundle()
    except Exception:
        sys.stdout.buffer.write(_failure("trusted-bundle-invalid"))
        return 2
    raw, status = supervise(helper_source=helper_source)
    sys.stdout.buffer.write(raw)
    return status


if __name__ == "__main__":
    raise SystemExit(main())
