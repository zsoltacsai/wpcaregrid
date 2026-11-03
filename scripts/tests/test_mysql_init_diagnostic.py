from __future__ import annotations

import json
import os
import sys
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch

import mysql_init_diagnostic as diagnostic_module
from mysql_init_diagnostic import (
    MAX_STREAM_BYTES,
    TEMP_PREFIX,
    CollectionResult,
    ProcessCapture,
    DiagnosticError,
    compile_filters,
    execute_one_minimal_init,
    run_bounded_process,
    sanitize_streams,
    sanitized_json,
)


class MySqlInitDiagnosticTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="wpcg-diag-test-")
        self.root = Path(self.temp.name)
        os.chmod(self.root, 0o700)

    def tearDown(self) -> None:
        self.temp.cleanup()

    def run_fake(self, *, exit_code=0, stdout=b"", stderr=b"", create_data=True, **kwargs):
        seen: dict[str, object] = {}

        def fake_runner(argv, *, cwd, env):
            seen["argv"] = argv
            seen["cwd"] = Path(cwd)
            seen["env"] = env
            work = Path(cwd)
            seen["files_before"] = sorted(p.name for p in work.iterdir())
            if create_data:
                (work / "data").mkdir()
                (work / "data" / "synthetic-db-file").write_bytes(b"synthetic")
            return ProcessCapture(exit_code, stdout, stderr)

        result = execute_one_minimal_init(
            self.root, owner_go=True, process_runner=fake_runner, **kwargs
        )
        return result, seen

    def test_empty_stderr_is_explicit_and_success_is_not_conflated(self):
        result, _ = self.run_fake(stdout=b"synthetic stdout")
        record = json.loads(sanitized_json(result))
        self.assertEqual(record["command_exit_code"], 0)
        self.assertEqual(record["collector_status"], "PASS")
        self.assertEqual(record["diagnostic"]["categories"], ["NO_DIAGNOSTIC_TEXT"])
        self.assertEqual(record["overall_result"], "PASS")

    def test_allowlisted_mysql_code_is_only_value_exposed(self):
        result = sanitize_streams(b"", b"MY-010457: sensitive synthetic text", 1)
        self.assertEqual(result["my_error_codes"], ["MY-010457"])
        encoded = json.dumps(result)
        self.assertNotIn("sensitive", encoded)

    def test_errno_17_is_fixed_category(self):
        result = sanitize_streams(b"", b"operation failed errno=17", 1)
        self.assertEqual(result["os_errnos"], [17])
        self.assertIn("ERRNO_17", result["categories"])

    def test_multiple_messages_are_bounded_and_deduplicated(self):
        result = sanitize_streams(
            b"MY-010457 first\n", b"MY-010457 second errno=17\nMY-010458 errno:13", 1
        )
        self.assertEqual(result["my_error_codes"], ["MY-010457", "MY-010458"])
        self.assertEqual(result["os_errnos"], [13, 17])

    def test_special_characters_and_multiline_text_are_never_returned(self):
        raw = b"private/path/\xff\nMY-010457: secret; token=abc\nerrno=17\n"
        result = sanitize_streams(b"", raw, 1)
        public = json.dumps(result)
        for forbidden in ("private", "secret", "token", "abc"):
            self.assertNotIn(forbidden, public)
        self.assertEqual(result["my_error_codes"], ["MY-010457"])
        self.assertEqual(result["os_errnos"], [17])

    def test_invalid_filter_returns_unavailable_and_stops(self):
        with self.assertRaisesRegex(DiagnosticError, "FILTER_INVALID"):
            compile_filters({"my_code": "["})
        result = execute_one_minimal_init(
            self.root,
            owner_go=True,
            process_runner=lambda *_a, **_k: ProcessCapture(1, b"", b"", read_failed=True),
        )
        record = json.loads(sanitized_json(result))
        self.assertEqual(record["command_exit_code"], 1)
        self.assertEqual(record["diagnostic_status"], "DIAGNOSTIC_UNAVAILABLE")
        self.assertEqual(record["collector_status"], "FAIL")
        self.assertEqual(record["overall_result"], "STOP")
        self.assertIsNone(record["diagnostic"])
        self.assertEqual(list(self.root.iterdir()), [])

    def test_interrupted_runner_cleans_temp_data_and_reports_stop(self):
        def interrupted_runner(*_args, **_kwargs):
            raise KeyboardInterrupt()

        result = execute_one_minimal_init(
            self.root, owner_go=True, process_runner=interrupted_runner
        )
        record = json.loads(sanitized_json(result))
        self.assertEqual(record["diagnostic_status"], "DIAGNOSTIC_UNAVAILABLE")
        self.assertEqual(record["collector_status"], "FAIL")
        self.assertEqual(record["temp_data_status"], "CLEANED")
        self.assertEqual(record["overall_result"], "STOP")
        self.assertEqual(list(self.root.iterdir()), [])

    def test_timed_out_process_capture_is_unavailable_even_with_zero_exit(self):
        result = execute_one_minimal_init(
            self.root,
            owner_go=True,
            process_runner=lambda *_a, **_k: ProcessCapture(124, b"", b"", timed_out=True),
        )
        record = json.loads(sanitized_json(result))
        self.assertEqual(record["command_result"], "FAIL")
        self.assertEqual(record["diagnostic_status"], "DIAGNOSTIC_UNAVAILABLE")
        self.assertEqual(record["overall_result"], "STOP")
        self.assertEqual(list(self.root.iterdir()), [])

    def test_oversized_stream_is_drained_but_not_classified(self):
        result, _ = self.run_fake(exit_code=0, stderr=b"x" * (MAX_STREAM_BYTES + 1))
        record = json.loads(sanitized_json(result))
        self.assertEqual(record["diagnostic_status"], "DIAGNOSTIC_UNAVAILABLE")
        self.assertEqual(record["command_result"], "PASS")
        self.assertEqual(record["overall_result"], "STOP")
        self.assertEqual(list(self.root.iterdir()), [])

    def test_missing_or_invalid_capture_object_is_unavailable(self):
        bad = ProcessCapture(0, "not bytes", b"")  # type: ignore[arg-type]
        result = execute_one_minimal_init(
            self.root, owner_go=True, process_runner=lambda *_a, **_k: bad
        )
        record = json.loads(sanitized_json(result))
        self.assertEqual(record["diagnostic_status"], "DIAGNOSTIC_UNAVAILABLE")
        self.assertEqual(record["overall_result"], "STOP")
        self.assertEqual(list(self.root.iterdir()), [])

    def test_runner_preserves_nonzero_exit_and_cleans_datadir(self):
        result, seen = self.run_fake(exit_code=1, stderr=b"errno=17")
        record = json.loads(sanitized_json(result))
        self.assertEqual(record["command_exit_code"], 1)
        self.assertEqual(record["command_result"], "FAIL")
        self.assertEqual(record["collector_status"], "PASS")
        self.assertEqual(record["overall_result"], "STOP")
        self.assertEqual(record["temp_data_status"], "CLEANED")
        self.assertEqual(seen["files_before"], [])
        self.assertEqual(list(self.root.iterdir()), [])
        argv = seen["argv"]
        self.assertEqual(argv[:4], [
            "/usr/sbin/mysqld", "--no-defaults", "--initialize-insecure", "--basedir=/usr"
        ])
        self.assertTrue(argv[-1].startswith("--datadir="))
        self.assertFalse(any(arg.startswith(("--socket", "--pid-file", "--log-error", "--user")) for arg in argv))

    def test_successful_command_does_not_mean_overall_success_if_collector_fails(self):
        result = execute_one_minimal_init(
            self.root,
            owner_go=True,
            process_runner=lambda *_a, **_k: ProcessCapture(0, b"", b"", read_failed=True),
        )
        record = json.loads(sanitized_json(result))
        self.assertEqual(record["command_result"], "PASS")
        self.assertEqual(record["diagnostic_status"], "DIAGNOSTIC_UNAVAILABLE")
        self.assertEqual(record["overall_result"], "STOP")
        self.assertNotIn("internal", sanitized_json(result))

    def test_cleanup_failure_is_stop_even_after_mysql_zero(self):
        result, _ = self.run_fake(
            exit_code=0,
            cleanup_fn=lambda _path: (_ for _ in ()).throw(PermissionError("private detail")),
        )
        record = json.loads(sanitized_json(result))
        self.assertEqual(record["command_result"], "PASS")
        self.assertEqual(record["collector_status"], "FAIL")
        self.assertEqual(record["cleanup_status"], "FAIL")
        self.assertEqual(record["temp_data_status"], "CLEANUP_FAILED")
        self.assertEqual(record["overall_result"], "STOP")
        self.assertNotIn("private detail", sanitized_json(result))

    def test_no_owner_go_means_no_process_execution(self):
        called = False

        def runner(*_args, **_kwargs):
            nonlocal called
            called = True
            raise AssertionError("must not execute")

        with self.assertRaisesRegex(DiagnosticError, "OWNER_GO_REQUIRED"):
            execute_one_minimal_init(self.root, process_runner=runner)
        self.assertFalse(called)

    def test_real_bounded_capture_handles_both_streams(self):
        script = "import sys; sys.stdout.write('safe-out'); sys.stderr.write('MY-010457 hidden')"
        capture = run_bounded_process([sys.executable, "-c", script], cwd=str(self.root), env=os.environ.copy())
        self.assertEqual(capture.exit_code, 0)
        self.assertEqual(capture.stdout, b"safe-out")
        self.assertEqual(capture.stderr, b"MY-010457 hidden")
        self.assertFalse(capture.read_failed)
        self.assertFalse(capture.output_limit_exceeded)

    def test_bounded_capture_marks_overflow_without_retaining_extra_bytes(self):
        script = f"import sys; sys.stdout.buffer.write(b'x' * {MAX_STREAM_BYTES + 5000})"
        capture = run_bounded_process([sys.executable, "-c", script], cwd=str(self.root), env=os.environ.copy())
        self.assertEqual(capture.exit_code, 0)
        self.assertEqual(len(capture.stdout), MAX_STREAM_BYTES)
        self.assertTrue(capture.output_limit_exceeded)

    def test_real_child_timeout_terminates_process_and_fails_closed(self):
        with patch.object(diagnostic_module, "PROCESS_TIMEOUT_SECONDS", 0.1):
            capture = run_bounded_process(
                [sys.executable, "-c", "import time; time.sleep(10)"],
                cwd=str(self.root), env=os.environ.copy(),
            )
        self.assertTrue(capture.timed_out)
        self.assertIsInstance(capture.exit_code, int)
        self.assertNotEqual(capture.exit_code, 0)
        self.assertFalse(capture.read_failed)

    def test_thread_start_interruption_kills_child_and_cleans_workspace(self):
        original_start = diagnostic_module.threading.Thread.start
        starts = 0

        def interrupt_second_start(thread):
            nonlocal starts
            starts += 1
            if starts == 2:
                raise KeyboardInterrupt()
            return original_start(thread)

        started_at = time.monotonic()
        with patch.object(diagnostic_module.threading.Thread, "start", interrupt_second_start):
            result = execute_one_minimal_init(
                self.root,
                owner_go=True,
                process_runner=lambda argv, *, cwd, env: run_bounded_process(
                    [sys.executable, "-c", "import time; time.sleep(10)"], cwd=cwd, env=env
                ),
            )
        self.assertEqual(starts, 2)
        record = json.loads(sanitized_json(result))
        self.assertEqual(record["diagnostic_status"], "DIAGNOSTIC_UNAVAILABLE")
        self.assertEqual(record["temp_data_status"], "CLEANED")
        self.assertEqual(record["overall_result"], "STOP")
        self.assertLess(time.monotonic() - started_at, 5)
        self.assertEqual(list(self.root.iterdir()), [])

    def test_public_projection_rejects_raw_diagnostic_payload(self):
        bad = CollectionResult(
            0, {"raw": "secret"}, "AVAILABLE", "PASS", "PASS", "CLEANED"
        )
        record = json.loads(sanitized_json(bad))
        self.assertEqual(record["diagnostic_status"], "DIAGNOSTIC_UNAVAILABLE")
        self.assertEqual(record["overall_result"], "STOP")
        self.assertNotIn("secret", sanitized_json(bad))

    def test_malformed_exit_codes_fail_closed_and_remain_distinct_from_command_failure(self):
        valid_diagnostic = {
            "my_error_codes": [],
            "os_errnos": [],
            "categories": ["UNKNOWN"],
        }
        malformed_values = ("0", "exit-code-secret", True, 0.0, 256, -256)
        for malformed in malformed_values:
            with self.subTest(exit_code_type=type(malformed).__name__):
                result = CollectionResult(
                    malformed, valid_diagnostic, "AVAILABLE", "PASS", "PASS", "CLEANED"
                )
                record = json.loads(sanitized_json(result))
                self.assertIsNone(record["command_exit_code"])
                self.assertEqual(record["command_result"], "NOT_STARTED_OR_UNKNOWN")
                self.assertEqual(record["diagnostic_status"], "DIAGNOSTIC_UNAVAILABLE")
                self.assertEqual(record["collector_status"], "FAIL")
                self.assertEqual(record["overall_result"], "STOP")
                self.assertIsNone(record["diagnostic"])
                self.assertNotIn("exit-code-secret", sanitized_json(result))

        command_failed_collection_failed = CollectionResult(
            1, None, "DIAGNOSTIC_UNAVAILABLE", "FAIL", "NOT_ATTEMPTED", "CLEANED"
        )
        failed_record = json.loads(sanitized_json(command_failed_collection_failed))
        self.assertEqual(failed_record["command_exit_code"], 1)
        self.assertEqual(failed_record["command_result"], "FAIL")
        self.assertEqual(failed_record["diagnostic_status"], "DIAGNOSTIC_UNAVAILABLE")
        self.assertEqual(failed_record["collector_status"], "FAIL")
        self.assertEqual(failed_record["overall_result"], "STOP")

    def test_no_capture_or_retention_files_are_created(self):
        result, seen = self.run_fake(stderr=b"MY-010457")
        self.assertEqual(result.cleanup_status, "PASS")
        self.assertEqual(seen["files_before"], [])
        self.assertEqual(list(self.root.glob(TEMP_PREFIX + "*")), [])
        self.assertFalse(any(self.root.rglob("stdout.bin")))
        self.assertFalse(any(self.root.rglob("stderr.bin")))
        self.assertFalse(any(self.root.rglob("retention.json")))


if __name__ == "__main__":
    unittest.main(verbosity=2)
