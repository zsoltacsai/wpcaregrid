import io
import json
import os
import subprocess
import sys
import tempfile
import textwrap
import unittest
from unittest.mock import patch
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ARTIFACT = ROOT / 'deploy/php-fpm-confinement/orchestrators/1.0.16-post-io-v2'
sys.path.insert(0, str(ARTIFACT))
import one_shot_bridge
from one_shot_bridge import run_one_shot_root_bridge


FAKE_SECRET = b"FAKE_ONE_SHOT_SECRET_ONLY"
RAW_SENTINEL = b"UNSAFE_RAW_SENTINEL"
FAKE_CHILD = Path(__file__).resolve().parent / 'fake_gate2_tty_child.py'
WRAPPER = r"""
import json, sys
sys.path.insert(0, sys.argv[4])
from one_shot_bridge import run_one_shot_root_bridge
report = run_one_shot_root_bridge(
    [sys.executable, sys.argv[1]], sys.argv[2], sys.argv[3].encode("ascii"),
    input_fd=0, output_fd=1, timeout=3.0,
    require_interactive_stdin=False,
)
print(json.dumps(report, sort_keys=True), flush=True)
"""


class OneShotBridgeTests(unittest.TestCase):
    def test_interactive_bridge_requires_tty_on_input_and_output(self):
        with tempfile.TemporaryDirectory(prefix="one-shot-bridge-tty-") as td:
            secret = Path(td) / "one-use"
            secret.write_bytes(FAKE_SECRET)
            secret.chmod(0o600)
            report = run_one_shot_root_bridge(
                [sys.executable, str(FAKE_CHILD)], str(secret), b"true",
                input_fd=0, output_fd=1, timeout=0.1, require_interactive_stdin=True,
            )
            self.assertEqual(report["outcome"], "outer_stream_not_tty")
            self.assertFalse(report["outer_stdin_is_tty"])
            self.assertFalse(report["outer_stdout_is_tty"])
            self.assertFalse(report["password_prompt_seen"])
            self.assertTrue(secret.exists())

    def test_final_json_contains_rc_and_whitelist_not_raw_pty(self):
        with tempfile.TemporaryDirectory(prefix="one-shot-bridge-") as td:
            secret = Path(td) / "one-use"
            secret.write_bytes(FAKE_SECRET)
            secret.chmod(0o600)
            proc = subprocess.run(
                [sys.executable, "-c", WRAPPER, str(FAKE_CHILD), str(secret), "package-check", str(ARTIFACT)],
                stdin=subprocess.PIPE,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                timeout=8,
                check=False,
            )
            self.assertEqual(proc.returncode, 0, proc.stderr.decode("utf-8", "replace"))
            self.assertFalse(secret.exists())
            self.assertNotIn(FAKE_SECRET, proc.stdout + proc.stderr)
            self.assertNotIn(RAW_SENTINEL, proc.stdout + proc.stderr)
            report = json.loads(proc.stdout.splitlines()[-1])
            self.assertTrue(report["password_prompt_seen"])
            self.assertTrue(report["identity_marker_matched"])
            self.assertTrue(report["command_report_valid"])
            self.assertEqual(report["command_report"]["rc"], 7)
            self.assertEqual(report["command_report"]["pin_count"], 20)
            self.assertTrue(report["child_reaped"])
            self.assertEqual(report["su_exit_code"], 0)
            self.assertEqual(report["outcome"], "completed")

    def test_symlink_card_file_is_rejected_and_not_followed(self):
        with tempfile.TemporaryDirectory(prefix="one-shot-bridge-symlink-") as td:
            secret = Path(td) / "secret"
            secret.write_bytes(FAKE_SECRET)
            secret.chmod(0o600)
            link = Path(td) / "one-use"
            link.symlink_to(secret)
            proc = subprocess.run(
                [sys.executable, "-c", WRAPPER, str(FAKE_CHILD), str(link), "package-check", str(ARTIFACT)],
                stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                timeout=8, check=False,
            )
            self.assertEqual(proc.returncode, 0, proc.stderr.decode("utf-8", "replace"))
            report = json.loads(proc.stdout.splitlines()[-1])
            self.assertEqual(report["outcome"], "driver_error")
            self.assertTrue(report["child_reaped"])
            self.assertTrue(link.is_symlink())
            self.assertEqual(secret.read_bytes(), FAKE_SECRET)
            self.assertNotIn(FAKE_SECRET, proc.stdout + proc.stderr)

    def test_prompt_consumes_invalid_one_use_file_without_following_or_leaking(self):
        with tempfile.TemporaryDirectory(prefix="one-shot-bridge-invalid-card-") as td:
            secret = Path(td) / "one-use"
            secret.write_bytes(b"x" * 4097)
            secret.chmod(0o600)
            proc = subprocess.run(
                [sys.executable, "-c", WRAPPER, str(FAKE_CHILD), str(secret), "package-check", str(ARTIFACT)],
                stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                timeout=8, check=False,
            )
            self.assertEqual(proc.returncode, 0, proc.stderr.decode("utf-8", "replace"))
            self.assertFalse(secret.exists())
            self.assertNotIn(b"x" * 4097, proc.stdout + proc.stderr)
            report = json.loads(proc.stdout.splitlines()[-1])
            self.assertEqual(report["outcome"], "driver_error")
            self.assertTrue(report["password_prompt_seen"])
            self.assertTrue(report["child_reaped"])

    def test_cli_only_runs_versioned_orchestrator_with_validated_paths(self):
        fake = {"outcome": "completed", "command_report_valid": True,
                "child_reaped": True, "su_exit_code": 0,
                "command_report": {"phase": "acceptance-post", "rc": 0, "overall_pass": True}}
        with patch.object(one_shot_bridge, "_bundle_valid", return_value=True), \
             patch.object(one_shot_bridge, "run_one_shot_root_bridge", return_value=fake) as bridge, \
             patch("sys.stdout", new_callable=io.StringIO) as output:
            rc = one_shot_bridge.main([
                "--root-card-file", "/run/one-use-card",
                "--package-dir", "/root/package-1.0.16",
                "--evidence-envelope", "/root/gate2-evidence",
                "--route-closure-evidence", "/root/gate2-evidence/route-closure.json",
            ])
        self.assertEqual(rc, 0)
        args = bridge.call_args.args
        self.assertEqual(args[0], ["su", "-"])
        self.assertEqual(args[1], "/run/one-use-card")
        command = args[2].decode("utf-8")
        self.assertIn("acceptance-post.py", command)
        self.assertIn("/root/package-1.0.16", command)
        self.assertNotIn("FAKE_ONE_USE_SECRET", command)
        self.assertNotIn("/run/one-use-card", output.getvalue())

    def test_cli_nonpass_report_returns_nonzero(self):
        fake = {"outcome": "completed", "command_report_valid": True,
                "child_reaped": True, "su_exit_code": 0,
                "command_report": {"phase": "acceptance-post", "rc": 1, "overall_pass": False}}
        with patch.object(one_shot_bridge, "_bundle_valid", return_value=True), \
             patch.object(one_shot_bridge, "run_one_shot_root_bridge", return_value=fake), \
             patch("sys.stdout", new_callable=io.StringIO):
            rc = one_shot_bridge.main([
                "--root-card-file", "/run/one-use-card",
                "--package-dir", "/root/package-1.0.16",
                "--evidence-envelope", "/root/gate2-evidence",
                "--route-closure-evidence", "/root/gate2-evidence/route-closure.json",
            ])
        self.assertEqual(rc, 1)

    def test_inventory_metadata_report_is_whitelisted_and_raw_free(self):
        with tempfile.TemporaryDirectory(prefix="one-shot-inventory-meta-") as td:
            secret = Path(td) / "one-use"
            secret.write_bytes(FAKE_SECRET)
            secret.chmod(0o600)
            proc = subprocess.run(
                [sys.executable, "-c", WRAPPER, str(FAKE_CHILD), str(secret), "inventory-evidence-metadata", str(ARTIFACT)],
                stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                timeout=8, check=False,
            )
            self.assertEqual(proc.returncode, 0, proc.stderr.decode("utf-8", "replace"))
            self.assertFalse(secret.exists())
            self.assertNotIn(FAKE_SECRET, proc.stdout + proc.stderr)
            self.assertNotIn(RAW_SENTINEL, proc.stdout + proc.stderr)
            report = json.loads(proc.stdout.splitlines()[-1])
            summary = report["command_report"]
            self.assertTrue(report["command_report_valid"])
            self.assertEqual(summary["phase"], "inventory-evidence-metadata")
            self.assertEqual(summary["rc"], 0)
            self.assertEqual(summary["children"][0]["name"], "collector.stdout")
            self.assertEqual(summary["content_inspection"], "not-read-basename-unknown")
            self.assertTrue(report["child_reaped"])
            self.assertEqual(report["su_exit_code"], 0)

    def test_inventory_candidate_report_rejects_sensitive_fields(self):
        with tempfile.TemporaryDirectory(prefix="one-shot-inventory-candidate-") as td:
            secret = Path(td) / "one-use"
            secret.write_bytes(FAKE_SECRET)
            secret.chmod(0o600)
            proc = subprocess.run(
                [sys.executable, "-c", WRAPPER, str(FAKE_CHILD), str(secret), "inventory-candidate", str(ARTIFACT)],
                stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                timeout=8, check=False,
            )
            self.assertEqual(proc.returncode, 0, proc.stderr.decode("utf-8", "replace"))
            self.assertFalse(secret.exists())
            self.assertNotIn(FAKE_SECRET, proc.stdout + proc.stderr)
            self.assertNotIn(RAW_SENTINEL, proc.stdout + proc.stderr)
            report = json.loads(proc.stdout.splitlines()[-1])
            summary = report["command_report"]
            self.assertTrue(report["command_report_valid"])
            self.assertEqual(summary["phase"], "inventory-candidate")
            self.assertFalse(summary["candidate_origin_proven"])
            self.assertEqual(summary["schema"], 2)
            self.assertEqual(summary["verifier_failure_count"], 0)
            self.assertEqual(summary["rejected_fd_count"], 0)
            self.assertTrue(report["child_reaped"])
            self.assertEqual(report["su_exit_code"], 0)


if __name__ == "__main__":
    unittest.main()
