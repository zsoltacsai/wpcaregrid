#!/usr/bin/env python3
"""Fake-only tests for the inert Gate 1 root transition adapter."""

import hashlib
import importlib.util
import errno
import json
import sys
import tempfile
import threading
import unittest
from contextlib import contextmanager
from pathlib import Path
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location(
    "wpcaregrid_deploy_priv", ROOT / "scripts" / "wpcaregrid-deploy-priv.py"
)
sys.dont_write_bytecode = True
HELPER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(HELPER)

BOOT = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"
OTHER_BOOT = "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb"
SHA = "1" * 40
EVIDENCE = "2" * 64


class FakeLock:
    def __init__(self):
        self.condition = threading.Condition()
        self.readers = 0
        self.writer = False
        self.events = []
        self.inode = 1
        self.replace_on_exit = False
        self.fail_next = False

    @contextmanager
    def hold(self, mode):
        if self.fail_next:
            self.fail_next = False
            raise HELPER.TransitionHold("injected lock loss")
        with self.condition:
            if mode == "shared":
                self.condition.wait_for(lambda: not self.writer)
                self.readers += 1
            else:
                self.condition.wait_for(lambda: not self.writer and self.readers == 0)
                self.writer = True
            self.events.append(("acquire", mode))
            held_inode = self.inode
        try:
            yield None
        finally:
            with self.condition:
                if self.replace_on_exit:
                    self.inode += 1
                    self.replace_on_exit = False
                self.events.append(("release", mode))
                if mode == "shared":
                    self.readers -= 1
                else:
                    self.writer = False
                self.condition.notify_all()
            if self.inode != held_inode:
                raise HELPER.TransitionHold("injected lock inode replacement")


class FakeDatabase:
    def __init__(self, initial):
        self.tuple = dict(initial)
        self.manifest = None
        self.permits = []
        self.nonces = {}
        self.lock = threading.Lock()
        self.events = []
        self.active_connector_jobs = 0
        self.active_backup_operations = 0
        self.active_or_unknown_effects = 0

    def read_tuple(self):
        with self.lock:
            return dict(self.tuple)

    def compare_and_set(self, source, target, *, release=None, details=None):
        with self.lock:
            self.events.append("database_cas")
            source_tuple = {key: source[key] for key in HELPER.TRANSITION_TUPLE_FIELDS}
            if {key: self.tuple[key] for key in HELPER.TRANSITION_TUPLE_FIELDS} != source_tuple:
                raise HELPER.TransitionHold("fake CAS source mismatch")
            if release is not None:
                nonce_digest = release["nonce_digest"]
                if nonce_digest in self.nonces:
                    raise HELPER.TransitionHold("fake nonce replay")
                self.nonces[nonce_digest] = dict(release)
            self.tuple = dict(target)
            self.manifest = target.get("permit_manifest_hash")

    def validate_permit_manifest(self, source, permits, digest):
        if len({item.get("id") for item in permits}) != len(permits):
            raise HELPER.TransitionHold("duplicate fake permit")
        target_operation = source["operation_id"]
        target_generation = source["generation"]
        payload = {"operation_id": target_operation, "generation": target_generation,
                   "cutoff_seq": source["journal_seq"], "permits": permits}
        if hashlib.sha256(HELPER._transition_canonical(payload)).hexdigest() != digest:
            raise HELPER.TransitionHold("manifest hash mismatch")
        self.permits = json.loads(json.dumps(permits))

    def assert_exact_permit(self, current, binding):
        match = [item for item in self.permits if item == binding]
        if not match or current.get("permit_manifest_hash") != self.manifest:
            raise HELPER.TransitionHold("permit is absent or mismatched")

    def nonce_consumed(self, nonce):
        return nonce in self.nonces

    def read_permit_manifest_hash(self):
        return self.manifest

    def validate_release(self, authorization, source, nonce_digest):
        if not nonce_digest or authorization["expected_journal_seq"] != source["journal_seq"] + 1:
            raise HELPER.TransitionHold("invalid fake release")

    def verify_transition_preconditions(self, source, target, details, permits):
        target_state = target["state"]
        self.events.append(("preconditions", source["state"], target_state))
        if target_state in {"HOLD_REQUESTED", "DRAINING", "QUIESCED"} and (
            self.active_connector_jobs or self.active_backup_operations or self.active_or_unknown_effects
        ):
            raise HELPER.TransitionHold("fake active work blocks transition")
        if target_state == "QUIESCED":
            for field in ("settlement_digest", "pending_digest"):
                if not isinstance(details.get(field), str) or len(details[field]) != 64:
                    raise HELPER.TransitionHold("fake quiescence evidence is incomplete")
        if target_state == "READY_PENDING_OWNER":
            required = {
                "php_profile": "8.4", "ci_verified": True, "release_receipt_verified": True,
                "backup_verified": True, "rollback_verified": True, "health_verified": True,
                "worker_scheduler_verified": True,
            }
            if any(details.get(key) != value for key, value in required.items()):
                raise HELPER.TransitionHold("fake readiness evidence is incomplete")


class TransitionAdapterTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="wpcg-transition-")
        root = Path(self.temp.name)
        self.journal = root / "var/lib/transitions/journal.jsonl"
        self.status = root / "run/wpcaregrid/transition-status.json"
        self.pending = root / "run/wpcaregrid/.transition-status.next"
        self.status.parent.mkdir(parents=True)
        self.lock = FakeLock()
        self.initial = {
            "state": "OPEN",
            "operation_id": "00000000-0000-7000-8000-000000000001",
            "generation": 0,
            "boot_id": BOOT,
            "journal_seq": 0,
            "journal_head_hash": HELPER.TRANSITION_GENESIS_HASH,
            "evidence_digest": EVIDENCE,
        }
        self.status.write_bytes(HELPER._transition_canonical(self.initial))
        self.db = FakeDatabase(self.initial)
        self.fault_at = None
        self.adapter = self.make_adapter()

    def tearDown(self):
        self.temp.cleanup()

    def make_adapter(self, *, boot=BOOT):
        adapter = HELPER.RootTransitionAdapter(
            journal_path=self.journal,
            status_path=self.status,
            pending_path=self.pending,
            lock=self.lock,
            database=self.db,
            boot_id=boot,
            host_id="staging-test-host",
            require_root_owned=False,
            fault=self.inject,
        )
        adapter._fsync_dir = self.fake_directory_fsync
        adapter.journal.fsync_dir = adapter._fsync_dir
        return adapter

    def fake_directory_fsync(self, path):
        if not Path(path).is_dir():
            raise OSError("directory is unavailable")

    def inject(self, point):
        if point == self.fault_at:
            raise RuntimeError("injected crash at " + point)

    def move(self, state, **kwargs):
        args = dict(source_sha=SHA, target_sha="3" * 40, evidence_digest=EVIDENCE)
        args.update(kwargs)
        return self.adapter.transition(state, **args)

    def read_state(self):
        return json.loads(self.status.read_text(encoding="ascii"))["state"]

    def test_transition_and_hash_chain_are_canonical_and_monotonic(self):
        first = self.move("HOLD_REQUESTED", details={"reason": "planned"})
        self.assertEqual(first["generation"], 1)
        self.assertEqual(first["journal_seq"], 1)
        self.assertRegex(first["operation_id"], r"^[0-9a-f-]{36}$")
        self.assertEqual(self.read_state(), "HOLD_REQUESTED")
        self.move("CLOSING")
        self.move("CLOSED")
        records = self.adapter.journal.records()
        self.assertEqual([record["seq"] for record in records], [1, 2, 3])
        self.assertEqual(records[1]["prior_hash"], records[0]["record_hash"])
        self.assertEqual(records[2]["prior_hash"], records[1]["record_hash"])
        raw = self.journal.read_bytes()
        self.assertTrue(all(line.endswith(b"\n") for line in raw.splitlines(keepends=True)))

    def test_illegal_transition_and_secret_like_detail_fail_before_marker(self):
        with self.assertRaises(HELPER.TransitionHold):
            self.move("CLOSED")
        with self.assertRaises(ValueError):
            self.move("HOLD_REQUESTED", details={"api_token": "not-persisted"})
        self.assertFalse(self.pending.exists())
        self.assertFalse(self.journal.exists())

    def test_open_and_draining_requires_exact_state_and_permit(self):
        self.assertEqual(self.adapter.assert_open()["state"], "OPEN")
        with self.assertRaises(HELPER.TransitionHold):
            self.adapter.assert_drain_permit({"id": "x"})
        self.move("HOLD_REQUESTED")
        self.move("CLOSING")
        self.move("CLOSED")
        permit = [{"id": "p1", "work_id": "w1", "payload_hash": "4" * 64}]
        with self.assertRaises(HELPER.TransitionHold):
            self.adapter.assert_drain_permit(permit[0])
        self.move("DRAINING", permits=permit)
        self.assertEqual(self.adapter.assert_drain_permit(permit[0])["state"], "DRAINING")
        with self.assertRaises(HELPER.TransitionHold):
            self.adapter.assert_drain_permit({"id": "p2", "work_id": "w2", "payload_hash": "5" * 64})

    def test_connector_and_backup_work_never_receive_drain_permits(self):
        for state in ("HOLD_REQUESTED", "CLOSING", "CLOSED"):
            self.move(state)
        for domain in ("CONNECTOR", "BACKUP", "RESTORE"):
            with self.subTest(domain=domain):
                with self.assertRaises(HELPER.TransitionHold):
                    self.move("DRAINING", permits=[{"id": "p1", "domain": domain}])
                self.assertEqual(self.read_state(), "CLOSED")
                self.assertFalse(self.pending.exists())

    def test_partial_marker_and_all_pre_rename_crashes_fail_closed(self):
        points = [
            "before_marker_create", "marker_created", "marker_partial_write", "marker_written", "before_marker_file_fsync",
            "marker_file_fsync", "before_marker_directory_fsync", "marker_directory_fsync",
            "before_journal_append", "journal_appended", "before_journal_fsync", "journal_fsync",
            "before_journal_directory_fsync", "journal_directory_fsync",
            "before_database_commit", "database_commit", "before_projection_rename",
            "projection_rename", "before_projection_directory_fsync", "projection_directory_fsync",
        ]
        for point in points:
            with self.subTest(point=point):
                self.tearDown()
                self.setUp()
                try:
                    self.fault_at = point
                    with self.assertRaises(RuntimeError):
                        self.move("HOLD_REQUESTED")
                    if point == "before_marker_create":
                        self.assertFalse(self.pending.exists())
                        self.assertEqual(self.adapter.journal.head()[0], 0)
                        self.assertEqual(self.db.read_tuple()["state"], "OPEN")
                        continue
                    if points.index(point) >= points.index("projection_rename"):
                        self.assertFalse(self.pending.exists())
                        self.assertEqual(self.read_state(), "HOLD_REQUESTED")
                    else:
                        self.assertTrue(self.pending.exists())
                    with self.assertRaises(HELPER.TransitionHold):
                        self.adapter.assert_open()
                    if point in {"before_marker_create", "marker_created", "marker_partial_write",
                                 "marker_written", "before_marker_file_fsync", "marker_file_fsync",
                                 "before_marker_directory_fsync", "marker_directory_fsync",
                                 "before_journal_append"}:
                        self.assertEqual(self.adapter.journal.head()[0], 0)
                        self.assertEqual(self.db.read_tuple()["state"], "OPEN")
                    elif point in {"journal_appended", "before_journal_fsync", "journal_fsync",
                                   "before_journal_directory_fsync", "journal_directory_fsync",
                                   "before_database_commit"}:
                        self.assertEqual(self.adapter.journal.head()[0], 1)
                        self.assertEqual(self.db.read_tuple()["state"], "OPEN")
                    elif points.index(point) < points.index("projection_rename"):
                        self.assertEqual(self.db.read_tuple()["state"], "HOLD_REQUESTED")
                    else:
                        self.assertEqual(self.db.read_tuple()["state"], "HOLD_REQUESTED")
                finally:
                    self.tearDown()

    def test_rename_is_commit_point_and_directory_fsync_failure_is_not_rollback(self):
        self.fault_at = "projection_rename"
        with self.assertRaises(RuntimeError):
            self.move("HOLD_REQUESTED")
        self.assertFalse(self.pending.exists())
        self.assertEqual(self.read_state(), "HOLD_REQUESTED")
        self.assertEqual(self.db.read_tuple()["state"], "HOLD_REQUESTED")

        self.tearDown()
        self.setUp()
        real_fsync_dir = self.adapter._fsync_dir
        directory_calls = 0

        def fail_final_directory(path):
            nonlocal directory_calls
            directory_calls += 1
            if directory_calls == 2:
                raise OSError("simulated directory fsync failure")
            return real_fsync_dir(path)

        self.adapter._fsync_dir = fail_final_directory
        with self.assertRaises(HELPER.TransitionDurabilityUnconfirmed):
            self.move("HOLD_REQUESTED")
        self.assertEqual(self.read_state(), "HOLD_REQUESTED")
        self.assertEqual(self.db.read_tuple()["state"], "HOLD_REQUESTED")
        self.assertFalse(self.pending.exists())

    def test_marker_file_and_directory_fsync_errors_leave_marker(self):
        for fail_on in (1, 2):
            with self.subTest(fail_on=fail_on):
                self.tearDown()
                self.setUp()
                if fail_on == 1:
                    with mock.patch.object(HELPER.os, "fsync", side_effect=OSError("simulated file fsync error")):
                        with self.assertRaises(OSError):
                            self.move("HOLD_REQUESTED")
                else:
                    self.adapter._fsync_dir = mock.Mock(side_effect=OSError("simulated marker directory fsync error"))
                    with self.assertRaises(OSError):
                        self.move("HOLD_REQUESTED")
                self.assertTrue(self.pending.exists())
                with self.assertRaises(HELPER.TransitionHold):
                    self.adapter.assert_open()

    def test_journal_fsync_rename_and_enospc_failures_remain_closed(self):
        real_fsync = HELPER.os.fsync
        calls = 0

        def fail_journal_fsync(fd):
            nonlocal calls
            calls += 1
            if calls == 2:
                raise OSError("simulated journal fsync error")
            return real_fsync(fd)

        with mock.patch.object(HELPER.os, "fsync", side_effect=fail_journal_fsync):
            with self.assertRaises(OSError):
                self.move("HOLD_REQUESTED")
        self.assertTrue(self.pending.exists())
        self.assertEqual(self.db.read_tuple()["state"], "OPEN")
        with self.assertRaises(HELPER.TransitionHold):
            self.adapter.assert_open()

        self.tearDown()
        self.setUp()
        with mock.patch.object(HELPER.os, "replace", side_effect=OSError("simulated rename error")):
            with self.assertRaises(OSError):
                self.move("HOLD_REQUESTED")
        self.assertTrue(self.pending.exists())
        self.assertEqual(self.db.read_tuple()["state"], "HOLD_REQUESTED")
        with self.assertRaises(HELPER.TransitionHold):
            self.adapter.assert_open()

        self.tearDown()
        self.setUp()
        with mock.patch.object(HELPER.os, "write", side_effect=OSError(errno.ENOSPC, "simulated full disk")):
            with self.assertRaises(OSError):
                self.move("HOLD_REQUESTED")
        self.assertTrue(self.pending.exists())
        self.assertEqual(self.db.read_tuple()["state"], "OPEN")
        with self.assertRaises(HELPER.TransitionHold):
            self.adapter.assert_open()

    def test_journal_corruption_truncation_and_tuple_mismatch_hold(self):
        self.move("HOLD_REQUESTED")
        raw = self.journal.read_bytes()
        self.journal.write_bytes(raw[:-1])
        with self.assertRaises(HELPER.TransitionHold):
            self.adapter.journal.records()
        self.journal.write_bytes(raw.replace(b"HOLD_REQUESTED", b"COLD_REQUESTED"))
        with self.assertRaises(HELPER.TransitionHold):
            self.adapter.journal.records()

    def test_boot_id_change_missing_or_corrupt_projection_fails_closed(self):
        with self.assertRaises(HELPER.TransitionHold):
            self.make_adapter(boot=OTHER_BOOT).assert_open()
        self.status.write_text("{", encoding="ascii")
        with self.assertRaises(HELPER.TransitionHold):
            self.adapter.assert_open()
        self.status.unlink()
        with self.assertRaises(HELPER.TransitionHold):
            self.adapter.assert_open()

    def test_drain_quiesce_and_ready_are_sequential_and_closed(self):
        for state in ("HOLD_REQUESTED", "CLOSING", "CLOSED"):
            self.move(state)
        permit = [{"id": "p1"}]
        self.move("DRAINING", permits=permit)
        self.move("QUIESCED", details={"settlement_digest": "6" * 64, "pending_digest": "7" * 64})
        self.move("READY_PENDING_OWNER", details={
            "php_profile": "8.4", "ci_verified": True, "release_receipt_verified": True,
            "backup_verified": True, "rollback_verified": True, "health_verified": True,
            "worker_scheduler_verified": True,
        })
        self.assertEqual(self.read_state(), "READY_PENDING_OWNER")
        with self.assertRaises(HELPER.TransitionHold):
            self.adapter.assert_open()

    def test_release_burns_one_time_nonce_and_publishes_open_last(self):
        for state in ("HOLD_REQUESTED", "CLOSING", "CLOSED", "DRAINING", "QUIESCED", "READY_PENDING_OWNER"):
            kwargs = {"permits": []} if state == "DRAINING" else {}
            if state == "QUIESCED":
                kwargs["details"] = {"settlement_digest": "6" * 64, "pending_digest": "7" * 64}
            if state == "READY_PENDING_OWNER":
                kwargs["details"] = {
                    "php_profile": "8.4", "ci_verified": True, "release_receipt_verified": True,
                    "backup_verified": True, "rollback_verified": True, "health_verified": True,
                    "worker_scheduler_verified": True,
                }
            self.move(state, **kwargs)
        nonce = "N" * 48
        auth = {
            "host_id": "staging-test-host",
            "operation_id": self.db.read_tuple()["operation_id"],
            "generation": self.db.read_tuple()["generation"],
            "source_sha": SHA,
            "target_sha": "3" * 40,
            "approval_ref": "owner-approval-record",
            "php_profile": "8.4",
            "rollback_evidence": "8" * 64,
            "expected_journal_seq": self.db.read_tuple()["journal_seq"] + 1,
            "nonce": nonce,
        }
        self.adapter.release(auth, source_sha=SHA, target_sha="3" * 40, evidence_digest=EVIDENCE)
        self.assertEqual(self.read_state(), "OPEN")
        nonce_digest = hashlib.sha256(nonce.encode("ascii")).hexdigest()
        self.assertIn(nonce_digest, self.db.nonces)
        record = self.adapter.journal.records()[-1]
        self.assertEqual(record["event"], "RELEASE_COMMIT")
        self.assertNotIn(nonce, self.journal.read_text(encoding="ascii"))
        with self.assertRaises(HELPER.TransitionHold):
            self.adapter.release(auth, source_sha=SHA, target_sha="3" * 40, evidence_digest=EVIDENCE)

    def test_release_scope_and_invalid_nonce_fail_closed(self):
        for state in ("HOLD_REQUESTED", "CLOSING", "CLOSED", "DRAINING", "QUIESCED", "READY_PENDING_OWNER"):
            kwargs = {"permits": []} if state == "DRAINING" else {}
            if state == "QUIESCED":
                kwargs["details"] = {"settlement_digest": "6" * 64, "pending_digest": "7" * 64}
            if state == "READY_PENDING_OWNER":
                kwargs["details"] = {
                    "php_profile": "8.4", "ci_verified": True, "release_receipt_verified": True,
                    "backup_verified": True, "rollback_verified": True, "health_verified": True,
                    "worker_scheduler_verified": True,
                }
            self.move(state, **kwargs)
        auth = {
            "host_id": "wrong-host", "operation_id": self.db.read_tuple()["operation_id"],
            "generation": self.db.read_tuple()["generation"], "source_sha": SHA,
            "target_sha": "3" * 40, "approval_ref": "approval", "php_profile": "8.4",
            "rollback_evidence": "8" * 64,
            "expected_journal_seq": self.db.read_tuple()["journal_seq"] + 1, "nonce": "short",
        }
        with self.assertRaises(HELPER.TransitionHold):
            self.adapter.release(auth, source_sha=SHA, target_sha="3" * 40, evidence_digest=EVIDENCE)
        self.assertEqual(self.read_state(), "READY_PENDING_OWNER")

    def test_precondition_runs_under_exclusive_lock_before_any_persistence(self):
        observed = []

        def reject(source, target):
            observed.append((source["state"], target, self.lock.writer))
            raise HELPER.TransitionHold("active backup blocks transition")

        with self.assertRaises(HELPER.TransitionHold):
            self.move("HOLD_REQUESTED", preconditions=reject)
        self.assertEqual(observed, [("OPEN", "HOLD_REQUESTED", True)])
        self.assertFalse(self.pending.exists())
        self.assertFalse(self.journal.exists())

    def test_active_backup_or_connector_work_blocks_hold_before_marker(self):
        self.db.active_backup_operations = 1
        with self.assertRaises(HELPER.TransitionHold):
            self.move("HOLD_REQUESTED")
        self.assertFalse(self.pending.exists())
        self.assertFalse(self.journal.exists())
        self.db.active_backup_operations = 0
        self.db.active_connector_jobs = 1
        with self.assertRaises(HELPER.TransitionHold):
            self.move("HOLD_REQUESTED")
        self.assertFalse(self.pending.exists())

    def test_shared_readers_and_exclusive_transitions_are_serialized(self):
        self.adapter.assert_open()
        self.move("HOLD_REQUESTED")
        self.assertEqual(self.lock.readers, 0)
        self.assertFalse(self.lock.writer)
        self.assertEqual(self.lock.events[0], ("acquire", "shared"))
        self.assertIn(("acquire", "exclusive"), self.lock.events)

    def test_lock_loss_and_inode_replacement_deny_admission(self):
        self.lock.fail_next = True
        with self.assertRaises(HELPER.TransitionHold):
            self.adapter.assert_open()
        self.lock.replace_on_exit = True
        with self.assertRaises(HELPER.TransitionHold):
            self.adapter.assert_open()
        self.assertEqual(self.read_state(), "OPEN")

    def test_writer_waits_for_shared_reader(self):
        acquired = threading.Event()
        started = threading.Event()

        def writer():
            started.set()
            with self.lock.hold("exclusive"):
                acquired.set()

        with self.lock.hold("shared"):
            thread = threading.Thread(target=writer)
            thread.start()
            self.assertTrue(started.wait(1))
            self.assertFalse(acquired.wait(0.05))
        self.assertTrue(acquired.wait(1))
        thread.join(timeout=1)
        self.assertFalse(thread.is_alive())


if __name__ == "__main__":
    unittest.main(verbosity=2)
