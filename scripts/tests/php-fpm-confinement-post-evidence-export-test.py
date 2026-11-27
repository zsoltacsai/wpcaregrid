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
        parents = {}
        for parent in ast.walk(tree):
            for child in ast.iter_child_nodes(parent):
                parents[child] = parent
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
                        self.assertEqual(len(node.args), 2)
                        flag_expr = node.args[1]
                        self.assertTrue(
                            (isinstance(flag_expr, ast.Name) and flag_expr.id == "flags")
                            or (isinstance(flag_expr, ast.Call)
                                and isinstance(flag_expr.func, ast.Name)
                                and flag_expr.func.id == "_directory_flags")
                        )
                        os_open_calls.append(node)
                    self.assertNotEqual(func.attr, "verify", "the diagnostic must never call the acceptance verifier")
        allowed_openers = {"_open_absolute_directory", "_open_dir_at", "_read_regular_at"}
        opener_names = set()
        for call in os_open_calls:
            parent = parents.get(call)
            while parent is not None and not isinstance(parent, (ast.FunctionDef, ast.AsyncFunctionDef)):
                parent = parents.get(parent)
            self.assertIsNotNone(parent)
            opener_names.add(parent.name)
        self.assertEqual(opener_names, allowed_openers)

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

        def guarded_open(_path, flags, **kwargs):
            self.assertEqual(kwargs, {"dir_fd": 123})
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
             mock.patch.object(HELPER.os, "stat", return_value=fake_stat), \
             mock.patch.object(HELPER.os, "fstat", return_value=fake_stat), \
             mock.patch.object(HELPER.os, "read", side_effect=lambda _fd, _size: chunks.pop(0)), \
             mock.patch.object(HELPER.os, "close", return_value=None):
            actual, metadata = HELPER._read_regular_at(123, "input", expected_mode=0o600)

        self.assertEqual(actual, data)
        self.assertEqual(metadata["sha256"], hashlib.sha256(data).hexdigest())
        self.assertEqual(len(flags_seen), 1)

    def test_reader_rejects_metadata_hash_and_race_mismatches(self):
        data = b"fixture bytes"
        original = types.SimpleNamespace(
            st_mode=stat.S_IFREG | 0o600, st_uid=0, st_gid=0, st_nlink=1,
            st_size=len(data), st_dev=11, st_ino=22, st_mtime_ns=33, st_ctime_ns=44,
        )
        bad_inputs = (
            ({"st_uid": 1000}, "input-metadata", None),
            ({"st_mode": stat.S_IFREG | 0o644}, "input-mode", 0o600),
            ({"st_nlink": 2}, "input-metadata", None),
        )
        for changes, code, expected_mode in bad_inputs:
            attrs = vars(original).copy()
            attrs.update(changes)
            bad_stat = types.SimpleNamespace(**attrs)
            with self.subTest(code=code, changes=changes), \
                 mock.patch.object(HELPER.os, "stat", return_value=bad_stat), \
                 mock.patch.object(HELPER.os, "open") as open_mock:
                with self.assertRaises(HELPER.DiagnosticHold) as caught:
                    HELPER._read_regular_at(123, "input", expected_mode=expected_mode)
                self.assertEqual(caught.exception.code, code)
                open_mock.assert_not_called()

        changed = types.SimpleNamespace(**{**vars(original), "st_ino": 99})
        with mock.patch.object(HELPER.os, "stat", return_value=original), \
             mock.patch.object(HELPER.os, "open", return_value=91), \
             mock.patch.object(HELPER.os, "fstat", return_value=changed), \
             mock.patch.object(HELPER.os, "close") as close_mock:
            with self.assertRaises(HELPER.DiagnosticHold) as caught:
                HELPER._read_regular_at(123, "input")
            self.assertEqual(caught.exception.code, "input-raced")
            close_mock.assert_called_once_with(91)

        drifted = types.SimpleNamespace(**{**vars(original), "st_mtime_ns": 34})
        chunks = [data, b""]
        with mock.patch.object(HELPER.os, "stat", return_value=original), \
             mock.patch.object(HELPER.os, "open", return_value=91), \
             mock.patch.object(HELPER.os, "fstat", side_effect=[original, drifted]), \
             mock.patch.object(HELPER.os, "read", side_effect=lambda _fd, _size: chunks.pop(0)), \
             mock.patch.object(HELPER.os, "close"):
            with self.assertRaises(HELPER.DiagnosticHold) as caught:
                HELPER._read_regular_at(123, "input")
            self.assertEqual(caught.exception.code, "input-raced")

        chunks = [data, b""]
        with mock.patch.object(HELPER.os, "stat", return_value=original), \
             mock.patch.object(HELPER.os, "open", return_value=91), \
             mock.patch.object(HELPER.os, "fstat", return_value=original), \
             mock.patch.object(HELPER.os, "read", side_effect=lambda _fd, _size: chunks.pop(0)), \
             mock.patch.object(HELPER.os, "close"):
            with self.assertRaises(HELPER.DiagnosticHold) as caught:
                HELPER._read_regular_at(123, "input", expected_sha256="0" * 64)
            self.assertEqual(caught.exception.code, "pinned-input-digest-mismatch")

        small = types.SimpleNamespace(**{**vars(original), "st_size": 4})
        chunks = [b"12345"]
        with mock.patch.object(HELPER, "MAX_FILE_BYTES", 4), \
             mock.patch.object(HELPER.os, "stat", return_value=small), \
             mock.patch.object(HELPER.os, "open", return_value=91), \
             mock.patch.object(HELPER.os, "fstat", return_value=small), \
             mock.patch.object(HELPER.os, "read", side_effect=lambda _fd, _size: chunks.pop(0)), \
             mock.patch.object(HELPER.os, "close"):
            with self.assertRaises(HELPER.DiagnosticHold) as caught:
                HELPER._read_regular_at(123, "input")
            self.assertEqual(caught.exception.code, "input-size-bound")

    def test_dirfd_open_detects_directory_replacement(self):
        before = types.SimpleNamespace(st_mode=stat.S_IFDIR | 0o755, st_uid=0, st_gid=0,
                                       st_nlink=2, st_dev=1, st_ino=10)
        replaced = types.SimpleNamespace(st_mode=stat.S_IFDIR | 0o755, st_uid=0, st_gid=0,
                                         st_nlink=2, st_dev=1, st_ino=11)
        with mock.patch.object(HELPER.os, "stat", return_value=before) as stat_mock, \
             mock.patch.object(HELPER.os, "open", return_value=91) as open_mock, \
             mock.patch.object(HELPER.os, "fstat", return_value=replaced), \
             mock.patch.object(HELPER.os, "close") as close_mock:
            with self.assertRaises(HELPER.DiagnosticHold) as caught:
                HELPER._open_dir_at(77, "child", expected_mode=0o755)
        self.assertEqual(caught.exception.code, "directory-raced")
        self.assertEqual(stat_mock.call_args.kwargs, {"dir_fd": 77, "follow_symlinks": False})
        self.assertEqual(open_mock.call_args.kwargs, {"dir_fd": 77})
        self.assertEqual(open_mock.call_args.args[1] & os.O_ACCMODE, os.O_RDONLY)
        self.assertTrue(open_mock.call_args.args[1] & os.O_NOFOLLOW)
        self.assertTrue(open_mock.call_args.args[1] & os.O_DIRECTORY)
        close_mock.assert_called_once_with(91)

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
        with mock.patch.object(HELPER, "_open_absolute_directory", return_value=10), \
             mock.patch.object(HELPER, "_open_dir_at", return_value=11), \
             mock.patch.object(HELPER.os, "listdir", return_value=["private-link", "private-file"]), \
             mock.patch.object(HELPER.os, "stat", side_effect=[symlink_stat, regular_stat]), \
             mock.patch.object(HELPER.os, "close", return_value=None), \
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


    def test_traceback_summary_emits_only_allowlisted_metadata(self):
        raw = (b'Traceback (most recent call last):\n'
               b'  File "/root/private/path/probe.py", line 427, in main\n'
               b'PermissionError: /root/private/secret/value\n')
        summary = HELPER._traceback_summary(raw)
        rendered = str(summary)
        self.assertEqual(summary, {
            "status": "PARSED", "exception_class": "PermissionError", "frame_count": 1,
            "thrower_basename": "probe.py", "thrower_function": "main", "thrower_line": 427,
        })
        for forbidden in ("/root", "private", "secret", "PermissionError:"):
            self.assertNotIn(forbidden, rendered)

    def test_traceback_summary_hides_unrecognized_class_and_filename(self):
        raw = (b'Traceback (most recent call last):\n'
               b'  File "/etc/private/tokenized.py", line 9, in call\n'
               b'vendor.secret.Error: sensitive-message\n')
        summary = HELPER._traceback_summary(raw)
        self.assertEqual(summary["exception_class"], "OTHER_EXCEPTION")
        self.assertEqual(summary["thrower_basename"], "OTHER_BASENAME")
        self.assertNotIn("tokenized", str(summary))
        self.assertNotIn("sensitive", str(summary))

    def test_traceback_summary_fails_closed_for_unbounded_or_malformed_input(self):
        self.assertEqual(HELPER._traceback_summary(None), {"status": "ABSENT_OR_UNKNOWN"})
        self.assertEqual(HELPER._traceback_summary(b""), {"status": "EMPTY"})
        self.assertEqual(HELPER._traceback_summary(b"x" * (HELPER._MAX_TRACEBACK_BYTES + 1))["status"], "OVER_BOUND")
        self.assertEqual(HELPER._traceback_summary(b"Traceback (most recent call last):\nnot a frame\nValueError: x")["status"], "UNPARSEABLE")

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
