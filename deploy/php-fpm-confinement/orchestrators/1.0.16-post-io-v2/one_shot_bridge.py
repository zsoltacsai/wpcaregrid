"""One-shot root PTY runner that returns only a validated final JSON report."""
from __future__ import annotations

import json
import hashlib
import os
import pty
import re
import shlex
import select
import signal
import stat
import time
from typing import Any

from pty_io import _Reader, _reap, _write_all

REPORT_PREFIX = b"__WPCG_ONESHOT_REPORT__:"
DONE_TOKEN = b"__WPCG_ONESHOT_DONE__"
IDENTITY_COMMAND = b"printf '\\n__WPCG_ROOT_CHECK__:%s:%s\\n' \"$(id -u)\" \"$(hostname -f)\""
IDENTITY_LINE = b"__WPCG_ROOT_CHECK__:0:stg1.wpcaregrid.com"

PACKAGE_REPORT_TYPES = {
    "phase": str,
    "rc": int,
    "package_version": str,
    "tree_match": bool,
    "sha256sums_ok": bool,
    "pin_count": int,
    "sha256sums_digest_match": bool,
    "binding_digest_match": bool,
    "filelist_match": bool,
    "owner_mode_type_links_match": bool,
}

PREFLIGHT_REPORT_TYPES = {
    "phase": str,
    "rc": int,
    "package_version": str,
    "fixture_status_rc": int,
    "fixture_expected_count": int,
    "fixture_observed_count": int,
    "fixture_match": bool,
    "inventory1_complete": bool,
    "inventory2_complete": bool,
    "inventory_stable": bool,
    "inventory_unknown_peers": int,
    "parser_ok": bool,
    "nginx_active_enforce": bool,
    "production_fpm_active_enforce": bool,
    "php84_masked_inactive": bool,
    "php84_socket_absent": bool,
    "stock_php85_inactive": bool,
    "production_socket_listening": bool,
    "mysqld_root_metadata_ok": bool,
    "mysqld_parent_metadata_ok": bool,
    "sibling_socket_absent": bool,
    "route_closed_verified": bool,
    "preflight_pass": bool,
}

DIAGNOSTIC_REPORT_TYPES = {
    "phase": str,
    "rc": int,
    "evidence_path": str,
    "evidence_hash": str,
    "fixture_missing": list,
    "peer_unmatched_count": int,
    "peer_records": list,
    "nginx_summary": dict,
    "package_pycache_present": bool,
    "package_pycache_count": int,
    "bridge_status": dict,
    "route_status": str,
}

REPAIR_REPORT_TYPES = {
    "phase": str,
    "rc": int,
    "cache": dict,
    "package": dict,
    "fixture_before": dict,
    "fixture_create": dict,
    "fixture_after": dict,
    "evidence_path": str,
}

INVENTORY_EVIDENCE_REPORT_TYPES = {
    "phase": str,
    "rc": int,
    "evidence_path": str,
    "directory": dict,
    "children": list,
    "error_class": str,
    "content_inspection": str,
}

INVENTORY_CANDIDATE_REPORT_TYPES = {
    "phase": str,
    "rc": int,
    "evidence_path": str,
    "stdout_name": str,
    "stderr_name": str,
    "candidate_origin_proven": bool,
    "directory_meta": dict,
    "stdout_meta": dict,
    "stderr_meta": dict,
    "access_gate": bool,
    "content_read": bool,
    "stderr_error_class": str,
    "parse_ok": bool,
    "schema": int,
    "complete": bool,
    "scans": int,
    "matrix_sha256_match": bool,
    "boot_id_match": bool,
    "collected_time_valid": bool,
    "process_count": int,
    "units": list,
    "production_surface": dict,
    "reason_codes": dict,
    "verifier_check_ran": bool,
    "verifier_failure_count": int,
    "rejected_fd_count": int,
    "verifier_failure_codes": list,
}

INVENTORY_PAIR_REPORT_TYPES = {
    "phase": str,
    "rc": int,
    "evidence_path": str,
    "package_pin_ok": bool,
    "matrix_pin_ok": bool,
    "collector_pin_ok": bool,
    "runs": list,
    "stable_process_signature": bool,
    "verifier_pass": bool,
}

ACCEPTANCE_POST_REPORT_TYPES = {
    "phase": str,
    "rc": int,
    "evidence_path": str,
    "package_pin_ok": bool,
    "fixture_pre_match": bool,
    "host_preflight_ok": bool,
    "profile_results": list,
    "fixture_cleanup_rc": int,
    "fixture_empty": bool,
    "sibling_socket_absent": bool,
    "overall_pass": bool,
    "failure_code": str,
    "exception_metadata_written": bool,
    "exception_phase": str,
    "exception_class": str,
    "exception_errno": (int, type(None)),
    "exception_thrower": (dict, type(None)),
    "exception_thrower_truncated": bool,
}

ACCEPTANCE_DIAGNOSTIC_REPORT_TYPES = {
    "phase": str, "rc": int, "evidence_path": str, "directory_meta": dict,
    "children": list, "sha256_check": dict, "fixtures_before": dict,
    "preflight_summary": dict, "stage_code": str,
}


def _capture_until(reader: _Reader, token: bytes, timeout: float, limit: int = 65536) -> tuple[bytes, str]:
    deadline = time.monotonic() + timeout
    captured = bytearray()
    while True:
        index = reader.pending.find(token)
        if index >= 0:
            captured.extend(reader.pending[:index])
            del reader.pending[:index + len(token)]
            return bytes(captured), "matched"
        if reader.pending:
            captured.extend(reader.pending)
            reader.pending.clear()
            if len(captured) > limit:
                return b"", "too_large"
        if time.monotonic() >= deadline:
            return b"", "timeout"
        if not reader._fill(deadline):
            return b"", "eof"


def _parse_report(captured: bytes) -> dict[str, Any] | None:
    rows = []
    for line in captured.split(b"\n"):
        line = line.strip(b"\r")
        if line.startswith(REPORT_PREFIX):
            rows.append(line[len(REPORT_PREFIX):])
    if len(rows) != 1:
        return None
    try:
        payload = json.loads(rows[0].decode("ascii"))
    except (UnicodeDecodeError, json.JSONDecodeError):
        return None
    if not isinstance(payload, dict) or not isinstance(payload.get("phase"), str):
        return None
    report_types = {
        "package-check": PACKAGE_REPORT_TYPES,
        "preflight": PREFLIGHT_REPORT_TYPES,
        "diagnostic": DIAGNOSTIC_REPORT_TYPES,
        "repair-preflight": REPAIR_REPORT_TYPES,
        "inventory-evidence-metadata": INVENTORY_EVIDENCE_REPORT_TYPES,
        "inventory-candidate": INVENTORY_CANDIDATE_REPORT_TYPES,
        "inventory-pair": INVENTORY_PAIR_REPORT_TYPES,
        "acceptance-post": ACCEPTANCE_POST_REPORT_TYPES,
        "acceptance-diagnostic": ACCEPTANCE_DIAGNOSTIC_REPORT_TYPES,
    }.get(payload["phase"])
    if report_types is None or set(payload) != set(report_types):
        return None
    for key, expected in report_types.items():
        if isinstance(expected, tuple):
            if type(payload[key]) not in expected:
                return None
        elif type(payload[key]) is not expected:
            return None
    if not (0 <= payload["rc"] <= 255):
        return None
    if len(payload["phase"]) > 32 or len(payload.get("package_version", "")) > 32:
        return None
    if payload["phase"] == "package-check" and not (0 <= payload["pin_count"] <= 100):
        return None
    if payload["phase"] == "preflight" and not (0 <= payload["inventory_unknown_peers"] <= 10000):
        return None
    if payload["phase"] == "diagnostic" and (len(payload["evidence_path"]) > 512
                                                or len(payload["evidence_hash"]) != 64
                                                or len(payload["fixture_missing"]) > 21
                                                or len(payload["peer_records"]) > 32):
        return None
    if payload["phase"] == "repair-preflight" and len(payload["evidence_path"]) > 512:
        return None
    if payload["phase"] == "inventory-evidence-metadata":
        if len(payload["evidence_path"]) > 512 or len(payload["children"]) > 64:
            return None
        if payload["content_inspection"] != "not-read-basename-unknown":
            return None
        if payload["error_class"] not in ("none", "PermissionError", "FileNotFoundError", "NotADirectoryError", "other"):
            return None
        stat_keys = {"type", "uid", "gid", "mode", "nlink", "size", "mtime_ns"}
        if set(payload["directory"]) != stat_keys:
            return None
        if any(type(payload["directory"][key]) is not int for key in stat_keys - {"type"}):
            return None
        if type(payload["directory"]["type"]) is not str:
            return None
        for child in payload["children"]:
            if not isinstance(child, dict) or set(child) != ({"name"} | stat_keys):
                return None
            if type(child["name"]) is not str or len(child["name"]) > 255 or "/" in child["name"]:
                return None
            if type(child["type"]) is not str or any(type(child[key]) is not int for key in stat_keys - {"type"}):
                return None
    if payload["phase"] == "inventory-candidate":
        if len(payload["evidence_path"]) > 512 or len(payload["stdout_name"]) > 255 or len(payload["stderr_name"]) > 255:
            return None
        if "/" in payload["stdout_name"] or "/" in payload["stderr_name"]:
            return None
        if payload["candidate_origin_proven"] is not False:
            return None
        if payload["stderr_error_class"] not in ("none", "not-read", "Traceback", "PermissionError", "FileNotFoundError", "JSONDecodeError", "other"):
            return None
        if not (0 <= payload["schema"] <= 100 and 0 <= payload["scans"] <= 100):
            return None
        for key in ("process_count", "verifier_failure_count", "rejected_fd_count"):
            if not (0 <= payload[key] <= 1000000000):
                return None
        stat_keys = {"type", "uid", "gid", "mode", "nlink", "size", "mtime_ns"}
        for key in ("directory_meta", "stdout_meta", "stderr_meta"):
            meta = payload[key]
            if not isinstance(meta, dict) or set(meta) != stat_keys:
                return None
            if type(meta["type"]) is not str or len(meta["type"]) > 32:
                return None
            if any(type(meta[field]) is not int for field in stat_keys - {"type"}):
                return None
        if len(payload["units"]) > 64 or len(payload["reason_codes"]) > 32 or len(payload["verifier_failure_codes"]) > 32:
            return None
        for unit in payload["units"]:
            if not isinstance(unit, dict) or set(unit) != {"name", "present", "pid_count"}:
                return None
            if type(unit["name"]) is not str or len(unit["name"]) > 128 or type(unit["present"]) is not bool:
                return None
            if type(unit["pid_count"]) is not int or not (0 <= unit["pid_count"] <= 100000):
                return None
        surface = payload["production_surface"]
        if not isinstance(surface, dict) or set(surface) != {"present", "complete", "real_file_count", "fixture_file_count", "reason_count"}:
            return None
        if any(type(surface[key]) is not bool for key in ("present", "complete")):
            return None
        if any(type(surface[key]) is not int or not (0 <= surface[key] <= 1000000)
               for key in ("real_file_count", "fixture_file_count", "reason_count")):
            return None
        safe_reasons = {"unreadable-status", "unreadable-process-data", "cgroup-error", "boot-id-unreadable", "churn", "other"}
        if any(key not in safe_reasons or type(value) is not int or not (0 <= value <= 100000)
               for key, value in payload["reason_codes"].items()):
            return None
        safe_failures = {"inventory", "fd-inventory", "other"}
        if any(code not in safe_failures for code in payload["verifier_failure_codes"]):
            return None
    if payload["phase"] == "inventory-pair":
        if len(payload["evidence_path"]) > 512 or len(payload["runs"]) != 2:
            return None
        run_keys = {"rc", "stderr_empty", "json_size", "parse_ok", "schema", "complete", "scans",
                    "matrix_match", "boot_match", "time_valid", "process_count", "units",
                    "verifier_failures", "rejected_fd", "reason_codes"}
        unit_keys = {"name", "present", "pid_count"}
        for run in payload["runs"]:
            if not isinstance(run, dict) or set(run) != run_keys:
                return None
            for key, expected in (("rc", int), ("stderr_empty", bool), ("json_size", int),
                                  ("parse_ok", bool), ("schema", int), ("complete", bool),
                                  ("scans", int), ("matrix_match", bool), ("boot_match", bool),
                                  ("time_valid", bool), ("process_count", int),
                                  ("verifier_failures", int), ("rejected_fd", int)):
                if type(run[key]) is not expected:
                    return None
            if any(run[key] < 0 for key in ("json_size", "schema", "scans", "process_count", "verifier_failures", "rejected_fd")):
                return None
            if not isinstance(run["units"], list) or len(run["units"]) > 64:
                return None
            for unit in run["units"]:
                if not isinstance(unit, dict) or set(unit) != unit_keys or type(unit["name"]) is not str or len(unit["name"]) > 128 or type(unit["present"]) is not bool or type(unit["pid_count"]) is not int or unit["pid_count"] < 0:
                    return None
            if not isinstance(run["reason_codes"], list) or len(run["reason_codes"]) > 32 or any(type(code) is not str or len(code) > 64 for code in run["reason_codes"]):
                return None
    if payload["phase"] == "acceptance-post":
        if (len(payload["evidence_path"]) > 512
                or (payload["evidence_path"] and not re.fullmatch(r'/[A-Za-z0-9_./-]{1,511}',payload["evidence_path"]))
                or not re.fullmatch(r'[a-z0-9_.-]{1,64}',payload["failure_code"])
                or len(payload["profile_results"]) > 4):
            return None
        if (len(payload["exception_phase"]) > 96
                or not re.fullmatch(r'[a-z0-9_.-]{1,96}', payload["exception_phase"])
                or payload["exception_class"] not in {
                    "none", "Other", "AttributeError", "CalledProcessError", "FileNotFoundError",
                    "ImportError", "IndexError", "IsADirectoryError", "JSONDecodeError", "KeyError",
                    "NotADirectoryError", "OSError", "PermissionError", "RuntimeError",
                    "TimeoutExpired", "TypeError", "ValueError"}):
            return None
        if payload["exception_errno"] is not None and not -4096 <= payload["exception_errno"] <= 4096:
            return None
        thrower = payload["exception_thrower"]
        if payload["exception_thrower_truncated"] and thrower is not None:
            return None
        if thrower is not None:
            if not isinstance(thrower, dict) or set(thrower) != {"basename", "function", "line"}:
                return None
            if (type(thrower["basename"]) is not str or not re.fullmatch(r'[A-Za-z0-9._-]{1,96}', thrower["basename"])
                    or type(thrower["function"]) is not str or not re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]{0,79}', thrower["function"])
                    or type(thrower["line"]) is not int or thrower["line"] < 0):
                return None
        if payload["failure_code"] == "unhandled" and (payload["rc"] != 2 or payload["overall_pass"]):
            return None
        if payload["fixture_cleanup_rc"] not in (-1, 0, 1, 2, 255):
            return None
        profile_names = {"wpcaregrid-nginx", "wpcaregrid-php-fpm-production",
                         "wpcaregrid-php-cli-production-build", "wpcaregrid-php-cli-production-deploy"}
        keys = {"profile", "status", "inventory_before_rc", "baseline_rc", "confined_rc",
                "inventory_after_rc", "verifier_rc", "sibling_required", "sibling_cleanup_ok",
                "probe_unloaded", "original_exec_check", "failure_code"}
        for result in payload["profile_results"]:
            if not isinstance(result, dict) or set(result) != keys:
                return None
            if result["profile"] not in profile_names or result["status"] not in ("PASS", "HOLD"):
                return None
            if type(result["failure_code"]) is not str or not re.fullmatch(r'[a-z0-9_.-]{1,64}',result["failure_code"]):
                return None
            for key in ("inventory_before_rc", "baseline_rc", "confined_rc", "inventory_after_rc", "verifier_rc"):
                if type(result[key]) is not int or result[key] not in (-1, 0, 1, 2, 3, 255):
                    return None
            if any(type(result[key]) is not bool for key in ("sibling_required", "sibling_cleanup_ok", "probe_unloaded", "original_exec_check")):
                return None
    if payload["phase"] == "acceptance-diagnostic":
        if len(payload["evidence_path"]) > 512 or len(payload["children"]) > 64 or payload["stage_code"] not in {
            "evidence-dir-absent", "checksum-output", "fixture-status", "host-preflight", "profile-run", "unknown"
        }:
            return None
        stat_keys={"type","uid","gid","mode","nlink","size","mtime_ns"}
        if not isinstance(payload["directory_meta"],dict) or set(payload["directory_meta"])!=stat_keys:
            return None
        for item in payload["children"]:
            if not isinstance(item,dict) or set(item)!=(stat_keys|{"name"}) or type(item["name"]) is not str or len(item["name"])>255 or "/" in item["name"]:
                return None
            if type(item["type"]) is not str or any(type(item[k]) is not int for k in stat_keys-{"type"}): return None
        sh=payload["sha256_check"]
        if set(sh)!={"present","stdout_lines","ok_lines","bad_lines","stderr_size","stderr_class"} or type(sh["present"]) is not bool or any(type(sh[k]) is not int or sh[k]<0 for k in ("stdout_lines","ok_lines","bad_lines","stderr_size")) or sh["stderr_class"] not in ("none","missing","permission","not-found","checksum-failure","other"):
            return None
        fx=payload["fixtures_before"]
        if set(fx)!={"present","parse_ok","found_count","expected_count","exact_match","stderr_size","stderr_class"} or any(type(fx[k]) is not bool for k in ("present","parse_ok","exact_match")) or any(type(fx[k]) is not int or fx[k]<0 for k in ("found_count","expected_count","stderr_size")) or fx["stderr_class"] not in ("none","missing","permission","not-found","other"):
            return None
        sm=payload["preflight_summary"]
        if set(sm)!={"present","parsed","pin_checks","service_rcs","fixture_count","parent_ok","socket_absent","parser_ok"} or type(sm["present"]) is not bool or type(sm["parsed"]) is not bool or any(type(sm[k]) is not bool for k in ("parent_ok","socket_absent","parser_ok")) or type(sm["fixture_count"]) is not int or sm["fixture_count"]<0 or not isinstance(sm["pin_checks"],dict) or len(sm["pin_checks"])>16 or any(type(k) is not str or type(v) is not bool for k,v in sm["pin_checks"].items()) or not isinstance(sm["service_rcs"],dict) or len(sm["service_rcs"])>16 or any(type(k) is not str or type(v) is not int for k,v in sm["service_rcs"].items()):
            return None
    return payload


def _read_one_use_secret(path: str) -> bytearray:
    nofollow=getattr(os,'O_NOFOLLOW',None)
    nonblock=getattr(os,'O_NONBLOCK',None)
    if nofollow is None or nonblock is None:
        raise OSError('secure-open-unavailable')
    fd=os.open(path,os.O_RDONLY|nofollow|nonblock|getattr(os,'O_CLOEXEC',0))
    identity=None
    safe_to_unlink=False
    secret=None
    failed=False
    try:
        st=os.fstat(fd)
        identity=(st.st_dev,st.st_ino)
        safe_to_unlink=(stat.S_ISREG(st.st_mode) and st.st_uid==os.getuid())
        if (not safe_to_unlink or st.st_nlink!=1 or stat.S_IMODE(st.st_mode)&0o077
                or not stat.S_IMODE(st.st_mode)&0o400 or st.st_size>4096):
            raise OSError('invalid-one-use-file')
        data=bytearray()
        try:
            while len(data)<=4096:
                chunk=os.read(fd,4097-len(data))
                if not chunk: break
                data.extend(chunk)
            if not data or len(data)>4096:
                raise OSError('invalid-one-use-file-size')
            secret=bytearray(data.rstrip(b'\r\n'))
            if not secret:
                raise OSError('empty-one-use-file')
            return secret
        except BaseException:
            failed=True
            if secret is not None:
                for index in range(len(secret)):
                    secret[index]=0
                secret.clear()
            raise
        finally:
            for index in range(len(data)):
                data[index]=0
            data.clear()
    finally:
        os.close(fd)
        if safe_to_unlink and identity is not None:
            try:
                current=os.lstat(path)
                if ((current.st_dev,current.st_ino)!=identity
                        or not stat.S_ISREG(current.st_mode)
                        or current.st_uid!=os.getuid()):
                    failed=True
                else:
                    os.unlink(path)
            except OSError:
                failed=True
        if failed:
            if secret is not None:
                for index in range(len(secret)):
                    secret[index]=0
                secret.clear()
            raise OSError('one-use-remove-failed')


def run_one_shot_root_bridge(
    argv: list[str],
    secret_path: str,
    command: bytes,
    input_fd: int = 0,
    output_fd: int = 1,
    timeout: float = 20.0,
    require_interactive_stdin: bool = True,
) -> dict[str, Any]:
    """Run one command under su, capture its whitelist JSON, exit and reap.

    The root PTY stream is never forwarded. Only the validated command report
    is included in the outer JSON emitted by the caller after this returns.
    """
    report: dict[str, Any] = {
        "password_prompt_seen": False,
        "credential_stdin_written": False,
        "identity_marker_matched": False,
        "command_report": None,
        "command_report_valid": False,
        "outer_stdin_is_tty": os.isatty(input_fd),
        "outer_stdout_is_tty": os.isatty(output_fd),
        "timed_out": False,
        "child_reaped": False,
        "su_exit_code": None,
        "su_signal": None,
        "outcome": "not_started",
    }
    if require_interactive_stdin and not (report["outer_stdin_is_tty"] and report["outer_stdout_is_tty"]):
        report["outcome"] = "outer_stream_not_tty"
        return report
    if not isinstance(command, bytes) or not command or b"\n" in command or b"\r" in command:
        report["outcome"] = "invalid_command_frame"
        return report

    pid = fd = None
    try:
        pid, fd = pty.fork()
        if pid == 0:
            os.execvp(argv[0], argv)
        reader = _Reader(fd)

        prompt = reader.wait_token((b"Password:", b"password:"), timeout)
        if prompt == "timeout":
            report["timed_out"] = True
            report["outcome"] = "password_prompt_timeout"
            _reap(pid, fd, report, send_hup=True)
            return report
        if prompt == "eof":
            report["outcome"] = "child_exited_before_password_prompt"
            _reap(pid, fd, report, send_hup=False)
            return report
        report["password_prompt_seen"] = True

        secret = _read_one_use_secret(secret_path)
        try:
            _write_all(fd, secret)
            _write_all(fd, b"\n")
            report["credential_stdin_written"] = True
        finally:
            for index in range(len(secret)):
                secret[index] = 0
            secret.clear()

        prompt = reader.wait_token((b"Password:", b"password:", b"# "), timeout)
        if prompt == "timeout":
            report["timed_out"] = True
            report["outcome"] = "shell_prompt_timeout"
            _reap(pid, fd, report, send_hup=True)
            return report
        if prompt == "eof":
            report["outcome"] = "child_exited_after_credential"
            _reap(pid, fd, report, send_hup=False)
            return report
        if prompt != b"# ":
            report["outcome"] = "authentication_rejected"
            _reap(pid, fd, report, send_hup=True)
            return report

        _write_all(fd, b"stty -echo\n")
        if reader.wait_token((b"# ",), timeout) != b"# ":
            report["outcome"] = "echo_disable_failed"
            _reap(pid, fd, report, send_hup=True)
            return report
        _write_all(fd, IDENTITY_COMMAND + b"\n")
        identity = reader.wait_exact_line(IDENTITY_LINE, timeout)
        if identity != "matched":
            report["timed_out"] = identity == "timeout"
            report["outcome"] = "identity_check_failed"
            _reap(pid, fd, report, send_hup=identity == "timeout")
            return report
        report["identity_marker_matched"] = True
        if reader.wait_token((b"# ",), timeout) != b"# ":
            report["outcome"] = "identity_prompt_missing"
            _reap(pid, fd, report, send_hup=True)
            return report

        _write_all(fd, command + b"\n")
        captured, state = _capture_until(reader, DONE_TOKEN, timeout)
        if state != "matched":
            report["timed_out"] = state == "timeout"
            report["outcome"] = "command_report_missing"
            _reap(pid, fd, report, send_hup=True)
            return report
        command_report = _parse_report(captured)
        if command_report is None:
            report["outcome"] = "command_report_invalid"
            _reap(pid, fd, report, send_hup=True)
            return report
        report["command_report"] = command_report
        report["command_report_valid"] = True
        if reader.wait_token((b"# ",), timeout) != b"# ":
            report["outcome"] = "post_command_prompt_missing"
            _reap(pid, fd, report, send_hup=True)
            return report

        _write_all(fd, b"exit\n")
        closed = reader.wait_eof(timeout)
        if closed != "eof":
            report["timed_out"] = closed == "timeout"
            report["outcome"] = "shell_exit_failed"
            _reap(pid, fd, report, send_hup=True)
            return report
        report["outcome"] = "completed"
        _reap(pid, fd, report, send_hup=False)
        return report
    except BaseException:
        report["outcome"] = "driver_error"
        if pid is not None and fd is not None and not report["child_reaped"]:
            _reap(pid, fd, report, send_hup=True)
        return report


def _safe_absolute_path(value: str) -> str:
    if (not isinstance(value,str) or not value.startswith('/') or len(value)>4096
            or any(ord(c)<0x20 or ord(c)==0x7f for c in value)):
        raise ValueError('path')
    return value


def _bundle_valid() -> bool:
    root=os.path.dirname(os.path.abspath(__file__))
    required={'acceptance-post.py','one_shot_bridge.py','pty_io.py','PINNED_INPUTS.json','README.md'}
    try:
        declared={}
        with open(os.path.join(root,'SHA256SUMS'),'r',encoding='ascii') as source:
            for line in source.read().splitlines():
                digest,name=line.split('  ',1)
                if name in declared or '/' in name or not re.fullmatch(r'[0-9a-f]{64}',digest): return False
                declared[name]=digest
        root_st=os.lstat(root)
        if (not stat.S_ISDIR(root_st.st_mode) or stat.S_ISLNK(root_st.st_mode)
                or (root_st.st_uid,root_st.st_gid)!=(0,0)
                or stat.S_IMODE(root_st.st_mode)!=0o755): return False
        if set(declared)!=required or {n for n in os.listdir(root) if n!='SHA256SUMS'}!=required: return False
        for name,digest in declared.items():
            path=os.path.join(root,name); st=os.lstat(path)
            mode=0o755 if name in ('acceptance-post.py','one_shot_bridge.py') else 0o644
            if (not stat.S_ISREG(st.st_mode) or st.st_nlink!=1
                    or (st.st_uid,st.st_gid)!=(0,0) or stat.S_IMODE(st.st_mode)!=mode): return False
            h=hashlib.sha256()
            with open(path,'rb') as source:
                for block in iter(lambda:source.read(1024*1024),b''): h.update(block)
            if h.hexdigest()!=digest: return False
        st=os.lstat(os.path.join(root,'SHA256SUMS'))
        return (stat.S_ISREG(st.st_mode) and st.st_nlink==1
                and (st.st_uid,st.st_gid)==(0,0) and stat.S_IMODE(st.st_mode)==0o644)
    except (OSError,ValueError):
        return False


def main(argv: list[str] | None = None) -> int:
    """T3 tty entry point: run only the pinned 1.0.16 post orchestrator."""
    import argparse
    parser=argparse.ArgumentParser(description='One-shot Gate 2 1.0.16 post runner')
    parser.add_argument('--root-card-file',required=True)
    parser.add_argument('--package-dir',required=True)
    parser.add_argument('--evidence-envelope',required=True)
    parser.add_argument('--route-closure-evidence',required=True)
    args=parser.parse_args(argv)
    if not _bundle_valid():
        print(json.dumps({"outcome":"artifact_pin_failed","child_reaped":True,
                          "su_exit_code":None},separators=(",",":")),flush=True)
        return 2
    card_path=_safe_absolute_path(args.root_card_file)
    package_path=_safe_absolute_path(args.package_dir)
    envelope_path=_safe_absolute_path(args.evidence_envelope)
    route_path=_safe_absolute_path(args.route_closure_evidence)
    script_path=os.path.join(os.path.dirname(os.path.abspath(__file__)),'acceptance-post.py')
    command='/usr/bin/python3 -B '+shlex.quote(script_path)+' --package-dir '+shlex.quote(package_path)
    command+=' --evidence-envelope '+shlex.quote(envelope_path)+' --route-closure-evidence '+shlex.quote(route_path)
    report=run_one_shot_root_bridge(['su','-'],card_path,command.encode('utf-8'),timeout=1800,require_interactive_stdin=True)
    print(json.dumps(report,sort_keys=True,separators=(',',':')),flush=True)
    result=report.get('command_report') or {}
    success=(report.get('outcome')=='completed' and report.get('command_report_valid') is True
             and report.get('child_reaped') is True and report.get('su_exit_code')==0
             and result.get('phase')=='acceptance-post' and result.get('rc')==0
             and result.get('overall_pass') is True)
    return 0 if success else 1


if __name__=='__main__':
    raise SystemExit(main())
