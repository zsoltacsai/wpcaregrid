#!/usr/bin/env python3
"""Emit a bounded, allowlisted projection of Package B PHPUnit JUnit failures."""

from __future__ import annotations

import argparse
import re
import stat
import sys
import xml.etree.ElementTree as ET
from pathlib import Path


PREFIX = "WPCG_PB_DIAG_V1"
TARGET_CLASS = "Tests\\Feature\\Deployment\\Phase2B\\TransitionLeasePersistenceTest"
TARGET_CLASS_DOTTED = TARGET_CLASS.replace("\\", ".")
MAX_XML_BYTES = 2 * 1024 * 1024
MAX_FAILURES = 32
CLEANUP_STATES = {"REMOVED", "ALREADY_ABSENT", "FAILED"}
XML_ENCODING_RE = re.compile(rb"<\?xml\b[^>]*\bencoding\s*=\s*['\"]([^'\"]+)['\"]", re.I)
INCOMPLETE_XML_REASONS = (
    "no element found",
    "unclosed token",
    "unclosed cdata section",
    "unexpected end",
    "premature end",
)

EXCEPTION_CLASSES = {
    "Illuminate\\Database\\QueryException": "QueryException",
    "PDOException": "PDOException",
    "PHPUnit\\Framework\\ExpectationFailedException": "ExpectationFailedException",
    "PHPUnit\\Framework\\AssertionFailedError": "AssertionFailedError",
    "PHPUnit\\Framework\\Error\\Error": "PHPUnitError",
    "RuntimeException": "RuntimeException",
    "InvalidArgumentException": "InvalidArgumentException",
    "LogicException": "LogicException",
    "ErrorException": "ErrorException",
    "TypeError": "TypeError",
    "ValueError": "ValueError",
    "Error": "Error",
    "Exception": "Exception",
}
METHOD_RE = re.compile(r"\A(test_[A-Za-z0-9_]{1,120})(?:\b|#| with data set)")
SQLSTATE_RE = re.compile(r"SQLSTATE\[([A-Z0-9]{5})\]", re.I)
VENDOR_CODE_RE = re.compile(r"\]\s*:\s*[^:\r\n]{1,80}:\s*(\d{1,5})(?=\s|$)")
PHASE_SETUP_RE = re.compile(r"(?:TransitionLeasePersistenceTest(?:->|::)setUp|RefreshDatabase|migrate:fresh)")
PHASE_TEARDOWN_RE = re.compile(r"TransitionLeasePersistenceTest(?:->|::)tearDown")


def cleanup_files(entries: tuple[tuple[str, str | Path], ...], unlinker=None) -> dict[str, str]:
    """Attempt each diagnostic-file deletion independently and return fixed states only."""
    if unlinker is None:
        unlinker = lambda path: Path(path).unlink()
    outcomes: dict[str, str] = {}
    for label, path in entries:
        if label not in {"workflow_log", "junit"} or label in outcomes:
            raise ValueError("Invalid cleanup entry.")
        try:
            unlinker(path)
            outcomes[label] = "REMOVED"
        except FileNotFoundError:
            outcomes[label] = "ALREADY_ABSENT"
        except Exception:
            outcomes[label] = "FAILED"
    if set(outcomes) != {"workflow_log", "junit"}:
        raise ValueError("Incomplete cleanup entry set.")
    return outcomes


def exit_code_after_cleanup(test_exit_code: int, outcomes: dict[str, str]) -> int:
    """Preserve a failing PHPUnit status; fail a successful run if cleanup is unresolved."""
    if test_exit_code != 0:
        return test_exit_code
    return 1 if any(outcomes.get(label) == "FAILED" for label in ("workflow_log", "junit")) else 0


def cleanup_record(outcomes: dict[str, str]) -> str:
    if set(outcomes) != {"workflow_log", "junit"} or any(value not in CLEANUP_STATES for value in outcomes.values()):
        raise ValueError("Invalid cleanup outcome.")
    return (
        f"{PREFIX}|status=CLEANUP|workflow_log={outcomes['workflow_log']}"
        f"|junit={outcomes['junit']}"
    )


def self_test_cleanup() -> int:
    """Deterministic synthetic regression checks for independent cleanup attempts."""
    scenarios = (
        ({}, {"workflow_log": "REMOVED", "junit": "REMOVED"}, 0, 0),
        ({"workflow_log": OSError()}, {"workflow_log": "FAILED", "junit": "REMOVED"}, 0, 1),
        ({"junit": OSError()}, {"workflow_log": "REMOVED", "junit": "FAILED"}, 0, 1),
        ({"workflow_log": OSError(), "junit": OSError()}, {"workflow_log": "FAILED", "junit": "FAILED"}, 0, 1),
        ({"workflow_log": FileNotFoundError()}, {"workflow_log": "ALREADY_ABSENT", "junit": "REMOVED"}, 0, 0),
        ({"junit": FileNotFoundError()}, {"workflow_log": "REMOVED", "junit": "ALREADY_ABSENT"}, 0, 0),
        ({"junit": OSError()}, {"workflow_log": "REMOVED", "junit": "FAILED"}, 2, 2),
    )
    for failures, expected, test_exit_code, expected_exit_code in scenarios:
        attempted: list[str] = []

        def synthetic_unlink(path) -> None:
            attempted.append(str(path))
            error = failures.get(str(path))
            if error is not None:
                raise error

        actual = cleanup_files((("workflow_log", "workflow_log"), ("junit", "junit")), synthetic_unlink)
        if attempted != ["workflow_log", "junit"] or actual != expected:
            return 1
        if cleanup_record(actual) != (
            f"{PREFIX}|status=CLEANUP|workflow_log={expected['workflow_log']}|junit={expected['junit']}"
        ):
            return 1
        if exit_code_after_cleanup(test_exit_code, actual) != expected_exit_code:
            return 1
    return len(scenarios)


def _safe_class(raw: str | None) -> str:
    if not raw or len(raw) > 180:
        return "UNKNOWN"
    return EXCEPTION_CLASSES.get(raw, "UNKNOWN")


def _single_code(pattern: re.Pattern[str], value: str) -> str:
    found = list(dict.fromkeys(pattern.findall(value)))
    return found[0].upper() if len(found) == 1 else "UNKNOWN"


def _vendor_code(value: str) -> str:
    found = list(dict.fromkeys(VENDOR_CODE_RE.findall(value)))
    if len(found) != 1:
        return "UNKNOWN"
    number = int(found[0])
    return str(number) if 1 <= number <= 65535 else "UNKNOWN"


def _phase(trace: str, method: str) -> str:
    if PHASE_SETUP_RE.search(trace):
        return "SETUP"
    if PHASE_TEARDOWN_RE.search(trace):
        return "TEARDOWN"
    if re.search(r"TransitionLeasePersistenceTest(?:->|::)" + re.escape(method) + r"\b", trace):
        return "TEST_CASE"
    return "UNKNOWN"


def _failure_record(case: ET.Element, event: ET.Element, driver: str) -> str | None:
    class_name = case.get("class") or case.get("classname") or ""
    if class_name not in {TARGET_CLASS, TARGET_CLASS_DOTTED}:
        return None

    raw_name = case.get("name") or ""
    method_match = METHOD_RE.match(raw_name)
    if method_match is None:
        return None
    method = method_match.group(1)

    exception = _safe_class(event.get("type"))
    message = event.get("message") or ""
    # The parser only extracts fixed-format codes; message and trace content
    # are never included in emitted output.
    sqlstate = _single_code(SQLSTATE_RE, message)
    vendor_code = _vendor_code(message) if sqlstate != "UNKNOWN" else "UNKNOWN"
    trace = event.text or ""
    phase = _phase(trace, method)

    if exception in {"PDOException", "QueryException"} or sqlstate != "UNKNOWN":
        category = "DB_EXCEPTION"
        assertion = "NOT_ASSERTION"
    elif event.tag == "failure" or exception in {"ExpectationFailedException", "AssertionFailedError"}:
        category = "ASSERTION_FAILURE"
        assertion = "ASSERTION_FAILURE"
    elif exception == "UNKNOWN":
        category = "UNKNOWN"
        assertion = "UNKNOWN"
    else:
        category = "TEST_EXCEPTION"
        assertion = "NOT_ASSERTION"

    return (
        f"{PREFIX}|status=FAIL|class={TARGET_CLASS_DOTTED}|method={method}"
        f"|exception={exception}|sqlstate={sqlstate}|vendor_code={vendor_code}"
        f"|category={category}|phase={phase}|driver={driver}|assertion={assertion}"
    )


def project(path: Path, driver: str, exit_code: int) -> list[str]:
    if driver != "mysql":
        return [f"{PREFIX}|status=UNKNOWN|reason=DRIVER_NOT_ALLOWLISTED"]
    try:
        info = path.stat()
        if not stat.S_ISREG(info.st_mode):
            return [f"{PREFIX}|status=UNKNOWN|reason=JUNIT_UNAVAILABLE"]
        if info.st_size > MAX_XML_BYTES:
            return [f"{PREFIX}|status=UNKNOWN|reason=JUNIT_SIZE_LIMIT"]
        with path.open("rb") as junit_file:
            raw = junit_file.read(MAX_XML_BYTES + 1)
    except FileNotFoundError:
        return [f"{PREFIX}|status=UNKNOWN|reason=JUNIT_MISSING"]
    except OSError:
        return [f"{PREFIX}|status=UNKNOWN|reason=JUNIT_UNAVAILABLE"]
    if len(raw) > MAX_XML_BYTES:
        return [f"{PREFIX}|status=UNKNOWN|reason=JUNIT_SIZE_LIMIT"]
    if not raw.strip():
        return [f"{PREFIX}|status=UNKNOWN|reason=JUNIT_EMPTY"]
    xml_prefix = raw[3:] if raw.startswith(b"\xef\xbb\xbf") else raw
    encoding_match = XML_ENCODING_RE.search(xml_prefix[:512])
    if encoding_match is not None:
        try:
            declared_encoding = encoding_match.group(1).decode("ascii").lower().replace("_", "-")
        except UnicodeDecodeError:
            return [f"{PREFIX}|status=UNKNOWN|reason=JUNIT_ENCODING_INVALID"]
        if declared_encoding not in {"utf-8", "utf8"}:
            return [f"{PREFIX}|status=UNKNOWN|reason=JUNIT_ENCODING_INVALID"]
    try:
        raw.decode("utf-8-sig", errors="strict")
    except UnicodeDecodeError:
        return [f"{PREFIX}|status=UNKNOWN|reason=JUNIT_ENCODING_INVALID"]
    upper = raw.upper()
    if b"<!DOCTYPE" in upper or b"<!ENTITY" in upper:
        return [f"{PREFIX}|status=UNKNOWN|reason=XML_DECLARATION_REJECTED"]
    try:
        root = ET.fromstring(raw)
    except ET.ParseError as exc:
        parse_reason = str(exc).lower()
        code = "JUNIT_INCOMPLETE" if any(reason in parse_reason for reason in INCOMPLETE_XML_REASONS) else "JUNIT_MALFORMED"
        return [f"{PREFIX}|status=UNKNOWN|reason={code}"]
    except ValueError:
        return [f"{PREFIX}|status=UNKNOWN|reason=JUNIT_MALFORMED"]

    if root.tag not in {"testsuites", "testsuite"}:
        return [f"{PREFIX}|status=UNKNOWN|reason=JUNIT_SCHEMA_INCOMPATIBLE"]

    records: list[str] = []
    matching_cases = 0
    for case in root.iter("testcase"):
        class_name = case.get("class") or case.get("classname") or ""
        if class_name not in {TARGET_CLASS, TARGET_CLASS_DOTTED}:
            continue
        matching_cases += 1
        for event in case:
            if event.tag not in {"failure", "error"}:
                continue
            record = _failure_record(case, event, driver)
            if record is None:
                return [f"{PREFIX}|status=UNKNOWN|reason=TARGET_RECORD_UNCLASSIFIED"]
            records.append(record)
            if len(records) > MAX_FAILURES:
                return [f"{PREFIX}|status=UNKNOWN|reason=RECORD_LIMIT"]

    if records:
        if exit_code == 0:
            return [f"{PREFIX}|status=UNKNOWN|reason=EXIT_STATUS_CONFLICT"]
        return records
    if exit_code != 0 and matching_cases == 0:
        return [f"{PREFIX}|status=UNKNOWN|reason=TARGET_FAILURE_NOT_IN_REPORT"]
    if exit_code != 0 and matching_cases > 0:
        return [f"{PREFIX}|status=UNKNOWN|reason=FAILURE_RECORD_MISSING"]
    if matching_cases == 0:
        return [f"{PREFIX}|status=UNKNOWN|reason=TARGET_NOT_REPORTED"]
    return [f"{PREFIX}|status=PASS|class={TARGET_CLASS_DOTTED}|driver=mysql"]


def main() -> int:
    parser = argparse.ArgumentParser(add_help=False)
    parser.add_argument("--junit")
    parser.add_argument("--driver")
    parser.add_argument("--exit-code", type=int)
    parser.add_argument("--cleanup-log")
    parser.add_argument("--self-test-cleanup", action="store_true")
    try:
        args = parser.parse_args()
        if args.self_test_cleanup:
            if any(value is not None for value in (args.junit, args.driver, args.exit_code, args.cleanup_log)):
                print(f"{PREFIX}|status=UNKNOWN|reason=ARGUMENTS_INVALID")
                return 1
            result = self_test_cleanup()
            if result != 7:
                print(f"{PREFIX}|status=UNKNOWN|reason=CLEANUP_SELF_TEST_FAILED")
                return 1
            print(f"{PREFIX}|status=SELFTEST|cleanup_cases={result}")
            return 0
        if args.junit is None or args.driver is None or args.exit_code is None:
            print(f"{PREFIX}|status=UNKNOWN|reason=ARGUMENTS_INVALID")
            return 1
        try:
            lines = project(Path(args.junit), args.driver, args.exit_code)
        except Exception:
            lines = [f"{PREFIX}|status=UNKNOWN|reason=PROJECTION_FAILED"]
        if args.cleanup_log is not None:
            outcomes = cleanup_files((
                ("workflow_log", args.cleanup_log),
                ("junit", args.junit),
            ))
            lines.append(cleanup_record(outcomes))
    except SystemExit:
        print(f"{PREFIX}|status=UNKNOWN|reason=ARGUMENTS_INVALID")
        return 1
    except Exception:
        print(f"{PREFIX}|status=UNKNOWN|reason=PROJECTION_FAILED")
        return 1
    for line in lines:
        print(line)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
