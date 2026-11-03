"""Bounded, in-memory diagnostics for an isolated MySQL initialization.

This module does not run MySQL unless ``execute_one_minimal_init`` is called
with explicit authorization. Raw stdout/stderr is kept only in bounded memory;
it is never written to a file or returned to the caller.
"""

from __future__ import annotations

import json
import os
import re
import signal
import shutil
import stat
import subprocess
import tempfile
import threading
from dataclasses import dataclass
from pathlib import Path
from typing import Callable


MAX_STREAM_BYTES = 64 * 1024
MAX_CODES = 8
PROCESS_TIMEOUT_SECONDS = 600
PIPE_DRAIN_TIMEOUT_SECONDS = 5
ALLOWED_CATEGORIES = frozenset({
    "NO_DIAGNOSTIC_TEXT",
    "DATADIR_NOT_EMPTY_OR_EXISTS",
    "PERMISSION_DENIED",
    "FILE_EXISTS",
    "SHARED_LIBRARY_FAILURE",
    "ERRNO_17",
    "UNKNOWN",
})
TEMP_PREFIX = "wpcg-mysql-init-diagnostic-"

DEFAULT_FILTERS = {
    "my_code": r"\bMY-\d{6}\b",
    "errno": r"\berrno\s*(?:=|:)?\s*(\d{1,3})\b",
    "datadir": r"data directory.*(?:not empty|exists)|directory exists.*aborting",
    "permission": r"permission denied|operation not permitted",
    "file_exists": r"file exists",
    "shared_library": r"error while loading shared libraries|cannot open shared object",
}


class DiagnosticError(Exception):
    """A fixed, safe diagnostic-processing failure."""


@dataclass(frozen=True)
class ProcessCapture:
    exit_code: int | None
    stdout: bytes
    stderr: bytes
    output_limit_exceeded: bool = False
    read_failed: bool = False
    timed_out: bool = False


@dataclass(frozen=True)
class CollectionResult:
    command_exit_code: int | None
    diagnostic: dict[str, object] | None
    diagnostic_status: str
    collector_status: str
    cleanup_status: str
    temp_data_status: str

    def public_record(self) -> dict[str, object]:
        """Return only fixed statuses, allowlisted codes, and process exit."""
        diagnostic_status = self.diagnostic_status
        collector_status = self.collector_status
        cleanup_status = self.cleanup_status
        temp_data_status = self.temp_data_status
        code = self.command_exit_code
        diagnostic = None
        if code is not None and (type(code) is not int or not -255 <= code <= 255):
            code = None
            diagnostic_status = "DIAGNOSTIC_UNAVAILABLE"
            collector_status = "FAIL"
        if diagnostic_status not in {"AVAILABLE", "DIAGNOSTIC_UNAVAILABLE"}:
            diagnostic_status = "DIAGNOSTIC_UNAVAILABLE"
            collector_status = "FAIL"
        if collector_status not in {"PASS", "FAIL"}:
            collector_status = "FAIL"
        if cleanup_status not in {"PASS", "FAIL", "NOT_ATTEMPTED"}:
            cleanup_status = "FAIL"
            collector_status = "FAIL"
        if temp_data_status not in {"CLEANED", "CLEANUP_FAILED", "NOT_CREATED"}:
            temp_data_status = "CLEANUP_FAILED"
            collector_status = "FAIL"
        if cleanup_status == "FAIL" or temp_data_status == "CLEANUP_FAILED" or code is None:
            collector_status = "FAIL"
        if collector_status == "FAIL":
            diagnostic_status = "DIAGNOSTIC_UNAVAILABLE"
        if diagnostic_status == "AVAILABLE" and self.diagnostic is not None:
            try:
                validate_report(self.diagnostic)
                diagnostic = self.diagnostic
            except Exception:
                diagnostic_status = "DIAGNOSTIC_UNAVAILABLE"
                collector_status = "FAIL"
        else:
            diagnostic_status = "DIAGNOSTIC_UNAVAILABLE"
            collector_status = "FAIL"
        command_result = (
            "NOT_STARTED_OR_UNKNOWN" if code is None
            else "PASS" if code == 0
            else "FAIL"
        )
        overall = "PASS" if (
            code == 0
            and diagnostic_status == "AVAILABLE"
            and collector_status == "PASS"
            and cleanup_status == "PASS"
            and temp_data_status == "CLEANED"
        ) else "STOP"
        return {
            "command_exit_code": code,
            "command_result": command_result,
            "diagnostic_status": diagnostic_status,
            "collector_status": collector_status,
            "cleanup_status": cleanup_status,
            "temp_data_status": temp_data_status,
            "overall_result": overall,
            "diagnostic": diagnostic,
        }


def compile_filters(overrides: dict[str, str] | None = None) -> dict[str, re.Pattern[str]]:
    patterns = dict(DEFAULT_FILTERS)
    if overrides:
        patterns.update(overrides)
    if set(patterns) != set(DEFAULT_FILTERS):
        raise DiagnosticError("FILTER_SET_INVALID")
    try:
        return {name: re.compile(source, re.IGNORECASE) for name, source in patterns.items()}
    except (re.error, TypeError):
        raise DiagnosticError("FILTER_INVALID") from None


def sanitize_streams(
    stdout: bytes,
    stderr: bytes,
    command_exit_code: int,
    *,
    filter_overrides: dict[str, str] | None = None,
) -> dict[str, object]:
    """Project bounded raw bytes to the closed diagnostic allowlist."""
    if type(command_exit_code) is not int or not -255 <= command_exit_code <= 255:
        raise DiagnosticError("EXIT_CODE_INVALID")
    if not isinstance(stdout, bytes) or not isinstance(stderr, bytes):
        raise DiagnosticError("CAPTURE_TYPE_INVALID")
    if len(stdout) > MAX_STREAM_BYTES or len(stderr) > MAX_STREAM_BYTES:
        raise DiagnosticError("OUTPUT_LIMIT_EXCEEDED")
    filters = compile_filters(filter_overrides)
    text = (stdout + b"\n" + stderr).decode("utf-8", errors="replace")
    categories: set[str] = set()
    if not stderr:
        categories.add("NO_DIAGNOSTIC_TEXT")
    for name, category in (
        ("datadir", "DATADIR_NOT_EMPTY_OR_EXISTS"),
        ("permission", "PERMISSION_DENIED"),
        ("file_exists", "FILE_EXISTS"),
        ("shared_library", "SHARED_LIBRARY_FAILURE"),
    ):
        if filters[name].search(text):
            categories.add(category)
    codes = sorted(set(filters["my_code"].findall(text)))[:MAX_CODES]
    errnos = sorted({int(value) for value in filters["errno"].findall(text)})[:MAX_CODES]
    if 17 in errnos:
        categories.add("ERRNO_17")
    if not codes and not errnos and not categories:
        categories.add("UNKNOWN")
    report: dict[str, object] = {
        "my_error_codes": codes,
        "os_errnos": errnos,
        "categories": sorted(categories),
    }
    validate_report(report)
    return report


def validate_report(report: dict[str, object]) -> None:
    if set(report) != {"my_error_codes", "os_errnos", "categories"}:
        raise DiagnosticError("REPORT_SCHEMA_INVALID")
    codes = report["my_error_codes"]
    if not isinstance(codes, list) or len(codes) > MAX_CODES or any(
        not isinstance(value, str) or not re.fullmatch(r"MY-\d{6}", value)
        for value in codes
    ):
        raise DiagnosticError("REPORT_CODE_INVALID")
    errnos = report["os_errnos"]
    if not isinstance(errnos, list) or len(errnos) > MAX_CODES or any(
        type(value) is not int or not 0 <= value <= 999 for value in errnos
    ):
        raise DiagnosticError("REPORT_ERRNO_INVALID")
    categories = report["categories"]
    if not isinstance(categories, list) or any(
        not isinstance(value, str) or value not in ALLOWED_CATEGORIES
        for value in categories
    ):
        raise DiagnosticError("REPORT_CATEGORY_INVALID")
    try:
        encoded = json.dumps(report, ensure_ascii=True, separators=(",", ":"))
    except (TypeError, ValueError):
        raise DiagnosticError("REPORT_SERIALIZATION_INVALID") from None
    if len(encoded.encode("ascii")) > 2048:
        raise DiagnosticError("REPORT_SIZE_INVALID")


def _read_bounded(pipe, buffer: bytearray, overflow: list[bool], failure: list[bool]) -> None:
    try:
        while True:
            chunk = pipe.read(8192)
            if not chunk:
                break
            remaining = MAX_STREAM_BYTES - len(buffer)
            if remaining > 0:
                buffer.extend(chunk[:remaining])
            if len(chunk) > remaining:
                overflow[0] = True
    except Exception:
        failure[0] = True
    finally:
        try:
            pipe.close()
        except Exception:
            failure[0] = True


def run_bounded_process(
    argv: list[str], *, cwd: str, env: dict[str, str]
) -> ProcessCapture:
    """Run once, drain both pipes, and retain at most 128 KiB total."""
    try:
        process = subprocess.Popen(
            argv,
            cwd=cwd,
            env=env,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            shell=False,
            close_fds=True,
            start_new_session=(os.name == "posix"),
        )
    except Exception:
        return ProcessCapture(None, b"", b"", read_failed=True)
    stdout = bytearray()
    stderr = bytearray()
    out_overflow = [False]
    err_overflow = [False]
    out_failed = [False]
    err_failed = [False]
    out_thread = threading.Thread(
        target=_read_bounded, args=(process.stdout, stdout, out_overflow, out_failed), daemon=True
    )
    err_thread = threading.Thread(
        target=_read_bounded, args=(process.stderr, stderr, err_overflow, err_failed), daemon=True
    )
    started: list[threading.Thread] = []
    timed_out = False
    try:
        out_thread.start()
        started.append(out_thread)
        err_thread.start()
        started.append(err_thread)
        try:
            exit_code = process.wait(timeout=PROCESS_TIMEOUT_SECONDS)
        except subprocess.TimeoutExpired:
            timed_out = True
            _kill_child_tree(process)
            exit_code = process.wait()
        for thread in started:
            thread.join(timeout=PIPE_DRAIN_TIMEOUT_SECONDS)
        if any(thread.is_alive() for thread in started):
            _kill_child_tree(process)
            for thread in started:
                thread.join(timeout=PIPE_DRAIN_TIMEOUT_SECONDS)
            if any(thread.is_alive() for thread in started):
                out_failed[0] = True
                err_failed[0] = True
    except BaseException:
        _kill_child_tree(process)
        try:
            process.wait()
        except Exception:
            pass
        for thread in started:
            try:
                thread.join(timeout=PIPE_DRAIN_TIMEOUT_SECONDS)
            except BaseException:
                pass
        if any(thread.is_alive() for thread in started):
            out_failed[0] = True
            err_failed[0] = True
        for pipe in (process.stdout, process.stderr):
            try:
                if pipe is not None:
                    pipe.close()
            except Exception:
                pass
        exit_code = None
        out_failed[0] = True
        err_failed[0] = True
    return ProcessCapture(
        exit_code,
        bytes(stdout),
        bytes(stderr),
        out_overflow[0] or err_overflow[0],
        out_failed[0] or err_failed[0],
        timed_out,
    )


def _kill_child_tree(process: subprocess.Popen[bytes]) -> None:
    try:
        if os.name == "posix":
            os.killpg(process.pid, signal.SIGKILL)
        else:
            process.kill()
    except OSError:
        try:
            process.kill()
        except OSError:
            pass


def _safe_root(private_root: Path) -> Path:
    if private_root.is_symlink():
        raise DiagnosticError("PRIVATE_ROOT_INVALID")
    root = private_root.resolve(strict=True)
    st = root.stat()
    if not root.is_dir() or (os.name == "posix" and stat.S_IMODE(st.st_mode) != 0o700):
        raise DiagnosticError("PRIVATE_ROOT_PERMISSIONS_INVALID")
    if os.name == "posix" and st.st_uid != os.geteuid():
        raise DiagnosticError("PRIVATE_ROOT_OWNER_INVALID")
    return root


def _cleanup_workdir(work: Path, root: Path, cleanup_fn: Callable[[Path], None] | None) -> bool:
    """Remove only our direct, non-symlink child; verify absence afterward."""
    try:
        if work.is_symlink() or work.resolve(strict=True).parent != root:
            return False
        st = work.stat(follow_symlinks=False)
        if not stat.S_ISDIR(st.st_mode) or (
            os.name == "posix" and st.st_uid != os.geteuid()
        ):
            return False
        (cleanup_fn or shutil.rmtree)(work)
        return not work.exists() and not work.is_symlink()
    except BaseException:
        return False


def _unavailable(exit_code: int | None, cleanup_status: str, temp_status: str) -> CollectionResult:
    return CollectionResult(
        exit_code, None, "DIAGNOSTIC_UNAVAILABLE", "FAIL", cleanup_status, temp_status
    )


def execute_one_minimal_init(
    private_root: Path,
    *,
    owner_go: bool = False,
    process_runner: Callable[..., ProcessCapture] = run_bounded_process,
    cleanup_fn: Callable[[Path], None] | None = None,
) -> CollectionResult:
    """Prepare one isolated init attempt; invocation requires explicit GO.

    Raw streams exist only in bounded bytearrays. The disposable work directory
    contains only MySQL's temporary datadir; no stdout/stderr or report files.
    """
    if not owner_go:
        raise DiagnosticError("OWNER_GO_REQUIRED")
    if os.name != "posix" and process_runner is run_bounded_process:
        return _unavailable(None, "NOT_ATTEMPTED", "NOT_CREATED")
    try:
        root = _safe_root(private_root)
    except DiagnosticError:
        return _unavailable(None, "NOT_ATTEMPTED", "NOT_CREATED")
    work: Path | None = None
    try:
        work = Path(tempfile.mkdtemp(prefix=TEMP_PREFIX, dir=root))
        os.chmod(work, 0o700)
        if work.is_symlink() or work.resolve(strict=True).parent != root:
            cleaned = _cleanup_workdir(work, root, cleanup_fn)
            return _unavailable(None, "NOT_ATTEMPTED", "CLEANED" if cleaned else "CLEANUP_FAILED")
        work_stat = work.stat(follow_symlinks=False)
        if not stat.S_ISDIR(work_stat.st_mode) or (
            os.name == "posix" and (
                work_stat.st_uid != os.geteuid() or stat.S_IMODE(work_stat.st_mode) != 0o700
            )
        ):
            cleaned = _cleanup_workdir(work, root, cleanup_fn)
            return _unavailable(None, "NOT_ATTEMPTED", "CLEANED" if cleaned else "CLEANUP_FAILED")
    except BaseException:
        if work is not None:
            cleaned = _cleanup_workdir(work, root, cleanup_fn)
            return _unavailable(None, "NOT_ATTEMPTED", "CLEANED" if cleaned else "CLEANUP_FAILED")
        return _unavailable(None, "NOT_ATTEMPTED", "NOT_CREATED")

    data_dir = work / "data"
    if data_dir.exists() or data_dir.is_symlink():
        cleaned = _cleanup_workdir(work, root, cleanup_fn)
        return _unavailable(None, "PASS" if cleaned else "FAIL", "CLEANED" if cleaned else "CLEANUP_FAILED")

    argv = [
        "/usr/sbin/mysqld", "--no-defaults", "--initialize-insecure",
        "--basedir=/usr", f"--datadir={data_dir}",
    ]
    env = {"PATH": "/usr/sbin:/usr/bin:/bin", "LANG": "C", "LC_ALL": "C"}
    exit_code: int | None = None
    try:
        capture = process_runner(argv, cwd=str(work), env=env)
        if not isinstance(capture, ProcessCapture):
            raise DiagnosticError("PROCESS_CAPTURE_INVALID")
        exit_code = capture.exit_code
        if (
            type(exit_code) is not int or not -255 <= exit_code <= 255
            or capture.read_failed or capture.output_limit_exceeded or capture.timed_out
        ):
            raise DiagnosticError("PROCESS_CAPTURE_INCOMPLETE")
        diagnostic = sanitize_streams(capture.stdout, capture.stderr, exit_code)
    except BaseException:
        cleaned = _cleanup_workdir(work, root, cleanup_fn)
        return _unavailable(exit_code, "PASS" if cleaned else "FAIL", "CLEANED" if cleaned else "CLEANUP_FAILED")

    cleaned = _cleanup_workdir(work, root, cleanup_fn)
    if not cleaned:
        return _unavailable(exit_code, "FAIL", "CLEANUP_FAILED")
    return CollectionResult(exit_code, diagnostic, "AVAILABLE", "PASS", "PASS", "CLEANED")


def sanitized_json(result: CollectionResult) -> str:
    """Serialize only the public allowlist; never raw process output or paths."""
    try:
        record = result.public_record()
        encoded = json.dumps(record, ensure_ascii=True, separators=(",", ":"))
        if len(encoded.encode("ascii")) > 4096:
            raise DiagnosticError("PUBLIC_RECORD_TOO_LARGE")
        return encoded
    except Exception:
        return json.dumps({
            "command_exit_code": None,
            "command_result": "NOT_STARTED_OR_UNKNOWN",
            "diagnostic_status": "DIAGNOSTIC_UNAVAILABLE",
            "collector_status": "FAIL",
            "cleanup_status": "FAIL",
            "temp_data_status": "CLEANUP_FAILED",
            "overall_result": "STOP",
            "diagnostic": None,
        }, separators=(",", ":"))
