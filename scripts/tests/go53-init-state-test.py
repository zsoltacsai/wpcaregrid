#!/usr/bin/env python3
"""Regression tests for the GO-5.3 initialization-state diagnostic (GO-20.35).

Covers the separation of the one-time initialization section from later startup
events in the shared error log:
  - [ERROR] lines before the initialization end marker still block (UNCLEAR);
  - [ERROR] lines after it (a later startup attempt) are reported as POST facts
    and do not downgrade a proven-complete initialization;
  - datadir mtimes newer than the end-marker timestamp are reported as
    post-initialization touch evidence;
  - duplicated/unordered/overlapping markers and unparseable marker timestamps
    stay UNCLEAR;
  - every pre-existing check (ownership, modes, symlinks, processes, runtime
    markers, required entries, walk limits) is preserved;
  - no log content (in particular the generated root password) ever appears in
    facts, reasons or report output.

Hermetic: synthetic datadirs and logs under a temp root with injected uid/gid;
no host state is read; no root required (classify() itself does not check EUID).
"""
import importlib.util
import os
import sys
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TOOL = ROOT / "scripts" / "production-g1-ops" / "go53-init-state.py"

_spec = importlib.util.spec_from_file_location("go53_init_state_tested", TOOL)
go53 = importlib.util.module_from_spec(_spec)
sys.modules["go53_init_state_tested"] = go53
_spec.loader.exec_module(go53)

UID, GID = os.getuid(), os.getgid()

T_START = "2026-11-09T20:27:08.100000Z"
T_END = "2026-11-09T20:34:16.200000Z"
T_POST = "2026-11-12T15:53:08.300000Z"
EPOCH_START = datetime.fromisoformat(T_START.replace("Z", "+00:00")).timestamp()
EPOCH_END = datetime.fromisoformat(T_END.replace("Z", "+00:00")).timestamp()


def line(ts, body):
    return f"{ts} {body}\n".encode()


INIT_SECTION = (
    line(T_START, "[Server] [MY-015017] [Server] MySQL Server Initialization - start.") +
    line("2026-11-09T20:27:09.000000Z", "[InnoDB] [MY-013576] [InnoDB] InnoDB initialization has started.") +
    line("2026-11-09T20:34:15.000000Z", "[InnoDB] [MY-013577] [InnoDB] InnoDB initialization has ended.") +
    line(T_END, "[Server] [MY-015018] [Server] MySQL Server Initialization - end.")
)
POST_STARTUP_ERRORS = (
    line(T_POST, "[ERROR] [MY-012345] [Server] fake later startup failure one") +
    line(T_POST, "[ERROR] [MY-012346] [Server] fake later startup failure two") +
    line(T_POST, "[ERROR] [MY-012347] [Server] fake later startup failure three")
)
REQUIRED_DIRS = ("mysql", "sys", "performance_schema", "#innodb_redo")
REQUIRED_FILES = ("auto.cnf", "ibdata1", "mysql.ibd", "undo_001", "undo_002", "mysql_upgrade_history")


class Sandbox:
    """One synthetic instance per test: datadir + log + fake /proc under a temp root."""

    def __init__(self, *, name="wpcaregrid-production", with_entries=True, log=b"", log_mode=0o600, datadir_mode=0o750):
        self.base = tempfile.TemporaryDirectory(prefix="go53-test-")
        self.root = Path(self.base.name)
        (self.root / "proc").mkdir()
        self.name = name
        datadir = self.root / "var/lib" / name
        datadir.mkdir(parents=True)
        os.chmod(datadir, datadir_mode)
        if with_entries:
            for d in REQUIRED_DIRS:
                (datadir / d).mkdir(mode=0o750)
                os.chmod(datadir / d, 0o750)
            for f in REQUIRED_FILES:
                (datadir / f).write_bytes(b"synthetic")
                os.chmod(datadir / f, 0o640)
        log_path = self.root / "var/log" / f"{name}.log"
        log_path.parent.mkdir(parents=True, exist_ok=True)
        log_path.write_bytes(log)
        os.chmod(log_path, log_mode)
        os.utime(log_path, (EPOCH_END, EPOCH_END))
        self.spec = {
            "datadir": f"/var/lib/{name}",
            "log": f"/var/log/{name}.log",
            "socket": f"/run/mysqld/{name}.sock",
            "pid": f"/run/mysqld/{name}.pid",
            "tmpdir": f"/var/tmp/{name}",
            "unit": f"mysql@{name}.service",
        }
        self.set_mtimes(EPOCH_END - 30)

    def set_mtimes(self, epoch):
        datadir = self.root / self.spec["datadir"].lstrip("/")
        for d in REQUIRED_DIRS:
            path = datadir / d
            if path.exists():
                os.utime(path, (epoch, epoch))
        for f in REQUIRED_FILES:
            path = datadir / f
            if path.exists():
                os.utime(path, (epoch, epoch))
        os.utime(datadir, (epoch, epoch))

    def classify(self, ids=lambda: (UID, GID)):
        return go53.classify(self.name, self.spec, root=str(self.root),
                             proc_root=str(self.root / "proc"), ids=ids)

    def close(self):
        self.base.cleanup()


class InitStateTests(unittest.TestCase):
    def setUp(self):
        self.sandboxes = []

    def tearDown(self):
        for box in self.sandboxes:
            box.close()

    def sandbox(self, **kwargs):
        box = Sandbox(**kwargs)
        self.sandboxes.append(box)
        return box

    def test_empty_datadir_classifies_empty(self):
        box = self.sandbox(with_entries=False, log=b"")
        state, reasons, facts = box.classify()
        self.assertEqual(state, "EMPTY")
        self.assertEqual(reasons, [])

    def test_proven_complete_classic_without_post_events(self):
        box = self.sandbox(log=INIT_SECTION)
        state, reasons, facts = box.classify()
        self.assertEqual(state, "PROVEN_COMPLETE")
        self.assertEqual(reasons, [])
        self.assertEqual(facts["errors"], 0)
        self.assertEqual(facts["post_errors"], 0)
        self.assertEqual(facts["post_touched"], "no")
        self.assertEqual(facts["end_ts"], T_END)

    def test_go2035_scenario_later_startup_errors_do_not_downgrade(self):
        # The exact GO-20.35 production case: a complete initialization section
        # followed by three [ERROR] lines from a separate, later startup attempt.
        # The old whole-log error count misclassified this as UNCLEAR.
        box = self.sandbox(log=INIT_SECTION + POST_STARTUP_ERRORS)
        box.set_mtimes(EPOCH_END - 30)
        state, reasons, facts = box.classify()
        self.assertEqual(state, "PROVEN_COMPLETE")
        self.assertEqual(reasons, [])
        self.assertEqual(facts["errors"], 0)
        self.assertEqual(facts["post_errors"], 3)
        self.assertEqual(facts["post_touched"], "no")

    def test_post_initialization_touch_is_reported_not_downgrading(self):
        box = self.sandbox(log=INIT_SECTION + POST_STARTUP_ERRORS)
        datadir = box.root / box.spec["datadir"].lstrip("/")
        touched = EPOCH_END + 3600
        os.utime(datadir / "#innodb_redo", (touched, touched))
        state, reasons, facts = box.classify()
        self.assertEqual(state, "PROVEN_COMPLETE")
        self.assertEqual(facts["post_touched"], "yes")
        self.assertTrue(facts["post_newest_mtime"].startswith("2026-11-09T21:"))

    def test_touch_grace_window_tolerates_finalize_writes(self):
        # Files written within the grace window after the end marker count as
        # initialization-finalization writes, not as later touches.
        box = self.sandbox(log=INIT_SECTION)
        box.set_mtimes(EPOCH_END + 10)
        state, reasons, facts = box.classify()
        self.assertEqual(state, "PROVEN_COMPLETE")
        self.assertEqual(facts["post_touched"], "no")

    def test_error_inside_initialization_section_blocks(self):
        inside = line("2026-11-09T20:30:00.000000Z", "[ERROR] [MY-000001] init-phase failure")
        box = self.sandbox(log=INIT_SECTION[:120] + inside + INIT_SECTION[120:])
        state, reasons, facts = box.classify()
        self.assertEqual(state, "UNCLEAR")
        self.assertIn("initialization_error_lines", reasons)
        self.assertEqual(facts["errors"], 1)
        self.assertEqual(facts["post_errors"], 0)

    def test_error_before_start_marker_blocks(self):
        pre = line("2026-11-09T20:20:00.000000Z", "[ERROR] [MY-000002] pre-init failure")
        box = self.sandbox(log=pre + INIT_SECTION + POST_STARTUP_ERRORS)
        state, reasons, facts = box.classify()
        self.assertEqual(state, "UNCLEAR")
        self.assertIn("initialization_error_lines", reasons)
        self.assertEqual(facts["post_errors"], 3)

    def test_interrupted_initialize_is_partial(self):
        log = INIT_SECTION[:INIT_SECTION.index(b"[MY-015018]")].rstrip(b"\n") + b"\n"
        box = self.sandbox(log=log)
        state, reasons, facts = box.classify()
        self.assertEqual(state, "PARTIAL")
        self.assertIn("log_end_marker_missing", reasons)

    def test_interrupted_initialize_with_errors_counts_all_as_init_scope(self):
        log = (INIT_SECTION[:INIT_SECTION.index(b"[MY-015018]")].rstrip(b"\n") + b"\n" +
               line("2026-11-09T20:40:00.000000Z", "[ERROR] [MY-000003] aborted run failure"))
        box = self.sandbox(log=log)
        state, reasons, facts = box.classify()
        self.assertEqual(state, "PARTIAL")
        self.assertEqual(facts["errors"], 1)
        self.assertEqual(facts["post_errors"], 0)

    def test_multiple_start_markers_is_unclear(self):
        box = self.sandbox(log=INIT_SECTION + b"\n" + INIT_SECTION)
        state, reasons, facts = box.classify()
        self.assertEqual(state, "UNCLEAR")
        self.assertIn("marker_sequence_invalid:multiple_markers", reasons)

    def test_end_without_start_is_unclear(self):
        log = line(T_END, "[Server] [MY-015018] [Server] MySQL Server Initialization - end.")
        box = self.sandbox(log=log)
        state, reasons, facts = box.classify()
        self.assertEqual(state, "UNCLEAR")
        self.assertIn("marker_sequence_invalid:end_without_start", reasons)

    def test_end_before_start_is_unclear(self):
        log = (line(T_END, "[Server] [MY-015018] [Server] MySQL Server Initialization - end.") +
               line(T_START, "[Server] [MY-015017] [Server] MySQL Server Initialization - start."))
        box = self.sandbox(log=log)
        state, reasons, facts = box.classify()
        self.assertEqual(state, "UNCLEAR")
        self.assertIn("marker_sequence_invalid:end_before_start", reasons)

    def test_unparseable_marker_timestamp_is_unclear(self):
        log = (b"[Server] [MY-015017] [Server] MySQL Server Initialization - start.\n" +
               b"[Server] [MY-015018] [Server] MySQL Server Initialization - end.\n")
        box = self.sandbox(log=log)
        state, reasons, facts = box.classify()
        self.assertEqual(state, "UNCLEAR")
        self.assertIn("marker_timestamp_unparseable", reasons)

    def test_end_marker_with_missing_required_entries_is_unclear(self):
        box = self.sandbox(log=INIT_SECTION)
        (box.root / box.spec["datadir"].lstrip("/") / "mysql_upgrade_history").unlink()
        state, reasons, facts = box.classify()
        self.assertEqual(state, "UNCLEAR")
        self.assertIn("end_marker_but_required_entries_missing", reasons)

    def test_runtime_marker_present_is_unclear(self):
        box = self.sandbox(log=INIT_SECTION)
        socket_path = box.root / box.spec["socket"].lstrip("/")
        socket_path.parent.mkdir(parents=True, exist_ok=True)
        socket_path.write_bytes(b"marker")
        state, reasons, facts = box.classify()
        self.assertEqual(state, "UNCLEAR")
        self.assertIn("runtime_marker_present", reasons)

    def test_symlink_in_datadir_is_unclear(self):
        box = self.sandbox(log=INIT_SECTION)
        os.symlink("auto.cnf", box.root / box.spec["datadir"].lstrip("/") / "strange-link")
        state, reasons, facts = box.classify()
        self.assertEqual(state, "UNCLEAR")
        self.assertIn("symlink_in_datadir", reasons)

    def test_foreign_group_in_datadir_is_unclear(self):
        box = self.sandbox(log=INIT_SECTION)
        state, reasons, facts = box.classify(ids=lambda: (UID, GID + 1))
        self.assertEqual(state, "UNCLEAR")
        self.assertIn("foreign_group_in_datadir", reasons)

    def test_wrong_log_mode_is_unclear(self):
        box = self.sandbox(log=INIT_SECTION, log_mode=0o644)
        state, reasons, facts = box.classify()
        self.assertEqual(state, "UNCLEAR")
        self.assertIn("log_type_owner_or_mode", reasons)

    def test_empty_datadir_with_nonempty_log_is_unclear(self):
        box = self.sandbox(with_entries=False, log=INIT_SECTION)
        state, reasons, facts = box.classify()
        self.assertEqual(state, "UNCLEAR")
        self.assertIn("datadir_empty_but_log_not_empty", reasons)

    def test_log_beyond_limit_reports_truncation_but_keeps_the_proof(self):
        filler = b"2026-11-10T00:00:00.000000Z [System] filler line without markers\n" * 45000
        box = self.sandbox(log=INIT_SECTION + POST_STARTUP_ERRORS + filler)
        state, reasons, facts = box.classify()
        self.assertEqual(state, "PROVEN_COMPLETE")
        self.assertTrue(facts["log_truncated"])
        self.assertEqual(facts["post_errors"], 3)

    def test_walk_limit_returns_structured_unclear_not_crash(self):
        # Round-2 fix (QA B-1): the WALK_LIMIT early return must keep the
        # (size, newest) tuple contract so an oversized datadir yields the
        # structured UNCLEAR reason instead of a TypeError.
        box = self.sandbox(log=INIT_SECTION)
        saved = go53.WALK_LIMIT
        try:
            go53.WALK_LIMIT = 3
            state, reasons, facts = box.classify()
        finally:
            go53.WALK_LIMIT = saved
        self.assertEqual(state, "UNCLEAR")
        self.assertIn("datadir_too_large_to_scan", reasons)

    def test_end_timestamp_before_start_timestamp_is_unclear(self):
        # Round-2/3 fix (AppSec F-1): a byte-ordered but chronologically
        # reversed marker pair contradicts the event sequence. The reversed
        # END timestamp must neither back a PROVEN_COMPLETE nor drive the
        # POST_INIT touch evidence (end_ts/touched/newest_mtime stay "-"),
        # in facts AND in the report output.
        for end_ts in ("2026-11-09T20:00:00.000000Z", "2026-11-09T20:27:08.099999Z"):
            with self.subTest(end_ts=end_ts):
                log = (line("2026-11-09T20:27:08.100000Z", "[Server] [MY-015017] [Server] MySQL Server Initialization - start.") +
                       line(end_ts, "[Server] [MY-015018] [Server] MySQL Server Initialization - end."))
                box = self.sandbox(log=log)
                state, reasons, facts = box.classify()
                self.assertEqual(state, "UNCLEAR")
                self.assertIn("marker_sequence_invalid:end_timestamp_before_start_timestamp", reasons)
                self.assertEqual(facts["end_ts"], "-")
                self.assertEqual(facts["post_touched"], "-")
                self.assertEqual(facts["post_newest_mtime"], "-")
                collected = []
                go53.report({box.name: (state, reasons)}, {box.name: facts}, {}, out=collected.append)
                self.assertIn("POST_INIT end_ts=- touched=- newest_mtime=- truncated=no", "\n".join(collected))

    def test_error_token_after_end_token_on_the_same_line_is_post_init(self):
        end_line = (T_END.encode() + b" [Server] [MY-015018] [Server] MySQL Server Initialization - end."
                    b" [ERROR] [MY-000004] same-line trailing error\n")
        box = self.sandbox(log=INIT_SECTION.replace(
            line(T_END, "[Server] [MY-015018] [Server] MySQL Server Initialization - end."), end_line))
        state, reasons, facts = box.classify()
        self.assertEqual(state, "PROVEN_COMPLETE")
        self.assertEqual(facts["errors"], 0)
        self.assertEqual(facts["post_errors"], 1)

    def test_end_marker_as_last_line_without_trailing_newline(self):
        box = self.sandbox(log=INIT_SECTION[:-1])
        state, reasons, facts = box.classify()
        self.assertEqual(state, "PROVEN_COMPLETE")

    def test_instance_process_present_is_unclear(self):
        box = self.sandbox(log=INIT_SECTION)
        fake_pid = box.root / "proc" / "4242"
        fake_pid.mkdir()
        (fake_pid / "stat").write_text(
            "4242 (mysqld) S 1 1 1 0 -1 4194304 100 0 0 0 1 2 3 4 5 6 7 0 0 20 0 1 0 1000 2000 3000 4000 0 0 0 0 0 0 0 0 0 0 0 0 0 0\n")
        (fake_pid / "cmdline").write_bytes(
            b"/usr/sbin/mysqld\0--defaults-group-suffix=@wpcaregrid-production\0")
        state, reasons, facts = box.classify()
        self.assertEqual(state, "UNCLEAR")
        self.assertIn("instance_process_present", reasons)

    def test_generated_password_never_appears_in_facts_reasons_or_output(self):
        secret = "SECRETPW123"
        password_line = line("2026-11-09T20:34:10.000000Z",
                             f"[Note] [MY-010454] [Server] A temporary password is generated for root@localhost: {secret}")
        box = self.sandbox(log=password_line + INIT_SECTION + POST_STARTUP_ERRORS)
        state, reasons, facts = box.classify()
        self.assertEqual(state, "PROVEN_COMPLETE")
        blob = repr(facts) + repr(reasons)
        self.assertNotIn(secret, blob)
        collected = []
        go53.report({box.name: (state, reasons)}, {box.name: facts}, {}, out=collected.append)
        self.assertNotIn(secret, "\n".join(collected))
        for line_text in collected:
            self.assertNotIn("temporary password", line_text)

    def test_report_and_final_line_format(self):
        box = self.sandbox(log=INIT_SECTION + POST_STARTUP_ERRORS)
        box.set_mtimes(EPOCH_END - 30)
        # The restore instance lives under the same synthetic root:
        restore_datadir = box.root / "var/lib/wpcaregrid-production-restore"
        restore_datadir.mkdir()
        os.chmod(restore_datadir, 0o750)
        os.utime(restore_datadir, (EPOCH_END, EPOCH_END))
        restore_log = box.root / "var/log/wpcaregrid-production-restore.log"
        restore_log.write_bytes(b"")
        os.chmod(restore_log, 0o600)
        restore_spec = {
            "datadir": "/var/lib/wpcaregrid-production-restore",
            "log": "/var/log/wpcaregrid-production-restore.log",
            "socket": "/run/mysqld/wpcaregrid-production-restore.sock",
            "pid": "/run/mysqld/wpcaregrid-production-restore.pid",
            "tmpdir": "/var/tmp/wpcaregrid-production-restore",
            "unit": "mysql@wpcaregrid-production-restore.service",
        }
        saved = dict(go53.INSTANCES)
        try:
            go53.INSTANCES = {box.name: box.spec, "wpcaregrid-production-restore": restore_spec}
            collected = []
            go53.main(root=str(box.root), proc_root=str(box.root / "proc"),
                      ids=lambda: (UID, GID), euid=0,
                      run=lambda args: (0, ""), out=collected.append)
        finally:
            go53.INSTANCES = saved
        text = "\n".join(collected)
        self.assertIn("POST_INIT end_ts=2026-11-09T20:34:16.200000Z touched=no", text)
        self.assertIn("init_errors=0 post_errors=3", text)
        self.assertIn("KERNEL_DENIALS=distinct:0;total:0", text)
        self.assertIn("G1_GO53_INIT_STATE=DONE;WPCAREGRID_PRODUCTION=PROVEN_COMPLETE;"
                      "WPCAREGRID_PRODUCTION_RESTORE=EMPTY", text)
        # The restore instance (no ordered section) reports the POST defaults.
        self.assertIn("POST_INIT end_ts=- touched=- newest_mtime=- truncated=no", text)


if __name__ == "__main__":
    unittest.main(verbosity=2)
