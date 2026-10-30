#!/usr/bin/env python3
"""Fake-only tests for the inert Gate 1 root transition adapter."""

import hashlib
import importlib.util
import errno
import json
import os
import stat
import subprocess
import sys
import tempfile
import threading
import time
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
        self.drain_intents = {}
        self.drain_outcomes = {}
        self.drain_events = []

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

    def record_drain_intent(self, current, binding, intent_id):
        self.drain_events.append(("intent", intent_id))
        self.drain_intents[intent_id] = {"tuple": dict(current), "binding": dict(binding)}
        return True

    def record_drain_outcome(self, intent_id, outcome):
        if intent_id not in self.drain_intents:
            raise HELPER.TransitionHold("outcome has no durable intent")
        self.drain_events.append(("outcome", intent_id, outcome["status"]))
        self.drain_outcomes[intent_id] = dict(outcome)
        return True

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


class PersistentFakeDatabase(FakeDatabase):
    """File-backed fake used only by fresh-process crash/recovery tests."""

    def __init__(self, path):
        self.path = Path(path)
        data = json.loads(self.path.read_text(encoding="ascii"))
        super().__init__(data["tuple"])
        self.manifest = data["manifest"]
        self.permits = data["permits"]
        self.nonces = data["nonces"]
        self.drain_intents = data["drain_intents"]
        self.drain_outcomes = data["drain_outcomes"]

    def _save(self):
        data = {
            "tuple": self.tuple, "manifest": self.manifest, "permits": self.permits,
            "nonces": self.nonces, "drain_intents": self.drain_intents,
            "drain_outcomes": self.drain_outcomes,
        }
        temp = self.path.with_suffix(".next")
        temp.write_text(json.dumps(data, sort_keys=True), encoding="ascii")
        os.replace(temp, self.path)

    def validate_permit_manifest(self, source, permits, digest):
        super().validate_permit_manifest(source, permits, digest)
        self._save()

    def compare_and_set(self, source, target, *, release=None, details=None):
        super().compare_and_set(source, target, release=release, details=details)
        self._save()

    def record_drain_intent(self, current, binding, intent_id):
        result = super().record_drain_intent(current, binding, intent_id)
        self._save()
        return result

    def record_drain_outcome(self, intent_id, outcome):
        result = super().record_drain_outcome(intent_id, outcome)
        self._save()
        return result


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
            self.adapter.check_drain_permit({"id": "x"})
        with self.assertRaisesRegex(HELPER.TransitionHold, "not authorization"):
            self.adapter.assert_drain_permit({"id": "x"})
        self.move("HOLD_REQUESTED")
        self.move("CLOSING")
        self.move("CLOSED")
        permit = [{"id": "p1", "work_id": "w1", "payload_hash": "4" * 64}]
        with self.assertRaises(HELPER.TransitionHold):
            self.adapter.assert_drain_permit(permit[0])
        self.move("DRAINING", permits=permit)
        self.assertEqual(self.adapter.check_drain_permit(permit[0])["state"], "DRAINING")
        with self.assertRaises(HELPER.TransitionHold):
            self.adapter.check_drain_permit({"id": "p2", "work_id": "w2", "payload_hash": "5" * 64})

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
            if directory_calls == 3:
                raise OSError("simulated directory fsync failure")
            return real_fsync_dir(path)

        self.adapter._fsync_dir = fail_final_directory
        with self.assertRaises(HELPER.TransitionDurabilityUnconfirmed):
            self.move("HOLD_REQUESTED")
        self.assertEqual(self.read_state(), "HOLD_REQUESTED")
        self.assertEqual(self.db.read_tuple()["state"], "HOLD_REQUESTED")
        self.assertFalse(self.pending.exists())
        self.adapter = self.make_adapter()
        self.move("CLOSING")
        self.assertEqual(self.read_state(), "CLOSING")
        stale = self.make_adapter(boot=OTHER_BOOT)
        with self.assertRaisesRegex(HELPER.TransitionHold, "stale"):
            stale.transition("CLOSED", source_sha=SHA, target_sha="3" * 40,
                             evidence_digest=EVIDENCE)
        self.assertEqual(self.read_state(), "CLOSING")

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
                    real_fsync_dir = self.adapter._fsync_dir
                    calls = 0

                    def fail_marker_directory(path):
                        nonlocal calls
                        calls += 1
                        if calls == 2:  # preflight is first; marker directory is second
                            raise OSError("simulated marker directory fsync error")
                        return real_fsync_dir(path)

                    self.adapter._fsync_dir = fail_marker_directory
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

    def test_drain_execution_records_intent_effect_and_outcome_under_shared_lock(self):
        for state in ("HOLD_REQUESTED", "CLOSING", "CLOSED"):
            self.move(state)
        permit = {"id": "p1", "work_id": "w1", "payload_hash": "4" * 64}
        self.move("DRAINING", permits=[permit])
        trace = []

        def effect(deadline):
            self.assertGreater(deadline, time.monotonic())
            trace.append(("effect", list(self.db.drain_events)))
            return {"status": "completed", "evidence_digest": EVIDENCE}

        result = self.adapter.execute_drain_permit(permit, effect)
        self.assertEqual(result["status"], "completed")
        self.assertEqual([item[0] for item in trace[0][1]], ["intent"])
        self.assertEqual([item[0] for item in self.db.drain_events], ["intent", "outcome"])
        with self.assertRaisesRegex(HELPER.TransitionHold, "not authorization"):
            self.adapter.assert_drain_permit(permit)

    def test_drain_effect_failure_records_outcome_before_releasing_shared_lock(self):
        for state in ("HOLD_REQUESTED", "CLOSING", "CLOSED"):
            self.move(state)
        permit = {"id": "p1", "work_id": "w1", "payload_hash": "4" * 64}
        self.move("DRAINING", permits=[permit])

        def fail(_deadline):
            raise OSError("fake effect failure")

        with self.assertRaises(OSError):
            self.adapter.execute_drain_permit(permit, fail)
        self.assertEqual([item[0] for item in self.db.drain_events], ["intent", "outcome"])
        self.assertEqual(self.db.drain_events[-1][2], "failed")
        self.assertEqual(self.lock.readers, 0)

    def test_drain_effect_deadline_and_authoritative_revalidation_fail_closed(self):
        for state in ("HOLD_REQUESTED", "CLOSING", "CLOSED"):
            self.move(state)
        permit = {"id": "p1", "work_id": "w1", "payload_hash": "4" * 64}
        self.move("DRAINING", permits=[permit])

        def late(_deadline):
            time.sleep(0.01)
            return {"status": "completed", "evidence_digest": EVIDENCE}

        with self.assertRaisesRegex(HELPER.TransitionHold, "deadline"):
            self.adapter.execute_drain_permit(permit, late, max_duration_seconds=0.001)
        self.assertEqual(self.db.drain_events[-1][2], "failed")

        self.db.drain_events.clear()

        def mutate_authority(_deadline):
            self.db.tuple["generation"] += 1
            return {"status": "completed", "evidence_digest": EVIDENCE}

        with self.assertRaises(HELPER.TransitionHold):
            self.adapter.execute_drain_permit(permit, mutate_authority)
        self.assertEqual([event[0] for event in self.db.drain_events], ["intent", "outcome"])
        self.assertEqual(self.db.drain_events[-1][2], "failed")
        self.assertEqual(self.lock.readers, 0)

    def test_durability_preflight_retry_and_stale_boot_hold(self):
        real_fsync_dir = self.adapter._fsync_dir
        calls = 0

        def fail_only_final(path):
            nonlocal calls
            calls += 1
            if calls == 3:
                raise OSError("projection directory fsync failed")
            return real_fsync_dir(path)

        self.adapter._fsync_dir = fail_only_final
        with self.assertRaises(HELPER.TransitionDurabilityUnconfirmed):
            self.move("HOLD_REQUESTED")
        self.assertEqual(self.read_state(), "HOLD_REQUESTED")
        self.assertEqual(self.db.read_tuple()["state"], "HOLD_REQUESTED")

        # The first retry preflight still fails; the following successful preflight
        # verifies the authoritative tuple and allows a new transition.
        self.adapter._fsync_dir = mock.Mock(side_effect=OSError("preflight unavailable"))
        with self.assertRaisesRegex(HELPER.TransitionHold, "preflight"):
            self.move("CLOSING")
        self.assertEqual(self.read_state(), "HOLD_REQUESTED")
        self.adapter = self.make_adapter()
        self.move("CLOSING")
        self.assertEqual(self.read_state(), "CLOSING")

    def test_process_termination_and_fresh_process_recovery_same_and_stale_boot(self):
        if sys.platform != "linux":
            self.skipTest("fresh-process directory-fsync recovery requires Linux")

        def run(mode, root, value, boot=BOOT):
            return subprocess.run(
                [sys.executable, str(Path(__file__).resolve()), mode, str(root), value, boot],
                cwd=ROOT, capture_output=True, text=True, timeout=15, check=False,
            )

        after_rename = Path(self.temp.name) / "after-rename"
        _persisted_layout(after_rename)
        crashed = run("--crash-worker", after_rename, "projection_rename")
        self.assertEqual(crashed.returncode, 97)
        recovered = run("--recover-worker", after_rename, "unused")
        self.assertEqual(recovered.returncode, 0, recovered.stdout + recovered.stderr)
        self.assertIn("RECOVERED", recovered.stdout)
        self.assertEqual(
            json.loads((after_rename / "run/wpcaregrid/transition-status.json").read_text())["state"],
            "CLOSING",
        )

        before_rename = Path(self.temp.name) / "before-rename"
        _persisted_layout(before_rename)
        crashed = run("--crash-worker", before_rename, "before_projection_rename")
        self.assertEqual(crashed.returncode, 97)
        held = run("--recover-worker", before_rename, "unused")
        self.assertEqual(held.returncode, 20)
        self.assertIn("pending marker", held.stdout)
        self.assertTrue((before_rename / "run/wpcaregrid/.transition-status.next").exists())

        stale = Path(self.temp.name) / "stale-boot"
        _persisted_layout(stale)
        crashed = run("--crash-worker", stale, "projection_rename")
        self.assertEqual(crashed.returncode, 97)
        held = run("--recover-worker", stale, "unused", OTHER_BOOT)
        self.assertEqual(held.returncode, 20)
        self.assertIn("stale", held.stdout)
        self.assertEqual(
            json.loads((stale / "run/wpcaregrid/transition-status.json").read_text())["state"],
            "HOLD_REQUESTED",
        )

        for point in ("database_commit", "projection_rename"):
            release_root = Path(self.temp.name) / ("release-" + point)
            _persisted_layout(release_root)
            _prepare_persistent_release(release_root)
            crashed = run("--crash-release", release_root, point)
            self.assertEqual(crashed.returncode, 97)
            replay = run("--replay-release", release_root, "unused")
            self.assertEqual(replay.returncode, 20)
            self.assertIn("REPLAY_BLOCKED consumed=true", replay.stdout)
            if point == "projection_rename":
                self.assertIn("release nonce was already used", replay.stdout)
            else:
                self.assertIn("pending marker", replay.stdout)

    def test_each_commit_boundary_for_hold_draining_and_release(self):
        index = {point: position for position, point in enumerate(FAULT_POINTS)}

        def prepare_release():
            for state in ("HOLD_REQUESTED", "CLOSING", "CLOSED", "DRAINING"):
                kwargs = {"permits": []} if state == "DRAINING" else {}
                self.move(state, **kwargs)
            self.move("QUIESCED", details={"settlement_digest": "6" * 64,
                                           "pending_digest": "7" * 64})
            self.move("READY_PENDING_OWNER", details={
                "php_profile": "8.4", "ci_verified": True, "release_receipt_verified": True,
                "backup_verified": True, "rollback_verified": True, "health_verified": True,
                "worker_scheduler_verified": True,
            })
            db_tuple = self.db.read_tuple()
            nonce = "Q" * 48
            authorization = {
                "host_id": "staging-test-host", "operation_id": db_tuple["operation_id"],
                "generation": db_tuple["generation"], "source_sha": SHA,
                "target_sha": "3" * 40, "approval_ref": "fake-owner-approval",
                "php_profile": "8.4", "rollback_evidence": "8" * 64,
                "expected_journal_seq": db_tuple["journal_seq"] + 1, "nonce": nonce,
            }
            return authorization, hashlib.sha256(nonce.encode("ascii")).hexdigest()

        for target in ("HOLD_REQUESTED", "DRAINING", "RELEASE_COMMIT"):
            for point in FAULT_POINTS:
                if target != "HOLD_REQUESTED" and point in {
                    "before_journal_directory_fsync", "journal_directory_fsync"
                }:
                    # The append-only journal already exists for these later states.
                    continue
                with self.subTest(target=target, point=point):
                    self.tearDown()
                    self.setUp()
                    try:
                        if target == "DRAINING":
                            for state in ("HOLD_REQUESTED", "CLOSING", "CLOSED"):
                                self.move(state)
                            invoke = lambda: self.move(
                                "DRAINING", permits=[{"id": "p1", "work_id": "w1"}]
                            )
                            source_state, target_state = "CLOSED", "DRAINING"
                        elif target == "RELEASE_COMMIT":
                            authorization, nonce_digest = prepare_release()
                            invoke = lambda: self.adapter.release(
                                authorization, source_sha=SHA, target_sha="3" * 40,
                                evidence_digest=EVIDENCE,
                            )
                            source_state, target_state = "READY_PENDING_OWNER", "OPEN"
                        else:
                            invoke = lambda: self.move("HOLD_REQUESTED")
                            source_state, target_state = "OPEN", "HOLD_REQUESTED"
                            nonce_digest = None
                        if target != "RELEASE_COMMIT":
                            nonce_digest = None
                        source_seq = self.db.read_tuple()["journal_seq"]
                        self.fault_at = point
                        with self.assertRaises(RuntimeError):
                            invoke()
                        pos = index[point]
                        if pos == index["before_marker_create"]:
                            self.assertFalse(self.pending.exists())
                            self.assertEqual(self.db.read_tuple()["state"], source_state)
                            self.assertEqual(self.adapter.journal.head()[0], source_seq)
                        elif pos >= index["projection_rename"]:
                            self.assertFalse(self.pending.exists())
                            self.assertEqual(self.read_state(), target_state)
                            self.assertEqual(self.db.read_tuple()["state"], target_state)
                        else:
                            self.assertTrue(self.pending.exists())
                            committed = pos >= index["database_commit"]
                            self.assertEqual(
                                self.db.read_tuple()["state"],
                                target_state if committed else source_state,
                            )
                        if target == "RELEASE_COMMIT":
                            self.assertEqual(nonce_digest in self.db.nonces,
                                             pos >= index["database_commit"])
                    finally:
                        self.tearDown()

    @unittest.skipUnless(sys.platform == "linux", "requires Linux flock and www-data")
    def test_www_data_can_take_shared_flock_read_only_without_write_permission(self):
        if os.geteuid() != 0:
            self.fail("Linux lock permission test must run as root")
        import grp
        import pwd
        import shutil

        if shutil.which("runuser") is None:
            self.fail("Linux lock permission test requires runuser")
        try:
            app = pwd.getpwnam("www-data")
            group = grp.getgrnam("www-data")
        except KeyError:
            self.fail("Linux lock permission test requires the www-data user and group")
        root = Path(self.temp.name)
        os.chmod(root, 0o755)
        lock_path = root / "transition.lock"
        fd = os.open(lock_path, os.O_CREAT | os.O_EXCL | os.O_RDWR, 0o600)
        try:
            os.fchown(fd, 0, group.gr_gid)
            os.fchmod(fd, 0o640)
        finally:
            os.close(fd)
        child = """import fcntl, os, sys
p = sys.argv[1]
try_write = False
try:
    fd = os.open(p, os.O_RDWR)
except PermissionError:
    pass
else:
    os.close(fd)
    try_write = True
assert not try_write
fd = os.open(p, os.O_RDONLY)
fcntl.flock(fd, fcntl.LOCK_SH | fcntl.LOCK_NB)
print('SHARED_READ_ONLY_OK')
"""
        result = subprocess.run(
            ["runuser", "-u", app.pw_name, "--", sys.executable, "-c", child, str(lock_path)],
            capture_output=True, text=True, timeout=10, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("SHARED_READ_ONLY_OK", result.stdout)
        self.assertEqual(lock_path.stat().st_uid, 0)
        self.assertEqual(lock_path.stat().st_gid, group.gr_gid)
        self.assertEqual(stat.S_IMODE(lock_path.stat().st_mode), 0o640)

    @unittest.skipUnless(sys.platform == "linux", "requires Linux process flock")
    def test_competing_process_cannot_cross_drain_effect_outcome_boundary(self):
        if os.geteuid() != 0:
            self.fail("Linux competing-process test must run as root")
        import grp

        try:
            group = grp.getgrnam("www-data")
        except KeyError:
            self.fail("Linux competing-process test requires the www-data group")
        root = Path(self.temp.name)
        os.chmod(root, 0o755)
        lock_path = root / "transition.lock"
        fd = os.open(lock_path, os.O_CREAT | os.O_EXCL | os.O_RDWR, 0o600)
        try:
            os.fchown(fd, 0, group.gr_gid)
            os.fchmod(fd, 0o640)
        finally:
            os.close(fd)
        with mock.patch.object(HELPER, "open_root_trusted_directory", HELPER.open_directory):
            self.lock = HELPER.PosixTransitionLock(lock_path)
            self.adapter = self.make_adapter()
            for state in ("HOLD_REQUESTED", "CLOSING", "CLOSED"):
                self.move(state)
            permit = {"id": "p1", "work_id": "w1", "payload_hash": "4" * 64}
            self.move("DRAINING", permits=[permit])
            acquired = root / "exclusive-acquired"
            child = (
                "import fcntl,os,sys; fd=os.open(sys.argv[1],os.O_RDWR); "
                "fcntl.flock(fd,fcntl.LOCK_EX); open(sys.argv[2],'w').write('acquired')"
            )
            process = subprocess.Popen(
                [sys.executable, "-c", child, str(lock_path), str(acquired)],
                stdout=subprocess.DEVNULL, stderr=subprocess.PIPE,
            )
            try:
                def effect(_deadline):
                    time.sleep(0.2)
                    if acquired.exists() or process.poll() is not None:
                        raise AssertionError("exclusive process crossed the effect boundary")
                    return {"status": "completed", "evidence_digest": EVIDENCE}

                result = self.adapter.execute_drain_permit(permit, effect)
                self.assertEqual(result["status"], "completed")
                self.assertEqual(self.db.drain_events[-1][0], "outcome")
                return_code = process.wait(timeout=5)
                stderr = process.stderr.read().decode()
                process.stderr.close()
                self.assertEqual(return_code, 0, stderr)
                self.assertTrue(acquired.exists())
            finally:
                if process.poll() is None:
                    process.kill()
                    process.wait(timeout=5)


FAULT_POINTS = [
    "before_marker_create", "marker_created", "marker_partial_write", "marker_written",
    "before_marker_file_fsync", "marker_file_fsync", "before_marker_directory_fsync",
    "marker_directory_fsync", "before_journal_append", "journal_appended",
    "before_journal_fsync", "journal_fsync", "before_journal_directory_fsync",
    "journal_directory_fsync", "before_database_commit", "database_commit",
    "before_projection_rename", "projection_rename",
    "before_projection_directory_fsync", "projection_directory_fsync",
]


def _persisted_layout(root):
    root = Path(root)
    status = root / "run/wpcaregrid/transition-status.json"
    status.parent.mkdir(parents=True, exist_ok=True)
    initial = {
        "state": "OPEN", "operation_id": "00000000-0000-7000-8000-000000000001",
        "generation": 0, "boot_id": BOOT, "journal_seq": 0,
        "journal_head_hash": HELPER.TRANSITION_GENESIS_HASH, "evidence_digest": EVIDENCE,
    }
    status.write_bytes(HELPER._transition_canonical(initial))
    data = {
        "tuple": initial, "manifest": None, "permits": [], "nonces": {},
        "drain_intents": {}, "drain_outcomes": {},
    }
    (root / "database.json").write_text(json.dumps(data, sort_keys=True), encoding="ascii")


def _persistent_adapter(root, boot=BOOT, fault=None):
    root = Path(root)
    database = PersistentFakeDatabase(root / "database.json")
    adapter = HELPER.RootTransitionAdapter(
        journal_path=root / "var/lib/transitions/journal.jsonl",
        status_path=root / "run/wpcaregrid/transition-status.json",
        pending_path=root / "run/wpcaregrid/.transition-status.next",
        lock=FakeLock(), database=database, boot_id=boot, host_id="process-test",
        require_root_owned=False, fault=fault,
    )
    return adapter


def _prepare_persistent_release(root):
    adapter = _persistent_adapter(root)
    for state in ("HOLD_REQUESTED", "CLOSING", "CLOSED", "DRAINING"):
        adapter.transition(state, source_sha=SHA, target_sha="3" * 40,
                           evidence_digest=EVIDENCE,
                           permits=[] if state == "DRAINING" else None)
    adapter.transition("QUIESCED", source_sha=SHA, target_sha="3" * 40,
                       evidence_digest=EVIDENCE,
                       details={"settlement_digest": "6" * 64, "pending_digest": "7" * 64})
    adapter.transition("READY_PENDING_OWNER", source_sha=SHA, target_sha="3" * 40,
                       evidence_digest=EVIDENCE, details={
                           "php_profile": "8.4", "ci_verified": True,
                           "release_receipt_verified": True, "backup_verified": True,
                           "rollback_verified": True, "health_verified": True,
                           "worker_scheduler_verified": True,
                       })
    db_tuple = adapter.database.read_tuple()
    authorization = {
        "host_id": "process-test", "operation_id": db_tuple["operation_id"],
        "generation": db_tuple["generation"], "source_sha": SHA,
        "target_sha": "3" * 40, "approval_ref": "fake-owner-approval",
        "php_profile": "8.4", "rollback_evidence": "8" * 64,
        "expected_journal_seq": db_tuple["journal_seq"] + 1, "nonce": "R" * 48,
    }
    (Path(root) / "authorization.json").write_text(
        json.dumps(authorization, sort_keys=True), encoding="ascii"
    )


def _process_worker(argv):
    mode, root, value, boot = argv
    if mode == "--crash-worker":
        def crash(point):
            if point == value:
                os._exit(97)

        adapter = _persistent_adapter(root, boot, crash)
        adapter.transition("HOLD_REQUESTED", source_sha=SHA, target_sha="3" * 40,
                           evidence_digest=EVIDENCE)
        return 98
    if mode == "--crash-release":
        def crash(point):
            if point == value:
                os._exit(97)

        adapter = _persistent_adapter(root, boot, crash)
        authorization = json.loads((Path(root) / "authorization.json").read_text(encoding="ascii"))
        adapter.release(authorization, source_sha=SHA, target_sha="3" * 40,
                        evidence_digest=EVIDENCE)
        return 98
    if mode == "--replay-release":
        adapter = _persistent_adapter(root, boot)
        authorization = json.loads((Path(root) / "authorization.json").read_text(encoding="ascii"))
        digest = hashlib.sha256(authorization["nonce"].encode("ascii")).hexdigest()
        consumed = adapter.database.nonce_consumed(digest)
        try:
            adapter.release(authorization, source_sha=SHA, target_sha="3" * 40,
                            evidence_digest=EVIDENCE)
        except HELPER.TransitionHold as exc:
            sys.stdout.write("REPLAY_BLOCKED consumed=" + str(consumed).lower()
                             + " reason=" + str(exc) + "\n")
            return 20
        return 98
    if mode == "--recover-worker":
        adapter = _persistent_adapter(root, boot)
        try:
            adapter.transition("CLOSING", source_sha=SHA, target_sha="3" * 40,
                               evidence_digest=EVIDENCE)
        except HELPER.TransitionHold as exc:
            sys.stdout.write("HOLD: " + str(exc) + "\n")
            return 20
        sys.stdout.write("RECOVERED\n")
        return 0
    raise ValueError("unknown process worker mode")


if __name__ == "__main__":
    worker_modes = {"--crash-worker", "--recover-worker", "--crash-release", "--replay-release"}
    if len(sys.argv) > 1:
        if len(sys.argv) != 5 or sys.argv[1] not in worker_modes:
            raise SystemExit("unexpected arguments; worker modes require exactly four arguments")
        raise SystemExit(_process_worker(sys.argv[1:]))
    unittest.main(verbosity=2)
