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

    def is_held(self, mode=None):
        if mode == "exclusive":
            return self.writer
        if mode == "shared":
            return self.readers > 0
        return self.writer or self.readers > 0

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
        self.drain_claims = {}
        self.drain_reconciliations = {}
        self.transition_receipts = {}
        self.remote_effect_count = 0
        self.drain_events = []
        self.drain_preclaim_barrier = None

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
            details = dict(details or {})
            request_id = details.get("rpc_request_id")
            request_digest = details.get("rpc_request_digest")
            if request_id and request_digest:
                self.transition_receipts[request_id] = {
                    "committed": True,
                    "request_id": request_id,
                    "request_digest": request_digest,
                    "target": dict(target),
                }

    def read_transition_receipt(self, request_id, request_digest):
        with self.lock:
            receipt = self.transition_receipts.get(request_id)
            if (not receipt or receipt.get("committed") is not True
                or receipt.get("request_digest") != request_digest
                or receipt.get("target") != self.tuple):
                return {
                    "committed": False, "request_id": request_id,
                    "request_digest": request_digest, "target": None,
                }
            return dict(receipt)

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
        key = self.drain_claim_key(current, binding)
        if key in self.drain_claims:
            raise HELPER.TransitionHold("drain permit step was already claimed and cannot be replayed")

    @staticmethod
    def drain_claim_key(current, binding):
        exact = {
            "operation_id": current["operation_id"],
            "generation": current["generation"],
            "permit_id": binding.get("id"),
            "execution_id": binding.get("execution_id"),
            "work_id": binding.get("work_id"),
            "step_index": binding.get("step_index", 0),
            "step_id": binding.get("step_id", "step-0"),
            "idempotency_key": binding.get("idempotency_key", binding.get("id")),
            "binding": binding,
        }
        return hashlib.sha256(HELPER._transition_canonical(exact)).hexdigest()

    def claim_drain_step(self, current, binding, intent_id):
        key = self.drain_claim_key(current, binding)
        with self.lock:
            if key in self.drain_claims:
                raise HELPER.TransitionHold("drain permit step already has a durable claim")
            if self._has_unresolved_drain_effects(
                current["operation_id"], current["generation"]
            ):
                raise HELPER.TransitionHold(
                    "another DRAIN intent/UNKNOWN is unresolved for this operation and generation"
                )
            self.assert_exact_permit(current, binding)
            self.drain_claims[key] = intent_id
            self.drain_events.append(("intent", intent_id))
            self.drain_intents[intent_id] = {
                "tuple": dict(current), "binding": dict(binding), "claim_key": key,
                "status": "INTENT",
            }
        return intent_id

    def record_drain_outcome(self, claim, intent_id, outcome):
        record = self.drain_intents.get(intent_id)
        if claim != intent_id or not record or record["status"] != "INTENT":
            raise HELPER.TransitionHold("outcome has no durable intent")
        if outcome.get("status") not in {"SUCCEEDED", "FAILED", "UNKNOWN"}:
            raise HELPER.TransitionHold("outcome status is invalid")
        if not isinstance(outcome.get("evidence_digest"), str) or len(outcome["evidence_digest"]) != 64:
            raise HELPER.TransitionHold("outcome evidence is invalid")
        record["status"] = outcome["status"]
        self.drain_events.append(("outcome", intent_id, outcome["status"]))
        self.drain_outcomes[intent_id] = dict(outcome)
        return True

    def verify_drain_effect_absent(self, claim, intent_id, evidence_digest, authority_reference):
        return bool(
            claim == intent_id
            and intent_id in self.drain_intents
            and self.drain_intents[intent_id]["status"] == "INTENT"
            and evidence_digest == EVIDENCE
            and authority_reference == "fake-no-effect-proof"
        )

    def assert_no_unresolved_drain_effects(self, operation_id, generation):
        unresolved = not self._has_unresolved_drain_effects(operation_id, generation)
        barrier = self.drain_preclaim_barrier
        if barrier is not None:
            barrier.wait(timeout=3)
        return unresolved

    def _has_unresolved_drain_effects(self, operation_id, generation):
        for intent_id, intent in self.drain_intents.items():
            claim_tuple = intent["tuple"]
            if (claim_tuple["operation_id"] == operation_id
                and claim_tuple["generation"] == generation
                and intent["status"] in {"INTENT", "UNKNOWN"}
                and intent_id not in self.drain_reconciliations):
                return True
        return self.active_or_unknown_effects != 0

    def record_drain_reconciliation(self, claim, resolution, evidence_digest, authority_reference):
        if resolution not in {"SUCCEEDED", "FAILED"} or not authority_reference:
            raise HELPER.TransitionHold("authoritative reconciliation is incomplete")
        record = self.drain_intents.get(claim)
        if not record or record["status"] not in {"INTENT", "UNKNOWN"}:
            raise HELPER.TransitionHold("claim is not unresolved")
        if authority_reference != "fake-authoritative-reconciliation":
            raise HELPER.TransitionHold("fake authority rejected reconciliation reference")
        if claim in self.drain_reconciliations:
            raise HELPER.TransitionHold("reconciliation record is immutable")
        self.drain_reconciliations[claim] = {
            "resolution": resolution, "evidence_digest": evidence_digest,
            "authority_reference": authority_reference,
        }
        return True

    def record_fake_remote_effect(self):
        self.remote_effect_count += 1
        return self.remote_effect_count

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
        self._load()

    def _load(self):
        data = json.loads(self.path.read_text(encoding="ascii"))
        if not hasattr(self, "lock"):
            super().__init__(data["tuple"])
        else:
            self.tuple = dict(data["tuple"])
        self.manifest = data["manifest"]
        self.permits = data["permits"]
        self.nonces = data["nonces"]
        self.drain_intents = data.get("drain_intents", {})
        self.drain_outcomes = data.get("drain_outcomes", {})
        self.drain_claims = data.get("drain_claims", {})
        self.drain_reconciliations = data.get("drain_reconciliations", {})
        self.transition_receipts = data.get("transition_receipts", {})
        self.remote_effect_count = data.get("remote_effect_count", 0)

    @contextmanager
    def _transaction(self):
        import fcntl

        lock_path = self.path.with_suffix(".lock")
        fd = os.open(lock_path, os.O_CREAT | os.O_RDWR, 0o600)
        try:
            fcntl.flock(fd, fcntl.LOCK_EX)
            self._load()
            yield
            self._save()
        finally:
            fcntl.flock(fd, fcntl.LOCK_UN)
            os.close(fd)

    def _save(self):
        data = {
            "tuple": self.tuple, "manifest": self.manifest, "permits": self.permits,
            "nonces": self.nonces, "drain_intents": self.drain_intents,
            "drain_outcomes": self.drain_outcomes, "drain_claims": self.drain_claims,
            "drain_reconciliations": self.drain_reconciliations,
            "transition_receipts": self.transition_receipts,
            "remote_effect_count": self.remote_effect_count,
        }
        temp = self.path.with_name(self.path.name + "." + str(os.getpid()) + ".next")
        encoded = json.dumps(data, sort_keys=True).encode("ascii")
        fd = os.open(temp, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        try:
            os.write(fd, encoded)
            os.fsync(fd)
        finally:
            os.close(fd)
        os.replace(temp, self.path)
        parent_fd = os.open(self.path.parent, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
        try:
            os.fsync(parent_fd)
        finally:
            os.close(parent_fd)

    def validate_permit_manifest(self, source, permits, digest):
        super().validate_permit_manifest(source, permits, digest)
        self._save()

    def compare_and_set(self, source, target, *, release=None, details=None):
        with self._transaction():
            super().compare_and_set(source, target, release=release, details=details)

    def read_transition_receipt(self, request_id, request_digest):
        with self._transaction():
            return FakeDatabase.read_transition_receipt(self, request_id, request_digest)

    def record_drain_intent(self, current, binding, intent_id):
        raise AssertionError("obsolete non-atomic drain intent API must not be called")

    def claim_drain_step(self, current, binding, intent_id):
        with self._transaction():
            return FakeDatabase.claim_drain_step(self, current, binding, intent_id)

    def record_drain_outcome(self, claim, intent_id, outcome):
        with self._transaction():
            return FakeDatabase.record_drain_outcome(self, claim, intent_id, outcome)

    def verify_drain_effect_absent(self, claim, intent_id, evidence_digest, authority_reference):
        with self._transaction():
            return FakeDatabase.verify_drain_effect_absent(
                self, claim, intent_id, evidence_digest, authority_reference
            )

    def assert_no_unresolved_drain_effects(self, operation_id, generation):
        with self._transaction():
            return FakeDatabase.assert_no_unresolved_drain_effects(self, operation_id, generation)

    def record_drain_reconciliation(self, claim, resolution, evidence_digest, authority_reference):
        with self._transaction():
            return FakeDatabase.record_drain_reconciliation(
                self, claim, resolution, evidence_digest, authority_reference
            )

    def record_fake_remote_effect(self):
        with self._transaction():
            return FakeDatabase.record_fake_remote_effect(self)


def _prepare_persistent_draining(root, permits=None):
    adapter = _persistent_adapter(root)
    for state in ("HOLD_REQUESTED", "CLOSING", "CLOSED"):
        adapter.transition(state, source_sha=SHA, target_sha="3" * 40,
                           evidence_digest=EVIDENCE)
    permits = permits or [{
        "id": "drain-crash-permit", "work_id": "work-1", "execution_id": "execution-1",
        "step_index": 0, "step_id": "step-0", "idempotency_key": "idem-1",
        "payload_hash": "4" * 64,
    }]
    adapter.transition("DRAINING", source_sha=SHA, target_sha="3" * 40,
                       evidence_digest=EVIDENCE, permits=permits)
    (Path(root) / "drain-permit.json").write_text(
        json.dumps(permits[0], sort_keys=True), encoding="ascii"
    )
    (Path(root) / "drain-permits.json").write_text(
        json.dumps(permits, sort_keys=True), encoding="ascii"
    )


def _rpc_worker(database_path, scenario, socket_path):
    return _rpc_worker_impl(database_path, scenario, socket_path)


def _rpc_worker_impl(database_path, scenario, socket_path):
    """Unprivileged fake Laravel CLI child used only by isolated Linux RPC tests."""
    import fcntl
    import socket

    path = Path(database_path)
    connection = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    connection.settimeout(10)
    connection.connect(socket_path)
    request_data = bytearray()
    while b"\n" not in request_data:
        chunk = connection.recv(4096)
        if not chunk:
            raise RuntimeError("root closed before dispatching the typed RPC")
        request_data.extend(chunk)
        if len(request_data) > HELPER.TRANSITION_RPC_MAX_BYTES:
            raise RuntimeError("typed RPC exceeded its bound")
    request_line, trailing = bytes(request_data).split(b"\n", 1)
    if trailing:
        raise RuntimeError("unexpected bytes after typed RPC")
    request = json.loads(request_line.decode("ascii"))
    if (not isinstance(request, dict)
        or set(request) != {"version", "request_id", "request_digest", "operation", "payload"}
        or request["version"] != HELPER.TRANSITION_RPC_VERSION):
        raise RuntimeError("invalid typed RPC envelope")

    def read_state():
        return json.loads(path.read_text(encoding="ascii"))

    def write_state(value):
        temp = path.with_name(path.name + "." + str(os.getpid()) + ".next")
        fd = os.open(temp, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        try:
            encoded = json.dumps(value, sort_keys=True, separators=(",", ":")).encode("ascii")
            os.write(fd, encoded)
            os.fsync(fd)
        finally:
            os.close(fd)
        os.replace(temp, path)
        parent_fd = os.open(path.parent, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
        try:
            os.fsync(parent_fd)
        finally:
            os.close(parent_fd)

    lock_fd = os.open(path.with_suffix(".lock"), os.O_CREAT | os.O_RDWR, 0o660)
    try:
        fcntl.flock(lock_fd, fcntl.LOCK_EX)
        state = read_state()
        state.setdefault("operations", {})
        state.setdefault("receipts", {})
        operation = request["operation"]
        state["operations"][operation] = state["operations"].get(operation, 0) + 1
        identity = {
            "pid": os.getpid(),
            "uids": list(os.getresuid()),
            "gids": list(os.getresgid()),
            "supplementary_groups": os.getgroups(),
            "cap_eff": next((line.split()[1] for line in Path("/proc/self/status").read_text().splitlines()
                             if line.startswith("CapEff:")), None),
        }
        if operation == "compare_and_set":
            payload = request["payload"]
            request_id = payload["request_id"]
            request_digest = payload["request_digest"]
            prior = state["receipts"].get(request_id)
            if prior is not None:
                if prior["request_digest"] != request_digest:
                    response = {"status": "REFUSED", "result": {"reason": "request ID digest conflict"}}
                elif (prior.get("committed") is True and state["tuple"] == prior.get("target")):
                    response = {"status": "COMMITTED", "result": dict(prior)}
                else:
                    response = {"status": "REFUSED", "result": {"reason": "receipt not positively verified"}}
            else:
                state.setdefault("cas_attempts", 0)
                state["cas_attempts"] += 1
                if scenario == "crash_before_commit":
                    write_state(state)
                    os._exit(97)
                if state["tuple"] != payload["source"]:
                    response = {"status": "REFUSED", "result": {"reason": "source tuple mismatch"}}
                else:
                    state["tuple"] = payload["target"]
                    receipt = {
                        "committed": True,
                        "request_id": request_id,
                        "request_digest": request_digest,
                        "target": payload["target"],
                    }
                    state["receipts"][request_id] = receipt
                    state["identity"] = identity
                    write_state(state)
                    if scenario == "crash_after_commit":
                        os._exit(97)
                    response = {"status": "COMMITTED", "result": receipt}
        elif operation == "read_transition_receipt":
            payload = request["payload"]
            receipt = state["receipts"].get(payload["request_id"])
            verified = bool(
                receipt
                and receipt.get("committed") is True
                and receipt.get("request_digest") == payload["request_digest"]
                and state["tuple"] == receipt.get("target")
            )
            result = dict(receipt) if verified else {
                "committed": False,
                "request_id": payload["request_id"],
                "request_digest": payload["request_digest"],
                "target": None,
            }
            response = {"status": "OK", "result": result}
            state["identity"] = identity
            write_state(state)
        elif operation == "read_tuple":
            response = {"status": "OK", "result": state["tuple"]}
            state["identity"] = identity
            write_state(state)
        else:
            response = {"status": "REFUSED", "result": {"reason": "unsupported fake RPC"}}
            state["identity"] = identity
            write_state(state)
        response_envelope = {
            "version": HELPER.TRANSITION_RPC_VERSION,
            "request_id": request["request_id"],
            "request_digest": request["request_digest"],
            "status": response["status"],
            "result": response["result"],
        }
        connection.sendall(HELPER._transition_canonical(response_envelope))
    finally:
        fcntl.flock(lock_fd, fcntl.LOCK_UN)
        os.close(lock_fd)
        connection.close()
    return 0


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
        effects = []

        def effect(deadline):
            self.assertGreater(deadline, time.monotonic())
            trace.append(("effect", list(self.db.drain_events)))
            effects.append("called")
            return {"status": "SUCCEEDED", "evidence_digest": EVIDENCE}

        result = self.adapter.execute_drain_permit(permit, effect)
        self.assertEqual(result["status"], "SUCCEEDED")
        self.assertEqual(trace[0][1], [("intent", next(iter(self.db.drain_intents)))])
        self.assertEqual([item[0] for item in self.db.drain_events], ["intent", "outcome"])
        claim = next(iter(self.db.drain_intents))
        with self.assertRaisesRegex(HELPER.TransitionHold, "no durable intent"):
            self.db.record_drain_outcome(
                claim, claim, {"status": "UNKNOWN", "evidence_digest": EVIDENCE}
            )
        with self.assertRaisesRegex(HELPER.TransitionHold, "already claimed|replay"):
            self.adapter.execute_drain_permit(permit, effect)
        self.assertEqual(effects, ["called"])
        with self.assertRaisesRegex(HELPER.TransitionHold, "not authorization"):
            self.adapter.assert_drain_permit(permit)

    def test_timeout_after_remote_effect_is_unknown_and_fresh_intent_replay_is_rejected(self):
        for state in ("HOLD_REQUESTED", "CLOSING", "CLOSED"):
            self.move(state)
        permit = {"id": "p1", "work_id": "w1", "payload_hash": "4" * 64}
        self.move("DRAINING", permits=[permit])
        calls = []

        def timed_out_after_remote_success(_deadline):
            calls.append("remote-effect")
            self.db.record_fake_remote_effect()
            raise TimeoutError("response lost after remote success")

        with self.assertRaisesRegex(HELPER.TransitionHold, "UNKNOWN"):
            self.adapter.execute_drain_permit(permit, timed_out_after_remote_success)
        self.assertEqual([item[0] for item in self.db.drain_events], ["intent", "outcome"])
        claim = next(iter(self.db.drain_intents))
        self.assertEqual(self.db.drain_outcomes[claim]["status"], "UNKNOWN")
        self.assertEqual(self.db.remote_effect_count, 1)
        with self.assertRaises(HELPER.TransitionHold):
            self.adapter.execute_drain_permit(permit, timed_out_after_remote_success)
        self.assertEqual(calls, ["remote-effect"])
        with self.assertRaisesRegex(HELPER.TransitionHold, "unresolved DRAIN"):
            self.move("QUIESCED", details={
                "settlement_digest": "6" * 64, "pending_digest": "7" * 64,
            })
        self.assertEqual(self.lock.readers, 0)

    def test_unknown_permit_blocks_distinct_permit_until_authoritative_reconciliation(self):
        for state in ("HOLD_REQUESTED", "CLOSING", "CLOSED"):
            self.move(state)
        permit_a = {"id": "p1", "work_id": "w1", "payload_hash": "4" * 64}
        permit_b = {"id": "p2", "work_id": "w2", "payload_hash": "5" * 64}
        self.move("DRAINING", permits=[permit_a, permit_b])

        def ambiguous(_deadline):
            self.db.record_fake_remote_effect()
            raise TimeoutError("permit A may have completed remotely")

        with self.assertRaisesRegex(HELPER.TransitionHold, "UNKNOWN"):
            self.adapter.execute_drain_permit(permit_a, ambiguous)
        claim_a = next(iter(self.db.drain_intents))
        calls_b = []

        def effect_b(_deadline):
            calls_b.append("effect")
            self.db.record_fake_remote_effect()
            return {"status": "SUCCEEDED", "evidence_digest": EVIDENCE}

        with self.assertRaisesRegex(HELPER.TransitionHold, "unresolved DRAIN"):
            self.adapter.execute_drain_permit(permit_b, effect_b)
        self.assertEqual(calls_b, [])
        self.assertEqual(len(self.db.drain_claims), 1)
        self.assertFalse(self.db.assert_no_unresolved_drain_effects(
            self.db.tuple["operation_id"], self.db.tuple["generation"]
        ))

        self.adapter.reconcile_drain_effect(
            claim_a, resolution="SUCCEEDED", evidence_digest=EVIDENCE,
            authority_reference="fake-authoritative-reconciliation",
        )
        self.assertEqual(self.db.drain_outcomes[claim_a]["status"], "UNKNOWN")
        self.assertIn(claim_a, self.db.drain_reconciliations)
        result = self.adapter.execute_drain_permit(permit_b, effect_b)
        self.assertEqual(result["status"], "SUCCEEDED")
        self.assertEqual(calls_b, ["effect"])
        self.assertEqual(self.db.remote_effect_count, 2)

    def test_atomic_claim_blocks_a_competing_distinct_permit(self):
        for state in ("HOLD_REQUESTED", "CLOSING", "CLOSED"):
            self.move(state)
        permits = [
            {"id": "p1", "work_id": "w1", "payload_hash": "4" * 64},
            {"id": "p2", "work_id": "w2", "payload_hash": "5" * 64},
        ]
        self.move("DRAINING", permits=permits)
        self.db.drain_preclaim_barrier = threading.Barrier(2)
        effect_started = threading.Event()
        finish_effect = threading.Event()
        effect_calls = []
        results = []

        def worker(permit):
            def effect(_deadline):
                effect_calls.append(permit["id"])
                effect_started.set()
                if not finish_effect.wait(3):
                    raise TimeoutError("competing permit test timed out")
                return {"status": "SUCCEEDED", "evidence_digest": EVIDENCE}

            try:
                results.append(("success", self.adapter.execute_drain_permit(permit, effect)))
            except HELPER.TransitionHold as exc:
                results.append(("blocked", str(exc)))

        workers = [threading.Thread(target=worker, args=(permit,)) for permit in permits]
        for item in workers:
            item.start()
        self.assertTrue(effect_started.wait(2), "one permit should enter the held effect boundary")
        deadline = time.monotonic() + 3
        while len(results) < 1 and time.monotonic() < deadline:
            time.sleep(0.01)
        self.assertEqual(len(results), 1, "competing claim should be rejected while the first intent is unresolved")
        self.assertEqual(results[0][0], "blocked")
        self.assertEqual(len(effect_calls), 1)
        finish_effect.set()
        for item in workers:
            item.join(timeout=3)
            self.assertFalse(item.is_alive())
        self.assertEqual(len(results), 2)
        self.assertEqual([kind for kind, _result in results].count("success"), 1)
        self.assertEqual(len(effect_calls), 1)
        self.assertEqual(len(self.db.drain_claims), 1)

    def test_failed_is_recorded_only_with_explicit_proof_no_effect_occurred(self):
        for state in ("HOLD_REQUESTED", "CLOSING", "CLOSED"):
            self.move(state)
        permit = {"id": "p1", "work_id": "w1", "payload_hash": "4" * 64}
        self.move("DRAINING", permits=[permit])

        def not_sent(_deadline):
            raise HELPER.DrainEffectNotPerformed(EVIDENCE, "fake-no-effect-proof")

        with self.assertRaisesRegex(HELPER.TransitionHold, "did not occur"):
            self.adapter.execute_drain_permit(permit, not_sent)
        claim = next(iter(self.db.drain_intents))
        self.assertEqual(self.db.drain_outcomes[claim]["status"], "FAILED")
        self.assertEqual(self.db.remote_effect_count, 0)
        with self.assertRaisesRegex(HELPER.TransitionHold, "already claimed|replay"):
            self.adapter.execute_drain_permit(permit, not_sent)

    def test_unverified_no_effect_claim_remains_unresolved_not_failed(self):
        for state in ("HOLD_REQUESTED", "CLOSING", "CLOSED"):
            self.move(state)
        permit = {"id": "p1", "work_id": "w1", "payload_hash": "4" * 64}
        self.move("DRAINING", permits=[permit])

        def unverified(_deadline):
            raise HELPER.DrainEffectNotPerformed(EVIDENCE, "untrusted-reference")

        with self.assertRaisesRegex(HELPER.TransitionHold, "absence of external effect is unverified"):
            self.adapter.execute_drain_permit(permit, unverified)
        claim = next(iter(self.db.drain_intents))
        self.assertEqual(self.db.drain_intents[claim]["status"], "INTENT")
        self.assertNotIn(claim, self.db.drain_outcomes)
        with self.assertRaisesRegex(HELPER.TransitionHold, "unresolved DRAIN"):
            self.move("QUIESCED", details={
                "settlement_digest": "6" * 64, "pending_digest": "7" * 64,
            })

    def test_drain_effect_deadline_and_authoritative_revalidation_fail_closed(self):
        for state in ("HOLD_REQUESTED", "CLOSING", "CLOSED"):
            self.move(state)
        permit = {"id": "p1", "work_id": "w1", "payload_hash": "4" * 64}
        self.move("DRAINING", permits=[permit])

        def late(_deadline):
            time.sleep(0.01)
            self.db.record_fake_remote_effect()
            return {"status": "SUCCEEDED", "evidence_digest": EVIDENCE}

        with self.assertRaisesRegex(HELPER.TransitionHold, "deadline"):
            self.adapter.execute_drain_permit(permit, late, max_duration_seconds=0.001)
        late_claim = next(iter(self.db.drain_intents))
        self.assertEqual(self.db.drain_outcomes[late_claim]["status"], "UNKNOWN")

        self.tearDown()
        self.setUp()
        for state in ("HOLD_REQUESTED", "CLOSING", "CLOSED"):
            self.move(state)
        permit = {"id": "p2", "work_id": "w2", "payload_hash": "5" * 64}
        self.move("DRAINING", permits=[permit])

        def mutate_authority(_deadline):
            self.db.tuple["generation"] += 1
            self.db.record_fake_remote_effect()
            return {"status": "SUCCEEDED", "evidence_digest": EVIDENCE}

        with self.assertRaises(HELPER.TransitionHold):
            self.adapter.execute_drain_permit(permit, mutate_authority)
        revalidation_claim = next(iter(self.db.drain_intents))
        self.assertEqual(self.db.drain_outcomes[revalidation_claim]["status"], "UNKNOWN")
        self.assertEqual([event[0] for event in self.db.drain_events], ["intent", "outcome"])
        self.assertEqual(self.db.drain_events[-1][2], "UNKNOWN")
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

    def test_open_admission_waits_for_durability_preflight_after_release_rename(self):
        for state in ("HOLD_REQUESTED", "CLOSING", "CLOSED", "DRAINING"):
            self.move(state, **({"permits": []} if state == "DRAINING" else {}))
        self.move("QUIESCED", details={
            "settlement_digest": "6" * 64, "pending_digest": "7" * 64,
        })
        self.move("READY_PENDING_OWNER", details={
            "php_profile": "8.4", "ci_verified": True, "release_receipt_verified": True,
            "backup_verified": True, "rollback_verified": True, "health_verified": True,
            "worker_scheduler_verified": True,
        })
        current = self.db.read_tuple()
        nonce = "N" * 48
        authorization = {
            "host_id": "staging-test-host", "operation_id": current["operation_id"],
            "generation": current["generation"], "source_sha": SHA,
            "target_sha": "3" * 40, "approval_ref": "fake-owner-approval",
            "php_profile": "8.4", "rollback_evidence": "8" * 64,
            "expected_journal_seq": current["journal_seq"] + 1, "nonce": nonce,
        }
        real_fsync_dir = self.adapter._fsync_dir
        fail_final_projection_fsync = False

        def arm_final_projection_fsync(point):
            nonlocal fail_final_projection_fsync
            if point == "before_projection_directory_fsync":
                fail_final_projection_fsync = True

        def fail_only_final_projection_sync(path):
            nonlocal fail_final_projection_fsync
            if fail_final_projection_fsync:
                fail_final_projection_fsync = False
                raise OSError("simulated release projection directory fsync failure")
            return real_fsync_dir(path)

        self.adapter.fault = arm_final_projection_fsync
        self.adapter._fsync_dir = fail_only_final_projection_sync
        self.adapter.journal.fsync_dir = fail_only_final_projection_sync
        with self.assertRaises(HELPER.TransitionDurabilityUnconfirmed):
            self.adapter.release(authorization, source_sha=SHA, target_sha="3" * 40,
                                 evidence_digest=EVIDENCE)
        self.assertEqual(self.read_state(), "OPEN")
        self.assertEqual(self.db.read_tuple()["state"], "OPEN")

        self.adapter._fsync_dir = mock.Mock(side_effect=OSError("preflight unavailable"))
        with self.assertRaisesRegex(HELPER.TransitionHold, "durability preflight"):
            self.adapter.assert_open()
        self.adapter._fsync_dir = real_fsync_dir
        self.adapter.journal.fsync_dir = real_fsync_dir
        self.assertEqual(self.adapter.assert_open()["state"], "OPEN")

    def test_drain_effect_waits_for_durability_preflight_after_projection_rename(self):
        for state in ("HOLD_REQUESTED", "CLOSING", "CLOSED"):
            self.move(state)
        permit = {"id": "p1", "work_id": "w1", "payload_hash": "4" * 64}
        real_fsync_dir = self.adapter._fsync_dir
        fail_final_projection_fsync = False

        def arm_final_projection_fsync(point):
            nonlocal fail_final_projection_fsync
            if point == "before_projection_directory_fsync":
                fail_final_projection_fsync = True

        def fail_only_final_projection_sync(path):
            nonlocal fail_final_projection_fsync
            if fail_final_projection_fsync:
                fail_final_projection_fsync = False
                raise OSError("simulated DRAIN projection directory fsync failure")
            return real_fsync_dir(path)

        self.adapter.fault = arm_final_projection_fsync
        self.adapter._fsync_dir = fail_only_final_projection_sync
        self.adapter.journal.fsync_dir = fail_only_final_projection_sync
        with self.assertRaises(HELPER.TransitionDurabilityUnconfirmed):
            self.move("DRAINING", permits=[permit])
        self.assertEqual(self.read_state(), "DRAINING")
        self.assertEqual(self.db.read_tuple()["state"], "DRAINING")

        self.adapter._fsync_dir = mock.Mock(side_effect=OSError("preflight unavailable"))
        calls = []
        with self.assertRaisesRegex(HELPER.TransitionHold, "durability preflight"):
            self.adapter.check_drain_permit(permit)
        with self.assertRaisesRegex(HELPER.TransitionHold, "durability preflight"):
            self.adapter.execute_drain_permit(permit, lambda _deadline: calls.append("effect"))
        self.assertEqual(calls, [])
        self.assertEqual(self.db.drain_claims, {})

        self.adapter._fsync_dir = real_fsync_dir
        self.adapter.journal.fsync_dir = real_fsync_dir
        result = self.adapter.execute_drain_permit(permit, lambda _deadline: calls.append("effect") or {
            "status": "SUCCEEDED", "evidence_digest": EVIDENCE,
        })
        self.assertEqual(result["status"], "SUCCEEDED")
        self.assertEqual(calls, ["effect"])

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

    @unittest.skipUnless(sys.platform == "linux", "requires Linux fresh-process receipt recovery")
    def test_root_process_death_after_db_commit_recovers_only_by_receipt_readback(self):
        if os.geteuid() != 0:
            self.fail("Linux receipt recovery test must run as root")
        for crash_point, expected in (("database_commit", "RECOVERED"),
                                      ("before_database_commit", "HOLD")):
            with self.subTest(crash_point=crash_point):
                root = Path(self.temp.name) / ("rpc-recovery-" + crash_point)
                _persisted_layout(root)
                crashed = subprocess.run(
                    [sys.executable, str(Path(__file__).resolve()), "--crash-worker",
                     str(root), crash_point, BOOT],
                    cwd=ROOT, capture_output=True, text=True, timeout=15, check=False,
                )
                self.assertEqual(crashed.returncode, 97, crashed.stderr)
                recovered = subprocess.run(
                    [sys.executable, str(Path(__file__).resolve()), "--recover-rpc-transition",
                     str(root), "unused", BOOT],
                    cwd=ROOT, capture_output=True, text=True, timeout=15, check=False,
                )
                state = json.loads((root / "database.json").read_text(encoding="ascii"))
                if expected == "RECOVERED":
                    self.assertEqual(recovered.returncode, 0, recovered.stderr + recovered.stdout)
                    self.assertEqual(state["tuple"]["state"], "HOLD_REQUESTED")
                    self.assertFalse((root / "run/wpcaregrid/.transition-status.next").exists())
                    self.assertTrue(state["transition_receipts"])
                else:
                    self.assertEqual(recovered.returncode, 20, recovered.stderr + recovered.stdout)
                    self.assertEqual(state["tuple"]["state"], "OPEN")
                    self.assertTrue((root / "run/wpcaregrid/.transition-status.next").exists())
                    self.assertEqual(state["transition_receipts"], {})

    @unittest.skipUnless(sys.platform == "linux", "requires Linux persistent process workers")
    def test_drain_claim_survives_process_death_restart_and_competing_claimants(self):
        if os.geteuid() != 0:
            self.fail("Linux drain process tests must run as root")

        def run(root, mode):
            return subprocess.run(
                [sys.executable, str(Path(__file__).resolve()), "--drain-worker",
                 str(root), mode, BOOT],
                cwd=ROOT, capture_output=True, text=True, timeout=20, check=False,
            )

        for crash_mode, expected_effects in (
            ("after_intent", 0), ("during_effect", 1), ("after_effect", 1),
        ):
            root = Path(self.temp.name) / ("drain-" + crash_mode)
            root.mkdir()
            _persisted_layout(root)
            _prepare_persistent_draining(root)
            crashed = run(root, crash_mode)
            self.assertEqual(crashed.returncode, 97, crashed.stdout + crashed.stderr)
            replay = run(root, "replay")
            self.assertEqual(replay.returncode, 20, replay.stdout + replay.stderr)
            data = json.loads((root / "database.json").read_text(encoding="ascii"))
            self.assertEqual(data["remote_effect_count"], expected_effects)
            self.assertEqual(len(data["drain_claims"]), 1)
            self.assertEqual(len(data["drain_intents"]), 1)
            intent = next(iter(data["drain_intents"].values()))
            self.assertIn(intent["status"], {"INTENT", "UNKNOWN"})
            adapter = _persistent_adapter(root)
            with self.assertRaisesRegex(HELPER.TransitionHold, "unresolved DRAIN"):
                adapter.transition("QUIESCED", source_sha=SHA, target_sha="3" * 40,
                                   evidence_digest=EVIDENCE, details={
                                       "settlement_digest": "6" * 64,
                                       "pending_digest": "7" * 64,
                                   })

        root = Path(self.temp.name) / "drain-timeout"
        root.mkdir()
        _persisted_layout(root)
        _prepare_persistent_draining(root)
        timed_out = run(root, "timeout")
        self.assertEqual(timed_out.returncode, 20, timed_out.stdout + timed_out.stderr)
        replay = run(root, "replay")
        self.assertEqual(replay.returncode, 20, replay.stdout + replay.stderr)
        data = json.loads((root / "database.json").read_text(encoding="ascii"))
        self.assertEqual(data["remote_effect_count"], 1)
        self.assertEqual(next(iter(data["drain_outcomes"].values()))["status"], "UNKNOWN")

        root = Path(self.temp.name) / "drain-sibling-permit"
        root.mkdir()
        _persisted_layout(root)
        permit_a = {
            "id": "permit-a", "work_id": "work-a", "execution_id": "execution-a",
            "step_index": 0, "step_id": "step-a", "idempotency_key": "idem-a",
            "payload_hash": "4" * 64,
        }
        permit_b = {
            "id": "permit-b", "work_id": "work-b", "execution_id": "execution-b",
            "step_index": 0, "step_id": "step-b", "idempotency_key": "idem-b",
            "payload_hash": "5" * 64,
        }
        _prepare_persistent_draining(root, [permit_a, permit_b])
        first_unknown = run(root, "timeout:0")
        self.assertEqual(first_unknown.returncode, 20, first_unknown.stdout + first_unknown.stderr)
        sibling = run(root, "success:1")
        self.assertEqual(sibling.returncode, 20, sibling.stdout + sibling.stderr)
        data = json.loads((root / "database.json").read_text(encoding="ascii"))
        self.assertEqual(data["remote_effect_count"], 1)
        self.assertEqual(len(data["drain_claims"]), 1)
        self.assertEqual(next(iter(data["drain_outcomes"].values()))["status"], "UNKNOWN")

        adapter = _persistent_adapter(root)
        claim_a = next(iter(adapter.database.drain_intents))
        adapter.reconcile_drain_effect(
            claim_a, resolution="SUCCEEDED", evidence_digest=EVIDENCE,
            authority_reference="fake-authoritative-reconciliation",
        )
        resolved_sibling = run(root, "success:1")
        self.assertEqual(resolved_sibling.returncode, 0,
                         resolved_sibling.stdout + resolved_sibling.stderr)
        data = json.loads((root / "database.json").read_text(encoding="ascii"))
        self.assertEqual(data["remote_effect_count"], 2)
        self.assertEqual(len(data["drain_claims"]), 2)

        root = Path(self.temp.name) / "drain-contenders"
        root.mkdir()
        _persisted_layout(root)
        _prepare_persistent_draining(root)
        first = subprocess.Popen(
            [sys.executable, str(Path(__file__).resolve()), "--drain-worker",
             str(root), "success", BOOT], cwd=ROOT, stdout=subprocess.PIPE,
            stderr=subprocess.PIPE, text=True,
        )
        second = subprocess.Popen(
            [sys.executable, str(Path(__file__).resolve()), "--drain-worker",
             str(root), "success", BOOT], cwd=ROOT, stdout=subprocess.PIPE,
            stderr=subprocess.PIPE, text=True,
        )
        results = [first.communicate(timeout=20), second.communicate(timeout=20)]
        codes = [first.returncode, second.returncode]
        self.assertEqual(sorted(codes), [0, 20], results)
        data = json.loads((root / "database.json").read_text(encoding="ascii"))
        self.assertEqual(data["remote_effect_count"], 1)
        self.assertEqual(len(data["drain_claims"]), 1)

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

                        # Authorization is checked at the same persistence boundary as
                        # the state assertion above: pending/partial tuples never grant
                        # the target state's permission, while the renamed complete
                        # tuple does. HOLD, DRAINING, and RELEASE share the marker ->
                        # journal -> DB CAS -> atomic projection publication primitive.
                        if pos >= index["projection_rename"]:
                            if target == "HOLD_REQUESTED":
                                with self.assertRaises(HELPER.TransitionHold):
                                    self.adapter.assert_open()
                            elif target == "DRAINING":
                                permit = {"id": "p1", "work_id": "w1"}
                                self.assertEqual(self.adapter.check_drain_permit(permit)["state"],
                                                 "DRAINING")
                                calls = []
                                result = self.adapter.execute_drain_permit(
                                    permit, lambda _deadline: calls.append("effect") or {
                                        "status": "SUCCEEDED", "evidence_digest": EVIDENCE
                                    }
                                )
                                self.assertEqual(result["status"], "SUCCEEDED")
                                with self.assertRaisesRegex(HELPER.TransitionHold,
                                                            "already claimed|replay"):
                                    self.adapter.execute_drain_permit(
                                        permit, lambda _deadline: {
                                            "status": "SUCCEEDED", "evidence_digest": EVIDENCE
                                        }
                                    )
                                self.assertEqual(calls, ["effect"])
                            else:
                                self.assertEqual(self.adapter.assert_open()["state"], "OPEN")
                                with self.assertRaises(HELPER.TransitionHold):
                                    self.adapter.release(
                                        authorization, source_sha=SHA, target_sha="3" * 40,
                                        evidence_digest=EVIDENCE,
                                    )
                        else:
                            if target == "HOLD_REQUESTED" and pos == index["before_marker_create"]:
                                # Until the HOLD logical commit, OPEN source authority
                                # remains the only active admission state.
                                self.assertEqual(self.adapter.assert_open()["state"], "OPEN")
                            else:
                                with self.assertRaises(HELPER.TransitionHold):
                                    self.adapter.assert_open()
                            if target == "DRAINING":
                                with self.assertRaises(HELPER.TransitionHold):
                                    self.adapter.check_drain_permit(
                                        {"id": "p1", "work_id": "w1"}
                                    )
                    finally:
                        self.tearDown()

    def _make_rpc_fixture(self, scenario):
        import grp

        os.chmod(self.temp.name, 0o755)
        root = Path(tempfile.mkdtemp(prefix="rpc-fixture-", dir=self.temp.name))
        os.chmod(root, 0o755)
        app_gid = grp.getgrnam("www-data").gr_gid
        socket_temp = tempfile.TemporaryDirectory(prefix="wpcg-rpc-")
        self.addCleanup(socket_temp.cleanup)
        socket_dir = Path(socket_temp.name)
        runtime_root = socket_dir / "runtime"
        runtime_tests = runtime_root / "scripts" / "tests"
        runtime_tests.mkdir(parents=True, mode=0o755)
        runtime_helper = runtime_root / "scripts" / "wpcaregrid-deploy-priv.py"
        worker_script = runtime_tests / "deploy-transition-state-machine-test.py"
        runtime_helper.write_bytes((ROOT / "scripts" / "wpcaregrid-deploy-priv.py").read_bytes())
        worker_script.write_bytes(Path(__file__).resolve().read_bytes())
        os.chmod(runtime_root, 0o755)
        os.chmod(runtime_root / "scripts", 0o755)
        os.chmod(runtime_tests, 0o755)
        os.chmod(runtime_helper, 0o644)
        os.chmod(worker_script, 0o644)
        os.chown(socket_dir, 0, app_gid)
        os.chmod(socket_dir, 0o711)
        state_dir = root / "rpc-state"
        state_dir.mkdir(mode=0o770)
        os.chown(state_dir, 0, app_gid)
        os.chmod(state_dir, 0o2770)
        database_path = state_dir / "database.json"
        state = {
            "tuple": dict(self.initial), "receipts": {}, "operations": {},
            "cas_attempts": 0, "scenario": scenario,
        }
        database_path.write_text(json.dumps(state, sort_keys=True), encoding="ascii")
        os.chown(database_path, 0, app_gid)
        os.chmod(database_path, 0o660)
        command = (
            sys.executable, str(worker_script), "--rpc-worker",
            str(database_path), scenario,
        )
        adapter = HELPER.LaravelTransitionDatabaseAdapter(
            socket_dir=socket_dir, timeout_seconds=5,
        )
        adapter.bind_lock(self.lock)
        return root, database_path, adapter, command

    @staticmethod
    def _rpc_transition_request(source, target, *, request_id="00000000-0000-7000-8000-000000000123",
                                request_digest="a" * 64):
        return source, target, {
            "rpc_request_id": request_id,
            "rpc_request_digest": request_digest,
        }

    @unittest.skipUnless(sys.platform == "linux", "requires Linux AF_UNIX and SO_PEERCRED")
    def test_linux_rpc_authenticates_privilege_dropped_child_and_committed_transition(self):
        if os.geteuid() != 0:
            self.fail("Linux transition RPC test must run as root")
        import grp
        import pwd

        app = pwd.getpwnam("www-data")
        self.assertEqual(app.pw_gid, grp.getgrnam("www-data").gr_gid)
        root, database_path, adapter, command = self._make_rpc_fixture("normal")
        target = dict(self.initial, state="HOLD_REQUESTED", operation_id="00000000-0000-7000-8000-000000000123",
                      generation=1, journal_seq=1, journal_head_hash="b" * 64)
        source, target, details = self._rpc_transition_request(self.initial, target)
        with mock.patch.object(HELPER, "TRANSITION_RPC_COMMAND", command), \
             mock.patch.object(HELPER, "APP", root), \
             mock.patch.object(HELPER, "open_root_trusted_directory", HELPER.open_directory):
            with self.lock.hold("exclusive"):
                self.assertTrue(adapter.compare_and_set(source, target, details=details))
            with self.lock.hold("shared"):
                self.assertEqual(adapter.read_tuple(), target)

        state = json.loads(database_path.read_text(encoding="ascii"))
        self.assertEqual(state["cas_attempts"], 1)
        self.assertEqual(state["tuple"], target)
        identity = state["identity"]
        self.assertEqual(identity["uids"], [app.pw_uid, app.pw_uid, app.pw_uid])
        self.assertEqual(identity["gids"], [app.pw_gid, app.pw_gid, app.pw_gid])
        self.assertEqual(identity["supplementary_groups"], [])
        self.assertEqual(int(identity["cap_eff"], 16), 0)
        self.assertNotEqual(identity["pid"], os.getpid())

    @unittest.skipUnless(sys.platform == "linux", "requires Linux persistent child processes")
    def test_rpc_lost_response_uses_fresh_receipt_readback_without_replay(self):
        if os.geteuid() != 0:
            self.fail("Linux transition RPC test must run as root")
        root, database_path, adapter, command = self._make_rpc_fixture("crash_after_commit")
        target = dict(self.initial, state="HOLD_REQUESTED", operation_id="00000000-0000-7000-8000-000000000123",
                      generation=1, journal_seq=1, journal_head_hash="b" * 64)
        source, target, details = self._rpc_transition_request(self.initial, target)
        with mock.patch.object(HELPER, "TRANSITION_RPC_COMMAND", command), \
             mock.patch.object(HELPER, "APP", root), \
             mock.patch.object(HELPER, "open_root_trusted_directory", HELPER.open_directory):
            with self.lock.hold("exclusive"):
                self.assertTrue(adapter.compare_and_set(source, target, details=details))

        state = json.loads(database_path.read_text(encoding="ascii"))
        self.assertEqual(state["cas_attempts"], 1)
        self.assertEqual(state["operations"].get("compare_and_set"), 1)
        self.assertEqual(state["operations"].get("read_transition_receipt"), 1)
        self.assertEqual(state["receipts"][details["rpc_request_id"]]["request_digest"],
                         details["rpc_request_digest"])
        self.assertEqual(state["receipts"][details["rpc_request_id"]]["target"], target)

    @unittest.skipUnless(sys.platform == "linux", "requires Linux persistent child processes")
    def test_rpc_precommit_death_and_digest_replay_fail_closed(self):
        if os.geteuid() != 0:
            self.fail("Linux transition RPC test must run as root")
        root, database_path, adapter, command = self._make_rpc_fixture("crash_before_commit")
        target = dict(self.initial, state="HOLD_REQUESTED", operation_id="00000000-0000-7000-8000-000000000123",
                      generation=1, journal_seq=1, journal_head_hash="b" * 64)
        source, target, details = self._rpc_transition_request(self.initial, target)
        with mock.patch.object(HELPER, "TRANSITION_RPC_COMMAND", command), \
             mock.patch.object(HELPER, "APP", root), \
             mock.patch.object(HELPER, "open_root_trusted_directory", HELPER.open_directory):
            with self.lock.hold("exclusive"):
                with self.assertRaises(HELPER.TransitionHold):
                    adapter.compare_and_set(source, target, details=details)

        state = json.loads(database_path.read_text(encoding="ascii"))
        self.assertEqual(state["cas_attempts"], 1)
        self.assertEqual(state["operations"].get("read_transition_receipt"), 1)
        self.assertEqual(state["receipts"], {})
        self.assertEqual(state["tuple"], self.initial)

        root, database_path, adapter, command = self._make_rpc_fixture("normal")
        source, target, details = self._rpc_transition_request(self.initial, target)
        with mock.patch.object(HELPER, "TRANSITION_RPC_COMMAND", command), \
             mock.patch.object(HELPER, "APP", root), \
             mock.patch.object(HELPER, "open_root_trusted_directory", HELPER.open_directory):
            with self.lock.hold("exclusive"):
                self.assertTrue(adapter.compare_and_set(source, target, details=details))
                self.assertTrue(adapter.compare_and_set(source, target, details=details))
                conflicting = dict(details, rpc_request_digest="c" * 64)
                with self.assertRaises(HELPER.TransitionHold):
                    adapter.compare_and_set(source, target, details=conflicting)
        state = json.loads(database_path.read_text(encoding="ascii"))
        self.assertEqual(state["cas_attempts"], 1)
        self.assertEqual(state["tuple"], target)

    def test_production_adapter_has_no_effect_catalog_or_drain_claim_surface(self):
        self.assertEqual(HELPER.TRANSITION_PRODUCTION_EFFECT_CATALOG, ())
        database = HELPER.LaravelTransitionDatabaseAdapter(socket_dir=Path(self.temp.name))
        with self.assertRaises(HELPER.TransitionHold):
            database.claim_drain_step({}, {}, "intent")
        invoked = []
        adapter = HELPER.RootTransitionAdapter(
            journal_path=self.journal, status_path=self.status, pending_path=self.pending,
            lock=self.lock, database=database, boot_id=BOOT, host_id="test",
            require_root_owned=True,
        )
        with self.assertRaisesRegex(HELPER.TransitionHold, "no enabled DRAIN effect catalog"):
            adapter.execute_drain_permit({}, lambda _deadline: invoked.append("effect"))
        self.assertEqual(invoked, [])

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
                    return {"status": "SUCCEEDED", "evidence_digest": EVIDENCE}

                result = self.adapter.execute_drain_permit(permit, effect)
                self.assertEqual(result["status"], "SUCCEEDED")
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
        "drain_intents": {}, "drain_outcomes": {}, "drain_claims": {},
        "drain_reconciliations": {}, "remote_effect_count": 0,
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
    if mode == "--drain-worker":
        root = Path(root)
        database = PersistentFakeDatabase(root / "database.json")
        scenario, separator, permit_index = value.partition(":")
        permit_index = int(permit_index) if separator else 0
        lock_path = root / "transition.lock"
        if not lock_path.exists():
            import grp
            try:
                fd = os.open(lock_path, os.O_CREAT | os.O_EXCL | os.O_RDWR, 0o600)
            except FileExistsError:
                pass
            else:
                try:
                    os.fchown(fd, 0, grp.getgrnam("www-data").gr_gid)
                    os.fchmod(fd, 0o640)
                finally:
                    os.close(fd)

        def crash(point):
            if scenario == "after_intent" and point == "drain_intent_durable":
                os._exit(97)
            if scenario == "after_effect" and point == "drain_effect_returned":
                os._exit(97)

        with mock.patch.object(HELPER, "open_root_trusted_directory", HELPER.open_directory):
            adapter = _persistent_adapter(root, boot, crash)
            adapter.lock = HELPER.PosixTransitionLock(lock_path)
            permit_file = root / "drain-permits.json"
            permits = json.loads(permit_file.read_text(encoding="ascii")) if permit_file.exists() else [
                json.loads((root / "drain-permit.json").read_text(encoding="ascii"))
            ]
            permit = permits[permit_index]

            def effect(_deadline):
                database.record_fake_remote_effect()
                if scenario == "during_effect":
                    os._exit(97)
                if scenario == "timeout":
                    raise TimeoutError("simulated ambiguous remote result")
                if scenario == "success":
                    time.sleep(0.2)
                return {"status": "SUCCEEDED", "evidence_digest": EVIDENCE}

            try:
                adapter.execute_drain_permit(permit, effect)
            except HELPER.TransitionHold as exc:
                sys.stdout.write("DRAIN_BLOCKED " + str(exc) + "\n")
                return 20
        return 0
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
    if mode == "--recover-rpc-transition":
        adapter = _persistent_adapter(root, boot)
        try:
            adapter.recover_uncertain_transition()
        except HELPER.TransitionHold as exc:
            sys.stdout.write("HOLD: " + str(exc) + "\n")
            return 20
        sys.stdout.write("RECOVERED\n")
        return 0
    raise ValueError("unknown process worker mode")


def _validate_required_linux_prerequisites():
    required = sys.platform == "linux" or os.environ.get("WPCG_REQUIRE_LINUX_TESTS") == "1"
    if not required:
        return
    if sys.platform != "linux":
        raise SystemExit("required Gate 1 Linux tests cannot run outside Linux")
    if os.geteuid() != 0:
        raise SystemExit("required Gate 1 Linux tests must run as root")
    import grp
    import pwd
    import socket
    import shutil

    try:
        app = pwd.getpwnam("www-data")
        group = grp.getgrnam("www-data")
    except KeyError:
        raise SystemExit("required Gate 1 Linux tests need www-data user and group") from None
    if app.pw_gid != group.gr_gid:
        raise SystemExit("required Gate 1 Linux tests need matching www-data UID/GID mapping")
    if shutil.which("runuser") is None:
        raise SystemExit("required Gate 1 Linux tests need runuser")
    if not hasattr(socket, "SO_PEERCRED") or not all(hasattr(os, name) for name in ("setgroups", "setresgid", "setresuid")):
        raise SystemExit("required Gate 1 Linux tests need SO_PEERCRED and full privilege-drop APIs")


if __name__ == "__main__":
    if len(sys.argv) == 1:
        _validate_required_linux_prerequisites()
    if len(sys.argv) > 1 and sys.argv[1] == "--rpc-worker":
        if len(sys.argv) != 6 or sys.argv[4] != "--connect-socket":
            raise SystemExit("unexpected arguments for fake RPC worker")
        raise SystemExit(_rpc_worker(sys.argv[2], sys.argv[3], sys.argv[5]))
    worker_modes = {
        "--crash-worker", "--recover-worker", "--crash-release", "--replay-release",
        "--drain-worker", "--recover-rpc-transition",
    }
    if len(sys.argv) > 1:
        if len(sys.argv) != 5 or sys.argv[1] not in worker_modes:
            raise SystemExit("unexpected arguments; worker modes require exactly four arguments")
        raise SystemExit(_process_worker(sys.argv[1:]))
    unittest.main(verbosity=2)
