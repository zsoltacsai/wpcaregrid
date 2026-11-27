#!/usr/bin/env python3
"""Structural safety tests for the read-only 1.0.16-post diagnostic helper."""
import ast
import contextlib
import hashlib
import importlib.util
import io
import os
import stat
import types
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
HELPER_PATH = ROOT / "deploy/php-fpm-confinement/diagnostics/1.0.16-post-evidence.py"
SPEC = importlib.util.spec_from_file_location("evidence_export", HELPER_PATH)
HELPER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(HELPER)


class EvidenceExportSafetyTests(unittest.TestCase):
    def test_ast_contains_no_write_or_network_or_subprocess_api(self):
        tree = ast.parse(HELPER_PATH.read_text(encoding="utf-8"))
        forbidden_imports = {
            "subprocess", "socket", "urllib", "http", "requests", "pathlib", "tempfile", "shutil"
        }
        os_open_calls = []
        # pathlib is used only by the test harness, never by the helper.
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                self.assertTrue(all(alias.name.split(".", 1)[0] not in forbidden_imports for alias in node.names))
            elif isinstance(node, ast.ImportFrom):
                self.assertNotIn((node.module or "").split(".", 1)[0], forbidden_imports)
            elif isinstance(node, ast.Call):
                func = node.func
                if isinstance(func, ast.Name):
                    self.assertNotIn(func.id, {"open", "compileall", "system", "popen"})
                if isinstance(func, ast.Attribute):
                    if func.attr == "replace":
                        receiver = func.value
                        safe_conversion = (
                            isinstance(receiver, ast.Call)
                            and (
                                (isinstance(receiver.func, ast.Attribute) and receiver.func.attr == "strptime")
                                or (isinstance(receiver.func, ast.Name) and receiver.func.id == "str")
                            )
                        )
                        self.assertTrue(safe_conversion, "replace is allowed only for the two audited value conversions")
                    self.assertNotIn(func.attr, {
                        "write", "writelines", "write_text", "write_bytes", "unlink", "remove", "mkdir",
                        "makedirs", "rmdir", "rename", "chmod", "chown", "truncate",
                        "mkstemp", "mkdtemp", "fdopen", "connect", "connect_ex", "Popen", "run",
                    })
                    if func.attr == "open":
                        allowed_os_open = isinstance(func.value, ast.Name) and func.value.id == "os"
                        self.assertTrue(allowed_os_open, "only guarded os.open with O_RDONLY is permitted")
                        os_open_calls.append(node)
                    self.assertNotEqual(func.attr, "verify", "the diagnostic must never call the acceptance verifier")
        self.assertEqual(len(os_open_calls), 1, "all file reads must pass through the single guarded reader")

    def test_verifier_source_matches_the_literal_pin(self):
        verifier_path = ROOT / "deploy/php-fpm-confinement/1.0.16/acceptance/verify-acceptance.py"
        actual = hashlib.sha256(verifier_path.read_bytes()).hexdigest()
        self.assertEqual(actual, HELPER.PINNED_PACKAGE_FILES["acceptance/verify-acceptance.py"])

    def test_guarded_reader_opens_only_read_only(self):
        data = b"fixture bytes"
        fake_stat = types.SimpleNamespace(
            st_mode=stat.S_IFREG | 0o600, st_uid=0, st_gid=0, st_nlink=1,
            st_size=len(data), st_dev=11, st_ino=22, st_mtime_ns=33, st_ctime_ns=44,
        )
        flags_seen = []

        def guarded_open(_path, flags):
            flags_seen.append(flags)
            self.assertEqual(flags & os.O_ACCMODE, os.O_RDONLY)
            for name in ("O_WRONLY", "O_RDWR", "O_CREAT", "O_TRUNC", "O_APPEND"):
                self.assertFalse(flags & getattr(os, name, 0), name)
            self.assertTrue(flags & os.O_NOFOLLOW)
            self.assertTrue(flags & os.O_NONBLOCK)
            return 91

        chunks = [data, b""]
        with mock.patch.object(HELPER.os, "lstat", return_value=fake_stat), \
             mock.patch.object(HELPER.os, "open", side_effect=guarded_open), \
             mock.patch.object(HELPER.os, "fstat", return_value=fake_stat), \
             mock.patch.object(HELPER.os, "read", side_effect=lambda _fd, _size: chunks.pop(0)), \
             mock.patch.object(HELPER.os, "close", return_value=None):
            actual, metadata = HELPER._read_regular("/synthetic/input", expected_mode=0o600)

        self.assertEqual(actual, data)
        self.assertEqual(metadata["sha256"], hashlib.sha256(data).hexdigest())
        self.assertEqual(len(flags_seen), 1)

    def test_untrusted_names_and_preflight_keys_are_not_echoed(self):
        info = types.SimpleNamespace(
            st_mode=stat.S_IFREG | 0o600, st_uid=0, st_gid=0, st_nlink=1,
            st_size=5, st_mtime_ns=7,
        )
        rendered = HELPER._meta("/private/token-value", info)
        self.assertEqual(rendered["basename"], "UNKNOWN_BASENAME")
        self.assertNotIn("token-value", str(rendered))

        preflight = HELPER._preflight_summary(
            b'{"pin_checks":{"verifier":true,"secret-key":"leak"},'
            b'"service_rcs":{"nginx":0,"secret-service":9}}'
        )
        self.assertEqual(preflight["pin_checks"], {"verifier": True})
        self.assertEqual(preflight["service_rcs"], {"nginx": 0})
        self.assertNotIn("secret", str(preflight))

    def test_symlinks_and_unallowlisted_files_are_never_opened(self):
        symlink_stat = types.SimpleNamespace(
            st_mode=stat.S_IFLNK | 0o777, st_uid=0, st_gid=0, st_nlink=1,
            st_size=18, st_mtime_ns=7,
        )
        regular_stat = types.SimpleNamespace(
            st_mode=stat.S_IFREG | 0o600, st_uid=0, st_gid=0, st_nlink=1,
            st_size=12, st_mtime_ns=7,
        )
        with mock.patch.object(HELPER, "_directory", return_value=regular_stat), \
             mock.patch.object(HELPER.os, "listdir", return_value=["private-link", "private-file"]), \
             mock.patch.object(HELPER.os, "lstat", side_effect=[symlink_stat, regular_stat]), \
             mock.patch.object(HELPER.os, "open", side_effect=AssertionError("untrusted evidence must not be opened")):
            manifest, selected, unknown = HELPER._evidence_manifest(
                HELPER.os.path.join(HELPER.ENVELOPE_DIR, HELPER.RUN_DIR_NAME)
            )
        self.assertEqual(selected, {})
        self.assertEqual(len(unknown), 2)
        self.assertTrue(all(row["basename"] == "UNKNOWN_BASENAME" for row in manifest))
        self.assertTrue(all(row["sha256"] is None for row in manifest))

    def test_state_stage_and_stderr_are_enum_only(self):
        self.assertEqual(HELPER._bounded_token("unexpected-secret", HELPER.RESULT_STATES), "UNKNOWN")
        self.assertEqual(HELPER._bounded_token("connect", HELPER.RESULT_STAGES), "connect")
        for raw, expected in (
            (b"label mismatch private/path", "label-refusal"),
            (b"fixture mismatch private/path", "fixture-refusal"),
            (b"Traceback (most recent call last):", "traceback"),
            (b"arbitrary secret value", "other"),
            (b"", "empty"),
        ):
            self.assertEqual(HELPER._stderr_class(raw), expected)

    def test_cli_emits_only_json_to_stdout_and_nothing_to_stderr(self):
        report = {"schema": 1, "diagnostic_only": True, "acceptance_verdict": "NOT_RUN"}
        stdout, stderr = io.StringIO(), io.StringIO()
        with mock.patch.object(HELPER.sys, "argv", [str(HELPER_PATH)]), \
             mock.patch.object(HELPER, "summarize", return_value=report), \
             contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
            rc = HELPER.main()
        self.assertEqual(rc, 0)
        self.assertEqual(stderr.getvalue(), "")
        self.assertEqual(__import__("json").loads(stdout.getvalue()), report)


if __name__ == "__main__":
    unittest.main()
