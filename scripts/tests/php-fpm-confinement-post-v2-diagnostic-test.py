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
HELPER_PATH = ROOT / "deploy/php-fpm-confinement/diagnostics/1.0.16-post-v2/helper.py"
SPEC = importlib.util.spec_from_file_location("evidence_export", HELPER_PATH)
HELPER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(HELPER)


class EvidenceExportSafetyTests(unittest.TestCase):
    def test_run_pin_and_nginx_evidence_allowlist_are_exact(self):
        self.assertEqual(HELPER.RUN_DIR_NAME, "acceptance-1.0.16-post-20261128T065928Z-c90b29")
        self.assertEqual(
            HELPER.EVIDENCE_ALLOWLIST,
            {
                "preflight-summary.json",
                "wpcaregrid-nginx-inventory-before.json",
                "wpcaregrid-nginx-inventory-before.stderr",
                "wpcaregrid-nginx-baseline.json",
                "wpcaregrid-nginx-baseline.stderr",
                "wpcaregrid-nginx-baseline.stderr.systemd-run",
                "wpcaregrid-nginx-confined.json",
                "wpcaregrid-nginx-confined.stderr",
                "wpcaregrid-nginx-confined.stderr.systemd-run",
                "wpcaregrid-nginx-derive.stderr",
                "wpcaregrid-nginx-audit.jsonl",
                "wpcaregrid-nginx-audit.stderr",
            },
        )

    def test_probe_identity_and_result_fields_are_allowlisted_and_redacted(self):
        secret = "/private/path/raw-secret-message"
        doc = {
            "schema": 2, "mode": "confined", "profile": "wpcaregrid-nginx",
            "label": secret, "pid": 321, "starttime_ticks": 12,
            "kernel_start_ns": 100, "started_ns": 101, "ended_ns": 102,
            "boot_id": "a" * 32, "matrix_sha256": HELPER.PINNED_PACKAGE_FILES["acceptance/matrix.json"],
            "peers": {}, "results": [{"id": secret, "state": secret, "stage": secret, "errno": 1}],
            "fd0": {"state": secret, "errno": 2}, "scm": {}, "scm_socket": {},
        }
        verifier = types.SimpleNamespace(expected_checks=lambda *_args: ({}, []))
        summary, _parsed, status = HELPER._probe_summary(
            "wpcaregrid-nginx-confined.json", __import__("json").dumps(doc).encode(),
            {"profiles": {"wpcaregrid-nginx": {"peer_roles": []}}}, "confined", verifier,
        )
        self.assertEqual(status, "parsed")
        self.assertEqual(summary["profile"], "wpcaregrid-nginx")
        self.assertEqual(summary["label_class"], "unexpected-redacted")
        self.assertEqual(summary["results"][0]["id"], "UNKNOWN_RESULT")
        self.assertEqual(summary["results"][0]["state"], "UNKNOWN")
        self.assertNotIn(secret, __import__("json").dumps(summary))

    def test_stderr_sidecars_are_summarized_independently(self):
        probe_error = b"permission denied"
        launcher_error = b"systemd-run: transient service failed"
        summaries = {
            "wpcaregrid-nginx-confined.stderr": HELPER._stderr_summary(probe_error),
            "wpcaregrid-nginx-confined.stderr.systemd-run": HELPER._stderr_summary(launcher_error),
        }
        self.assertEqual(set(summaries), {
            "wpcaregrid-nginx-confined.stderr",
            "wpcaregrid-nginx-confined.stderr.systemd-run",
        })
        self.assertNotEqual(summaries[next(iter(summaries))]["reason_class"], "")
        self.assertFalse(any("permission denied" in str(value) for value in summaries.values()))
        self.assertFalse(any("transient service failed" in str(value) for value in summaries.values()))

    def test_audit_without_confined_json_reports_only_aggregate_unknown_correlation(self):
        secret = "/private/path/raw-secret"
        records = [{"profile": "wpcaregrid-nginx-probe", "pid": 42, "_ts_ns": 1,
                    "name": secret, "operation": "open"}]
        verifier = types.SimpleNamespace(parse_audit=lambda _text: (records, []))
        report = HELPER._audit_summary(b"synthetic", None, None, {}, verifier)
        self.assertEqual(report["pid_profile_window"]["probe_pid_correlation"], "UNKNOWN_CONFINED_JSON_UNAVAILABLE")
        self.assertEqual(report["pid_profile_window"]["probe_window_correlation"], "UNKNOWN_CONFINED_JSON_UNAVAILABLE")
        self.assertNotIn(secret, __import__("json").dumps(report))
        self.assertNotIn("42", __import__("json").dumps(report))

    def test_progress_pipe_emits_only_fixed_stage_enums(self):
        read_fd, write_fd = os.pipe()
        original_fd = HELPER._PROGRESS_FD
        try:
            HELPER._PROGRESS_FD = write_fd
            HELPER._progress("PIN_LOAD")
            HELPER._progress("/private/path/raw-message")
            os.close(write_fd)
            write_fd = -1
            self.assertEqual(os.read(read_fd, 128), b"PIN_LOAD\n")
        finally:
            HELPER._PROGRESS_FD = original_fd
            os.close(read_fd)
            if write_fd >= 0:
                os.close(write_fd)

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
                    if func.attr == "write":
                        allowed_progress_write = isinstance(func.value, ast.Name) and func.value.id == "os"
                        self.assertTrue(allowed_progress_write, "only the progress-pipe os.write is permitted")
                        parent = parents.get(node)
                        while parent is not None and not isinstance(parent, (ast.FunctionDef, ast.AsyncFunctionDef)):
                            parent = parents.get(parent)
                        self.assertIsNotNone(parent)
                        self.assertEqual(parent.name, "_progress")
                    else:
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

    def test_stderr_report_fields_never_echo_raw_input_or_exception_message(self):
        raw = (b'Traceback (most recent call last):\n'
               b'  File "/root/private/raw-path/probe.py", line 12, in main\n'
               b'PermissionError: raw-exception-message-secret\n')
        summary = HELPER._stderr_summary(raw)
        rendered = __import__("json").dumps(summary, sort_keys=True)
        self.assertEqual(summary["status"], "BOUND")
        self.assertEqual(summary["traceback"]["status"], "PARSED")
        self.assertFalse(summary["raw_text_emitted"])
        for forbidden in ("raw-path", "/root", "private", "raw-exception-message-secret", raw.decode()):
            self.assertNotIn(forbidden, rendered)

    def test_traceback_summary_rejects_unparsed_intermediate_lines(self):
        raw = (b'Traceback (most recent call last):\n'
               b'  File "/safe/probe.py", line 41, in call\n'
               b'not a traceback line\n'
               b'ValueError: secret\n')
        summary = HELPER._traceback_summary(raw)
        self.assertEqual(summary["status"], "UNPARSEABLE")
        self.assertEqual(summary["parse_failure_stage"], "FIRST_HEADER_SUFFIX_INVALID")
        self.assertNotIn("secret", str(summary))

    def test_traceback_summary_accepts_chained_and_syntax_context_structure(self):
        raw = (b'Traceback (most recent call last):\n'
               b'  File "/safe/first.py", line 10, in first\n'
               b'    raise ValueError()\n'
               b'ValueError: hidden-one\n'
               b'\n'
               b'The above exception was the direct cause of the following exception:\n'
               b'\n'
               b'Traceback (most recent call last):\n'
               b'  File "/safe/probe.py", line 427, in main\n'
               b'    bad syntax\n'
               b'    ^\n'
               b'TypeError: hidden-two\n')
        summary = HELPER._traceback_summary(raw)
        self.assertEqual(summary["status"], "PARSED")
        self.assertEqual(summary["exception_class"], "TypeError")
        self.assertEqual(summary["frame_count"], 2)
        self.assertEqual(summary["thrower_basename"], "probe.py")
        self.assertEqual(summary["thrower_line"], 427)
        self.assertNotIn("hidden", str(summary))
        self.assertNotIn("bad syntax", str(summary))

    def test_traceback_summary_rejects_extra_chain_separator_lines(self):
        raw = (b'Traceback (most recent call last):\n'
               b'  File "/safe/first.py", line 10, in first\n'
               b'ValueError: hidden-one\n'
               b'\n'
               b'not a chain marker\n'
               b'\n'
               b'The above exception was the direct cause of the following exception:\n'
               b'\n'
               b'Traceback (most recent call last):\n'
               b'  File "/safe/probe.py", line 427, in main\n'
               b'TypeError: hidden-two\n')
        summary = HELPER._traceback_summary(raw)
        self.assertEqual(summary["status"], "UNPARSEABLE")
        self.assertEqual(summary["parse_failure_stage"], "FIRST_HEADER_SUFFIX_INVALID")

    def test_traceback_summary_rejects_unsupported_multiline_exception_groups(self):
        raw = (b'Traceback (most recent call last):\n'
               b'  File "/safe/probe.py", line 9, in run\n'
               b'ExceptionGroup: grouped\n'
               b'  +-+---------------- 1 ----------------\n')
        summary = HELPER._traceback_summary(raw)
        self.assertEqual(summary["status"], "UNSUPPORTED_EXCEPTION_FORMAT")
        self.assertEqual(summary["parse_failure_stage"], "FIRST_HEADER_SUFFIX_INVALID")
        self.assertEqual(summary["parse_failure_substage"], "UNSUPPORTED_EXCEPTION_FORMAT")

    def test_traceback_summary_fails_closed_for_unbounded_or_malformed_input(self):
        self.assertEqual(HELPER._traceback_summary(None), {"status": "ABSENT_OR_UNKNOWN"})
        self.assertEqual(HELPER._traceback_summary(b""), {"status": "EMPTY"})
        over_bound = HELPER._traceback_summary(b"x" * (HELPER._MAX_TRACEBACK_BYTES + 1))
        self.assertEqual(over_bound["status"], "OVER_BOUND")
        self.assertEqual(over_bound["parse_failure_stage"], "OVER_BOUND")
        malformed = HELPER._traceback_summary(b"Traceback (most recent call last):\nnot a frame\nValueError: x")
        self.assertEqual(malformed["status"], "UNPARSEABLE")
        self.assertEqual(malformed["parse_failure_stage"], "FIRST_HEADER_SUFFIX_INVALID")

    def test_traceback_summary_failure_stage_covers_rejected_grammar_without_echoing_input(self):
        header = b"Traceback (most recent call last):\n"
        frame = b'  File "/root/private/probe.py", line 41, in call\n'
        exception = b"ValueError: raw-exception-message-secret\n"
        chain = b"During handling of the above exception, another exception occurred:"
        valid_first = header + frame + exception
        cases = (
            ("no-header", b"system error: raw-exception-message-secret", "NO_EXACT_TRACEBACK_HEADER", None),
            ("blank-input", b"\n\n", "NO_EXACT_TRACEBACK_HEADER", None),
            ("header-without-frame", header + exception, "FIRST_HEADER_SUFFIX_INVALID", "NO_FRAME_AFTER_HEADER"),
            ("header-eof", header, "FIRST_HEADER_SUFFIX_INVALID", "NO_FRAME_AFTER_HEADER"),
            ("unexpected-after-header", header + b"wrapper raw-prefix-secret\n" + exception,
             "FIRST_HEADER_SUFFIX_INVALID", "UNEXPECTED_LINE_AFTER_HEADER"),
            ("malformed-frame", header + b'  File "/root/private/probe.py", line bad, call\n' + exception,
             "FIRST_HEADER_SUFFIX_INVALID", "MALFORMED_FRAME_LINE"),
            ("malformed-frame-unquoted-path", header + b"  File /root/private/probe.py, line 41, in call\n" + exception,
             "FIRST_HEADER_SUFFIX_INVALID", "MALFORMED_FRAME_LINE"),
            ("missing-exception", header + frame, "FIRST_HEADER_SUFFIX_INVALID", "MISSING_EXCEPTION_SUMMARY"),
            ("malformed-exception", header + frame + b"not an exception raw-secret\n",
             "FIRST_HEADER_SUFFIX_INVALID", "MALFORMED_EXCEPTION_SUMMARY"),
            ("missing-chain-separator", valid_first + b"raw-secret-missing-separator\n" + chain + b"\n" + header + frame + exception,
             "FIRST_HEADER_SUFFIX_INVALID", "EXPECTED_SEPARATOR_AFTER_EXCEPTION"),
            ("extra-before-chain", valid_first + b"\nraw-secret-before-chain\n\n" + chain + b"\n\n" + header + frame + exception,
             "FIRST_HEADER_SUFFIX_INVALID", "EXPECTED_CHAIN_MARKER"),
            ("wrong-chain-marker", valid_first + b"\nnot a marker raw-secret\n\n" + header + frame + exception,
             "FIRST_HEADER_SUFFIX_INVALID", "EXPECTED_CHAIN_MARKER"),
            ("missing-chain-trailing-separator", valid_first + b"\n" + chain + b"\nraw-secret-after-marker\n" + header + frame + exception,
             "FIRST_HEADER_SUFFIX_INVALID", "EXPECTED_SEPARATOR_AFTER_CHAIN_MARKER"),
            ("extra-after-chain", valid_first + b"\n" + chain + b"\n\nraw-secret-after-separator\n" + header + frame + exception,
             "FIRST_HEADER_SUFFIX_INVALID", "EXPECTED_NEXT_TRACEBACK_HEADER"),
            ("wrong-chained-header", valid_first + b"\n" + chain + b"\n\nnot a header raw-secret\n",
             "FIRST_HEADER_SUFFIX_INVALID", "EXPECTED_NEXT_TRACEBACK_HEADER"),
            ("too-many-context-lines", header + frame + b"    source one\n    source two\n    source three\n" + exception,
             "FIRST_HEADER_SUFFIX_INVALID", "SOURCE_CONTEXT_OVER_BOUND"),
        )
        for name, raw, expected_stage, expected_substage in cases:
            with self.subTest(name=name):
                summary = HELPER._traceback_summary(raw)
                self.assertEqual(summary["status"], "UNPARSEABLE")
                self.assertEqual(summary["parse_failure_stage"], expected_stage)
                self.assertEqual(summary["frame_count"], 0)
                if expected_substage is None:
                    self.assertNotIn("parse_failure_substage", summary)
                else:
                    self.assertEqual(summary["parse_failure_substage"], expected_substage)
                rendered = __import__("json").dumps(summary, sort_keys=True)
                self.assertNotIn("raw-exception-message-secret", rendered)
                self.assertNotIn("raw-secret", rendered)
                self.assertNotIn("/root", rendered)
                self.assertNotIn(raw.decode("utf-8"), rendered)

    def test_traceback_summary_accepts_only_complete_chained_suffix_after_prefix(self):
        prefix = b"systemd-run diagnostic prefix raw-prefix-secret\n"
        header = b"Traceback (most recent call last):\n"
        frame_one = b'  File "/root/private/first.py", line 9, in inner\n'
        frame_two = b'  File "/root/private/probe.py", line 27, in main\n'
        chain = b"\nDuring handling of the above exception, another exception occurred:\n\n"
        first = header + frame_one + b"ValueError: raw-inner-message\n"
        final = header + frame_two + b"PermissionError: raw-final-message\n"
        raw = prefix + first + chain + final

        summary = HELPER._traceback_summary(raw)
        self.assertEqual(summary, {
            "status": "PARSED", "exception_class": "PermissionError", "frame_count": 2,
            "thrower_basename": "probe.py", "thrower_function": "main", "thrower_line": 27,
            "parse_failure_stage": "PREFIX_BEFORE_TRACEBACK",
        })
        rendered = __import__("json").dumps(summary, sort_keys=True)
        for forbidden in ("raw-prefix-secret", "raw-inner-message", "raw-final-message", "/root", "private", raw.decode()):
            self.assertNotIn(forbidden, rendered)
        stderr_report = HELPER._stderr_summary(raw)
        stderr_rendered = __import__("json").dumps(stderr_report, sort_keys=True)
        self.assertFalse(stderr_report["raw_text_emitted"])
        self.assertEqual(stderr_report["traceback"], summary)
        for forbidden in ("raw-prefix-secret", "raw-inner-message", "raw-final-message", "/root", raw.decode()):
            self.assertNotIn(forbidden, stderr_rendered)

    def test_generic_error_wrapper_prefix_is_not_exception_summary(self):
        raw = (b"ERROR: raw-launcher-message\n"
               b"Traceback (most recent call last):\n"
               b'  File "/root/private/probe.py", line 12, in run\n'
               b"OSError: raw-exception-message\n")
        summary = HELPER._traceback_summary(raw)
        self.assertEqual(summary["status"], "PARSED")
        self.assertEqual(summary["parse_failure_stage"], "PREFIX_BEFORE_TRACEBACK")
        rendered = __import__("json").dumps(summary, sort_keys=True)
        for forbidden in ("raw-launcher-message", "raw-exception-message", "/root", raw.decode()):
            self.assertNotIn(forbidden, rendered)

    def test_traceback_summary_rejects_malformed_suffix_after_prefix(self):
        raw = (b"wrapper said error: raw-prefix-secret\n"
               b"Traceback (most recent call last):\n"
               b'  File "/root/private/probe.py", line 8, in call\n'
               b"ValueError: raw-message\n"
               b"trailing raw-suffix-secret\n")
        summary = HELPER._traceback_summary(raw)
        self.assertEqual(summary, {
            "status": "UNPARSEABLE", "parse_failure_stage": "FIRST_HEADER_SUFFIX_INVALID",
            "parse_failure_substage": "EXPECTED_SEPARATOR_AFTER_EXCEPTION",
            "frame_count": 0,
        })
        rendered = __import__("json").dumps(summary, sort_keys=True)
        for forbidden in ("raw-prefix-secret", "raw-message", "raw-suffix-secret", "/root", raw.decode()):
            self.assertNotIn(forbidden, rendered)

    def test_traceback_header_text_in_exception_message_is_not_a_header(self):
        raw = b"RuntimeError: Traceback (most recent call last): raw-message-secret\n"
        summary = HELPER._traceback_summary(raw)
        self.assertEqual(summary, {
            "status": "UNPARSEABLE", "parse_failure_stage": "NO_EXACT_TRACEBACK_HEADER",
            "frame_count": 0,
        })
        self.assertNotIn("raw-message-secret", __import__("json").dumps(summary))

        multiline_message = (b"RuntimeError: raw-prefix-message\n"
                             b"Traceback (most recent call last):\n"
                             b'  File "/root/private/probe.py", line 8, in call\n'
                             b"ValueError: raw-suffix-message\n")
        summary = HELPER._traceback_summary(multiline_message)
        self.assertEqual(summary, {
            "status": "UNPARSEABLE", "parse_failure_stage": "PREFIX_EXCEPTION_SUMMARY_BLOCKED_HEADER",
            "frame_count": 0,
        })
        rendered = __import__("json").dumps(summary)
        for forbidden in ("raw-prefix-message", "raw-suffix-message", "/root", multiline_message.decode()):
            self.assertNotIn(forbidden, rendered)

        class_only_message = (b"RuntimeError\n"
                              b"Traceback (most recent call last):\n"
                              b'  File "/root/private/probe.py", line 8, in call\n'
                              b"ValueError: raw-suffix-message\n")
        summary = HELPER._traceback_summary(class_only_message)
        self.assertEqual(summary, {
            "status": "UNPARSEABLE", "parse_failure_stage": "PREFIX_EXCEPTION_SUMMARY_BLOCKED_HEADER",
            "frame_count": 0,
        })
        rendered = __import__("json").dumps(summary)
        for forbidden in ("RuntimeError", "raw-suffix-message", "/root", class_only_message.decode()):
            self.assertNotIn(forbidden, rendered)

    def test_traceback_summary_rejects_multiple_unrelated_headers(self):
        header = b"Traceback (most recent call last):\n"
        frame = b'  File "/root/private/probe.py", line 8, in call\n'
        raw = (b"wrapper prefix raw-prefix-secret\n" + header + frame + b"ValueError: raw-one\n"
               + header + frame + b"TypeError: raw-two\n")
        summary = HELPER._traceback_summary(raw)
        self.assertEqual(summary, {
            "status": "UNPARSEABLE", "parse_failure_stage": "FIRST_HEADER_SUFFIX_INVALID",
            "parse_failure_substage": "EXPECTED_SEPARATOR_AFTER_EXCEPTION",
            "frame_count": 0,
        })
        rendered = __import__("json").dumps(summary, sort_keys=True)
        for forbidden in ("raw-prefix-secret", "raw-one", "raw-two", "/root", raw.decode()):
            self.assertNotIn(forbidden, rendered)

    def test_traceback_summary_failure_stages_cover_encoding_and_frame_bounds(self):
        invalid_encoding = HELPER._traceback_summary(b"\xffraw-secret")
        self.assertEqual(invalid_encoding, {
            "status": "UNPARSEABLE", "parse_failure_stage": "INVALID_ENCODING", "frame_count": 0,
        })
        frame = b'  File "/safe/probe.py", line 1, in f\n'
        too_many_frames = HELPER._traceback_summary(
            b"Traceback (most recent call last):\n" + frame * (HELPER._MAX_TRACEBACK_FRAMES + 1)
        )
        self.assertEqual(too_many_frames, {
            "status": "OVER_FRAME_BOUND", "parse_failure_stage": "FIRST_HEADER_SUFFIX_INVALID",
            "parse_failure_substage": "OVER_FRAME_BOUND",
            "frame_count": 0,
        })
        for summary in (invalid_encoding, too_many_frames):
            self.assertNotIn("raw-secret", str(summary))
            self.assertNotIn("Traceback", str(summary))

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
