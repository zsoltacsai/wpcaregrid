#!/usr/bin/env python3
"""Synthetic behavioral coverage for the journaled Phase 2B controller handoff."""

import hashlib
import importlib.util
import json
import sys
import stat
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
TX_SPEC = importlib.util.spec_from_file_location(
    "staging_next_db_transaction_1_4_11", ROOT / "scripts" / "staging_next_db_transaction_1_4_11.py")
TX = importlib.util.module_from_spec(TX_SPEC)
sys.modules[TX_SPEC.name] = TX
TX_SPEC.loader.exec_module(TX)
from scripts import staging_next_deploy_backend_1_4_11 as BACKEND
MIGRATIONS = TX.PHASE2B_MIGRATIONS
receipt_digest = TX._dba_receipt_hash


def fp(schema, ledger, data, migrations):
    return {"schema_sha256": schema * 64, "ledger_sha256": ledger * 64,
            "data_sha256": data * 64, "migrations": list(migrations)}


class Phase2BDbaControllerTests(unittest.TestCase):
    def setUp(self):
        self.directory = Path("/synthetic-state")
        self.names = tuple(name for name, _digest in MIGRATIONS)
        self.identity = TX.TransactionIdentity(
            application_sha="a" * 40, previous_sha="b" * 40,
            artifact_sha256="c" * 64, backup_sha256="d" * 64,
            backup_filename=f"staging-next-{'a' * 40}-123456789-abcdef12.sql.gz",
            mysql_uuid_sha256="e" * 64,
            migrations=(*self.names, "2026_11_16_000001_after_trigger_boundary"),
        )
        self.tx = TX.DatabaseReleaseTransaction(self.directory, self.identity)
        self.tx._lock_fd = 1  # test seam: exercise state logic without filesystem/DB effects
        self.tx._state = self.tx._new_state()
        self.tx._state["state"] = "FENCES_CLOSED"

        restore = {
            "backup_sha256": self.identity.backup_sha256,
            "target_schema_sha256": "1" * 64, "target_ledger_sha256": "2" * 64,
            "target_data_sha256": "3" * 64, "restored_schema_sha256": "1" * 64,
            "restored_ledger_sha256": "2" * 64, "restored_data_sha256": "f" * 64,
            "restore_mysql_uuid_sha256": "e" * 64,
        }
        restore["restore_receipt_sha256"] = TX.restore_receipt_sha256(restore)
        self.tx._state["evidence"] = {
            "BACKUP_VERIFIED": {"backup_sha256": self.identity.backup_sha256},
            "RESTORE_REHEARSAL_VERIFIED": restore,
            "MIGRATION_REHEARSAL_VERIFIED": {"migration_rehearsal_receipt_sha256": "1" * 64},
            "FENCES_CLOSED": {"fence_receipt_sha256": "2" * 64},
        }
        self.persisted = []
        self.persist_patch = mock.patch.object(self.tx, "_persist", side_effect=self.persist)
        self.persist_patch.start()
        self.addCleanup(self.persist_patch.stop)
        self.before = fp("1", "2", "3", ["legacy_migration"])
        self.first = fp("4", "5", "3", ["legacy_migration", self.names[0]])
        self.after = fp("6", "7", "3", ["legacy_migration", *self.names])
        self.next = fp("8", "9", "3", ["legacy_migration", *self.names,
                                             "2026_11_16_000001_after_trigger_boundary"])
        self.expected = {
            self.names[0]: {key: self.first[key] for key in ("schema_sha256", "ledger_sha256", "data_sha256")},
            self.names[1]: {key: self.after[key] for key in ("schema_sha256", "ledger_sha256", "data_sha256")},
            self.identity.migrations[2]: {key: self.next[key] for key in ("schema_sha256", "ledger_sha256", "data_sha256")},
        }
        self.inventory = [{"name": f"guard_{i}", "definer": "phase2b_guard@localhost", "valid": True}
                          for i in range(6)]

    def test_phase_b_policy_load_holds_when_legacy_route_exists(self):
        with tempfile.TemporaryDirectory() as directory:
            legacy_route = Path(directory) / "old-phase-b-policy.json"
            legacy_route.write_text("synthetic only")
            profile = SimpleNamespace(
                phase_b_policy_file=Path(directory) / "phase-b.json",
                phase_b_policy_sha256="a" * 64,
                test_only=False,
            )
            with mock.patch.object(BACKEND, "LEGACY_PHASE_B_CONTROLS", (legacy_route,)):
                with self.assertRaisesRegex(BACKEND.DeployRefused, "legacy_phase_b_control_present"):
                    BACKEND._load_phase_b_policy(profile)

    def start_transaction(self):
        return None

    def persist(self, state, *, create=False):
        self.persisted.append(json.loads(json.dumps(state)))
        self.tx._state = state

    def receipt(self):
        checkpoints = []
        for (name, digest), observation in zip(MIGRATIONS, (self.first, self.after)):
            checkpoints.append({"name": name, "sha256": digest,
                **{key: observation[key] for key in ("schema_sha256", "ledger_sha256", "data_sha256")},
                "migrations": observation["migrations"]})
        receipt = {
            "schema": 1, "transaction_id": self.tx.transaction_id,
            "application_sha": self.identity.application_sha,
            "artifact_sha256": self.identity.artifact_sha256,
            "source_sha256": self.identity.previous_sha,
            "target_uuid_sha256": self.identity.mysql_uuid_sha256,
            "database": "wpcg_staging_next",
            "mysql": {"log_bin": True, "binlog_format": "ROW", "trust_function_creators": "OFF"},
            "migration_files": [{"name": name + ".php", "sha256": digest} for name, digest in MIGRATIONS],
            "migration_checkpoints": checkpoints,
            "before": self.before,
            "after": self.after,
            "dba": {"approved_definer": "phase2b_guard@localhost", "temporary_super_revoked": True,
                    "elevated_sessions_terminated": True, "global_privileges_only_usage": True,
                    "account_locked": True, "definer_durable": True, "schema_grants_minimized": True},
            "trigger_inventory": self.inventory,
        }
        receipt["receipt_sha256"] = receipt_digest(receipt)
        return receipt

    def pause(self):
        self.start_transaction()
        current = dict(self.before)
        with self.assertRaisesRegex(TX.TransactionRefused, "phase2b_dba_handoff_required"):
            self.tx.apply_rehearsed_migrations(
                expected_after=self.expected, observe=lambda: current,
                apply_one=lambda _name: self.fail("trigger boundary crossed before DBA handoff"))

    def resume(self, receipt):
        return self.tx.resume_dba_handoff(
            receipt=receipt, observed=dict(self.after), trigger_inventory=self.inventory,
            source_sha256=self.identity.previous_sha, expected_definer="phase2b_guard@localhost")

    def test_pause_is_journaled_and_app_loop_cannot_cross_trigger_boundary(self):
        self.pause()
        self.assertEqual(self.tx.state["state"], "DBA_HANDOFF_REQUIRED")
        self.assertEqual(self.tx.state["migration_cursor"], 0)
        self.tx._validate_state(dict(self.tx.state))
        with self.assertRaisesRegex(TX.TransactionRefused, "phase2b_dba_handoff_required"):
            self.tx.apply_rehearsed_migrations(expected_after=self.expected,
                observe=lambda: dict(self.before), apply_one=lambda _name: self.fail("unexpected migration"))

    def test_pause_is_reached_at_real_nonzero_migration_cursor(self):
        prior = tuple(f"2026_10_01_{index + 1:06d}_prior_{index}" for index in range(71))
        migrations = (*prior, *self.names, "2026_11_16_000001_after_trigger_boundary")
        identity = TX.TransactionIdentity(
            application_sha="a" * 40, previous_sha="b" * 40,
            artifact_sha256="c" * 64, backup_sha256="d" * 64,
            backup_filename=f"staging-next-{'a' * 40}-123456789-abcdef12.sql.gz",
            mysql_uuid_sha256="e" * 64, migrations=migrations)
        transaction = TX.DatabaseReleaseTransaction(self.directory, identity)
        transaction._lock_fd = 1
        transaction._state = transaction._new_state()
        transaction._state["state"] = "FENCES_CLOSED"
        transaction._state["migration_cursor"] = len(prior)
        transaction._state["evidence"] = {
            "RESTORE_REHEARSAL_VERIFIED": {
                "target_schema_sha256": "1" * 64, "target_ledger_sha256": "2" * 64,
                "target_data_sha256": "3" * 64,
            },
            "migration:" + prior[-1]: {
                "schema_sha256": self.before["schema_sha256"],
                "ledger_sha256": self.before["ledger_sha256"],
                "data_sha256": self.before["data_sha256"],
            },
        }
        with mock.patch.object(transaction, "_persist", side_effect=lambda state, **_kwargs:
                               setattr(transaction, "_state", state)):
            with self.assertRaisesRegex(TX.TransactionRefused, "phase2b_dba_handoff_required"):
                transaction.apply_rehearsed_migrations(
                    expected_after={name: {"schema_sha256": "4" * 64,
                                           "ledger_sha256": "5" * 64,
                                           "data_sha256": "6" * 64} for name in migrations},
                    observe=lambda: dict(self.before),
                    apply_one=lambda name: self.fail(f"migration crossed DBA boundary: {name}"))
        self.assertEqual(transaction.state["state"], "DBA_HANDOFF_REQUIRED")
        self.assertEqual(transaction.state["migration_cursor"], len(prior))

    def test_missing_stale_or_malformed_receipts_do_not_resume(self):
        self.pause()
        with self.assertRaisesRegex(TX.TransactionRefused, "phase2b_dba_receipt_missing"):
            self.resume(None)
        malformed = {"schema": 1}
        with self.assertRaises(TX.TransactionRefused):
            self.resume(malformed)
        stale = self.receipt()
        stale["application_sha"] = "f" * 40
        stale["receipt_sha256"] = receipt_digest(stale)
        with self.assertRaisesRegex(TX.TransactionRefused, "phase2b_dba_release_sha_mismatch"):
            self.resume(stale)
        self.assertEqual(self.tx.state["state"], "DBA_HANDOFF_REQUIRED")

    def test_tampered_receipt_and_mismatched_observation_fail_closed(self):
        self.pause()
        receipt = self.receipt()
        receipt["receipt_sha256"] = "f" * 64
        with self.assertRaisesRegex(TX.TransactionRefused, "phase2b_dba_receipt_hash_mismatch"):
            self.resume(receipt)
        receipt = self.receipt()
        wrong = dict(self.after)
        wrong["ledger_sha256"] = "f" * 64
        with self.assertRaisesRegex(TX.TransactionRefused, "phase2b_dba_observed_target_mismatch"):
            self.tx.resume_dba_handoff(receipt=receipt, observed=wrong,
                trigger_inventory=self.inventory, source_sha256=self.identity.previous_sha,
                expected_definer="phase2b_guard@localhost")
        self.assertEqual(self.tx.state["state"], "DBA_HANDOFF_REQUIRED")

    def test_wrong_pinned_migration_hash_and_failed_dba_cleanup_reject_resume(self):
        self.pause()
        receipt = self.receipt()
        receipt["migration_files"][0]["sha256"] = "f" * 64
        receipt["receipt_sha256"] = receipt_digest(receipt)
        with self.assertRaisesRegex(TX.TransactionRefused, "phase2b_dba_migration_hash_mismatch"):
            self.resume(receipt)
        receipt = self.receipt()
        receipt["dba"]["elevated_sessions_terminated"] = False
        receipt["receipt_sha256"] = receipt_digest(receipt)
        with self.assertRaisesRegex(TX.TransactionRefused, "phase2b_dba_cleanup_unverified"):
            self.resume(receipt)

    def test_successful_resume_records_pair_then_app_loop_continues(self):
        self.pause()
        receipt = self.receipt()
        self.resume(receipt)
        self.assertEqual(self.tx.state["state"], "FENCES_CLOSED")
        self.assertEqual(self.tx.state["migration_cursor"], 2)
        self.tx._validate_state(dict(self.tx.state))
        self.assertEqual(self.tx.state["evidence"]["DBA_HANDOFF_VERIFIED"]["receipt_sha256"],
                         receipt["receipt_sha256"])
        current = dict(self.after)
        calls = []
        def apply(name):
            calls.append(name)
            current.update(self.expected[name])
            current["migrations"].append(name)
            return True
        self.tx.apply_rehearsed_migrations(expected_after=self.expected,
            observe=lambda: current, apply_one=apply)
        self.assertEqual(calls, [self.identity.migrations[2]])
        self.assertEqual(self.tx.state["state"], "MIGRATIONS_VERIFIED")

    def test_repeated_resume_is_idempotent_after_committed_handoff(self):
        self.pause()
        receipt = self.receipt()
        first = self.resume(receipt)
        self.assertEqual(first, self.resume(receipt))
        self.assertEqual(self.tx.state["migration_cursor"], 2)

    def test_recovery_reopens_paused_journal_and_consumes_same_receipt(self):
        self.pause()
        resumed = TX.DatabaseReleaseTransaction(self.directory, self.identity)
        resumed._lock_fd = 1
        resumed._state = json.loads(json.dumps(self.tx._state))
        persist_patch = mock.patch.object(resumed, "_persist", side_effect=lambda value, **_kwargs:
                                          setattr(resumed, "_state", value))
        with persist_patch:
            self.assertEqual(resumed.state["state"], "DBA_HANDOFF_REQUIRED")
            resumed._validate_state(dict(resumed.state))
            receipt = self.receipt()
            observed_tuple = dict(self.after)
            observed_tuple["migrations"] = tuple(observed_tuple["migrations"])
            digest = resumed.resume_dba_handoff(receipt=receipt, observed=observed_tuple,
                trigger_inventory=self.inventory, source_sha256=self.identity.previous_sha,
                expected_definer="phase2b_guard@localhost")
            self.assertEqual(digest, receipt["receipt_sha256"])
            self.assertEqual(resumed.state["migration_cursor"], 2)


class Phase2BDbaBackendReceiptTests(unittest.TestCase):
    def setUp(self):
        self.transaction = type("Transaction", (), {})()
        self.transaction.state = {"state": "DBA_HANDOFF_REQUIRED"}
        self.transaction.transaction_id = "a" * 32
        self.transaction.identity = type("Identity", (), {"previous_sha": "b" * 40})()
        def record_resume(**kwargs):
            self.transaction.resume_call = kwargs
        self.transaction.resume_dba_handoff = record_resume
        self.receipt = {"trigger_inventory": [
            {"name": f"guard_{i}", "definer": "phase2b_guard@localhost", "valid": True}
            for i in range(6)]}
        self.profile = type("Profile", (), {"state_dir": Path("/protected/state")})()

    def test_backend_consumes_only_fixed_transaction_bound_root_receipt(self):
        snapshot = {"schema_sha256": "c" * 64, "ledger_sha256": "d" * 64,
                    "data_sha256": "e" * 64, "migrations": ["m1", *[name for name, _ in MIGRATIONS]]}
        triggers = [{"name": item["name"], "definer": item["definer"]}
                    for item in self.receipt["trigger_inventory"]]
        with (mock.patch.object(BACKEND, "_lstat", return_value=SimpleNamespace(
                  st_mode=stat.S_IFREG | 0o400, st_uid=0, st_nlink=1)),
              mock.patch.object(BACKEND, "_read_regular_nofollow", return_value=json.dumps(self.receipt).encode())
              as read_receipt,
              mock.patch.object(BACKEND, "database_fingerprint", return_value=snapshot),
              mock.patch.object(BACKEND._TRANSACTION_MODULE, "database_trigger_inventory", return_value=triggers),
              mock.patch.object(BACKEND, "_phase2b_expected_definer", return_value="phase2b_guard@localhost")):
            self.assertTrue(BACKEND._consume_phase2b_dba_handoff(self.profile, self.transaction, object()))
        read_receipt.assert_called_once_with(
            Path("/protected/state/phase2b-dba-receipt-" + "a" * 32 + ".json"),
            max_bytes=64_000, expected_uid=0, expected_mode=0o400)
        self.assertEqual(self.transaction.resume_call["source_sha256"], "b" * 40)

    def test_backend_does_not_read_or_resume_without_paused_checkpoint(self):
        self.transaction.state = {"state": "FENCES_CLOSED"}
        with mock.patch.object(BACKEND, "_read_regular_nofollow") as read_receipt:
            self.assertFalse(BACKEND._consume_phase2b_dba_handoff(self.profile, self.transaction, object()))
        read_receipt.assert_not_called()

    def test_backend_refuses_missing_receipt_and_trigger_inventory_mismatch(self):
        with mock.patch.object(BACKEND, "_read_regular_nofollow", side_effect=BACKEND.DeployRefused("missing")):
            with self.assertRaisesRegex(BACKEND.DeployRefused, "phase2b_dba_receipt_missing_or_unprotected"):
                BACKEND._consume_phase2b_dba_handoff(self.profile, self.transaction, object())
        with (mock.patch.object(BACKEND, "_lstat", return_value=SimpleNamespace(
                  st_mode=stat.S_IFREG | 0o400, st_uid=0, st_nlink=1)),
              mock.patch.object(BACKEND, "_read_regular_nofollow", return_value=json.dumps(self.receipt).encode()),
              mock.patch.object(BACKEND, "database_fingerprint", return_value={
                  "schema_sha256": "c" * 64, "ledger_sha256": "d" * 64,
                  "data_sha256": "e" * 64, "migrations": []}),
              mock.patch.object(BACKEND._TRANSACTION_MODULE, "database_trigger_inventory", return_value=[])):
            with self.assertRaisesRegex(BACKEND.DeployRefused, "phase2b_dba_trigger_inventory_observation_mismatch"):
                BACKEND._consume_phase2b_dba_handoff(self.profile, self.transaction, object())

    def test_backend_rejects_writable_or_linked_and_malformed_receipt_files(self):
        for info in (SimpleNamespace(st_mode=stat.S_IFREG | 0o600, st_uid=0, st_nlink=1),
                     SimpleNamespace(st_mode=stat.S_IFREG | 0o400, st_uid=0, st_nlink=2),
                     SimpleNamespace(st_mode=stat.S_IFREG | 0o400, st_uid=1000, st_nlink=1)):
            with self.subTest(metadata=info), mock.patch.object(BACKEND, "_lstat", return_value=info):
                with self.assertRaisesRegex(BACKEND.DeployRefused, "phase2b_dba_receipt_missing_or_unprotected"):
                    BACKEND._consume_phase2b_dba_handoff(self.profile, self.transaction, object())
        with (mock.patch.object(BACKEND, "_lstat", return_value=SimpleNamespace(
                  st_mode=stat.S_IFREG | 0o400, st_uid=0, st_nlink=1)),
              mock.patch.object(BACKEND, "_read_regular_nofollow", return_value=b"{")):
            with self.assertRaisesRegex(BACKEND.DeployRefused, "phase2b_dba_receipt_invalid"):
                BACKEND._consume_phase2b_dba_handoff(self.profile, self.transaction, object())


if __name__ == "__main__":
    unittest.main()
