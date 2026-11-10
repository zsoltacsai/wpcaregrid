#!/usr/bin/env python3
"""Synthetic privacy and fail-closed tests for the #350 CI diagnostic parser."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from scripts.ci.safe_phpunit_test_diagnostics import (
    DIAGNOSTIC_TESTS,
    PREFIX,
    UNKNOWN,
    diagnostics_from_bytes,
)

FIRST = "dispatch_active_default_pulse"
SECOND = "resolve_legacy_paused_worker"
FAILED = (FIRST, SECOND)


def marker(test_id, exception_class="PDOException", sqlstate="HY000", db_code="1205"):
    return (
        f"{PREFIX}|test={test_id}|step={DIAGNOSTIC_TESTS[test_id]}"
        f"|exception_class={exception_class}|sqlstate={sqlstate}|db_code={db_code}"
    ).encode("ascii")


class SafePhpunitDiagnosticTests(unittest.TestCase):
    def test_valid_markers_emit_only_allowlisted_structured_fields(self):
        result = diagnostics_from_bytes(marker(FIRST) + b"\n", (FIRST,))
        self.assertEqual(
            [f"{PREFIX}|test={FIRST}|step={DIAGNOSTIC_TESTS[FIRST]}|exception_class=PDOException|sqlstate=HY000|db_code=1205"],
            result,
        )
        self.assertNotIn("message", result[0])
        self.assertNotIn("SQL", result[0])
        self.assertNotIn("trace", result[0])

    def test_missing_structured_codes_are_reported_as_unknown(self):
        record = marker(FIRST, exception_class="UNKNOWN", sqlstate="UNKNOWN", db_code="UNKNOWN")
        self.assertEqual(
            [f"{PREFIX}|test={FIRST}|step={DIAGNOSTIC_TESTS[FIRST]}|exception_class=UNKNOWN|sqlstate=UNKNOWN|db_code=UNKNOWN"],
            diagnostics_from_bytes(record, (FIRST,)),
        )

    def test_secret_message_or_sql_appended_to_marker_is_rejected_without_leak(self):
        secret = b"password=synthetic-secret SELECT * FROM private_table"
        result = diagnostics_from_bytes(marker(FIRST) + b"|" + secret, (FIRST,))
        self.assertEqual(1, len(result))
        self.assertIn("exception_class=UNKNOWN", result[0])
        self.assertNotIn(secret.decode("ascii"), result[0])
        self.assertNotIn("private_table", result[0])

    def test_unallowlisted_test_or_step_is_never_emitted(self):
        invalid = b"WPCG_TEST_DIAG_V1|test=other_test|step=other|exception_class=PDOException|sqlstate=HY000|db_code=1205"
        result = diagnostics_from_bytes(invalid, (FIRST,))
        self.assertEqual(1, len(result))
        self.assertEqual("test=" + FIRST, result[0].split("|")[1])

    def test_invalid_class_sqlstate_and_database_code_fail_closed(self):
        invalid_cases = (
            marker(FIRST, exception_class="PDOException secret"),
            marker(FIRST, sqlstate="HY000\nsecret"),
            marker(FIRST, db_code="1205;secret"),
            marker(FIRST, db_code="65536"),
        )
        for invalid in invalid_cases:
            with self.subTest(invalid_field=invalid.split(b"|")[3]):
                result = diagnostics_from_bytes(invalid, (FIRST,))
                self.assertEqual(1, len(result))
                self.assertIn("exception_class=UNKNOWN", result[0])
                self.assertIn("sqlstate=UNKNOWN", result[0])
                self.assertIn("db_code=UNKNOWN", result[0])
                self.assertNotIn(b"secret", result[0].encode("ascii"))

    def test_absent_duplicate_and_oversized_diagnostics_return_unknown(self):
        self.assertEqual(
            [f"{PREFIX}|test={FIRST}|step={DIAGNOSTIC_TESTS[FIRST]}|exception_class=UNKNOWN|sqlstate=UNKNOWN|db_code=UNKNOWN"],
            diagnostics_from_bytes(b"unrelated test output", (FIRST,)),
        )
        self.assertIn("exception_class=UNKNOWN", diagnostics_from_bytes(marker(FIRST) + b"\n" + marker(FIRST), (FIRST,))[0])
        self.assertIn("db_code=UNKNOWN", diagnostics_from_bytes(b"x" * (16 * 1024 * 1024 + 1), (FIRST,))[0])

    def test_requested_failure_order_is_deterministic_and_allowlisted(self):
        log = marker(SECOND) + b"\n" + marker(FIRST) + b"\n"
        result = diagnostics_from_bytes(log, (SECOND, FIRST, "not_allowlisted", SECOND))
        self.assertEqual([SECOND, FIRST], [record.split("|")[1].split("=")[1] for record in result])

    def test_no_failure_means_no_diagnostic(self):
        self.assertEqual([], diagnostics_from_bytes(marker(FIRST), ()))


if __name__ == "__main__":
    unittest.main()
