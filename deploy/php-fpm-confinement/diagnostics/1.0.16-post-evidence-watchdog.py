#!/usr/bin/env python3
"""Bounded process supervisor for the read-only 1.0.16-post evidence helper."""
from __future__ import annotations

import json
import os
import selectors
import signal
import subprocess
import sys
import time

HELPER = os.path.join(os.path.dirname(__file__), "1.0.16-post-evidence.py")
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


def supervise(command_factory, *, timeout_seconds: float = HARD_DEADLINE_SECONDS,
              term_grace_seconds: float = TERM_GRACE_SECONDS,
              reap_grace_seconds: float = REAP_GRACE_SECONDS) -> tuple[bytes, int]:
    """Run one child, cap every output channel, and never return raw stderr."""
    started = time.monotonic()
    progress_r, progress_w = os.pipe()
    child = None
    selector = selectors.DefaultSelector()
    channels = {}
    buffers = {"stdout": bytearray(), "stderr": bytearray(), "progress": bytearray()}
    limits = {"stdout": MAX_STDOUT_BYTES, "stderr": MAX_STDERR_BYTES,
              "progress": MAX_PROGRESS_BYTES}
    last_stage = "UNKNOWN"
    progress_partial = bytearray()
    failure = None

    try:
        command = command_factory(progress_w)
        child = subprocess.Popen(
            command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
            stderr=subprocess.PIPE, close_fds=True, pass_fds=(progress_w,),
            start_new_session=True,
        )
        os.close(progress_w)
        progress_w = -1
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
                or report.get("run_id") != "acceptance-1.0.16-post-20261127T135951Z-b726ce"
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
    raw, status = supervise(lambda fd: [sys.executable, "-B", HELPER,
                                       "--progress-fd", str(fd)])
    sys.stdout.buffer.write(raw)
    return status


if __name__ == "__main__":
    raise SystemExit(main())
