#!/usr/bin/env python3
"""Emit a bounded, allowlisted projection of Package B PHPUnit JUnit failures."""

from __future__ import annotations

import argparse
import re
import stat
import sys
import tempfile
import xml.parsers.expat as expat
import xml.etree.ElementTree as ET
from pathlib import Path


PREFIX = "WPCG_PB_DIAG_V1"
TARGET_CLASS = "Tests\\Feature\\Deployment\\Phase2B\\TransitionLeasePersistenceTest"
TARGET_CLASS_DOTTED = TARGET_CLASS.replace("\\", ".")
MAX_XML_BYTES = 2 * 1024 * 1024
MAX_FAILURES = 32
CLEANUP_STATES = {"REMOVED", "ALREADY_ABSENT", "FAILED"}
XML_ENCODING_RE = re.compile(rb"<\?xml\b[^>]*\bencoding\s*=\s*['\"]([^'\"]+)['\"]", re.I)
MAX_REPORTED_XML_BYTES = MAX_XML_BYTES + 1
JUNIT_CLASSIFICATIONS = {
    "JUNIT_VALID", "JUNIT_MISSING", "JUNIT_EMPTY", "JUNIT_INCOMPLETE",
    "JUNIT_INVALID_XML_SYNTAX", "JUNIT_INVALID_ENCODING", "JUNIT_UNEXPECTED_CONTENT",
    "JUNIT_UNSUPPORTED_STRUCTURE", "JUNIT_PARSER_OTHER", "JUNIT_UNAVAILABLE", "JUNIT_SIZE_LIMIT",
    "JUNIT_NOT_EVALUATED",
}
FILE_STATES = {"PRESENT", "MISSING", "UNAVAILABLE", "UNEVALUATED"}
INCOMPLETE_XML_CODES = {
    expat.errors.codes.get("no element found"),
    expat.errors.codes.get("unclosed token"),
    expat.errors.codes.get("unclosed CDATA section"),
    expat.errors.codes.get("partial character"),
}
INCOMPLETE_XML_CODES.discard(None)
UNEXPECTED_CONTENT_CODES = {expat.errors.codes.get("junk after document element")}
UNEXPECTED_CONTENT_CODES.discard(None)

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
    status = "CLEANUP_FAILED" if "FAILED" in outcomes.values() else "CLEANUP_COMPLETE"
    return (
        f"{PREFIX}|status={status}|workflow_log={outcomes['workflow_log']}"
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
        record = cleanup_record(actual)
        expected_status = "CLEANUP_FAILED" if "FAILED" in expected.values() else "CLEANUP_COMPLETE"
        if record != f"{PREFIX}|status={expected_status}|workflow_log={expected['workflow_log']}|junit={expected['junit']}":
            return 1
        if exit_code_after_cleanup(test_exit_code, actual) != expected_exit_code:
            return 1
    return len(scenarios)


def _metadata_line(exit_code: int, file_state: str, size_bytes: int | None, classification: str) -> str:
    if not -255 <= exit_code <= 255 or file_state not in FILE_STATES or classification not in JUNIT_CLASSIFICATIONS:
        raise ValueError("Invalid bounded diagnostic metadata.")
    if size_bytes is None:
        size = "UNKNOWN"
    elif not 0 <= size_bytes <= MAX_REPORTED_XML_BYTES:
        raise ValueError("Invalid bounded diagnostic size.")
    else:
        size = str(size_bytes)
    return (
        f"{PREFIX}|status=JUNIT|phpunit_exit={exit_code}|file_state={file_state}"
        f"|size_bytes={size}|classification={classification}"
    )


def _classify_parse_error(error: ET.ParseError) -> str:
    code = getattr(error, "code", None)
    if code is not None and code in INCOMPLETE_XML_CODES:
        return "JUNIT_INCOMPLETE"
    if code is not None and code in UNEXPECTED_CONTENT_CODES:
        return "JUNIT_UNEXPECTED_CONTENT"
    if isinstance(code, int):
        return "JUNIT_INVALID_XML_SYNTAX"
    return "JUNIT_PARSER_OTHER"


def _load_junit(path: Path) -> tuple[str, int | None, ET.Element | None]:
    try:
        info = path.stat()
    except FileNotFoundError:
        return "JUNIT_MISSING", None, None
    except OSError:
        return "JUNIT_UNAVAILABLE", None, None
    if not stat.S_ISREG(info.st_mode):
        return "JUNIT_UNAVAILABLE", None, None
    size_bytes = min(info.st_size, MAX_REPORTED_XML_BYTES)
    if info.st_size > MAX_XML_BYTES:
        return "JUNIT_SIZE_LIMIT", size_bytes, None
    try:
        with path.open("rb") as junit_file:
            raw = junit_file.read(MAX_REPORTED_XML_BYTES)
    except OSError:
        return "JUNIT_UNAVAILABLE", size_bytes, None
    if len(raw) > MAX_XML_BYTES:
        return "JUNIT_SIZE_LIMIT", MAX_REPORTED_XML_BYTES, None
    if not raw.strip():
        return "JUNIT_EMPTY", len(raw), None
    xml_prefix = raw[3:] if raw.startswith(b"\xef\xbb\xbf") else raw
    encoding_match = XML_ENCODING_RE.search(xml_prefix[:512])
    if encoding_match is not None:
        try:
            declared_encoding = encoding_match.group(1).decode("ascii").lower().replace("_", "-")
        except UnicodeDecodeError:
            return "JUNIT_INVALID_ENCODING", len(raw), None
        if declared_encoding not in {"utf-8", "utf8"}:
            return "JUNIT_INVALID_ENCODING", len(raw), None
    try:
        raw.decode("utf-8-sig", errors="strict")
    except UnicodeDecodeError:
        return "JUNIT_INVALID_ENCODING", len(raw), None
    if b"<!DOCTYPE" in raw.upper() or b"<!ENTITY" in raw.upper():
        return "JUNIT_UNEXPECTED_CONTENT", len(raw), None
    try:
        root = ET.fromstring(raw)
    except ET.ParseError as error:
        return _classify_parse_error(error), len(raw), None
    except (ValueError, LookupError):
        return "JUNIT_INVALID_ENCODING", len(raw), None
    except Exception:
        return "JUNIT_PARSER_OTHER", len(raw), None
    if root.tag not in {"testsuites", "testsuite"}:
        return "JUNIT_UNSUPPORTED_STRUCTURE", len(raw), None
    return "JUNIT_VALID", len(raw), root


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


def _project_classified(classification: str, root: ET.Element | None, driver: str, exit_code: int) -> list[str]:
    if classification != "JUNIT_VALID" or root is None:
        return [f"{PREFIX}|status=UNKNOWN|reason={classification}"]

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


def safe_project(path: Path, driver: str, exit_code: int) -> tuple[list[str], str]:
    if driver != "mysql":
        return [f"{PREFIX}|status=UNKNOWN|reason=DRIVER_NOT_ALLOWLISTED"], "VALID"
    if not -255 <= exit_code <= 255:
        return [f"{PREFIX}|status=UNKNOWN|reason=ARGUMENTS_INVALID"], "VALID"

    try:
        classification, size_bytes, root = _load_junit(path)
    except Exception:
        metadata = _metadata_line(exit_code, "UNEVALUATED", None, "JUNIT_NOT_EVALUATED")
        return [metadata, f"{PREFIX}|status=UNKNOWN|reason=PROJECTION_FAILED"], "INTERNAL_ERROR"

    file_state = "MISSING" if classification == "JUNIT_MISSING" else (
        "UNAVAILABLE" if classification == "JUNIT_UNAVAILABLE" else "PRESENT"
    )
    metadata = _metadata_line(exit_code, file_state, size_bytes, classification)
    try:
        body = _project_classified(classification, root, driver, exit_code)
    except Exception:
        return [metadata, f"{PREFIX}|status=UNKNOWN|reason=PROJECTION_FAILED"], "INTERNAL_ERROR"
    return [metadata, *body], "VALID"


def project(path: Path, driver: str, exit_code: int) -> list[str]:
    return safe_project(path, driver, exit_code)[0]


def self_test_diagnostics() -> int:
    valid = (
        f'<testsuites><testsuite><testcase classname="{TARGET_CLASS_DOTTED}" '
        'name="test_safe_failure"><error type="RuntimeException">private payload</error>'
        '</testcase></testsuite></testsuites>'
    ).encode("utf-8")
    valid_db = (
        f'<testsuites><testsuite><testcase classname="{TARGET_CLASS_DOTTED}" '
        'name="test_safe_database_failure"><error type="PDOException" '
        'message="SQLSTATE[40001]: Serialization failure: 1213 private synthetic detail"/>'
        '</testcase></testsuite></testsuites>'
    ).encode("utf-8")
    fixtures = (
        ("JUNIT_EMPTY", b""),
        ("JUNIT_INVALID_XML_SYNTAX", b"<testsuites><broken></testsuites>"),
        ("JUNIT_INCOMPLETE", b"<testsuites><testsuite>"),
        ("JUNIT_INVALID_ENCODING", b"<?xml version='1.0' encoding='utf-16'?><testsuites/>"),
        ("JUNIT_INVALID_ENCODING", b"<testsuites>\xff</testsuites>"),
        ("JUNIT_UNEXPECTED_CONTENT", b"<testsuites/><extra/>"),
        ("JUNIT_UNSUPPORTED_STRUCTURE", b"<not-a-junit/>"),
        ("JUNIT_VALID", valid),
        ("JUNIT_VALID", valid_db),
    )
    cases = 0
    with tempfile.TemporaryDirectory() as directory:
        for expected, payload in fixtures:
            path = Path(directory) / f"fixture-{cases}.xml"
            path.write_bytes(payload)
            lines = project(path, "mysql", 1)
            metadata = next((line for line in lines if "|status=JUNIT|" in line), "")
            if f"|classification={expected}" not in metadata:
                return 1
            if any(
                marker in line for marker in ("private payload", "private synthetic detail", "synthetic detail")
                for line in lines
            ):
                return 1
            if expected != "JUNIT_VALID" and not any(
                line == f"{PREFIX}|status=UNKNOWN|reason={expected}" for line in lines
            ):
                return 1
            if expected == "JUNIT_VALID" and not any("|status=FAIL|" in line for line in lines):
                return 1
            if expected == "JUNIT_VALID" and not any(
                "|method=test_safe_" in line and "|exception=" in line for line in lines
            ):
                return 1
            if b"safe_database_failure" in payload:
                records = [line for line in lines if "|status=FAIL|" in line]
                if not any("|exception=PDOException|sqlstate=40001|vendor_code=1213|" in line for line in records):
                    return 1
            cases += 1
        missing = project(Path(directory) / "absent.xml", "mysql", 1)
        if "|file_state=MISSING|size_bytes=UNKNOWN|classification=JUNIT_MISSING" not in missing[0]:
            return 1
        cases += 1
        if project(Path(directory) / "missing-driver.xml", "sqlite", 1) != [
            f"{PREFIX}|status=UNKNOWN|reason=DRIVER_NOT_ALLOWLISTED"
        ]:
            return 1
        cases += 1
        if _metadata_line(1, "PRESENT", MAX_REPORTED_XML_BYTES, "JUNIT_SIZE_LIMIT").find(
            f"size_bytes={MAX_REPORTED_XML_BYTES}"
        ) < 0:
            return 1
        cases += 1
        oversized = Path(directory) / "oversized.xml"
        oversized.write_bytes(b"x" * (MAX_XML_BYTES + 1))
        if "|classification=JUNIT_SIZE_LIMIT" not in project(oversized, "mysql", 1)[0]:
            return 1
        cases += 1
        original_fromstring = ET.fromstring
        try:
            ET.fromstring = lambda _raw: (_ for _ in ()).throw(RuntimeError("private synthetic parser failure"))
            parser_other, parser_other_size, parser_other_root = _load_junit(Path(directory) / "fixture-7.xml")
            if (parser_other, parser_other_root) != ("JUNIT_PARSER_OTHER", None) or parser_other_size is None:
                return 1
        finally:
            ET.fromstring = original_fromstring
        cases += 1
        original_projector = globals()["_project_classified"]
        try:
            globals()["_project_classified"] = lambda *_args: (_ for _ in ()).throw(RuntimeError("private synthetic projection failure"))
            for expected, payload in (
                ("JUNIT_VALID", valid),
                ("JUNIT_INVALID_XML_SYNTAX", b"<testsuites><broken></testsuites>"),
            ):
                path = Path(directory) / f"projection-{expected}.xml"
                path.write_bytes(payload)
                failed_projection, projection_state = safe_project(path, "mysql", 1)
                if (
                    projection_state != "INTERNAL_ERROR"
                    or f"|classification={expected}" not in failed_projection[0]
                    or failed_projection[1] != f"{PREFIX}|status=UNKNOWN|reason=PROJECTION_FAILED"
                    or any("private synthetic projection failure" in line for line in failed_projection)
                ):
                    return 1
                cases += 1
        finally:
            globals()["_project_classified"] = original_projector

        missing = safe_project(Path(directory) / "confirmed-missing.xml", "mysql", 1)[0]
        if (
            "|file_state=MISSING|size_bytes=UNKNOWN|classification=JUNIT_MISSING" not in missing[0]
            or f"{PREFIX}|status=UNKNOWN|reason=JUNIT_MISSING" not in missing
        ):
            return 1
        cases += 1

        original_loader = globals()["_load_junit"]
        try:
            globals()["_load_junit"] = lambda *_args: (_ for _ in ()).throw(RuntimeError("private synthetic read failure"))
            unevaluated, projection_state = safe_project(Path("unreadable-fixture"), "mysql", 1)
            if (
                projection_state != "INTERNAL_ERROR"
                or "|file_state=UNEVALUATED|size_bytes=UNKNOWN|classification=JUNIT_NOT_EVALUATED" not in unevaluated[0]
                or unevaluated[1] != f"{PREFIX}|status=UNKNOWN|reason=PROJECTION_FAILED"
            ):
                return 1
        finally:
            globals()["_load_junit"] = original_loader
        cases += 1

        readable, _ = safe_project(Path(directory) / "fixture-7.xml", "mysql", 1)
        reporter_failure = reporter_execution_record(7)
        if (
            "|classification=JUNIT_VALID" not in readable[0]
            or reporter_failure != f"{PREFIX}|status=REPORTER|execution=COMPLETED|exit_code=7|projection=UNAVAILABLE"
            or any("private payload" in line for line in readable + [reporter_failure])
        ):
            return 1
        cases += 1

        try:
            globals()["_project_classified"] = lambda *_args: (_ for _ in ()).throw(RuntimeError("private synthetic projection failure"))
            projection_failure, _ = safe_project(Path(directory) / "fixture-7.xml", "mysql", 1)
        finally:
            globals()["_project_classified"] = original_projector
        cleanup_failure = cleanup_record({"workflow_log": "FAILED", "junit": "REMOVED"})
        if (
            f"{PREFIX}|status=UNKNOWN|reason=PROJECTION_FAILED" not in projection_failure
            or cleanup_failure != f"{PREFIX}|status=CLEANUP_FAILED|workflow_log=FAILED|junit=REMOVED"
            or "JUNIT_VALID" not in projection_failure[0]
        ):
            return 1
        cases += 1
    # Reporter-execution and projection failures are separate fixed states.
    execution_failure = reporter_execution_record(None)
    projection_failure = reporter_projection_record("REJECTED")
    if execution_failure != f"{PREFIX}|status=REPORTER|execution=UNAVAILABLE|exit_code=UNKNOWN|projection=UNAVAILABLE":
        return 1
    if reporter_execution_record(7) != f"{PREFIX}|status=REPORTER|execution=COMPLETED|exit_code=7|projection=UNAVAILABLE":
        return 1
    if reporter_execution_record(-9) != f"{PREFIX}|status=REPORTER|execution=SIGNALED|signal_code=9|projection=UNAVAILABLE":
        return 1
    if projection_failure != f"{PREFIX}|status=REPORTER|execution=COMPLETED|exit_code=0|projection=REJECTED":
        return 1
    if "private payload" in execution_failure + projection_failure:
        return 1
    cases += 4
    return cases


def reporter_execution_record(exit_code: int | None) -> str:
    if exit_code is None:
        return f"{PREFIX}|status=REPORTER|execution=UNAVAILABLE|exit_code=UNKNOWN|projection=UNAVAILABLE"
    if -64 <= exit_code < 0:
        return f"{PREFIX}|status=REPORTER|execution=SIGNALED|signal_code={-exit_code}|projection=UNAVAILABLE"
    if not 0 <= exit_code <= 255:
        raise ValueError("Invalid reporter exit status.")
    projection = "STRUCTURED" if exit_code == 0 else "UNAVAILABLE"
    return f"{PREFIX}|status=REPORTER|execution=COMPLETED|exit_code={exit_code}|projection={projection}"


def reporter_projection_record(state: str) -> str:
    if state not in {"VALID", "REJECTED", "INTERNAL_ERROR", "UNAVAILABLE"}:
        raise ValueError("Invalid reporter projection state.")
    return f"{PREFIX}|status=REPORTER|execution=COMPLETED|exit_code=0|projection={state}"


def main() -> int:
    parser = argparse.ArgumentParser(add_help=False)
    parser.add_argument("--junit")
    parser.add_argument("--driver")
    parser.add_argument("--exit-code", type=int)
    parser.add_argument("--cleanup-log")
    parser.add_argument("--self-test-cleanup", action="store_true")
    parser.add_argument("--self-test-diagnostics", action="store_true")
    try:
        args = parser.parse_args()
        if args.self_test_diagnostics:
            if any(value is not None for value in (args.junit, args.driver, args.exit_code, args.cleanup_log)) or args.self_test_cleanup:
                print(f"{PREFIX}|status=UNKNOWN|reason=ARGUMENTS_INVALID")
                return 1
            result = self_test_diagnostics()
            if result != 24:
                print(f"{PREFIX}|status=UNKNOWN|reason=DIAGNOSTIC_SELF_TEST_FAILED")
                return 1
            print(f"{PREFIX}|status=SELFTEST|diagnostic_cases={result}")
            return 0
        if args.self_test_cleanup:
            if args.self_test_diagnostics or any(value is not None for value in (args.junit, args.driver, args.exit_code, args.cleanup_log)):
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
        lines, _projection_state = safe_project(Path(args.junit), args.driver, args.exit_code)
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
