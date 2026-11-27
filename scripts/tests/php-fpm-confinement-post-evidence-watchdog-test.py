#!/usr/bin/env python3
"""Bounded-output, timeout, progress-token, and reap tests for the diagnostic watchdog."""
import importlib.util
import json
import os
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
WATCHDOG_PATH = ROOT / "deploy/php-fpm-confinement/diagnostics/1.0.16-post-evidence-watchdog.py"
SPEC = importlib.util.spec_from_file_location("evidence_watchdog", WATCHDOG_PATH)
WATCHDOG = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(WATCHDOG)


def child_command(source):
    return lambda fd: [sys.executable, "-c", source, str(fd)]


def success_report():
    return {
        "schema": 1, "diagnostic_only": True, "acceptance_verdict": "NOT_RUN",
        "fixture_cleanup": "NOT_RUN",
        "run_id": "acceptance-1.0.16-post-20261127T135951Z-b726ce",
        "package_pins": {}, "package_tree_expected": "0" * 40,
        "run_manifest": [], "untrusted_or_unknown_files": [],
        "preflight": {}, "inventory_before": {}, "baseline": {},
        "confined": {}, "stderr": {}, "audit": {},
        "diagnostic_class": "INSUFFICIENT_BOUND_EVIDENCE",
        "bridge_report_file": "ABSENT_OR_UNKNOWN",
        "verifier_executed": False, "raw_stderr_emitted": False,
        "raw_audit_emitted": False, "raw_config_emitted": False,
    }


class EvidenceWatchdogTests(unittest.TestCase):
    def test_hanging_child_is_killed_and_reaped_after_each_stage(self):
        for stage in sorted(WATCHDOG.PROGRESS_STAGES):
            with self.subTest(stage=stage):
                source = (
                    "import os,sys,time; fd=int(sys.argv[1]); "
                    f"os.write(fd, b'{stage}\\n'); time.sleep(20)"
                )
                output, status = WATCHDOG.supervise(
                    child_command(source), timeout_seconds=0.15,
                    term_grace_seconds=0.1, reap_grace_seconds=0.2,
                )
                report = json.loads(output)
                self.assertEqual(status, 124)
                self.assertEqual(report["watchdog"]["status"], "TIMEOUT")
                self.assertEqual(report["watchdog"]["last_stage"], stage)
                self.assertTrue(report["watchdog"]["child_reaped"])
                self.assertEqual(report["acceptance_verdict"], "NOT_RUN")
                self.assertEqual(report["fixture_cleanup"], "NOT_RUN")

    def test_malformed_progress_fails_closed_without_echo(self):
        source = (
            "import os,sys,time; fd=int(sys.argv[1]); "
            "os.write(fd,b'/private/path/raw-secret\\n'); time.sleep(20)"
        )
        output, status = WATCHDOG.supervise(
            child_command(source), timeout_seconds=2,
            term_grace_seconds=0.1, reap_grace_seconds=0.2,
        )
        self.assertEqual(status, 2)
        self.assertNotIn(b"raw-secret", output)
        self.assertNotIn(b"/private/path", output)
        self.assertEqual(json.loads(output)["watchdog"]["reason_code"], "progress-invalid")

    def test_stdout_overflow_is_bounded_and_redacted(self):
        source = "import os,sys; os.write(1,b'x'*(2_000_001))"
        output, status = WATCHDOG.supervise(
            child_command(source), timeout_seconds=2,
            term_grace_seconds=0.1, reap_grace_seconds=0.2,
        )
        self.assertEqual(status, 2)
        self.assertLess(len(output), 512)
        self.assertEqual(json.loads(output)["watchdog"]["reason_code"], "output-bound-exceeded")
        self.assertNotIn(b"xxxxx", output)

    def test_stderr_overflow_is_bounded_and_redacted(self):
        source = "import os,sys; os.write(2,b'/secret/message-'*5000)"
        output, status = WATCHDOG.supervise(
            child_command(source), timeout_seconds=2,
            term_grace_seconds=0.1, reap_grace_seconds=0.2,
        )
        self.assertEqual(status, 2)
        self.assertLess(len(output), 512)
        self.assertEqual(json.loads(output)["watchdog"]["reason_code"], "output-bound-exceeded")
        self.assertNotIn(b"/secret/message", output)

    def test_stderr_and_invalid_child_json_never_leak(self):
        cases = (
            "import os; os.write(2,b'/private/path/secret-message'); os._exit(9)",
            "import os; os.write(1,b'/private/path/secret-message\\n'); os._exit(0)",
        )
        for source in cases:
            with self.subTest(source=source[:24]):
                output, status = WATCHDOG.supervise(child_command(source), timeout_seconds=2)
                self.assertEqual(status, 2)
                self.assertNotIn(b"secret-message", output)
                self.assertNotIn(b"/private/path", output)

    def test_success_json_with_unexpected_raw_fields_is_rejected(self):
        report = success_report()
        report["raw_stderr"] = "/private/path/secret-message"
        encoded = json.dumps(report)
        source = (
            "import json,os,sys; fd=int(sys.argv[1]); "
            "os.write(fd,b'OUTPUT\\n'); print(" + repr(encoded) + ")"
        )
        output, status = WATCHDOG.supervise(child_command(source), timeout_seconds=2)
        self.assertEqual(status, 2)
        self.assertNotIn(b"secret-message", output)
        self.assertNotIn(b"/private/path", output)
        self.assertEqual(json.loads(output)["watchdog"]["reason_code"], "child-output-invalid")

    def test_success_json_with_false_redaction_attestation_is_rejected(self):
        report = success_report()
        report["raw_stderr_emitted"] = True
        encoded = json.dumps(report)
        source = (
            "import json,os,sys; fd=int(sys.argv[1]); "
            "os.write(fd,b'OUTPUT\\n'); print(" + repr(encoded) + ")"
        )
        output, status = WATCHDOG.supervise(child_command(source), timeout_seconds=2)
        self.assertEqual(status, 2)
        self.assertEqual(json.loads(output)["watchdog"]["reason_code"], "child-output-invalid")

    def test_valid_helper_json_is_preserved_without_stderr(self):
        encoded = json.dumps(success_report())
        source = (
            "import json,os,sys; fd=int(sys.argv[1]); "
            "os.write(fd,b'OUTPUT\\n'); "
            "print(" + repr(encoded) + ")"
        )
        output, status = WATCHDOG.supervise(child_command(source), timeout_seconds=2)
        self.assertEqual(status, 0)
        self.assertEqual(json.loads(output)["acceptance_verdict"], "NOT_RUN")


if __name__ == "__main__":
    unittest.main()
