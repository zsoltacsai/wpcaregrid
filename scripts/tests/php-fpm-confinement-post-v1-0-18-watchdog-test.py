#!/usr/bin/env python3
"""Bounded-output, timeout, progress-token, and reap tests for the diagnostic watchdog."""
import importlib.util
import fcntl
import hashlib
import json
import os
import subprocess
import sys
import tempfile
import stat
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
WATCHDOG_PATH = ROOT / "deploy/php-fpm-confinement/diagnostics/1.0.18-post-v1/watchdog.py"
SPEC = importlib.util.spec_from_file_location("evidence_watchdog", WATCHDOG_PATH)
WATCHDOG = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(WATCHDOG)


def child_command(source):
    return lambda fd: [sys.executable, "-c", source, str(fd)]


def success_report():
    return {
        "schema": 1, "diagnostic_only": True, "acceptance_verdict": "NOT_RUN",
        "fixture_cleanup": "NOT_RUN",
        "run_id": "acceptance-1.0.18-post-20261128T204310Z-f4b8d2",
        "package_pins": {}, "package_tree_expected": "0" * 40,
        "run_manifest": [], "untrusted_or_unknown_files": [],
        "preflight": {}, "inventory_before": {}, "baseline": {},
        "confined": {}, "stderr": {}, "audit": {}, "verifier_diagnostic": {},
        "diagnostic_class": "INSUFFICIENT_BOUND_EVIDENCE",
        "bridge_report_file": "ABSENT_OR_UNKNOWN",
        "verifier_executed": False, "raw_stderr_emitted": False,
        "raw_audit_emitted": False, "raw_config_emitted": False,
    }


class EvidenceWatchdogTests(unittest.TestCase):
    def test_bundle_root_requires_0555_and_parent_allows_only_root_write(self):
        self.assertTrue(WATCHDOG._directory_mode_safe(stat.S_IFDIR | 0o555, bundle_root=True))
        self.assertFalse(WATCHDOG._directory_mode_safe(stat.S_IFDIR | 0o755, bundle_root=True))
        self.assertTrue(WATCHDOG._directory_mode_safe(stat.S_IFDIR | 0o755, bundle_root=False))
        self.assertFalse(WATCHDOG._directory_mode_safe(stat.S_IFDIR | 0o775, bundle_root=False))

    def make_bundle(self, root, helper_bytes, *, helper_mode=0o444):
        root = Path(root)
        watchdog_bytes = WATCHDOG_PATH.read_bytes()
        files = {
            WATCHDOG.WATCHDOG_NAME: watchdog_bytes,
            WATCHDOG.HELPER_NAME: helper_bytes,
        }
        manifest = {
            "schema": 1,
            "bundle_version": WATCHDOG.BUNDLE_VERSION,
            "watchdog_sha256": hashlib.sha256(watchdog_bytes).hexdigest(),
            "helper_sha256": hashlib.sha256(helper_bytes).hexdigest(),
        }
        files[WATCHDOG.MANIFEST_NAME] = json.dumps(manifest, sort_keys=True).encode()
        for name, data in files.items():
            path = root / name
            path.write_bytes(data)
            path.chmod(helper_mode if name == WATCHDOG.HELPER_NAME else 0o444)
        root.chmod(0o555)
        return files

    def test_writable_or_replaced_helper_is_rejected(self):
        for replacement, mode in ((False, 0o644), (True, 0o444)):
            with self.subTest(replacement=replacement, mode=oct(mode)):
                with tempfile.TemporaryDirectory() as temp:
                    root = Path(temp)
                    helper = b"print('trusted')\n"
                    self.make_bundle(root, helper, helper_mode=mode)
                    if replacement:
                        root.chmod(0o755)
                        helper_path = root / WATCHDOG.HELPER_NAME
                        helper_path.chmod(0o644)
                        helper_path.write_bytes(b"print('/private/path/raw-secret')\n")
                        helper_path.chmod(0o444)
                        root.chmod(0o555)
                    with self.assertRaises(ValueError):
                        WATCHDOG._load_bundle(
                            str(root), expected_uid=os.getuid(), expected_gid=os.getgid(),
                            require_trusted_path=False,
                        )
                    root.chmod(0o755)

    def test_verified_bytes_execute_after_helper_path_is_replaced(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            original = b"print('verified-source-ran')\n"
            self.make_bundle(root, original)
            verified = WATCHDOG._load_bundle(
                str(root), expected_uid=os.getuid(), expected_gid=os.getgid(),
                require_trusted_path=False,
            )
            self.assertEqual(verified, original)
            root.chmod(0o755)
            helper_path = root / WATCHDOG.HELPER_NAME
            helper_path.chmod(0o644)
            helper_path.write_bytes(b"print('replacement-ran')\n")
            helper_path.chmod(0o444)
            root.chmod(0o555)

            fd = WATCHDOG._sealed_source_fd(verified)
            try:
                seals = fcntl.fcntl(fd, fcntl.F_GET_SEALS)
                self.assertTrue(seals & fcntl.F_SEAL_WRITE)
                process = subprocess.Popen(
                    [sys.executable, "-B", f"/proc/self/fd/{fd}"],
                    pass_fds=(fd,), stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                    close_fds=True,
                )
            finally:
                os.close(fd)
            stdout, stderr = process.communicate(timeout=2)
            self.assertEqual(process.returncode, 0, stderr)
            self.assertEqual(stdout, b"verified-source-ran\n")
            self.assertNotIn(b"replacement-ran", stdout)
            root.chmod(0o755)

    def test_bundle_rejects_symlink_and_hardlink_helper_paths(self):
        for kind in ("symlink", "hardlink"):
            with self.subTest(kind=kind), tempfile.TemporaryDirectory() as temp:
                root = Path(temp)
                self.make_bundle(root, b"print('trusted')\n")
                root.chmod(0o755)
                helper_path = root / WATCHDOG.HELPER_NAME
                helper_path.unlink()
                if kind == "symlink":
                    victim = root / "victim"
                    victim.write_bytes(b"/private/path/raw-secret")
                    helper_path.symlink_to(victim)
                else:
                    os.link(root / WATCHDOG.WATCHDOG_NAME, helper_path)
                root.chmod(0o555)
                with self.assertRaises(ValueError):
                    WATCHDOG._load_bundle(
                        str(root), expected_uid=os.getuid(), expected_gid=os.getgid(),
                        require_trusted_path=False,
                    )
                root.chmod(0o755)

    def test_manifest_pins_the_versioned_local_bundle(self):
        manifest_path = WATCHDOG_PATH.with_name("bundle-manifest.json")
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        helper_path = WATCHDOG_PATH.with_name(WATCHDOG.HELPER_NAME)
        self.assertEqual(
            WATCHDOG.TRUSTED_BUNDLE_DIR,
            "/usr/local/lib/wpcaregrid/php-fpm-confinement/1.0.18-post-watchdog-1",
        )
        self.assertEqual(WATCHDOG.BUNDLE_VERSION, "1.0.18-post-watchdog-1")
        self.assertEqual(manifest["bundle_version"], WATCHDOG.BUNDLE_VERSION)
        self.assertEqual(manifest["watchdog_sha256"], hashlib.sha256(WATCHDOG_PATH.read_bytes()).hexdigest())
        self.assertEqual(manifest["helper_sha256"], hashlib.sha256(helper_path.read_bytes()).hexdigest())

    def test_hanging_child_is_killed_and_reaped_after_each_stage(self):
        for stage in sorted(WATCHDOG.PROGRESS_STAGES):
            with self.subTest(stage=stage):
                source = (
                    "import os,sys,time; fd=int(sys.argv[1]); "
                    f"os.write(fd, b'{stage}\\n'); time.sleep(20)"
                )
                output, status = WATCHDOG.supervise(
                    child_command(source), timeout_seconds=1.0,
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
