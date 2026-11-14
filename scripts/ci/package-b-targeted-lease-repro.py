#!/usr/bin/env python3
"""Run one exact Package B PHPUnit test and emit only fixed diagnostics."""

from __future__ import annotations

import argparse
import os
import re
import subprocess
import tempfile
from pathlib import Path
from typing import Callable


TEST_ID = (
    "Tests\\Feature\\Deployment\\Phase2B\\TransitionLeasePersistenceTest::"
    "test_create_claim_exact_replay_and_receipt_readback_are_durable_and_bound"
)
TEST_FILE = "tests/Feature/Deployment/Phase2B/TransitionLeasePersistenceTest.php"
TEST_METHOD = TEST_ID.rsplit("::", 1)[1]
MAX_CAPTURE_BYTES = 2_000_000
MAX_SELECTION_BYTES = 1_000_000
ALLOWED_EXCEPTIONS = {
    "QueryException", "PDOException", "ExpectationFailedException",
    "AssertionFailedError", "PHPUnitError", "RuntimeException",
    "InvalidArgumentException", "LogicException", "ErrorException",
    "TypeError", "ValueError", "Error", "Exception",
}
EXCEPTION_RE = re.compile(
    r"(?m)^\s*(?:\d+\)\s*)?(?:[A-Za-z_][A-Za-z0-9_]*\\)*"
    r"(" + "|".join(sorted(ALLOWED_EXCEPTIONS, key=len, reverse=True)) + r"):"
)
SQLSTATE_RE = re.compile(r"SQLSTATE\[([A-Z0-9]{5})\]")
VENDOR_RE = re.compile(r"SQLSTATE\[[A-Z0-9]{5}\]:[^\r\n:]{0,120}:\s*(\d{1,5})(?:\s|$)")
FAILURE_RE = re.compile(r"(?im)^There (?:was|were) \d+ failures?:\s*$|^FAILURES!\s*$")
ERROR_RE = re.compile(r"(?im)^There (?:was|were) \d+ errors?:\s*$|^ERRORS!\s*$")
ASSERTION_RE = re.compile(r"(?i)Failed asserting|AssertionFailedError|ExpectationFailedException")
SUCCESS_RE = re.compile(r"(?im)^OK\s*\(\s*1 test(?:,|\))")


def parse_selection(data: bytes) -> tuple[int, bool]:
    """Validate that PHPUnit listed exactly one test, and it is the target."""
    if len(data) > MAX_SELECTION_BYTES:
        return 0, False
    try:
        text = data.decode("utf-8", errors="strict")
    except UnicodeDecodeError:
        return 0, False
    listed = [match.group(1).strip() for line in text.splitlines()
              if (match := re.match(r"^\s*-\s+(.+?)\s*$", line))]
    target_count = sum(name == TEST_ID for name in listed)
    return target_count, len(listed) == 1 and listed[0] == TEST_ID


def project(raw: bytes, php_exit: int | None, selection_valid: bool) -> dict[str, str]:
    """Project captured output onto a bounded allowlist; never return source text."""
    result, exception, sqlstate, vendor, assertion = "INCONCLUSIVE", "NONE", "NONE", "NONE", "UNKNOWN"
    if selection_valid and len(raw) <= MAX_CAPTURE_BYTES:
        text = raw.decode("utf-8", errors="replace")
        failed = FAILURE_RE.search(text) is not None
        errored = ERROR_RE.search(text) is not None
        if php_exit == 0 and SUCCESS_RE.search(text):
            result, assertion = "PASS", "NOT_ASSERTION"
        elif php_exit not in (None, 0) and failed:
            result = "FAIL"
            assertion = "ASSERTION_FAILURE" if ASSERTION_RE.search(text) else "UNKNOWN"
        elif php_exit not in (None, 0) and errored:
            result = "ERROR"
            assertion = "DB_EXCEPTION" if re.search(r"(?i)QueryException|PDOException|SQLSTATE\[", text) else "TEST_EXCEPTION"

        match = EXCEPTION_RE.search(text)
        if match:
            exception = match.group(1)
        match = SQLSTATE_RE.search(text)
        if match:
            sqlstate = match.group(1)
        match = VENDOR_RE.search(text)
        if match:
            number = int(match.group(1))
            if 0 <= number <= 65535:
                vendor = str(number)

    return {
        "phpunit_exit": "UNKNOWN" if php_exit is None else str(php_exit),
        "result": result,
        "exception": exception,
        "sqlstate": sqlstate,
        "vendor_errno": vendor,
        "assertion": assertion,
    }


def unlink_all(
    paths: tuple[tuple[str, Path], ...],
    unlinker: Callable[[Path], None] = Path.unlink,
) -> dict[str, str]:
    outcomes: dict[str, str] = {}
    for label, path in paths:
        try:
            unlinker(path)
            outcomes[label] = "REMOVED"
        except FileNotFoundError:
            outcomes[label] = "ALREADY_ABSENT"
        except OSError:
            outcomes[label] = "FAILED"
    return outcomes


def self_test() -> tuple[bool, int]:
    checks = 0
    valid = True

    def check(condition: bool) -> None:
        nonlocal checks, valid
        checks += 1
        valid = valid and condition

    listed = ("- " + TEST_ID + "\n").encode()
    check(parse_selection(listed) == (1, True))
    check(parse_selection(b"") == (0, False))
    check(parse_selection(("- " + TEST_ID + "\n- " + TEST_ID + "\n").encode()) == (2, False))
    check(parse_selection(("- " + TEST_ID + "\n- OtherTest::test_x\n").encode()) == (1, False))
    check(parse_selection(b"\xff") == (0, False))
    check(parse_selection(b"x" * (MAX_SELECTION_BYTES + 1)) == (0, False))

    check(project(b"OK (1 test, 5 assertions)\n", 0, True)["result"] == "PASS")
    failure = b"There was 1 failure:\n1) " + TEST_ID.encode() + b"\nFailed asserting secret-value SELECT * FROM private_table\n"
    fail_projection = project(failure, 1, True)
    check(fail_projection["result"] == "FAIL" and fail_projection["assertion"] == "ASSERTION_FAILURE")
    error = (
        b"There was 1 error:\n1) " + TEST_ID.encode()
        + b"\nIlluminate\\Database\\QueryException: SQLSTATE[23000]: Integrity constraint violation: 1062 secret-value\n"
    )
    error_projection = project(error, 2, True)
    check(error_projection == {
        "phpunit_exit": "2", "result": "ERROR", "exception": "QueryException",
        "sqlstate": "23000", "vendor_errno": "1062", "assertion": "DB_EXCEPTION",
    })
    check(project(b"private payload", 1, True)["result"] == "INCONCLUSIVE")
    check(project(failure, 1, False)["result"] == "INCONCLUSIVE")
    check(project(b"x" * (MAX_CAPTURE_BYTES + 1), 1, True)["result"] == "INCONCLUSIVE")
    rendered = "|".join(str(value) for value in (*fail_projection.values(), *error_projection.values()))
    check(all(secret not in rendered for secret in ("secret-value", "private_table", "SELECT", "payload")))

    scenarios = (
        ("both_removed", {"a": "REMOVED", "b": "REMOVED"}, set()),
        ("first_failed", {"a": "FAILED", "b": "REMOVED"}, {"a"}),
        ("second_failed", {"a": "REMOVED", "b": "FAILED"}, {"b"}),
        ("both_failed", {"a": "FAILED", "b": "FAILED"}, {"a", "b"}),
        ("first_absent", {"a": "ALREADY_ABSENT", "b": "REMOVED"}, {"a"}),
        ("second_absent", {"a": "REMOVED", "b": "ALREADY_ABSENT"}, {"b"}),
    )
    with tempfile.TemporaryDirectory(prefix="wpcg-pb-targeted-selftest-") as temporary:
        root = Path(temporary)
        for name, expected, failures in scenarios:
            left, right = root / (name + "-a"), root / (name + "-b")
            left.write_text("synthetic", encoding="ascii")
            right.write_text("synthetic", encoding="ascii")
            attempts: list[str] = []

            def fake_unlink(path: Path) -> None:
                attempts.append(path.name)
                label = "a" if path == left else "b"
                if label in failures:
                    if (name == "first_absent" and label == "a") or (name == "second_absent" and label == "b"):
                        raise FileNotFoundError
                    raise PermissionError
                path.unlink()

            outcome = unlink_all((("a", left), ("b", right)), fake_unlink)
            check(outcome == expected and attempts == [left.name, right.name])
            # A cleanup failure is projected independently and never rewrites PHPUnit's failure.
            original = project(failure, 1, True)
            check(original["result"] == "FAIL" and ("FAILED" in outcome.values()) == (name in {"first_failed", "second_failed", "both_failed"}))

    return valid, checks


def run_reproduction(phpunit: Path, php_version: str) -> int:
    root = Path.cwd()
    runner_temp = Path(os.environ.get("RUNNER_TEMP", tempfile.gettempdir()))
    private_dir: Path | None = None
    selection_path: Path | None = None
    capture_path: Path | None = None
    selection_count = 0
    selection_valid = False
    phpunit_exit: int | None = None
    test_invoked = False
    report = {"phpunit_exit": "NOT_RUN", "result": "INCONCLUSIVE", "exception": "NONE",
              "sqlstate": "NONE", "vendor_errno": "NONE", "assertion": "UNKNOWN"}
    cleanup = {"selection": "ALREADY_ABSENT", "capture": "ALREADY_ABSENT", "directory": "ALREADY_ABSENT"}

    try:
        private_dir = Path(tempfile.mkdtemp(prefix="wpcg-pb-targeted-", dir=runner_temp))
        private_dir.chmod(0o700)
        selection_path = private_dir / "selection.txt"
        capture_path = private_dir / "phpunit-output.raw"
        list_command = [str(phpunit), "-c", "phpunit.xml", "--list-tests", "--filter", TEST_METHOD, TEST_FILE]
        with selection_path.open("xb") as stream:
            os.chmod(selection_path, 0o600)
            try:
                selected = subprocess.run(list_command, cwd=root, stdout=stream, stderr=subprocess.DEVNULL,
                                          check=False, timeout=60, env=os.environ.copy())
            except subprocess.TimeoutExpired:
                selected = None
            stream.flush()

        if selected is not None and selected.returncode == 0:
            size = selection_path.stat().st_size
            selection_count, selection_valid = parse_selection(selection_path.read_bytes()) if size <= MAX_SELECTION_BYTES else (0, False)

        if selected is not None and selected.returncode == 0 and selection_valid and selection_count == 1:
            command = [str(phpunit), "-c", "phpunit.xml", "--debug", "--fail-on-skipped",
                       "--filter", TEST_METHOD, TEST_FILE]
            with capture_path.open("xb") as stream:
                os.chmod(capture_path, 0o600)
                test_invoked = True
                try:
                    process = subprocess.run(command, cwd=root, stdout=stream, stderr=subprocess.STDOUT,
                                             check=False, timeout=300, env=os.environ.copy())
                    phpunit_exit = process.returncode
                except subprocess.TimeoutExpired:
                    phpunit_exit = None
                stream.flush()
            capture_size = capture_path.stat().st_size
            if capture_size <= MAX_CAPTURE_BYTES:
                report = project(capture_path.read_bytes(), phpunit_exit, selection_valid)
            else:
                report = project(b"", phpunit_exit, False)
        else:
            report = {"phpunit_exit": "NOT_RUN", "result": "INCONCLUSIVE", "exception": "NONE",
                      "sqlstate": "NONE", "vendor_errno": "NONE", "assertion": "UNKNOWN"}
    except (OSError, subprocess.SubprocessError, ValueError):
        report = {"phpunit_exit": "UNKNOWN" if test_invoked else "NOT_RUN", "result": "INCONCLUSIVE",
                  "exception": "NONE", "sqlstate": "NONE", "vendor_errno": "NONE", "assertion": "UNKNOWN"}
    finally:
        if private_dir is not None:
            owned = tuple((label, path) for label, path in (("selection", selection_path), ("capture", capture_path)) if path is not None)
            cleanup.update(unlink_all(owned))
            try:
                private_dir.rmdir()
                cleanup["directory"] = "REMOVED"
            except FileNotFoundError:
                cleanup["directory"] = "ALREADY_ABSENT"
            except OSError:
                cleanup["directory"] = "FAILED"

    cleanup_result = "CLEANUP_FAILED" if "FAILED" in cleanup.values() else "CLEANUP_COMPLETE"
    selection_field = str(selection_count) if selection_valid else "UNKNOWN"
    print(
        "PACKAGE_B_TARGETED_DIAG|php={}|test={}|selection_count={}|phpunit_exit={}|result={}|exception={}|sqlstate={}|vendor_errno={}|assertion={}|cleanup={}|cleanup_selection={}|cleanup_capture={}|cleanup_directory={}".format(
            php_version, TEST_ID, selection_field, report["phpunit_exit"], report["result"], report["exception"],
            report["sqlstate"], report["vendor_errno"], report["assertion"], cleanup_result,
            cleanup["selection"], cleanup["capture"], cleanup["directory"],
        ),
        flush=True,
    )

    if cleanup_result == "CLEANUP_FAILED":
        return 1 if phpunit_exit in (None, 0) else phpunit_exit
    if not selection_valid or selection_count != 1 or phpunit_exit is None:
        return 1
    return phpunit_exit


def main() -> int:
    parser = argparse.ArgumentParser()
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--self-test", action="store_true")
    mode.add_argument("--run", action="store_true")
    parser.add_argument("--php-version", choices=("8.4", "8.5"))
    args = parser.parse_args()
    if args.self_test:
        passed, count = self_test()
        if not passed:
            print("PACKAGE_B_TARGETED_DIAG|status=SYNTHETIC_VALIDATION_FAILED")
            return 1
        print(f"PACKAGE_B_TARGETED_DIAG|status=SYNTHETIC_VALIDATION_PASS|cases={count}")
        return 0
    phpunit = Path("vendor/bin/phpunit")
    if args.php_version is None or not phpunit.is_file():
        print("PACKAGE_B_TARGETED_DIAG|status=PRECONDITION_FAILED")
        return 1
    return run_reproduction(phpunit, args.php_version)


if __name__ == "__main__":
    raise SystemExit(main())
