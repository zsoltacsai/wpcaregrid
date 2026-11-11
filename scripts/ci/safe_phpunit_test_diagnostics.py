"""Strict parser for the two temporary #350 PHPUnit failure markers.

Only fixed test/step IDs, validated exception metadata, and exact allowlisted repository
file identifiers with bounded line numbers are returned. Raw logs, messages, SQL, parameters,
absolute or arbitrary paths, and traces are never returned.
"""
from __future__ import annotations

import re
from collections import defaultdict
from typing import Iterable

PREFIX = "WPCG_TEST_DIAG_V1"
UNKNOWN = "UNKNOWN"
MAX_LOG_BYTES = 16 * 1024 * 1024
MAX_LINE_BYTES = 2048

# IDs are intentionally short, fixed, and unrelated to runtime input.
DIAGNOSTIC_TESTS = {
    "dispatch_active_default_pulse": "phpunit_on_not_successful_test",
    "resolve_legacy_paused_worker": "phpunit_on_not_successful_test",
}
_CLASS = re.compile(r"(?:[A-Za-z_][A-Za-z0-9_]*\\)*[A-Za-z_][A-Za-z0-9_]*\Z")
_SQLSTATE = re.compile(r"[A-Z0-9]{5}\Z")
_DB_CODE = re.compile(r"(?:0|[1-9][0-9]{0,4})\\Z")
_ORIGIN_LINE = re.compile(r"[1-9][0-9]{0,4}\\Z")
_MAX_ORIGIN_LINE = 99999
ORIGIN_FILES = frozenset({
    "tests/Feature/Owner/StagingNextQueueMonitoringTest.php",
    "tests/TestCase.php",
    "app/Console/Commands/Monitoring/RunMonitoring.php",
    "app/Owner/SystemStatus.php",
    "app/Release/StagingWorkerQueuePolicy.php",
    "app/Monitoring/MonitoringEngine.php",
    "app/Monitoring/MonitoringObservation.php",
    "app/Models/MonitoringResult.php",
    "app/Models/MonitoringIncident.php",
    "app/Models/MonitoringTransition.php",
})
_KEYS = ("test", "step", "exception_class", "sqlstate", "db_code", "origin_file", "origin_line")


def _unknown(test_id: str) -> str:
    step = DIAGNOSTIC_TESTS[test_id]
    return (
        f"{PREFIX}|test={test_id}|step={step}|exception_class={UNKNOWN}"
        f"|sqlstate={UNKNOWN}|db_code={UNKNOWN}|origin_file={UNKNOWN}|origin_line={UNKNOWN}"
    )


def _extract_test_id(fields: list[str]) -> str | None:
    if len(fields) < 2 or not fields[1].startswith("test="):
        return None
    test_id = fields[1][len("test="):]
    return test_id if test_id in DIAGNOSTIC_TESTS else None


def _parse_marker(raw_line: bytes) -> tuple[str, str] | None:
    if len(raw_line) > MAX_LINE_BYTES:
        return None
    try:
        line = raw_line.decode("ascii")
    except UnicodeDecodeError:
        return None

    fields = line.split("|")
    if len(fields) != 8 or fields[0] != PREFIX:
        return None

    values: dict[str, str] = {}
    for expected, field in zip(_KEYS, fields[1:]):
        key, separator, value = field.partition("=")
        if separator != "=" or key != expected or not value or "=" in value:
            return None
        values[key] = value

    test_id = values["test"]
    if test_id not in DIAGNOSTIC_TESTS or values["step"] != DIAGNOSTIC_TESTS[test_id]:
        return None

    exception_class = values["exception_class"]
    if exception_class != UNKNOWN and (
        len(exception_class) > 192 or _CLASS.fullmatch(exception_class) is None
    ):
        return None

    sqlstate = values["sqlstate"]
    if sqlstate != UNKNOWN and _SQLSTATE.fullmatch(sqlstate) is None:
        return None

    db_code = values["db_code"]
    if db_code != UNKNOWN and (
        _DB_CODE.fullmatch(db_code) is None or int(db_code) > 65535
    ):
        return None

    origin_file = values["origin_file"]
    origin_line = values["origin_line"]
    if origin_file != UNKNOWN and origin_file not in ORIGIN_FILES:
        return None
    if origin_file == UNKNOWN:
        if origin_line != UNKNOWN:
            return None
    elif _ORIGIN_LINE.fullmatch(origin_line) is None or int(origin_line) > _MAX_ORIGIN_LINE:
        return None

    normalized = (
        f"{PREFIX}|test={test_id}|step={DIAGNOSTIC_TESTS[test_id]}"
        f"|exception_class={exception_class}|sqlstate={sqlstate}|db_code={db_code}"
        f"|origin_file={origin_file}|origin_line={origin_line}"
    )
    return test_id, normalized


def diagnostics_from_bytes(log_bytes: bytes, failed_test_ids: Iterable[str]) -> list[str]:
    """Return one safe record per failed allowlisted test, or UNKNOWN metadata."""
    requested = list(dict.fromkeys(test_id for test_id in failed_test_ids if test_id in DIAGNOSTIC_TESTS))
    if not requested:
        return []
    if not isinstance(log_bytes, bytes) or len(log_bytes) > MAX_LOG_BYTES:
        return [_unknown(test_id) for test_id in requested]

    parsed: dict[str, list[str]] = defaultdict(list)
    invalid_tests: set[str] = set()
    global_invalid = False

    for raw_line in log_bytes.splitlines():
        if len(raw_line) > MAX_LINE_BYTES:
            if raw_line.startswith(PREFIX.encode("ascii")):
                global_invalid = True
            continue
        if not raw_line.startswith(PREFIX.encode("ascii") + b"|"):
            continue
        result = _parse_marker(raw_line)
        if result is None:
            test_id = _extract_test_id(raw_line.decode("ascii", errors="ignore").split("|"))
            if test_id is None:
                global_invalid = True
            else:
                invalid_tests.add(test_id)
            continue
        test_id, normalized = result
        if test_id not in requested:
            global_invalid = True
        parsed[test_id].append(normalized)

    records: list[str] = []
    for test_id in requested:
        if global_invalid or test_id in invalid_tests or len(parsed[test_id]) != 1:
            records.append(_unknown(test_id))
        else:
            records.append(parsed[test_id][0])
    return records


def diagnostics_for_log(log_path: str, failed_test_ids: Iterable[str]) -> list[str]:
    """Read a bounded runner-private log without returning or printing its contents."""
    try:
        with open(log_path, "rb") as stream:
            if stream.seek(0, 2) > MAX_LOG_BYTES:
                return [_unknown(test_id) for test_id in dict.fromkeys(
                    item for item in failed_test_ids if item in DIAGNOSTIC_TESTS
                )]
            stream.seek(0)
            data = stream.read(MAX_LOG_BYTES + 1)
    except OSError:
        data = b""
    return diagnostics_from_bytes(data, failed_test_ids)
