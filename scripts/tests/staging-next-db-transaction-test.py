#!/usr/bin/env python3
"""Linux filesystem/process tests for the durable staging-next DB journal."""

from __future__ import annotations

import json
import os
import hashlib
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from scripts.staging_next_db_transaction import (
    DatabaseReleaseTransaction,
    TransactionIdentity,
    TransactionRefused,
    _mysql_defaults,
    _mysql_query_failure_code,
    _backup_failure_code,
    database_fingerprint,
    load_execution_policy,
    restore_receipt_sha256,
    verify_background_fences,
)


class DatabaseReleaseTransactionTests(unittest.TestCase):
    def restore_checkpoint(self):
        evidence = {
            "backup_sha256": self.identity.backup_sha256,
            "target_schema_sha256": "4" * 64,
            "target_ledger_sha256": "5" * 64,
            "target_data_sha256": "c" * 64,
            "restored_schema_sha256": "4" * 64,
            "restored_ledger_sha256": "5" * 64,
            "restored_data_sha256": "6" * 64,
            "restore_mysql_uuid_sha256": "f" * 64,
        }
        evidence["restore_receipt_sha256"] = restore_receipt_sha256(evidence)
        return evidence

    def test_backup_diagnostics_are_reduced_to_allowlisted_codes(self):
        cases = (
            (b"Access denied for user 'hidden'", "phase_b_backup_authorization_failed"),
            (b"mysqldump: unknown option --secret", "phase_b_backup_client_contract_failed"),
            (b"user lacks PROCESS privilege", "phase_b_backup_privilege_missing"),
            (b"you need the PROCESS privilege", "phase_b_backup_privilege_missing"),
            (b"couldn't execute SHOW TABLE STATUS", "phase_b_backup_metadata_query_failed"),
            (b"ERROR 1142 (42000)", "phase_b_backup_mysql_errno_1142"),
            (b"mysqldump: Got error: 1419: access denied for routine creation",
             "phase_b_backup_mysql_errno_1419"),
            (b"mysqldump: Got error: 2002: Can't connect through socket '/secret/path.sock'",
             "phase_b_backup_mysql_errno_2002_local_socket_route"),
            (b"mysqldump: Got error: 2003: Can't connect to server on 'private-host'",
             "phase_b_backup_mysql_errno_2003_network_route"),
            (b"mysqldump: Couldn't execute 'SHOW VIEW': denied (1142)\n",
             "phase_b_backup_mysql_errno_1142"),
            (b"unrecognized private error text", "phase_b_backup_server_rejected"),
            (b"x" * 20_000 + b"Access denied", "phase_b_backup_server_rejected"),
        )
        for stderr, expected in cases:
            with self.subTest(expected=expected):
                self.assertEqual(_backup_failure_code(stderr), expected)

    def test_mysql_query_diagnostics_are_reduced_to_codes(self):
        self.assertEqual(_mysql_query_failure_code(b"ERROR 1045 (28000): denied for 'secret'"),
                         "phase_b_mysql_errno_1045_sqlstate_28000")
        self.assertEqual(_mysql_query_failure_code(b"mysql: Got error: 2002: path /private/socket"),
                         "phase_b_mysql_errno_2002_sqlstate_UNKNOWN")
        self.assertEqual(_mysql_query_failure_code(b"Access denied for user 'secret'"),
                         "phase_b_mysql_authorization_failed")
        self.assertEqual(_mysql_query_failure_code(b"private diagnostic"),
                         "phase_b_mysql_query_failed")

    def test_database_fingerprint_binds_row_counts_and_ordered_table_contents(self):
        uuid = "00000000-0000-0000-0000-000000000000"
        policy = SimpleNamespace(target_uuid_sha256=hashlib.sha256(uuid.encode()).hexdigest(),
                                 isolated_uuid_sha256=hashlib.sha256(uuid.encode()).hexdigest())

        def query_result(sql):
            if sql.startswith("SELECT VERSION()"):
                return f"8.4.11\t{uuid}\twpcg_staging_next\n".encode()
            if "FROM information_schema.TABLES" in sql and "TABLE_TYPE='BASE TABLE'" in sql:
                return b"users\tInnoDB\t1\n"
            if sql.startswith("SELECT 'users',COUNT(*)"):
                return b"users\t3\n"
            if sql.startswith("SELECT migration FROM migrations"):
                return b"2026_11_01_000001_test\n"
            return b""

        with mock.patch("scripts.staging_next_db_transaction._mysql_query",
                        side_effect=lambda _policy, sql, **_kwargs: query_result(sql)), \
             mock.patch("scripts.staging_next_db_transaction._database_data_fingerprint",
                        return_value=("a" * 64, 123)):
            result = database_fingerprint(policy, database="wpcg_staging_next", isolated=False)
        expected = hashlib.sha256(
            b'{"counts":[["users",3]],"dump_sha256":"' + b"a" * 64
            + b'","dump_bytes":123}').hexdigest()
        self.assertEqual(result["data_sha256"], expected)
        self.assertNotIn("users", result)

        def nontransactional(sql):
            if "FROM information_schema.TABLES" in sql and "TABLE_TYPE='BASE TABLE'" in sql:
                return b"users\tMyISAM\t1\n"
            return query_result(sql)

        with mock.patch("scripts.staging_next_db_transaction._mysql_query",
                        side_effect=lambda _policy, sql, **_kwargs: nontransactional(sql)):
            with self.assertRaisesRegex(TransactionRefused, "phase_b_nontransactional_table_unsupported"):
                database_fingerprint(policy, database="wpcg_staging_next", isolated=False)

        def no_primary(sql):
            if "FROM information_schema.TABLES" in sql and "TABLE_TYPE='BASE TABLE'" in sql:
                return b"users\tInnoDB\t0\n"
            return query_result(sql)

        with mock.patch("scripts.staging_next_db_transaction._mysql_query",
                        side_effect=lambda _policy, sql, **_kwargs: no_primary(sql)):
            with self.assertRaisesRegex(TransactionRefused, "phase_b_table_without_primary_key_unsupported"):
                database_fingerprint(policy, database="wpcg_staging_next", isolated=False)

    def test_mysql_memfd_option_file_is_private_for_client_acceptance(self):
        fd, path = _mysql_defaults(
            {"username": "synthetic-user", "password": "synthetic-password"},
            "127.0.0.1", 3306,
        )
        try:
            self.assertEqual(os.fstat(fd).st_mode & 0o777, 0o600)
            self.assertEqual(path, f"/proc/self/fd/{fd}")
        finally:
            os.close(fd)

    def setUp(self):
        if os.geteuid() != 0:
            self.skipTest("journal metadata tests require root in the disposable Linux container")
        self.temp = tempfile.TemporaryDirectory(prefix="wpcg-db-transaction-", dir="/tmp")
        self.addCleanup(self.temp.cleanup)
        self.state_dir = Path(self.temp.name)
        os.chmod(self.state_dir, 0o700)
        self.identity = TransactionIdentity(
            application_sha="a" * 40,
            previous_sha="b" * 40,
            artifact_sha256="c" * 64,
            backup_sha256="d" * 64,
            backup_filename=f"staging-next-{'a' * 40}-123456789-abcdef12.sql.gz",
            mysql_uuid_sha256="e" * 64,
            migrations=("2026_11_01_000001_add_widget", "2026_11_02_000002_add_gadget"),
        )
        self.tx = DatabaseReleaseTransaction(self.state_dir, self.identity)

    def test_phase_b_policy_requires_exact_root_owned_artifacts_and_isolated_identity(self):
        with tempfile.TemporaryDirectory(prefix="wpcg-phase-b-policy-", dir="/root") as temporary:
            root = Path(temporary)
            os.chmod(root, 0o700)
            backup = root / "backups"
            backup.mkdir(mode=0o700)
            credentials = root / "credentials.json"
            credential_raw = json.dumps({
                "schema": 1,
                "target": {"username": "synthetic-target", "password": "synthetic-target-secret"},
                "isolated": {"username": "synthetic-isolated", "password": "synthetic-isolated-secret"},
            }, sort_keys=True, separators=(",", ":")).encode()
            credentials.write_bytes(credential_raw)
            os.chmod(credentials, 0o600)
            policy_path = root / "policy.json"
            worker_marker = root / "workers-disabled"
            scheduler_marker = root / "scheduler-paused"
            worker_marker.write_bytes(b"schema=1;profile=staging-next;workers=disabled\n")
            scheduler_marker.write_bytes(b"schema=1;profile=staging-next;scheduler=paused\n")
            os.chmod(worker_marker, 0o600)
            os.chmod(scheduler_marker, 0o600)
            policy = {
                "schema": 1, "profile": "staging-next", "enabled": True,
                "target_database": "wpcg_staging_next", "target_uuid_sha256": "e" * 64,
                "isolated_uuid_sha256": "f" * 64, "backup_directory": str(backup),
                "credentials_file": str(credentials),
                "credentials_sha256": hashlib.sha256(credential_raw).hexdigest(),
                "target_host": "127.0.0.1", "target_port": 3306,
                "restore_host": "127.0.0.1", "restore_port": 3307,
                "maintenance_file": str(root / "storage/framework/down"),
                "worker_programs": ["wpcaregrid-staging-next-default", "wpcaregrid-staging-next-backups"],
                "scheduler_unit": "wpcaregrid-staging-next-scheduler.timer",
                "worker_disabled_marker": str(worker_marker),
                "scheduler_paused_marker": str(scheduler_marker),
            }
            policy_raw = json.dumps(policy, sort_keys=True, separators=(",", ":")).encode()
            policy_path.write_bytes(policy_raw)
            os.chmod(policy_path, 0o644)
            paths = {"policy": policy_path, "credentials": credentials, "backup": backup,
                     "worker_disabled_marker": worker_marker, "scheduler_paused_marker": scheduler_marker}
            loaded = load_execution_policy(
                policy_path, hashlib.sha256(policy_raw).hexdigest(),
                expected_target_uuid_sha256="e" * 64,
                maintenance_file=Path(policy["maintenance_file"]), test_only_paths=paths)
            self.assertEqual(loaded.target_uuid_sha256, "e" * 64)
            self.assertNotEqual(loaded.target_uuid_sha256, loaded.isolated_uuid_sha256)
            self.assertEqual(loaded.worker_programs, tuple(policy["worker_programs"]))
            self.assertEqual(loaded.worker_disabled_marker, worker_marker)
            self.assertEqual(loaded.scheduler_paused_marker, scheduler_marker)
            self.assertNotIn("synthetic-target-secret", repr(loaded))
            with self.subTest("missing hash is fail closed"):
                with self.assertRaises(TransactionRefused) as error:
                    load_execution_policy(
                        policy_path, None,
                        expected_target_uuid_sha256="e" * 64,
                        maintenance_file=Path(policy["maintenance_file"]), test_only_paths=paths)
                self.assertEqual(str(error.exception), "phase_b_policy_unpinned")

            with self.subTest("target identity mismatch"):
                with self.assertRaises(TransactionRefused) as error:
                    load_execution_policy(
                        policy_path, hashlib.sha256(policy_raw).hexdigest(),
                        expected_target_uuid_sha256="a" * 64,
                        maintenance_file=Path(policy["maintenance_file"]), test_only_paths=paths)
                self.assertEqual(str(error.exception), "phase_b_policy_contract_invalid")
            with self.subTest("policy hash mismatch"):
                with self.assertRaises(TransactionRefused) as error:
                    load_execution_policy(
                        policy_path, "0" * 64,
                        expected_target_uuid_sha256="e" * 64,
                        maintenance_file=Path(policy["maintenance_file"]), test_only_paths=paths)
                self.assertEqual(str(error.exception), "phase_b_policy_hash_mismatch")

            class Result:
                def __init__(self, code, output):
                    self.returncode = code
                    self.stdout = output

            def fence_runner(active_consumer=False, consumer_command=b"php artisan queue:work default\n",
                             scheduler_enabled=False,
                             worker_state="STOPPED", worker_rc=3):
                def run(argv, **_kwargs):
                    if argv[:3] == ["/usr/bin/supervisorctl", "status", "wpcaregrid-staging-next-default"]:
                        return Result(worker_rc, f"wpcaregrid-staging-next-default {worker_state}\n".encode())
                    if argv[:3] == ["/usr/bin/supervisorctl", "status", "wpcaregrid-staging-next-backups"]:
                        return Result(worker_rc, f"wpcaregrid-staging-next-backups {worker_state}\n".encode())
                    if argv[0].endswith("systemctl") and "ActiveState" in argv[2]:
                        return Result(0, b"inactive\n")
                    if argv[0].endswith("systemctl") and "UnitFileState" in argv[2]:
                        return Result(0, b"enabled\n" if scheduler_enabled else b"disabled\n")
                    if argv[0].endswith("ps"):
                        return Result(0, consumer_command if active_consumer else b"/sbin/init\n")
                    raise AssertionError("unexpected fixed command")
                return run

            self.assertEqual(len(verify_background_fences(loaded, runner=fence_runner())), 64)
            with self.assertRaisesRegex(TransactionRefused, "phase_b_scheduler_not_persistently_paused"):
                verify_background_fences(loaded, runner=fence_runner(scheduler_enabled=True))
            with self.assertRaisesRegex(TransactionRefused, "phase_b_background_consumer_active"):
                verify_background_fences(loaded, runner=fence_runner(active_consumer=True))
            for command in (
                b"www-data 400 /usr/bin/php artisan queue:listen default\n",
                b"www-data 401 /usr/bin/php artisan horizon\n",
                b"www-data 402 /usr/bin/php artisan horizon:work\n",
                b"www-data 403 /usr/bin/php artisan --env=staging queue:work default\n",
                b"www-data 404 /usr/bin/php artisan --env staging queue:listen default\n",
                b"www-data 405 /usr/bin/php artisan --no-ansi horizon\n",
                b"www-data 406 /usr/bin/php artisan --no-interaction schedule:run\n",
            ):
                with self.subTest(consumer_command=command):
                    with self.assertRaisesRegex(TransactionRefused, "phase_b_background_consumer_active"):
                        verify_background_fences(
                            loaded, runner=fence_runner(active_consumer=True, consumer_command=command))
            with self.assertRaisesRegex(TransactionRefused, "phase_b_worker_not_stopped"):
                verify_background_fences(loaded, runner=fence_runner(worker_state="RUNNING", worker_rc=0))
            with self.assertRaisesRegex(TransactionRefused, "phase_b_worker_not_stopped"):
                verify_background_fences(loaded, runner=fence_runner(worker_state="STOPPED", worker_rc=7))
            worker_marker.write_bytes(b"schema=1;profile=staging-next;workers=unknown\n")
            with self.assertRaisesRegex(TransactionRefused, "phase_b_background_fence_marker_invalid"):
                verify_background_fences(loaded, runner=fence_runner())

    def advance_to_migrating(self):
        self.tx.advance("BACKUP_VERIFIED", {"backup_sha256": self.identity.backup_sha256})
        self.tx.advance("RESTORE_REHEARSAL_VERIFIED", self.restore_checkpoint())
        self.tx.advance("MIGRATION_REHEARSAL_VERIFIED", {"migration_rehearsal_receipt_sha256": "2" * 64})
        self.tx.advance("FENCES_CLOSED", {"fence_receipt_sha256": "3" * 64})

    def test_transaction_advances_only_in_order_and_binds_backup(self):
        with self.tx:
            self.assertEqual(self.tx.state["state"], "PREPARED")
            with self.assertRaisesRegex(TransactionRefused, "transaction_transition_out_of_order"):
                self.tx.advance("RESTORE_REHEARSAL_VERIFIED", {"restore_receipt_sha256": "1" * 64})
            with self.assertRaisesRegex(TransactionRefused, "backup_identity_mismatch"):
                self.tx.advance("BACKUP_VERIFIED", {"backup_sha256": "f" * 64})
            self.advance_to_migrating()
            self.assertEqual(self.tx.state["state"], "FENCES_CLOSED")

    def test_rehearsed_sequence_executes_exact_migrations_and_verifies_each_postcondition(self):
        current = {"schema_sha256": "4" * 64, "ledger_sha256": "5" * 64,
                   "data_sha256": "c" * 64, "migrations": []}
        rehearsed = {
            "2026_11_01_000001_add_widget": {"schema_sha256": "6" * 64, "ledger_sha256": "7" * 64,
                                               "data_sha256": "c" * 64},
            "2026_11_02_000002_add_gadget": {"schema_sha256": "9" * 64, "ledger_sha256": "a" * 64,
                                               "data_sha256": "c" * 64},
        }
        observed_calls = []

        def observe():
            return dict(current)

        def apply_one(name):
            observed_calls.append(name)
            current.update(rehearsed[name])
            current["migrations"] = [*current["migrations"], name]
            return True

        with self.tx:
            self.advance_to_migrating()
            self.tx.apply_rehearsed_migrations(expected_after=rehearsed, observe=observe, apply_one=apply_one)
            self.assertEqual(observed_calls, list(self.identity.migrations))
            self.assertEqual(self.tx.state["state"], "MIGRATIONS_VERIFIED")

    def test_target_mutation_after_rehearsal_is_rejected_before_any_migration(self):
        current = {"schema_sha256": "4" * 64, "ledger_sha256": "5" * 64,
                   "data_sha256": "d" * 64, "migrations": []}
        rehearsed = {
            "2026_11_01_000001_add_widget": {"schema_sha256": "6" * 64, "ledger_sha256": "7" * 64,
                                               "data_sha256": "d" * 64},
            "2026_11_02_000002_add_gadget": {"schema_sha256": "9" * 64, "ledger_sha256": "a" * 64,
                                               "data_sha256": "d" * 64},
        }
        attempted = []
        with self.tx:
            self.advance_to_migrating()
            with self.assertRaisesRegex(TransactionRefused, "target_changed_since_migration_rehearsal"):
                self.tx.apply_rehearsed_migrations(
                    expected_after=rehearsed, observe=lambda: dict(current),
                    apply_one=lambda name: attempted.append(name) or True,
                )
            self.assertEqual(attempted, [])
            self.assertEqual(self.tx.state["state"], "FENCES_CLOSED")

    def test_failed_migration_does_not_auto_retry_when_database_is_unchanged(self):
        current = {"schema_sha256": "4" * 64, "ledger_sha256": "5" * 64,
                   "data_sha256": "c" * 64, "migrations": []}
        rehearsed = {
            "2026_11_01_000001_add_widget": {"schema_sha256": "6" * 64, "ledger_sha256": "7" * 64,
                                               "data_sha256": "c" * 64},
            "2026_11_02_000002_add_gadget": {"schema_sha256": "9" * 64, "ledger_sha256": "a" * 64,
                                               "data_sha256": "c" * 64},
        }
        attempts = []

        with self.tx:
            self.advance_to_migrating()
            with self.assertRaisesRegex(TransactionRefused, "migration_execution_not_verified"):
                self.tx.apply_rehearsed_migrations(
                    expected_after=rehearsed, observe=lambda: dict(current),
                    apply_one=lambda _name: attempts.append("attempt") or False,
                )
            self.assertEqual(attempts, ["attempt"])
            self.assertEqual(self.tx.state["state"], "FENCES_CLOSED")

    def test_partial_execution_is_reconciled_one_migration_at_a_time(self):
        with self.tx:
            self.advance_to_migrating()
            self.tx.begin_migration("2026_11_01_000001_add_widget",
                                    observed_schema_sha256="4" * 64,
                                    observed_ledger_sha256="5" * 64,
                                    observed_data_sha256="c" * 64,
                                    expected_schema_sha256="6" * 64,
                                    expected_ledger_sha256="7" * 64,
                                    expected_data_sha256="c" * 64)
        # Reopen simulates a process restart after a durable start checkpoint.
        resumed = DatabaseReleaseTransaction(self.state_dir, self.identity)
        with resumed:
            status = resumed.reconcile_interrupted_migration(
                ledger_contains_started_migration=True,
                observed_schema_sha256="6" * 64,
                observed_ledger_sha256="7" * 64,
                observed_data_sha256="c" * 64,
                migration_receipt_sha256="8" * 64,
            )
            self.assertEqual(status, "COMPLETED_FROM_VERIFIED_LEDGER")
            self.assertEqual(resumed.state["migration_cursor"], 1)
            resumed.begin_migration("2026_11_02_000002_add_gadget",
                                    observed_schema_sha256="6" * 64,
                                    observed_ledger_sha256="7" * 64,
                                    observed_data_sha256="c" * 64,
                                    expected_schema_sha256="9" * 64,
                                    expected_ledger_sha256="a" * 64,
                                    expected_data_sha256="c" * 64)
            resumed.complete_migration("2026_11_02_000002_add_gadget",
                                       observed_schema_sha256="9" * 64,
                                       observed_ledger_sha256="a" * 64,
                                       observed_data_sha256="c" * 64,
                                       migration_receipt_sha256="b" * 64)
            self.assertEqual(resumed.state["state"], "MIGRATIONS_VERIFIED")
            self.assertEqual(resumed.state["migration_cursor"], 2)

    def test_backend_lookup_reopens_exact_unfinished_transaction_after_restart(self):
        with self.tx:
            self.advance_to_migrating()
            self.tx.begin_migration("2026_11_01_000001_add_widget",
                                    observed_schema_sha256="4" * 64,
                                    observed_ledger_sha256="5" * 64,
                                    observed_data_sha256="c" * 64,
                                    expected_schema_sha256="6" * 64,
                                    expected_ledger_sha256="7" * 64,
                                    expected_data_sha256="c" * 64)

        pending = DatabaseReleaseTransaction.find_unfinished_for_release(
            self.state_dir,
            application_sha=self.identity.application_sha,
            previous_sha=self.identity.previous_sha,
            artifact_sha256=self.identity.artifact_sha256,
            mysql_uuid_sha256=self.identity.mysql_uuid_sha256,
            migrations=self.identity.migrations,
        )
        self.assertIsNotNone(pending)
        identity, state = pending
        self.assertEqual(identity, self.identity)
        self.assertEqual(state["state"], "MIGRATING")
        resumed = DatabaseReleaseTransaction(self.state_dir, identity)
        with resumed:
            self.assertEqual(resumed.state["state"], "MIGRATING")

    def test_backend_lookup_holds_unfinished_transaction_for_another_release(self):
        with self.tx:
            self.advance_to_migrating()
        with self.assertRaisesRegex(TransactionRefused, "unfinished_database_transaction"):
            DatabaseReleaseTransaction.find_unfinished_for_release(
                self.state_dir,
                application_sha="f" * 40,
                previous_sha=self.identity.previous_sha,
                artifact_sha256=self.identity.artifact_sha256,
                mysql_uuid_sha256=self.identity.mysql_uuid_sha256,
                migrations=self.identity.migrations,
            )

    def test_interrupted_migration_with_unchanged_db_is_the_only_retry_case(self):
        with self.tx:
            self.advance_to_migrating()
            self.tx.begin_migration("2026_11_01_000001_add_widget",
                                    observed_schema_sha256="4" * 64,
                                    observed_ledger_sha256="5" * 64,
                                    observed_data_sha256="c" * 64,
                                    expected_schema_sha256="6" * 64,
                                    expected_ledger_sha256="7" * 64,
                                    expected_data_sha256="c" * 64)
        resumed = DatabaseReleaseTransaction(self.state_dir, self.identity)
        with resumed:
            status = resumed.reconcile_interrupted_migration(
                ledger_contains_started_migration=False,
                observed_schema_sha256="4" * 64,
                observed_ledger_sha256="5" * 64,
                observed_data_sha256="c" * 64,
            )
            self.assertEqual(status, "SAFE_TO_RETRY_UNCHANGED_DATABASE")
            self.assertEqual(resumed.state["state"], "FENCES_CLOSED")
            resumed.begin_migration("2026_11_01_000001_add_widget",
                                    observed_schema_sha256="4" * 64,
                                    observed_ledger_sha256="5" * 64,
                                    observed_data_sha256="c" * 64,
                                    expected_schema_sha256="6" * 64,
                                    expected_ledger_sha256="7" * 64,
                                    expected_data_sha256="c" * 64)

    def test_process_death_reopens_durable_checkpoint_without_resetting_it(self):
        pid = os.fork()
        if pid == 0:
            try:
                child_tx = DatabaseReleaseTransaction(self.state_dir, self.identity)
                with child_tx:
                    child_tx.advance("BACKUP_VERIFIED", {"backup_sha256": self.identity.backup_sha256})
                    child_tx.advance("RESTORE_REHEARSAL_VERIFIED", self.restore_checkpoint())
                    child_tx.advance("MIGRATION_REHEARSAL_VERIFIED", {"migration_rehearsal_receipt_sha256": "2" * 64})
                    child_tx.advance("FENCES_CLOSED", {"fence_receipt_sha256": "3" * 64})
                    child_tx.begin_migration("2026_11_01_000001_add_widget",
                                             observed_schema_sha256="4" * 64,
                                             observed_ledger_sha256="5" * 64,
                                             observed_data_sha256="c" * 64,
                                             expected_schema_sha256="6" * 64,
                                             expected_ledger_sha256="7" * 64,
                                             expected_data_sha256="c" * 64)
                os._exit(73)
            except BaseException:
                os._exit(74)
        _pid, status = os.waitpid(pid, 0)
        self.assertTrue(os.WIFEXITED(status))
        self.assertEqual(os.WEXITSTATUS(status), 73)
        resumed = DatabaseReleaseTransaction(self.state_dir, self.identity)
        with resumed:
            self.assertEqual(resumed.state["state"], "MIGRATING")
            self.assertEqual(resumed.state["migration_cursor"], 0)
            result = resumed.reconcile_interrupted_migration(
                ledger_contains_started_migration=False,
                observed_schema_sha256="4" * 64,
                observed_ledger_sha256="5" * 64,
                observed_data_sha256="c" * 64,
            )
            self.assertEqual(result, "SAFE_TO_RETRY_UNCHANGED_DATABASE")

    def test_partial_schema_without_ledger_fails_closed_for_manual_recovery(self):
        with self.tx:
            self.advance_to_migrating()
            self.tx.begin_migration("2026_11_01_000001_add_widget",
                                    observed_schema_sha256="4" * 64,
                                    observed_ledger_sha256="5" * 64,
                                    observed_data_sha256="c" * 64,
                                    expected_schema_sha256="6" * 64,
                                    expected_ledger_sha256="7" * 64,
                                    expected_data_sha256="c" * 64)
        resumed = DatabaseReleaseTransaction(self.state_dir, self.identity)
        with resumed:
            status = resumed.reconcile_interrupted_migration(
                ledger_contains_started_migration=False,
                observed_schema_sha256="6" * 64,
                observed_ledger_sha256="5" * 64,
                observed_data_sha256="c" * 64,
            )
            self.assertEqual(status, "MANUAL_RECOVERY_REQUIRED")
            self.assertEqual(resumed.state["state"], "RECOVERY_REQUIRED")
            with self.assertRaisesRegex(TransactionRefused, "migration_state_invalid"):
                resumed.begin_migration("2026_11_01_000001_add_widget",
                                        observed_schema_sha256="6" * 64,
                                        observed_ledger_sha256="5" * 64,
                                        observed_data_sha256="c" * 64,
                                        expected_schema_sha256="7" * 64,
                                        expected_ledger_sha256="8" * 64,
                                        expected_data_sha256="c" * 64)

    def test_identity_mismatch_or_malformed_journal_is_rejected(self):
        with self.tx:
            self.assertEqual(self.tx.state["profile"], "staging-next")
        other = TransactionIdentity(**{**self.identity.__dict__, "backup_sha256": "f" * 64})
        with self.assertRaisesRegex(TransactionRefused, "unfinished_database_transaction"):
            with DatabaseReleaseTransaction(self.state_dir, other):
                pass
        raw = json.loads(self.tx.journal.read_text(encoding="utf-8"))
        raw["state"] = "SUCCESS"
        self.tx.journal.write_text(json.dumps(raw), encoding="utf-8")
        os.chmod(self.tx.journal, 0o600)
        with self.assertRaisesRegex(TransactionRefused, "transaction_state_invalid"):
            with self.tx:
                pass

    def test_lock_excludes_concurrent_transaction_process(self):
        with self.tx:
            pid = os.fork()
            if pid == 0:
                try:
                    with DatabaseReleaseTransaction(self.state_dir, self.identity):
                        os._exit(10)
                except TransactionRefused as error:
                    os._exit(0 if str(error) == "transaction_lock_busy" else 11)
            _pid, status = os.waitpid(pid, 0)
            self.assertTrue(os.WIFEXITED(status))
            self.assertEqual(os.WEXITSTATUS(status), 0)


if __name__ == "__main__":
    unittest.main(verbosity=2)
