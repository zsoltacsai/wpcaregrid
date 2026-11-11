#!/usr/bin/env python3
"""Unprivileged policy tests for the fixed staging deploy helper interface."""

import importlib.util
import hashlib
import fnmatch
import io
import os
import shutil
import socket
import stat
import subprocess
import sys
import tempfile
import json
import types
from datetime import datetime, timedelta, timezone
from unittest.mock import patch
from contextlib import redirect_stdout, redirect_stderr
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
HELPER = ROOT / "scripts/wpcaregrid-deploy-priv.py"
CONTROLLER = ROOT / "scripts/deploy-staging.sh"
DENIAL_FIXTURE = ROOT / "scripts/tests/fixtures/deploy-sudo-denial-fixture.sh"
spec = importlib.util.spec_from_file_location("deploy_priv", HELPER)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
NGINX_VALIDATOR = module.validate_nginx_runtime_dump
RECOVERY_SPEC = importlib.util.spec_from_file_location(
    "staging_recovery", ROOT / "scripts" / "recover-staging-deployment.py"
)
RECOVERY = importlib.util.module_from_spec(RECOVERY_SPEC)
RECOVERY_SPEC.loader.exec_module(RECOVERY)


def expect_rejected(args):
    try:
        module.dispatch(args)
    except (ValueError, OSError, subprocess.SubprocessError):
        return
    raise AssertionError(f"unsafe helper request accepted: {args!r}")


def expect_preflight_code(expected, callback):
    try:
        callback()
    except module.RuntimePreflightFailure as error:
        assert error.code == expected, f"preflight code mismatch: expected {expected}, got {error.code}"
        assert str(error) == expected, "preflight diagnostic must contain only its fixed code"
        return
    raise AssertionError(f"preflight failure was not reported as {expected}")


def recovery_table(table, *, triggers=(), present=True):
    columns = [
        {"name": name, "type": kind, "nullable": nullable, "has_default": default, "extra": extra}
        for name, kind, nullable, default, extra in RECOVERY.COLS[table]
    ]
    return {
        "present": present,
        "engine": "InnoDB" if present else None,
        "collation": "utf8mb4_unicode_ci" if present else None,
        "rows": 0,
        "columns": columns if present else [],
        "indexes": {name: list(value) for name, value in RECOVERY.INDEXES[table].items()} if present else {},
        "foreign_keys": {
            name: [*contract[:4], contract[4][0]]
            for name, contract in RECOVERY.FOREIGN_KEYS[table].items()
        } if present and RECOVERY.FOREIGN_KEYS[table] else ([] if present else {}),
        "triggers": list(triggers),
    }


def recovery_snapshot(*, stage="partial", setting=True):
    first, second = RECOVERY.MIGRATIONS
    tables = {
        RECOVERY.TABLES[0]: recovery_table(RECOVERY.TABLES[0]),
        RECOVERY.TABLES[1]: recovery_table(RECOVERY.TABLES[1], present=False),
    }
    if stage == "first_migration_done":
        valid = [{"definition_valid": True} for _ in range(3)]
        tables[RECOVERY.TABLES[0]]["triggers"] = valid
        tables[RECOVERY.TABLES[1]] = recovery_table(RECOVERY.TABLES[1], triggers=(), present=True)
        pending, applied = [second], [first]
    elif stage == "complete":
        valid = [{"definition_valid": True} for _ in range(3)]
        tables[RECOVERY.TABLES[0]]["triggers"] = valid
        tables[RECOVERY.TABLES[1]] = recovery_table(RECOVERY.TABLES[1], triggers=valid)
        pending, applied = [], [first, second]
    else:
        pending, applied = [first, second], []
    return {
        "mysql": {"version": "8.4.11", "database": "wpcaregrid", "log_bin": True,
                  "binlog_format": "ROW", "trust_function_creators": setting,
                  "server_uuid_sha256": RECOVERY.MYSQL_UUID_SHA},
        "pending_migrations": pending,
        "applied_migrations": applied,
        "tables": tables,
    }


def test_gated_recovery_contract():
    assert "transition_history_events_request_fk" not in RECOVERY.INDEXES[RECOVERY.TABLES[1]]
    assert RECOVERY.FOREIGN_KEYS[RECOVERY.TABLES[1]]["transition_history_events_request_fk"] == (
        "request_id", "transition_v2_requests", "request_id", "RESTRICT", ("RESTRICT", "NO ACTION")
    )
    initial = recovery_snapshot()
    pending, applied = RECOVERY.validate_snapshot(initial, phase="migrate", setting=True)
    assert pending == set(RECOVERY.MIGRATIONS) and not applied

    progressed = recovery_snapshot(stage="first_migration_done")
    pending, applied = RECOVERY.validate_snapshot(progressed, phase="migrate", setting=True)
    assert pending == {RECOVERY.MIGRATIONS[1]} and applied == {RECOVERY.MIGRATIONS[0]}

    finished = recovery_snapshot(stage="complete", setting=False)
    pending, applied = RECOVERY.validate_snapshot(finished, phase="finalize", setting=False)
    assert not pending and applied == set(RECOVERY.MIGRATIONS)
    assert RECOVERY.validate_foreign_keys(
        {"transition_history_events_request_fk": [
            "request_id", "transition_v2_requests", "request_id", "RESTRICT", "NO ACTION"
        ]}, RECOVERY.TABLES[1]
    ), "InnoDB's equivalent no-action update rule should satisfy the pinned FK contract"
    assert RECOVERY.validate_foreign_keys([], RECOVERY.TABLES[0])
    assert not RECOVERY.validate_foreign_keys([], RECOVERY.TABLES[1])
    assert RECOVERY.validate_foreign_keys(
        {"transition_history_events_request_fk": [
            "request_id", "transition_v2_requests", "request_id", "RESTRICT", "NO ACTION"
        ]}, RECOVERY.TABLES[1]
    )
    assert not RECOVERY.validate_foreign_keys(
        {"unexpected_fk": ["request_id", "transition_v2_requests", "request_id", "RESTRICT", "NO ACTION"]},
        RECOVERY.TABLES[1],
    )
    assert not RECOVERY.validate_foreign_keys(
        {"transition_history_events_request_fk": ["request_id", "transition_v2_requests"]},
        RECOVERY.TABLES[1],
    )
    assert not RECOVERY.validate_foreign_keys(
        {"transition_history_events_request_fk": ["request_id", "other", "request_id", "RESTRICT", "NO ACTION"]},
        RECOVERY.TABLES[1],
    )
    actual_partial = recovery_snapshot()
    assert RECOVERY.validate_schema(actual_partial, RECOVERY.TABLES[0]), (
        "PHP JSON index arrays and an empty foreign-key JSON array must match the actual partial table"
    )
    migration_finished = recovery_snapshot(stage="complete", setting=True)
    pending, applied = RECOVERY.validate_migration_result(migration_finished)
    assert not pending and applied == set(RECOVERY.MIGRATIONS)
    saved_recovery = {
        name: getattr(RECOVERY, name)
        for name in ("verify_fatal_inert_protected_state", "verify_backup", "verify_provenance",
                     "verify_application_checkout", "verify_pre_recovery_backup",
                     "verify_restore_receipt", "load_attestation", "snapshot", "run",
                     "_readiness_runtime", "_readiness_composer")
    }
    migration_commands = []
    try:
        RECOVERY.verify_fatal_inert_protected_state = lambda: None
        RECOVERY.verify_application_checkout = lambda _sha: None
        RECOVERY.verify_backup = lambda: {"sha256": "b" * 64}
        RECOVERY.verify_pre_recovery_backup = lambda *_args: {
            "name": "synthetic-pre-recovery.sql.gz", "sha256": "d" * 64, "bytes": 15
        }
        RECOVERY.verify_restore_receipt = lambda *_args: "f" * 64
        RECOVERY.verify_provenance = lambda app, tool, package: {app: 123, tool: 456}
        RECOVERY.load_attestation = lambda phase: "c" * 64
        RECOVERY.snapshot = lambda: migration_finished
        RECOVERY._readiness_runtime = lambda: {"php": "8.4"}
        RECOVERY._readiness_composer = lambda: {"platform_requirements": "PASS"}
        RECOVERY.run = lambda argv, **kwargs: migration_commands.append(argv)
        retry_result = RECOVERY.phase_migrate(RECOVERY.APP_SHA, "a" * 40, ROOT)
        assert retry_result["MIGRATIONS"] == "PASS" and retry_result["TRIGGERS"] == 6
        assert not migration_commands, "completed migration retry must not re-execute DDL"
        RECOVERY.verify_restore_receipt = lambda *_args: (_ for _ in ()).throw(
            RECOVERY.RecoveryError("restore_receipt_unavailable")
        )
        try:
            RECOVERY.phase_migrate(RECOVERY.APP_SHA, "a" * 40, ROOT)
        except RECOVERY.RecoveryError as error:
            assert str(error) == "restore_receipt_unavailable"
        else:
            raise AssertionError("migration continuation bypassed the mandatory isolated restore receipt")
        assert not migration_commands, "migration command ran without an isolated restore receipt"
        try:
            RECOVERY.phase_finalize(RECOVERY.APP_SHA, "a" * 40, ROOT)
        except RECOVERY.RecoveryError as error:
            assert str(error) == "restore_receipt_unavailable"
        else:
            raise AssertionError("finalization bypassed the mandatory isolated restore receipt")
        assert not migration_commands, "finalization began without an isolated restore receipt"
    finally:
        for name, value in saved_recovery.items():
            setattr(RECOVERY, name, value)


    saved_main = {
        name: getattr(RECOVERY, name)
        for name in ("deployment_lock", "verify_application_checkout", "phase_migrate", "phase_finalize", "enforce_fail_closed")
    }
    prelock_calls = []
    class BusyDeploymentLock:
        def __enter__(self):
            raise RECOVERY.RecoveryError("deployment_lock_busy")
        def __exit__(self, *_args):
            return False
    try:
        RECOVERY.deployment_lock = lambda: BusyDeploymentLock()
        RECOVERY.verify_application_checkout = lambda _sha: prelock_calls.append("checkout")
        RECOVERY.phase_migrate = lambda *_args: prelock_calls.append("migrate")
        RECOVERY.phase_finalize = lambda *_args: prelock_calls.append("finalize")
        RECOVERY.enforce_fail_closed = lambda: prelock_calls.append("fence")
        error_output = io.StringIO()
        with redirect_stderr(error_output):
            exit_code = RECOVERY.main([
                "continue-migrations", "--application-sha", RECOVERY.APP_SHA,
                "--recovery-tool-sha", "a" * 40,
            ])
        assert exit_code == 1 and "CODE=deployment_lock_busy" in error_output.getvalue()
        assert not prelock_calls, "busy-lock refusal must not enter recovery or mutate host fences"
    finally:
        for name, value in saved_main.items():
            setattr(RECOVERY, name, value)
    lock_calls = []
    RECOVERY.acquire_nonblocking_lock(7, lambda fd, flag: lock_calls.append((fd, flag)), 9)
    assert lock_calls == [(7, 9)]
    def busy_lock(_fd, _flag):
        raise BlockingIOError
    try:
        RECOVERY.acquire_nonblocking_lock(8, busy_lock, 9)
    except RECOVERY.RecoveryError as error:
        assert str(error) == "deployment_lock_busy"
    else:
        raise AssertionError("a busy deploy lock was accepted")

    for table in RECOVERY.TABLES:
        finished["tables"][table]["triggers"] = [
            {"name": f"{table}_immutable_{event.lower()}", "event": event,
             "timing": "BEFORE", "definition_valid": True,
             "definition_sha256": hashlib.sha256(f"{table}:{event}".encode()).hexdigest()}
            for event in ("INSERT", "UPDATE", "DELETE")
        ]

    invalid_cases = []
    wrong_setting = recovery_snapshot()
    wrong_setting["mysql"]["trust_function_creators"] = False
    invalid_cases.append(("dba_setting_state_mismatch", wrong_setting))
    extra_pending = recovery_snapshot()
    extra_pending["pending_migrations"].append("unapproved_migration")
    invalid_cases.append(("unexpected_pending_migration", extra_pending))
    wrong_schema = recovery_snapshot()
    wrong_schema["tables"][RECOVERY.TABLES[0]]["columns"].pop()
    invalid_cases.append(("partial_schema_mismatch", wrong_schema))
    for bad_indexes in (
        {**recovery_snapshot()["tables"][RECOVERY.TABLES[0]]["indexes"], "unexpected": [1, "other"]},
        {name: value for name, value in recovery_snapshot()["tables"][RECOVERY.TABLES[0]]["indexes"].items()
         if name != "transition_v2_requests_request_id_unique"},
        {**recovery_snapshot()["tables"][RECOVERY.TABLES[0]]["indexes"], "PRIMARY": [True, "id"]},
        {**recovery_snapshot()["tables"][RECOVERY.TABLES[0]]["indexes"], "PRIMARY": [0, "wrong"]},
        {**recovery_snapshot()["tables"][RECOVERY.TABLES[0]]["indexes"], "PRIMARY": [0, "id", "extra"]},
    ):
        malformed_index = recovery_snapshot()
        malformed_index["tables"][RECOVERY.TABLES[0]]["indexes"] = bad_indexes
        invalid_cases.append(("partial_schema_mismatch", malformed_index))
    malformed_empty_fk = recovery_snapshot()
    malformed_empty_fk["tables"][RECOVERY.TABLES[0]]["foreign_keys"] = {"unexpected": []}
    invalid_cases.append(("partial_schema_mismatch", malformed_empty_fk))
    wrong_foreign_key = recovery_snapshot(stage="first_migration_done")
    wrong_foreign_key["tables"][RECOVERY.TABLES[1]]["foreign_keys"]["transition_history_events_request_fk"] = (
        "request_id", "unexpected_table", "request_id", "RESTRICT", "NO ACTION"
    )
    invalid_cases.append(("partial_schema_mismatch", wrong_foreign_key))
    bad_trigger = recovery_snapshot(stage="first_migration_done")
    bad_trigger["tables"][RECOVERY.TABLES[0]]["triggers"][0]["definition_valid"] = False
    invalid_cases.append(("partial_trigger_state_mismatch", bad_trigger))
    out_of_order = recovery_snapshot()
    out_of_order["tables"][RECOVERY.TABLES[1]] = recovery_table(RECOVERY.TABLES[1])
    invalid_cases.append(("partial_table_order_mismatch", out_of_order))
    bad_final_trigger = recovery_snapshot(stage="complete", setting=False)
    bad_final_trigger["tables"][RECOVERY.TABLES[1]]["triggers"][1]["definition_valid"] = False
    invalid_cases.append(("trigger_definition_mismatch", bad_final_trigger))
    for expected_code, invalid in invalid_cases:
        try:
            phase = "finalize" if invalid is bad_final_trigger else "migrate"
            setting = phase == "migrate"
            RECOVERY.validate_snapshot(invalid, phase=phase, setting=setting)
        except RECOVERY.RecoveryError as error:
            assert str(error) == expected_code, (expected_code, str(error))
        else:
            raise AssertionError(f"unsafe recovery snapshot accepted: {expected_code}")

    if os.name != "nt":
        original_receipts = RECOVERY.RECEIPTS
        with tempfile.TemporaryDirectory() as receipt_root:
            receipt_dir = Path(receipt_root)
            receipt_dir.chmod(0o700)
            RECOVERY.RECEIPTS = receipt_dir
            try:
                receipt_name = RECOVERY.write_receipt(
                    RECOVERY.APP_SHA, "a" * 40,
                    {RECOVERY.APP_SHA: 123, "a" * 40: 456},
                    {"name": "synthetic.sql.gz", "sha256": "b" * 64, "bytes": 12},
                    {"name": "synthetic-pre-recovery.sql.gz", "sha256": "d" * 64, "bytes": 15},
                    "c" * 64, finished,
                    "f" * 64,
                )
                receipt_path = receipt_dir / receipt_name
                receipt = json.loads(receipt_path.read_text(encoding="utf-8"))
                assert receipt_path.stat().st_mode & 0o777 == 0o600
                assert receipt["status"] == "VERIFIED_GATE_CLOSED"
                assert receipt["application_sha"] == RECOVERY.APP_SHA
                assert receipt["recovery_tool_sha"] == "a" * 40
                assert receipt["maintenance_gate"] == "CLOSED"
                assert receipt["isolated_restore_receipt_sha256"] == "f" * 64
                assert receipt["pre_recovery_backup"]["sha256"] == "d" * 64
                assert receipt["public_http_acceptance"] == "PENDING_SEPARATE_OWNER_GATE_RELEASE"
                assert "APP_KEY" not in receipt and "credentials" not in receipt
            finally:
                RECOVERY.RECEIPTS = original_receipts
    else:
        source = (ROOT / "scripts/recover-staging-deployment.py").read_text(encoding="utf-8")
        assert "'VERIFIED_GATE_CLOSED'" in source
        assert "'recovery_tool_sha'" in source and "'application_sha'" in source

    attestation = {
        "schema": 1,
        "application_sha": RECOVERY.APP_SHA,
        "mysql_server_uuid_sha256": RECOVERY.MYSQL_UUID_SHA,
        "instance_scope": "DEDICATED_STAGING",
        "replication": "NONE",
        "unrelated_application_use": "NONE",
        "original_log_bin_trust_function_creators": "OFF",
        "phase": "MIGRATION_WINDOW",
        "temporary_setting": "ENABLED_FOR_MIGRATION",
        "observed_log_bin_trust_function_creators": "ON",
        "operation_authorized_by_dba": True,
        "restoration_watchdog_active": True,
        "restoration_watchdog_independent": True,
        "restoration_watchdog_action": "RESTORE_LOG_BIN_TRUST_FUNCTION_CREATORS_OFF",
        "restoration_watchdog_id": "wpcg-db-recovery-off-012345abcdef.timer",
        "restoration_watchdog_sha256": "a" * 64,
        "restoration_watchdog_expires_at_utc": (datetime.now(timezone.utc) + timedelta(minutes=10)).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }
    assert RECOVERY.validate_attestation(attestation, phase="migrate")
    restored_attestation = {
        **{key: value for key, value in attestation.items() if key not in {
            "phase", "temporary_setting", "observed_log_bin_trust_function_creators",
            "operation_authorized_by_dba",
            "restoration_watchdog_active", "restoration_watchdog_independent",
            "restoration_watchdog_action", "restoration_watchdog_id",
            "restoration_watchdog_sha256", "restoration_watchdog_expires_at_utc",
        }},
        "phase": "RESTORATION_VERIFIED",
        "temporary_setting": "RESTORED",
        "restored_log_bin_trust_function_creators": "OFF",
        "restoration_independently_verified": True,
    }
    assert RECOVERY.validate_attestation(restored_attestation, phase="finalize")
    for key, value in (("restoration_watchdog_active", False),
                       ("restoration_watchdog_sha256", "bad"),
                       ("restoration_watchdog_expires_at_utc", "2020-01-01T00:00:00Z")):
        bad_window = dict(attestation, **{key: value})
        try:
            RECOVERY.validate_attestation(bad_window, phase="migrate")
        except RECOVERY.RecoveryError:
            pass
        else:
            raise AssertionError(f"invalid DBA restoration watchdog accepted: {key}")
    bad_attestation = dict(restored_attestation, restoration_independently_verified=False)
    try:
        RECOVERY.validate_attestation(bad_attestation, phase="finalize")
    except RECOVERY.RecoveryError as error:
        assert str(error) == "dba_attestation_mismatch"
    else:
        raise AssertionError("unverified DBA setting restoration was accepted")

    calls = []
    original_run = RECOVERY.run
    RECOVERY.run = lambda argv, **kwargs: calls.append(argv)
    try:
        RECOVERY.invoke_single_migration(RECOVERY.MIGRATION_FILES[0])
        assert calls == [[RECOVERY.PHP, "artisan", "migrate", "--force", "--no-interaction",
                          f"--path=database/migrations/{RECOVERY.MIGRATION_FILES[0]}"]]
        try:
            RECOVERY.invoke_single_migration("2026_01_01_unapproved.php")
        except RECOVERY.RecoveryError as error:
            assert str(error) == "migration_not_allowlisted"
        else:
            raise AssertionError("unapproved migration path was accepted")
        assert len(calls) == 1
    finally:
        RECOVERY.run = original_run

    fail_closed_calls = []
    original_helper_check = RECOVERY.verify_helper_identity
    def fail_first_fence(argv, **kwargs):
        fail_closed_calls.append(argv)
        if len(fail_closed_calls) == 1:
            raise RECOVERY.RecoveryError("synthetic_gate_command_failure")
        return subprocess.CompletedProcess(argv, 0, stdout="wpcaregrid-default STOPPED\n")
    RECOVERY.run = fail_first_fence
    RECOVERY.verify_helper_identity = lambda: None
    try:
        try:
            RECOVERY.enforce_fail_closed()
        except RECOVERY.RecoveryError as error:
            assert str(error) == "fail_closed_state_unverified"
        else:
            raise AssertionError("failed fence operation was not reported")
        assert len(fail_closed_calls) == 6, "all fence and worker-state checks must be attempted"
    finally:
        RECOVERY.run = original_run
        RECOVERY.verify_helper_identity = original_helper_check

    source = (ROOT / "scripts/recover-staging-deployment.py").read_text(encoding="utf-8")
    assert "gate-off" not in source
    assert "worker-runtime-enable" not in source
    assert "scheduler-resume" not in source
    assert "SET GLOBAL" not in source
    assert "'up'" not in source and '"up"' not in source
    assert "DEPLOY_LOCK = Path('/home/deploy/private-backups/.deploy.lock')" in source
    assert "with deployment_lock():" in source
    assert "if not pending:" in source
    assert "recovery_tool_sha" in source and "application_sha" in source


def test_migration_failure_reasserts_recovery_fences_without_destructive_rollback():
    class RecoveryLock:
        def __enter__(self): return self
        def __exit__(self, *_args): return False

    saved = {
        name: getattr(RECOVERY, name) for name in (
            "deployment_lock", "verify_application_checkout", "phase_migrate",
            "enforce_recovery_fail_closed", "enforce_fail_closed",
        )
    }
    calls = []
    try:
        RECOVERY.reset_recovery_execution_state()
        RECOVERY.deployment_lock = lambda: RecoveryLock()
        RECOVERY.verify_application_checkout = lambda _sha: None
        def fail_after_mutation(*_args):
            RECOVERY._RECOVERY_HELPER_VERIFIED = True
            RECOVERY.mark_recovery_mutation_started()
            raise RECOVERY.RecoveryError("synthetic_migration_failure")
        RECOVERY.phase_migrate = fail_after_mutation
        RECOVERY.enforce_recovery_fail_closed = lambda: calls.append("reassert_recovery_fences")
        RECOVERY.enforce_fail_closed = lambda: calls.append("stop_workers_destructively")
        with redirect_stderr(io.StringIO()):
            result = RECOVERY.main([
                "continue-migrations", "--application-sha", RECOVERY.APP_SHA,
                "--recovery-tool-sha", "a" * 40, "--restore-receipt-fd", "3",
                "--restore-receipt-sha256", "b" * 64,
                "--pre-recovery-backup-sha256", "c" * 64,
            ])
        assert result == 1
        assert calls == ["reassert_recovery_fences"], calls
    finally:
        RECOVERY.reset_recovery_execution_state()
        for name, value in saved.items():
            setattr(RECOVERY, name, value)


def test_pre_mutation_exceptions_never_run_privileged_fence_commands():
    class RecoveryLock:
        def __enter__(self): return self
        def __exit__(self, *_args): return False

    saved = {
        name: getattr(RECOVERY, name) for name in (
            "deployment_lock", "verify_application_checkout", "phase_migrate",
            "enforce_recovery_fail_closed", "enforce_fail_closed",
        )
    }
    fence_calls = []
    try:
        RECOVERY.deployment_lock = lambda: RecoveryLock()
        RECOVERY.verify_application_checkout = lambda _sha: None
        RECOVERY.enforce_recovery_fail_closed = lambda: fence_calls.append("recovery")
        RECOVERY.enforce_fail_closed = lambda: fence_calls.append("stop")
        for helper_verified, exception in (
            (False, RECOVERY.RecoveryError("helper_integrity_failed")),
            (True, RuntimeError("synthetic_pre_mutation_exception")),
        ):
            RECOVERY.reset_recovery_execution_state()
            def fail_before_mutation(*_args, verified=helper_verified, error=exception):
                RECOVERY._RECOVERY_HELPER_VERIFIED = verified
                raise error
            RECOVERY.phase_migrate = fail_before_mutation
            with redirect_stderr(io.StringIO()):
                result = RECOVERY.main([
                    "continue-migrations", "--application-sha", RECOVERY.APP_SHA,
                    "--recovery-tool-sha", "a" * 40, "--restore-receipt-fd", "3",
                    "--restore-receipt-sha256", "b" * 64,
                    "--pre-recovery-backup-sha256", "c" * 64,
                ])
            assert result == 1
            assert fence_calls == [], "pre-mutation failure must preserve existing fences without invoking helper"
    finally:
        RECOVERY.reset_recovery_execution_state()
        for name, value in saved.items():
            setattr(RECOVERY, name, value)


def test_unverified_helper_identity_blocks_all_privileged_commands():
    original_helper, original_sha = RECOVERY.HELPER, RECOVERY.HELPER_SHA
    original_run = RECOVERY.run
    calls = []
    try:
        with tempfile.TemporaryDirectory(prefix="recovery-helper-identity-") as directory:
            helper = Path(directory) / "helper"
            helper.write_bytes(b"synthetic helper bytes\n")
            actual_sha = hashlib.sha256(helper.read_bytes()).hexdigest()
            original_lstat = Path.lstat
            RECOVERY.HELPER = str(helper)
            RECOVERY.run = lambda argv, **_kwargs: calls.append(argv)
            for mode, uid, gid, expected_sha in (
                (stat.S_IFREG | 0o755, 0, 0, "0" * 64),
                (stat.S_IFREG | 0o755, 1000, 0, actual_sha),
                (stat.S_IFREG | 0o700, 0, 0, actual_sha),
            ):
                RECOVERY.reset_recovery_execution_state()
                RECOVERY.HELPER_SHA = expected_sha
                def synthetic_lstat(path, *, _mode=mode, _uid=uid, _gid=gid):
                    if path == helper:
                        return types.SimpleNamespace(st_mode=_mode, st_uid=_uid, st_gid=_gid)
                    return original_lstat(path)
                with patch.object(Path, "lstat", synthetic_lstat):
                    try:
                        RECOVERY.run_verified_helper(["gate-on"])
                    except RECOVERY.RecoveryError as error:
                        assert str(error) == "helper_integrity_failed"
                    else:
                        raise AssertionError("invalid helper identity was accepted")
                assert calls == [], "unverified helper must never receive a privileged invocation"
    finally:
        RECOVERY.reset_recovery_execution_state()
        RECOVERY.HELPER, RECOVERY.HELPER_SHA, RECOVERY.run = original_helper, original_sha, original_run

def test_fatal_inert_launcher_recovery():
    """Recovery-only FATAL_INERT classification never weakens the STOPPED installer contract."""
    worker_names = sorted(module.WORKERS)
    assert module.runtime_switch_preconditions(
        True, True, {name: "STOPPED" for name in module.WORKERS}
    )
    assert not module.runtime_switch_preconditions(
        True, True, {name: "FATAL" for name in module.WORKERS}
    )
    assert not module.runtime_switch_preconditions(
        True, True, {worker_names[0]: "FATAL", worker_names[1]: "STOPPED"}
    )
    assert not module.runtime_switch_preconditions(
        True, True, {worker_names[0]: "FATAL", worker_names[1]: "RUNNING"}
    )

    consumer = module._launcher_consumer_argv
    assert consumer(["/usr/local/bin/wpcaregrid-php", "/var/www/wpcaregrid/artisan", "queue:work"])
    assert consumer(["php8.4", "artisan", "schedule:run"])
    assert consumer(["php", "artisan", "horizon"])
    assert consumer(["php8.4", "artisan", "horizon:work"])
    assert consumer(["/bin/sh", "-c", "php artisan queue:work"])
    assert consumer(["php8.4", "./artisan", "some:command"])
    assert not consumer(["supervisord", "-n"])
    assert not consumer(["nginx", "-g", "daemon off;"])

    saved = {
        name: getattr(module, name) for name in (
            "require_regular", "nginx_gate_check", "run", "supervisor_runtime_contract",
            "_verify_scheduler_paused_contract", "_no_live_runtime_consumers",
        )
    }

    def valid_report(statuses=None, pids=None, pid_codes=None, consumer=False):
        statuses = statuses or {name: (f"{name} FATAL\n", 3) for name in worker_names}
        pids = pids or {name: "0\n" for name in worker_names}
        pid_codes = pid_codes or {name: 7 for name in worker_names}
        calls = []
        module.require_regular = lambda *_args, **_kwargs: types.SimpleNamespace(
            st_gid=0, st_mode=stat.S_IFREG | 0o644, st_size=0
        )
        module.nginx_gate_check = lambda: None
        module.supervisor_runtime_contract = lambda: None
        module._verify_scheduler_paused_contract = lambda: None

        def read_only_run(argv, **_kwargs):
            calls.append(list(argv))
            if argv[0] == "/usr/bin/curl":
                return subprocess.CompletedProcess(argv, 0, stdout="503", stderr="")
            if argv[:2] != ["/usr/bin/supervisorctl", "status"] and argv[:2] != ["/usr/bin/supervisorctl", "pid"]:
                raise AssertionError("preflight invoked an unexpected or mutating command")
            name = argv[-1]
            if argv[1] == "status":
                output, code = statuses[name]
            else:
                output, code = pids[name], pid_codes[name]
            return subprocess.CompletedProcess(argv, code, stdout=output, stderr="")
        module.run = read_only_run
        if consumer:
            module._no_live_runtime_consumers = lambda: (_ for _ in ()).throw(
                ValueError("FATAL_INERT_CONSUMER_PRESENT")
            )
        else:
            module._no_live_runtime_consumers = lambda: True
        evidence = module.verify_fatal_inert_recovery_readiness()
        return evidence, calls

    try:
        evidence, calls = valid_report()
        assert evidence["status"] == "PASS" and evidence["state"] == "FATAL_INERT"
        assert evidence["workers"] == {name: "FATAL" for name in worker_names}
        assert evidence["live_consumers"] == 0 and evidence["supervisor_control"] == "NOT_INVOKED"
        assert len(calls) == 5 and all("stop" not in call and "start" not in call for call in calls)

        name = worker_names[0]
        for bad_output, bad_code in (("37\n", 0), ("0\n", 9)):
            pids = {worker: "0\n" for worker in worker_names}
            pid_codes = {worker: 7 for worker in worker_names}
            pids[name], pid_codes[name] = bad_output, bad_code
            rejected, _ = valid_report(pids=pids, pid_codes=pid_codes)
            assert rejected["status"] == "BLOCKED" and rejected["state"] == "UNKNOWN"
            assert "FATAL_INERT_WORKER_STATE_INVALID" in rejected["blockers"]

        statuses = {worker: (f"{worker} FATAL\n", 3) for worker in worker_names}
        statuses[name] = (f"{name} RUNNING pid 45, uptime 0:01:00\n", 0)
        rejected, _ = valid_report(statuses=statuses)
        assert rejected["status"] == "BLOCKED"

        rejected, _ = valid_report(consumer=True)
        assert "FATAL_INERT_CONSUMER_PRESENT" in rejected["blockers"]
        assert rejected["status"] == "BLOCKED"
    finally:
        for name, value in saved.items():
            setattr(module, name, value)

    if hasattr(module.os, "geteuid"):
        saved = (module.os.geteuid, module.dispatch, os.environ.copy(), sys.argv[:])
        try:
            module.os.geteuid = lambda: 0
            module.dispatch = lambda _args: module._recovery_refuse("FATAL_INERT_WORKER_STATE_INVALID")
            sys.argv = [str(HELPER), "runtime-launcher-recover-fatal-inert"]
            diagnostic = io.StringIO()
            with redirect_stderr(diagnostic):
                assert module.main() == 1
            assert diagnostic.getvalue() == (
                "FATAL_INERT_RECOVERY=REFUSED;CODE=FATAL_INERT_WORKER_STATE_INVALID\n"
            )
        finally:
            module.os.geteuid, module.dispatch = saved[0], saved[1]
            os.environ.clear()
            os.environ.update(saved[2])
            sys.argv = saved[3]

    if os.name != "nt":
        launcher_bytes = (ROOT / "scripts/run-php-runtime.sh").read_bytes()
        original_artifact = module.PHP_RUNTIME_LAUNCHER_ARTIFACT
        with tempfile.TemporaryDirectory(prefix="wpcg-fatal-inert-artifact-") as temporary:
            private = Path(temporary) / ".private"
            private.mkdir(mode=0o700)
            private.chmod(0o700)
            artifact = private / original_artifact.name
            artifact.write_bytes(launcher_bytes)
            artifact.chmod(0o600)
            module.PHP_RUNTIME_LAUNCHER_ARTIFACT = artifact
            try:
                data = module._read_private_launcher_artifact(
                    deploy_uid=os.getuid(), deploy_gid=os.getgid()
                )
                assert data == launcher_bytes
                artifact.write_bytes(b"#!/bin/sh\nexit 0\n")
                try:
                    with redirect_stderr(io.StringIO()):
                        module._read_private_launcher_artifact(
                            deploy_uid=os.getuid(), deploy_gid=os.getgid()
                        )
                except ValueError as error:
                    assert str(error) == "FATAL_INERT_ARTIFACT_HASH_MISMATCH"
                else:
                    raise AssertionError("wrong launcher hash was accepted")
                artifact.unlink()
                artifact.write_bytes(launcher_bytes)
                artifact.chmod(0o644)
                try:
                    with redirect_stderr(io.StringIO()):
                        module._read_private_launcher_artifact(
                            deploy_uid=os.getuid(), deploy_gid=os.getgid()
                        )
                except ValueError as error:
                    assert str(error) == "FATAL_INERT_ARTIFACT_INVALID"
                else:
                    raise AssertionError("group/world-readable launcher artifact was accepted")
                artifact.unlink()
                try:
                    with redirect_stderr(io.StringIO()):
                        module._read_private_launcher_artifact(
                            deploy_uid=os.getuid(), deploy_gid=os.getgid()
                        )
                except ValueError as error:
                    assert str(error) == "FATAL_INERT_ARTIFACT_INVALID"
                else:
                    raise AssertionError("missing launcher artifact was accepted")
                artifact.unlink(missing_ok=True)
                artifact.symlink_to(ROOT / "scripts/run-php-runtime.sh")
                try:
                    module._read_private_launcher_artifact(
                        deploy_uid=os.getuid(), deploy_gid=os.getgid()
                    )
                except (ValueError, OSError):
                    pass
                else:
                    raise AssertionError("launcher artifact symlink was accepted")
            finally:
                module.PHP_RUNTIME_LAUNCHER_ARTIFACT = original_artifact

        with tempfile.TemporaryDirectory(prefix="wpcg-fatal-inert-proc-") as temporary:
            proc = Path(temporary)
            (proc / "123").mkdir()
            (proc / "123/cmdline").write_bytes(
                b"php\0/var/www/wpcaregrid/artisan\0queue:work\0--token=synthetic\0"
            )
            (proc / "124").mkdir()
            (proc / "124/cmdline").write_bytes(b"nginx\0-g\0daemon off;\0")
            try:
                with redirect_stderr(io.StringIO()):
                    module._no_live_runtime_consumers(str(proc), pid=999)
            except ValueError as error:
                assert str(error) == "FATAL_INERT_CONSUMER_PRESENT"
            else:
                raise AssertionError("live queue consumer was not detected")
            (proc / "123/cmdline").write_bytes(b"php-fpm8.4\0")
            assert module._no_live_runtime_consumers(str(proc), pid=999)

    original = {
        "require_regular": module.require_regular,
        "nginx_gate_check": module.nginx_gate_check,
        "supervisor_runtime_contract": module.supervisor_runtime_contract,
        "scheduler_contract": module._verify_scheduler_paused_contract,
        "run": module.run,
        "census": module._no_live_runtime_consumers,
    }
    calls = []

    def fake_run(argv, **kwargs):
        calls.append(list(argv))
        if argv[0] == "/usr/bin/curl":
            return subprocess.CompletedProcess(argv, 0, stdout="503")
        if argv[1:3] == ["status", argv[2]]:
            return subprocess.CompletedProcess(argv, 3, stdout=f"{argv[2]} FATAL\n")
        if argv[1:3] == ["pid", argv[2]]:
            return subprocess.CompletedProcess(argv, 7, stdout="0\n")
        raise AssertionError(f"unexpected operation in recovery preflight: {argv!r}")

    module.require_regular = lambda *_args, **_kwargs: type(
        "MarkerStat", (), {"st_gid": 0, "st_mode": stat.S_IFREG | 0o644, "st_size": 0}
    )()
    module.nginx_gate_check = lambda: None
    module.supervisor_runtime_contract = lambda **_kwargs: None
    module._verify_scheduler_paused_contract = lambda: None
    module.run = fake_run
    module._no_live_runtime_consumers = lambda: True
    try:
        module._verify_fatal_inert_preconditions()
        assert len([call for call in calls if call[1] == "status"]) == 2
        assert len([call for call in calls if call[1] == "pid"]) == 2
        assert not any(any(token in {"start", "stop", "restart", "reload", "reread", "update"} for token in call) for call in calls)

        def diagnostic_run(*, states=None, pids=None, pid_codes=None, status_codes=None, http="503"):
            states = states or {name: "FATAL" for name in worker_names}
            pids = pids or {name: "0\n" for name in worker_names}
            pid_codes = pid_codes or {name: 7 for name in worker_names}
            status_codes = status_codes or {}
            def run(argv, **_kwargs):
                if argv[0] == "/usr/bin/curl":
                    return subprocess.CompletedProcess(argv, 0, stdout=http)
                name = argv[-1]
                if argv[1] == "status":
                    state = states.get(name, "FATAL")
                    code = status_codes.get(name, 3 if state == "FATAL" else 0)
                    return subprocess.CompletedProcess(argv, code, stdout=f"{name} {state}\n")
                return subprocess.CompletedProcess(
                    argv, pid_codes.get(name, 7), stdout=pids.get(name, "0\n")
                )
            return run

        for states, pids, pid_codes, http, census_error, marker_error, expected_code in (
            ({worker_names[0]: "FATAL", worker_names[1]: "STOPPED"}, None, None, "503", False, False, "FATAL_INERT_WORKER_STATE_INVALID"),
            ({worker_names[0]: "FATAL", worker_names[1]: "RUNNING pid 44"}, None, None, "503", False, False, "FATAL_INERT_WORKER_STATE_INVALID"),
            (None, {worker_names[0]: "44\n", worker_names[1]: "0\n"}, {worker_names[0]: 0, worker_names[1]: 7}, "503", False, False, "FATAL_INERT_WORKER_PID_PRESENT"),
            (None, None, {worker_names[0]: 8, worker_names[1]: 7}, "503", False, False, "FATAL_INERT_WORKER_PID_PRESENT"),
            (None, {worker_names[0]: "0\n\n", worker_names[1]: "0\n"}, None, "503", False, False, "FATAL_INERT_WORKER_PID_PRESENT"),
            (None, None, None, "200", False, False, "FATAL_INERT_HTTP_GATE_OPEN"),
            (None, None, None, "503", True, False, "FATAL_INERT_PROCESS_CENSUS_UNAVAILABLE"),
            (None, None, None, "503", False, True, "FATAL_INERT_MARKERS_INVALID"),
        ):
            module.run = diagnostic_run(states=states, pids=pids, pid_codes=pid_codes, http=http)
            if census_error:
                module._no_live_runtime_consumers = lambda: module._recovery_refuse(
                    "FATAL_INERT_PROCESS_CENSUS_UNAVAILABLE"
                )
            else:
                module._no_live_runtime_consumers = lambda: True
            if marker_error:
                module.require_regular = lambda *_args, **_kwargs: (_ for _ in ()).throw(
                    OSError("synthetic marker failure")
                )
            else:
                module.require_regular = lambda *_args, **_kwargs: type(
                    "MarkerStat", (), {"st_gid": 0, "st_mode": stat.S_IFREG | 0o644, "st_size": 0}
                )()
            with redirect_stderr(io.StringIO()):
                try:
                    module._verify_fatal_inert_preconditions()
                except ValueError as error:
                    assert str(error) == expected_code, (expected_code, str(error))
                else:
                    raise AssertionError(f"unsafe FATAL_INERT state accepted: {expected_code}")

        for pid_code, pid_output in ((0, "0"), (3, "0\n")):
            module.run = diagnostic_run(
                pids={name: pid_output for name in worker_names},
                pid_codes={name: pid_code for name in worker_names},
            )
            module._no_live_runtime_consumers = lambda: True
            module.require_regular = lambda *_args, **_kwargs: type(
                "MarkerStat", (), {"st_gid": 0, "st_mode": stat.S_IFREG | 0o644, "st_size": 0}
            )()
            module._verify_fatal_inert_preconditions()

        module.run = diagnostic_run(status_codes={worker_names[0]: 0})
        with redirect_stderr(io.StringIO()):
            try:
                module._verify_fatal_inert_preconditions()
            except ValueError as error:
                assert str(error) == "FATAL_INERT_WORKER_STATE_INVALID"
            else:
                raise AssertionError("FATAL status text with a non-FATAL exit code was accepted")

        module.run = diagnostic_run()
        module.require_regular = lambda *_args, **_kwargs: type(
            "MarkerStat", (), {"st_gid": 0, "st_mode": stat.S_IFREG | 0o644, "st_size": 0}
        )()
        module._no_live_runtime_consumers = lambda: module._recovery_refuse(
            "FATAL_INERT_CONSUMER_PRESENT"
        )
        with redirect_stderr(io.StringIO()):
            try:
                module._verify_fatal_inert_preconditions()
            except ValueError as error:
                assert str(error) == "FATAL_INERT_CONSUMER_PRESENT"
            else:
                raise AssertionError("active queue consumer was accepted")
    finally:
        for name, value in original.items():
            if name == "census":
                module._no_live_runtime_consumers = value
            elif name == "scheduler_contract":
                module._verify_scheduler_paused_contract = value
            else:
                setattr(module, name, value)

    scheduler_bytes = (ROOT / "scripts/run-scheduler.sh").read_bytes()
    scheduler_saved = {
        "lock_check": module.scheduler_lock_check,
        "dest": module.SCHEDULER_DEST,
        "hash": module.SCHEDULER_RUNTIME_SOURCE_SHA256,
        "trusted": module.trusted_file_bytes,
        "run": module.run,
    }
    class SyntheticSchedulerPath:
        def lstat(self):
            return type("SchedulerStat", (), {
                "st_mode": stat.S_IFREG | 0o755, "st_uid": 0, "st_gid": 0, "st_nlink": 1
            })()
    try:
        module.scheduler_lock_check = lambda: None
        module.SCHEDULER_DEST = SyntheticSchedulerPath()
        module.SCHEDULER_RUNTIME_SOURCE_SHA256 = hashlib.sha256(scheduler_bytes).hexdigest()
        module.trusted_file_bytes = lambda *_args, **_kwargs: scheduler_bytes
        cron = module.GUARDED_CRON + "\n"
        scheduler_calls = []
        module.run = lambda argv, **kwargs: (
            scheduler_calls.append(argv) or subprocess.CompletedProcess(argv, 0, stdout=cron)
        )
        module._verify_scheduler_paused_contract()
        assert scheduler_calls == [["/usr/bin/crontab", "-u", "www-data", "-l"]]
        module.run = lambda argv, **kwargs: subprocess.CompletedProcess(
            argv, 0, stdout=module.GUARDED_CRON + "\n" + module.OLD_CRON + "\n"
        )
        with redirect_stderr(io.StringIO()):
            try:
                module._verify_scheduler_paused_contract()
            except ValueError as error:
                assert str(error) == "FATAL_INERT_SCHEDULER_CONTRACT_INVALID"
            else:
                raise AssertionError("unguarded cron scheduler command was accepted")
    finally:
        module.scheduler_lock_check = scheduler_saved["lock_check"]
        module.SCHEDULER_DEST = scheduler_saved["dest"]
        module.SCHEDULER_RUNTIME_SOURCE_SHA256 = scheduler_saved["hash"]
        module.trusted_file_bytes = scheduler_saved["trusted"]
        module.run = scheduler_saved["run"]

    installer_saved = {
        "require": module.require_regular,
        "run": module.run,
        "source": module.deploy_source_bytes,
    }
    source_reads = []
    try:
        module.require_regular = lambda *_args, **_kwargs: type(
            "MarkerStat", (), {"st_gid": 0, "st_mode": stat.S_IFREG | 0o644, "st_size": 0}
        )()
        module.run = lambda argv, **kwargs: subprocess.CompletedProcess(
            argv, 3, stdout=f"{argv[-1]} FATAL\n"
        )
        module.deploy_source_bytes = lambda *args, **kwargs: source_reads.append(args) or b""
        try:
            module.install_runtime_assets()
        except ValueError:
            pass
        else:
            raise AssertionError("normal runtime installer accepted FATAL workers")
        assert not source_reads, "normal runtime installer read/published assets before STOPPED precondition"
    finally:
        module.require_regular = installer_saved["require"]
        module.run = installer_saved["run"]
        module.deploy_source_bytes = installer_saved["source"]

    class SyntheticLauncherPath:
        def __init__(self, content, *, mode=0o755, owner=0, group=0, links=1):
            self.content = content
            self.mode, self.owner, self.group, self.links = mode, owner, group, links

        def lstat(self):
            return type("LauncherStat", (), {
                "st_mode": stat.S_IFREG | self.mode, "st_uid": self.owner,
                "st_gid": self.group, "st_nlink": self.links
            })()

        def read_bytes(self):
            return self.content

    launcher_bytes = (ROOT / "scripts/run-php-runtime.sh").read_bytes()
    old_bytes = b"synthetic prior pinned launcher bytes\n"
    prior_hashes = {
        "destination": module.PHP_RUNTIME_LAUNCHER_DEST,
        "new_hash": module.PHP_RUNTIME_LAUNCHER_SHA256,
        "old_hash": module.PHP_RUNTIME_LAUNCHER_PREVIOUS_SHA256,
        "verify": module._verify_fatal_inert_preconditions,
        "artifact": module._read_private_launcher_artifact,
        "backup": module._create_launcher_rollback_evidence,
        "publish": module.atomic_root_file,
        "trusted_read": module.trusted_file_bytes,
    }
    try:
        synthetic_destination = SyntheticLauncherPath(old_bytes)
        backup_calls = []
        publish_calls = []
        module.PHP_RUNTIME_LAUNCHER_DEST = synthetic_destination
        module.PHP_RUNTIME_LAUNCHER_SHA256 = hashlib.sha256(launcher_bytes).hexdigest()
        module.PHP_RUNTIME_LAUNCHER_PREVIOUS_SHA256 = hashlib.sha256(old_bytes).hexdigest()
        module._verify_fatal_inert_preconditions = lambda: True
        module._read_private_launcher_artifact = lambda: launcher_bytes
        module._create_launcher_rollback_evidence = lambda data: backup_calls.append(data)
        trusted_reads = []
        def bounded_trusted_read(path, *, mode, max_size):
            trusted_reads.append((path, mode, max_size))
            data = path.content
            if len(data) > max_size:
                raise ValueError("synthetic oversized launcher")
            return data
        module.trusted_file_bytes = bounded_trusted_read

        def publish(path, data, *, mode):
            publish_calls.append((path, data, mode))
            synthetic_destination.content = data

        module.atomic_root_file = publish
        result = module.recover_runtime_launcher_fatal_inert()
        assert backup_calls == [old_bytes]
        assert publish_calls == [(synthetic_destination, launcher_bytes, 0o755)]
        assert synthetic_destination.content == launcher_bytes
        assert result["state"] == "FATAL_INERT" and result["live_consumers"] == 0
        assert result["supervisor_control"] == "NOT_INVOKED"
        assert trusted_reads == [(synthetic_destination, 0o755, 8192)] * 2

        # Unexpected destination metadata or content must stop before backup or publication.
        for invalid_destination in (
            SyntheticLauncherPath(old_bytes, mode=0o777),
            SyntheticLauncherPath(b"unapproved launcher\n"),
            SyntheticLauncherPath(old_bytes, links=2),
            SyntheticLauncherPath(b"x" * 8193),
        ):
            module.PHP_RUNTIME_LAUNCHER_DEST = invalid_destination
            backup_calls.clear()
            publish_calls.clear()
            with redirect_stderr(io.StringIO()):
                try:
                    module.recover_runtime_launcher_fatal_inert()
                except ValueError as error:
                    assert str(error) == "FATAL_INERT_DESTINATION_INVALID"
                else:
                    raise AssertionError("unsafe launcher destination was accepted")
            assert not backup_calls and not publish_calls
        module.PHP_RUNTIME_LAUNCHER_DEST = synthetic_destination

        # A second call is idempotent and does not replace an already pinned launcher.
        publish_calls.clear()
        backup_calls.clear()
        result = module.recover_runtime_launcher_fatal_inert()
        assert not publish_calls and not backup_calls
        assert result["launcher_sha256"] == module.PHP_RUNTIME_LAUNCHER_SHA256

        # Publication failure preserves the old destination and returns a fixed refusal.
        synthetic_destination.content = old_bytes
        module.atomic_root_file = lambda *_args, **_kwargs: (_ for _ in ()).throw(OSError("synthetic"))
        with redirect_stderr(io.StringIO()):
            try:
                module.recover_runtime_launcher_fatal_inert()
            except ValueError as error:
                assert str(error) == "FATAL_INERT_PUBLISH_FAILED"
            else:
                raise AssertionError("publication failure was not fail-closed")
        assert synthetic_destination.content == old_bytes

        # Post-publication state drift is reported, while the corrected launcher remains installed.
        checks = []
        def drift_after_publish():
            checks.append(True)
            if len(checks) > 1:
                raise ValueError("synthetic state drift")
        module._verify_fatal_inert_preconditions = drift_after_publish
        module.atomic_root_file = publish
        with redirect_stderr(io.StringIO()):
            try:
                module.recover_runtime_launcher_fatal_inert()
            except ValueError as error:
                assert str(error) == "FATAL_INERT_POSTCHECK_FAILED"
            else:
                raise AssertionError("post-publication state drift was accepted")
        assert synthetic_destination.content == launcher_bytes
    finally:
        module.PHP_RUNTIME_LAUNCHER_DEST = prior_hashes["destination"]
        module.PHP_RUNTIME_LAUNCHER_SHA256 = prior_hashes["new_hash"]
        module.PHP_RUNTIME_LAUNCHER_PREVIOUS_SHA256 = prior_hashes["old_hash"]
        module._verify_fatal_inert_preconditions = prior_hashes["verify"]
        module._read_private_launcher_artifact = prior_hashes["artifact"]
        module._create_launcher_rollback_evidence = prior_hashes["backup"]
        module.atomic_root_file = prior_hashes["publish"]
        module.trusted_file_bytes = prior_hashes["trusted_read"]

    # Normal installer checks remain STOPPED-only; DB recovery requires independent FATAL_INERT proof.
    recovery_source = (ROOT / "scripts/recover-staging-deployment.py").read_text(encoding="utf-8")
    assert "def verify_protected_state()" in recovery_source
    protected = recovery_source.split("def verify_protected_state():", 1)[1].split("\ndef ", 1)[0]
    assert r"STOPPED(?:\s|$)" in protected
    assert "def verify_fatal_inert_protected_state()" in recovery_source
    assert "recovery-worker-preflight" in recovery_source
    assert "def phase_runtime_launcher_recovery(" in recovery_source
    assert "runtime-launcher-recover-fatal-inert" in recovery_source
    assert "'recover-runtime-launcher'" in recovery_source
    approved_helper_sha = "c61cebec06152fdbf54599fa4296c68d5d5db7c2220dacce38c7104da7b6aba2"
    actual_helper_sha = hashlib.sha256(HELPER.read_bytes()).hexdigest()
    assert actual_helper_sha == approved_helper_sha
    assert RECOVERY.HELPER_SHA == approved_helper_sha
    assert f"HELPER_SHA = '{approved_helper_sha}'" in recovery_source
    assert "runtime-launcher-recover-fatal-inert" in module.dispatch.__code__.co_consts
    assert "atomic_root_file(SUPERVISOR_CONFIG" not in module.recover_runtime_launcher_fatal_inert.__code__.co_names
    assert "worker-runtime-enable" not in module.recover_runtime_launcher_fatal_inert.__code__.co_consts
    assert "gate-off" not in module.recover_runtime_launcher_fatal_inert.__code__.co_consts

    class RecoveryLauncherPath:
        def lstat(self):
            return type("LauncherStat", (), {
                "st_mode": stat.S_IFREG | 0o755, "st_uid": 0, "st_gid": 0
            })()

    expected_helper_evidence = {
        "operation": "runtime-launcher-recover-fatal-inert",
        "state": "FATAL_INERT",
        "launcher_sha256": RECOVERY.RUNTIME_LAUNCHER_SHA,
        "workers": {name: "FATAL" for name in sorted(RECOVERY.WORKERS)},
        "live_consumers": 0,
        "maintenance_http": 503,
        "supervisor_control": "NOT_INVOKED",
    }
    saved_phase = {
        "verify": RECOVERY.verify_fatal_inert_protected_state,
        "backup": RECOVERY.verify_backup,
        "provenance": RECOVERY.verify_provenance,
        "run": RECOVERY.run,
        "launcher": RECOVERY.RUNTIME_LAUNCHER,
        "sha": RECOVERY.sha256,
    }
    phase_calls = []
    try:
        RECOVERY.verify_fatal_inert_protected_state = lambda: None
        RECOVERY.verify_backup = lambda: {"name": "pinned.sql.gz", "sha256": "b" * 64, "bytes": 42}
        RECOVERY.verify_provenance = lambda app, tool, package: {app: 123, tool: 456}
        RECOVERY.run = lambda argv, **kwargs: (
            phase_calls.append(argv) or subprocess.CompletedProcess(
                argv, 0, stdout=json.dumps(expected_helper_evidence)
            )
        )
        RECOVERY.RUNTIME_LAUNCHER = RecoveryLauncherPath()
        RECOVERY.sha256 = lambda _path: RECOVERY.RUNTIME_LAUNCHER_SHA
        phase_result = RECOVERY.phase_runtime_launcher_recovery(
            RECOVERY.APP_SHA, "a" * 40, ROOT
        )
        assert phase_result["RECOVERY"] == "LAUNCHER_INSTALLED_GATE_CLOSED"
        assert phase_result["WORKER_STATE"] == "FATAL_INERT"
        assert phase_result["SUPERVISOR_CONTROL"] == "NOT_INVOKED"
        assert phase_calls == [[
            "/usr/bin/sudo", "-n", RECOVERY.HELPER,
            "runtime-launcher-recover-fatal-inert",
        ]]
        RECOVERY.run = lambda argv, **kwargs: subprocess.CompletedProcess(argv, 0, stdout="{}\n")
        try:
            RECOVERY.phase_runtime_launcher_recovery(RECOVERY.APP_SHA, "a" * 40, ROOT)
        except RECOVERY.RecoveryError as error:
            assert str(error) == "launcher_recovery_evidence_invalid"
        else:
            raise AssertionError("malformed FATAL_INERT evidence was accepted")
        RECOVERY.run = lambda argv, **kwargs: subprocess.CompletedProcess(
            argv, 1, stdout="", stderr="FATAL_INERT_RECOVERY=REFUSED;CODE=FATAL_INERT_WORKER_STATE_INVALID\n"
        )
        try:
            RECOVERY.phase_runtime_launcher_recovery(RECOVERY.APP_SHA, "a" * 40, ROOT)
        except RECOVERY.RecoveryError as error:
            assert str(error) == "fatal_inert_worker_state_invalid"
        else:
            raise AssertionError("allowlisted helper refusal code was not propagated")
        raw_stderr = "FATAL_INERT_RECOVERY=REFUSED;CODE=FATAL_INERT_WORKER_STATE_INVALID\nsecret=do-not-leak\n"
        RECOVERY.run = lambda argv, **kwargs: subprocess.CompletedProcess(
            argv, 1, stdout="", stderr=raw_stderr
        )
        try:
            RECOVERY.phase_runtime_launcher_recovery(RECOVERY.APP_SHA, "a" * 40, ROOT)
        except RECOVERY.RecoveryError as error:
            assert str(error) == "launcher_recovery_failed"
            assert "secret" not in str(error)
        else:
            raise AssertionError("untrusted helper stderr was accepted")
    finally:
        RECOVERY.verify_fatal_inert_protected_state = saved_phase["verify"]
        RECOVERY.verify_backup = saved_phase["backup"]
        RECOVERY.verify_provenance = saved_phase["provenance"]
        RECOVERY.run = saved_phase["run"]
        RECOVERY.RUNTIME_LAUNCHER = saved_phase["launcher"]
        RECOVERY.sha256 = saved_phase["sha"]

    # Recovery-phase failures must not call the normal stop/restart fail-closed routine.
    class RecoveryLock:
        def __enter__(self): return self
        def __exit__(self, *_args): return False
    saved_main = {
        "lock": RECOVERY.deployment_lock,
        "checkout": RECOVERY.verify_application_checkout,
        "phase": RECOVERY.phase_runtime_launcher_recovery,
        "fence": RECOVERY.enforce_fail_closed,
    }
    fence_calls = []
    try:
        RECOVERY.deployment_lock = lambda: RecoveryLock()
        RECOVERY.verify_application_checkout = lambda _sha: None
        RECOVERY.phase_runtime_launcher_recovery = lambda *_args: (_ for _ in ()).throw(
            RECOVERY.RecoveryError("synthetic_recovery_refusal")
        )
        RECOVERY.enforce_fail_closed = lambda: fence_calls.append("called")
        with redirect_stderr(io.StringIO()):
            code = RECOVERY.main([
                "recover-runtime-launcher", "--application-sha", RECOVERY.APP_SHA,
                "--recovery-tool-sha", "a" * 40,
            ])
        assert code == 1 and not fence_calls
    finally:
        RECOVERY.deployment_lock = saved_main["lock"]
        RECOVERY.verify_application_checkout = saved_main["checkout"]
        RECOVERY.phase_runtime_launcher_recovery = saved_main["phase"]
        RECOVERY.enforce_fail_closed = saved_main["fence"]


def test_fatal_inert_recovery_without_laravel_config_cache():
    """Launcher-only recovery accepts the interrupted missing-cache state, while Laravel phases do not."""
    if os.name == "nt":
        # Win32 cannot model Unix ownership and mode bits; the required regression runs on Linux CI.
        return
    with tempfile.TemporaryDirectory(prefix="fatal-inert-no-config-cache-") as temp_dir:
        app = Path(temp_dir) / "app"
        app.mkdir()
        (app / "bootstrap/cache").mkdir(parents=True)
        env = app / ".env"
        env.write_bytes(b"synthetic-env-placeholder\n")
        env.chmod(0o600)
        helper_path = Path(temp_dir) / "helper"
        helper_path.write_bytes(b"synthetic-helper-placeholder\n")
        marker_paths = {
            "gate": Path(temp_dir) / "deploy-maintenance",
            "workers": Path(temp_dir) / "workers-disabled",
            "scheduler": Path(temp_dir) / "scheduler-paused",
        }
        for marker in marker_paths.values():
            marker.touch()

        saved = {
            name: getattr(RECOVERY, name)
            for name in (
                "APP", "HELPER", "HELPER_SHA", "GATE", "WORKER_MARKER",
                "SCHEDULER_MARKER", "marker_ok", "run", "verify_http_gate",
                "WORKERS",
            )
        }
        original_lstat = Path.lstat
        actual_helper_sha = hashlib.sha256(helper_path.read_bytes()).hexdigest()
        worker_state = "FATAL"
        commands = []
        current_uid = env.stat().st_uid

        def synthetic_lstat(path):
            if Path(path).resolve() == helper_path.resolve():
                return types.SimpleNamespace(
                    st_mode=stat.S_IFREG | 0o755, st_uid=0, st_gid=0
                )
            if Path(path).resolve() == env.resolve():
                return types.SimpleNamespace(st_mode=stat.S_IFREG | 0o600, st_uid=current_uid)
            return original_lstat(path)

        def read_only_run(argv, **_kwargs):
            commands.append(list(argv))
            if argv[0] == "/usr/bin/curl":
                return subprocess.CompletedProcess(argv, 0, stdout="503", stderr="")
            if argv[-1] == "recovery-worker-preflight":
                codes = {
                    "gate_marker": "FATAL_INERT_MARKERS_INVALID",
                    "worker_marker": "FATAL_INERT_MARKERS_INVALID",
                    "scheduler_marker": "FATAL_INERT_MARKERS_INVALID",
                    "nginx_config": "FATAL_INERT_NGINX_CONFIG_INVALID",
                    "http_gate": "FATAL_INERT_HTTP_GATE_OPEN",
                    "supervisor_contract": "FATAL_INERT_SUPERVISOR_CONTRACT_INVALID",
                    "scheduler_contract": "FATAL_INERT_SCHEDULER_CONTRACT_INVALID",
                    "worker_wpcaregrid_backups": "FATAL_INERT_WORKER_STATE_INVALID",
                    "worker_wpcaregrid_default": "FATAL_INERT_WORKER_STATE_INVALID",
                    "process_census": "FATAL_INERT_PROCESS_CENSUS_UNAVAILABLE",
                }
                evidence = {
                    "operation": "recovery-worker-preflight", "status": "PASS",
                    "state": "FATAL_INERT",
                    "checks": {name: {"status": "PASS"} for name in codes},
                    "blockers": [],
                    "workers": {name: "FATAL" for name in sorted(RECOVERY.WORKERS)},
                    "live_consumers": 0, "maintenance_http": 503,
                    "supervisor_control": "NOT_INVOKED",
                }
                return subprocess.CompletedProcess(argv, 0, stdout=json.dumps(evidence), stderr="")
            worker = argv[-1]
            return subprocess.CompletedProcess(
                argv, 0, stdout=f"{worker} {worker_state}\n", stderr=""
            )

        def existing_marker(path):
            return path in marker_paths.values() and path.is_file() and not path.is_symlink()

        try:
            RECOVERY.APP = app.resolve()
            RECOVERY.HELPER = str(helper_path)
            RECOVERY.HELPER_SHA = actual_helper_sha
            RECOVERY.GATE = marker_paths["gate"]
            RECOVERY.WORKER_MARKER = marker_paths["workers"]
            RECOVERY.SCHEDULER_MARKER = marker_paths["scheduler"]
            RECOVERY.marker_ok = existing_marker
            RECOVERY.run = read_only_run
            RECOVERY.WORKERS = ("wpcaregrid-default", "wpcaregrid-backups")
            with (
                patch.object(Path, "cwd", return_value=app.resolve()),
                patch.object(Path, "lstat", synthetic_lstat),
                patch.object(RECOVERY.os, "geteuid", return_value=current_uid, create=True),
                patch.dict(sys.modules, {"pwd": types.SimpleNamespace(
                    getpwnam=lambda _name: types.SimpleNamespace(pw_uid=current_uid)
                )}),
            ):
                assert not (app / "bootstrap/cache/config.php").exists()
                RECOVERY.verify_fatal_inert_protected_state()
                assert commands.count(["/usr/bin/curl", "--silent", "--show-error", "--output", "/dev/null",
                                       "--write-out", "%{http_code}", "--connect-timeout", "5", "--max-time", "15",
                                       f"{RECOVERY.URL}/up"]) == 1
                assert {command[-1] for command in commands if command[:3] == [
                    "/usr/bin/sudo", "-n", RECOVERY.HELPER
                ]} == {"recovery-worker-preflight"}

                worker_state = "STOPPED"
                try:
                    RECOVERY.verify_protected_state()
                except RECOVERY.RecoveryError as error:
                    assert str(error) == "config_cache_unavailable"
                else:
                    raise AssertionError("Laravel-dependent protected state accepted a missing config cache")
        finally:
            for name, value in saved.items():
                setattr(RECOVERY, name, value)

    recovery_source = (ROOT / "scripts/recover-staging-deployment.py").read_text(encoding="utf-8")
    fatal_preconditions = recovery_source.split(
        "def verify_fatal_inert_protected_state():", 1
    )[1].split("\ndef ", 1)[0]
    protected_preconditions = recovery_source.split(
        "def verify_protected_state():", 1
    )[1].split("\ndef ", 1)[0]
    launcher_phase = recovery_source.split(
        "def phase_runtime_launcher_recovery(", 1
    )[1].split("\ndef ", 1)[0]
    assert "config_cache_unavailable" not in fatal_preconditions
    assert "config_cache_unavailable" in protected_preconditions
    assert "verify_fatal_inert_protected_state()" in launcher_phase
    assert "verify_protected_state()" not in launcher_phase
    assert "artisan" not in launcher_phase and "COMPOSER" not in launcher_phase
    assert "verify_fatal_inert_protected_state();" in recovery_source.split(
        "def phase_migrate(", 1
    )[1].split("\ndef ", 1)[0]
    assert "verify_fatal_inert_protected_state();" in recovery_source.split(
        "def phase_finalize(", 1
    )[1].split("\ndef ", 1)[0]
    assert "verify_laravel_config_cache()" in recovery_source.split(
        "def phase_finalize(", 1
    )[1].split("\ndef ", 1)[0]
    helper_source = (ROOT / "scripts/wpcaregrid-deploy-priv.py").read_text(encoding="utf-8")
    privileged_recovery = helper_source.split(
        "def recover_runtime_launcher_fatal_inert():", 1
    )[1].split("\ndef ", 1)[0]
    assert "bootstrap/cache/config.php" not in privileged_recovery
    assert "artisan" not in privileged_recovery


def test_recovery_readiness_aggregates_without_mutation_or_config_cache():
    with tempfile.TemporaryDirectory(prefix="recovery-readiness-") as directory:
        app = Path(directory) / "app"
        app.mkdir()
        (app / "bootstrap/cache").mkdir(parents=True)
        env = app / ".env"
        env.write_bytes(b"synthetic-env-placeholder\n")
        env.chmod(0o600)
        helper = Path(directory) / "helper"
        helper.write_bytes(b"synthetic-helper\n")
        current_uid = env.stat().st_uid
        helper_hash = hashlib.sha256(helper.read_bytes()).hexdigest()
        codes = {
            "gate_marker": "FATAL_INERT_MARKERS_INVALID",
            "worker_marker": "FATAL_INERT_MARKERS_INVALID",
            "scheduler_marker": "FATAL_INERT_MARKERS_INVALID",
            "nginx_config": "FATAL_INERT_NGINX_CONFIG_INVALID",
            "http_gate": "FATAL_INERT_HTTP_GATE_OPEN",
            "supervisor_contract": "FATAL_INERT_SUPERVISOR_CONTRACT_INVALID",
            "scheduler_contract": "FATAL_INERT_SCHEDULER_CONTRACT_INVALID",
            "worker_wpcaregrid_backups": "FATAL_INERT_WORKER_STATE_INVALID",
            "worker_wpcaregrid_default": "FATAL_INERT_WORKER_STATE_INVALID",
            "process_census": "FATAL_INERT_CONSUMER_PRESENT",
        }
        worker_evidence = {
            "operation": "recovery-worker-preflight", "status": "BLOCKED", "state": "UNKNOWN",
            "checks": {name: ({"status": "BLOCKED", "code": codes[name]}
                              if name in {"http_gate", "process_census"} else {"status": "PASS"})
                       for name in codes},
            "blockers": sorted({codes["http_gate"], codes["process_census"]}),
            "workers": {}, "live_consumers": None, "maintenance_http": None,
            "supervisor_control": "NOT_INVOKED",
        }
        saved = {
            name: getattr(RECOVERY, name) for name in (
                "APP", "HELPER", "HELPER_SHA", "GATE", "WORKER_MARKER", "SCHEDULER_MARKER",
                "marker_ok", "verify_provenance", "read_fatal_inert_worker_evidence",
                "verify_backup", "verify_restore_receipt", "_readiness_runtime",
                "_readiness_nginx", "_readiness_composer", "_readiness_database",
            )
        }
        markers = [Path(directory) / name for name in ("gate", "workers", "scheduler")]
        original_lstat = Path.lstat
        def synthetic_lstat(path):
            if Path(path).resolve() == helper.resolve():
                return types.SimpleNamespace(st_mode=stat.S_IFREG | 0o755, st_uid=0, st_gid=0)
            if Path(path).resolve() == env.resolve():
                return types.SimpleNamespace(st_mode=stat.S_IFREG | 0o600, st_uid=os.geteuid(), st_gid=0)
            return original_lstat(path)
        try:
            RECOVERY.APP = app.resolve()
            RECOVERY.HELPER = str(helper)
            RECOVERY.HELPER_SHA = helper_hash
            RECOVERY.GATE, RECOVERY.WORKER_MARKER, RECOVERY.SCHEDULER_MARKER = markers
            RECOVERY.marker_ok = lambda _path: True
            RECOVERY.verify_provenance = lambda app_sha, tool_sha, _package: {app_sha: 1, tool_sha: 2}
            RECOVERY.read_fatal_inert_worker_evidence = lambda: worker_evidence
            RECOVERY.verify_backup = lambda: {"sha256": "b" * 64}
            RECOVERY.verify_restore_receipt = lambda *_args: (_ for _ in ()).throw(
                RECOVERY.RecoveryError("restore_receipt_unavailable")
            )
            RECOVERY._readiness_runtime = lambda: (_ for _ in ()).throw(
                RECOVERY.RecoveryError("php84_preflight_failed")
            )
            RECOVERY._readiness_nginx = lambda: {"nginx": "PASS"}
            RECOVERY._readiness_composer = lambda: {"platform_requirements": "PASS"}
            RECOVERY._readiness_database = lambda: (_ for _ in ()).throw(
                RECOVERY.RecoveryError("database_snapshot_unavailable")
            )
            with (
                patch.object(Path, "cwd", return_value=app.resolve()),
                patch.object(Path, "lstat", synthetic_lstat),
                patch.object(RECOVERY.os, "geteuid", return_value=current_uid, create=True),
                patch.dict(sys.modules, {"pwd": types.SimpleNamespace(
                    getpwnam=lambda _name: types.SimpleNamespace(pw_uid=current_uid)
                )}),
            ):
                fake_env = env.lstat()
                assert (stat.S_ISREG(fake_env.st_mode), stat.S_ISLNK(fake_env.st_mode),
                        fake_env.st_uid, os.geteuid(), stat.S_IMODE(fake_env.st_mode)) == (
                            True, False, current_uid, current_uid, 0o600
                        ), (fake_env, os.geteuid(), current_uid)
                result = RECOVERY.phase_readiness(RECOVERY.APP_SHA, "a" * 40, ROOT)
            assert result["RECOVERY_READINESS"] == "BLOCKED"
            assert result["STAGING_GATE"] == "CLOSED" and result["MUTATIONS"] == "NONE"
            assert result["CHECKS"]["recovery_inputs"]["status"] == "PASS", result["CHECKS"]["recovery_inputs"]
            assert result["CHECKS"]["worker_http_gate"]["code"] == "FATAL_INERT_HTTP_GATE_OPEN"
            assert result["CHECKS"]["worker_process_census"]["code"] == "FATAL_INERT_CONSUMER_PRESENT"
            assert result["CHECKS"]["config_cache"]["status"] == "REBUILD_DURING_FINALIZE"
            assert {"restore_receipt_unavailable", "php84_preflight_failed",
                    "FATAL_INERT_HTTP_GATE_OPEN", "FATAL_INERT_CONSUMER_PRESENT"}.issubset(
                        set(result["BLOCKERS"])
                    )
        finally:
            for name, value in saved.items():
                setattr(RECOVERY, name, value)


def test_recovery_readiness_short_circuits_on_helper_identity_failure():
    with tempfile.TemporaryDirectory(prefix="recovery-readiness-helper-fail-") as directory:
        app = Path(directory) / "app"
        app.mkdir()
        env = app / ".env"
        env.write_bytes(b"synthetic-env-placeholder\n")
        env.chmod(0o600)
        helper = Path(directory) / "helper"
        helper.write_bytes(b"synthetic-helper-placeholder\n")
        current_uid = env.stat().st_uid
        names = (
            "APP", "HELPER", "HELPER_SHA", "GATE", "WORKER_MARKER", "SCHEDULER_MARKER",
            "marker_ok", "verify_provenance", "read_fatal_inert_worker_evidence",
            "verify_backup", "verify_restore_receipt", "verify_pre_recovery_backup",
            "_readiness_runtime", "_readiness_nginx", "_readiness_composer",
            "_readiness_database", "run",
        )
        saved = {name: getattr(RECOVERY, name) for name in names}
        markers = [Path(directory) / name for name in ("gate", "workers", "scheduler")]
        privileged_calls = []
        original_lstat = Path.lstat
        try:
            RECOVERY.APP = app.resolve()
            RECOVERY.HELPER = str(helper)
            RECOVERY.HELPER_SHA = hashlib.sha256(helper.read_bytes()).hexdigest()
            RECOVERY.GATE, RECOVERY.WORKER_MARKER, RECOVERY.SCHEDULER_MARKER = markers
            RECOVERY.marker_ok = lambda _path: True
            RECOVERY.verify_provenance = lambda app_sha, tool_sha, _package: {app_sha: 1, tool_sha: 2}
            RECOVERY.run = lambda argv, **_kwargs: privileged_calls.append(argv)
            RECOVERY.read_fatal_inert_worker_evidence = lambda: privileged_calls.append("worker-preflight")
            RECOVERY.verify_backup = lambda: privileged_calls.append("backup")
            RECOVERY.verify_restore_receipt = lambda *_args: privileged_calls.append("restore")
            RECOVERY.verify_pre_recovery_backup = lambda *_args: privileged_calls.append("pre-backup")
            RECOVERY._readiness_runtime = lambda: privileged_calls.append("runtime")
            RECOVERY._readiness_nginx = lambda: privileged_calls.append("nginx")
            RECOVERY._readiness_composer = lambda: privileged_calls.append("composer")
            RECOVERY._readiness_database = lambda: privileged_calls.append("database")
            def synthetic_lstat(path):
                if Path(path).resolve() == helper.resolve():
                    # Correct file type and mode, wrong owner: stop before hashing or sudo.
                    return types.SimpleNamespace(st_mode=stat.S_IFREG | 0o755, st_uid=1000, st_gid=0)
                if Path(path).resolve() == env.resolve():
                    return types.SimpleNamespace(st_mode=stat.S_IFREG | 0o600, st_uid=os.geteuid(), st_gid=0)
                return original_lstat(path)
            with (
                patch.object(Path, "cwd", return_value=app.resolve()),
                patch.object(Path, "lstat", synthetic_lstat),
                patch.object(RECOVERY.os, "geteuid", return_value=current_uid, create=True),
                patch.dict(sys.modules, {"pwd": types.SimpleNamespace(
                    getpwnam=lambda _name: types.SimpleNamespace(pw_uid=current_uid)
                )}),
            ):
                RECOVERY.reset_recovery_execution_state()
                result = RECOVERY.phase_readiness(RECOVERY.APP_SHA, "a" * 40, ROOT)
            assert result["RECOVERY_READINESS"] == "BLOCKED"
            assert result["CHECKS"]["recovery_inputs"] == {
                "status": "BLOCKED", "code": "helper_integrity_failed"
            }
            assert all(result["CHECKS"][name]["status"] == "NOT_EVALUATED"
                       for name in ("worker_runtime", "runtime", "nginx", "database_schema"))
            assert result["BLOCKERS"] == ["helper_integrity_failed"]
            assert result["STAGING_GATE"] == "CLOSED" and result["MUTATIONS"] == "NONE"
            assert privileged_calls == []
        finally:
            RECOVERY.reset_recovery_execution_state()
            for name, value in saved.items():
                setattr(RECOVERY, name, value)


def test_restore_receipt_is_validated_from_root_opened_descriptor():
    payload = {
        "schema": 1, "application_sha": RECOVERY.APP_SHA,
        "backup_sha256": RECOVERY.BACKUP_SHA,
        "mysql_server_uuid_sha256": RECOVERY.MYSQL_UUID_SHA,
        "mysql_version": "8.4.11", "restore_database": "wpcg_recovery_restore_20261105_a1b2c3d4",
        "import_verified": True, "schema_verified": True,
        "ledger_verified": True, "triggers_verified": True,
        "recorded_at_utc": "2026-11-05T12:00:00Z",
    }
    encoded = json.dumps(payload, sort_keys=True, separators=(",", ":")).encode()
    expected = hashlib.sha256(encoded).hexdigest()
    with tempfile.TemporaryFile() as receipt:
        receipt.write(encoded)
        receipt.flush()
        original_fstat = os.fstat
        def root_receipt_fstat(fd):
            actual = original_fstat(fd)
            return types.SimpleNamespace(st_mode=stat.S_IFREG | 0o600, st_uid=0,
                                         st_gid=0, st_nlink=1, st_size=len(encoded))
        with patch.object(RECOVERY.os, "fstat", root_receipt_fstat):
            assert RECOVERY.verify_restore_receipt(RECOVERY.APP_SHA, receipt.fileno(), expected) == expected
            try:
                RECOVERY.verify_restore_receipt(RECOVERY.APP_SHA, receipt.fileno(), "0" * 64)
            except RECOVERY.RecoveryError as error:
                assert str(error) == "restore_receipt_identity_mismatch"
            else:
                raise AssertionError("restore receipt hash mismatch was accepted")

    # The isolated restore tool requires YYYYMMDD + underscore + eight lowercase
    # hexadecimal digits. A legacy 12-hex receipt must not pass this boundary.
    invalid_restore_databases = (
        "wpcg_recovery_restore_012345abcdef",
        "wpcg_recovery_restore_20261105_ABCDEF12",
        "wpcg_recovery_restore_20261105_bad!",
        "wpcg_recovery_restore_2026115_a1b2c3d4",
        "wpcg_recovery_restore_20261105_a1b2c3d4_extra",
        "wpcaregrid",
    )
    for invalid_name in invalid_restore_databases:
        invalid_payload = dict(payload, restore_database=invalid_name)
        invalid_encoded = json.dumps(invalid_payload, sort_keys=True, separators=(",", ":")).encode()
        invalid_hash = hashlib.sha256(invalid_encoded).hexdigest()
        with tempfile.TemporaryFile() as invalid_receipt:
            invalid_receipt.write(invalid_encoded)
            invalid_receipt.flush()
            def root_invalid_fstat(fd):
                actual = original_fstat(fd)
                return types.SimpleNamespace(st_mode=stat.S_IFREG | 0o600, st_uid=0,
                                             st_gid=0, st_nlink=1, st_size=len(invalid_encoded))
            with patch.object(RECOVERY.os, "fstat", root_invalid_fstat):
                try:
                    RECOVERY.verify_restore_receipt(
                        RECOVERY.APP_SHA, invalid_receipt.fileno(), invalid_hash
                    )
                except RECOVERY.RecoveryError as error:
                    assert str(error) == "restore_receipt_invalid", (invalid_name, error)
                else:
                    raise AssertionError(f"invalid isolated restore schema accepted: {invalid_name}")



def nginx_dump(files):
    items = list(files.items())
    return "".join(
        f"# configuration file {path}:\n{content}" + ("\n" if index < len(items) - 1 else "")
        for index, (path, content) in enumerate(items)
    )


def nginx_fixture(profile="8.4", *, staging_name="staging.wpcaregrid.com", extra_staging_include=None, handler_override=None, selector_path=None):
    selector_path = selector_path or module.PHP_RUNTIME_LINK.as_posix()
    files = {
        "/etc/nginx/nginx.conf": "events {}\nhttp {\n    include /etc/nginx/sites-enabled/*;\n}\n",
        "/etc/nginx/sites-enabled/staging": (
            "server {\n"
            f"    server_name {staging_name};\n"
            "    include /etc/nginx/snippets/wpcaregrid-staging-routes.conf;\n"
            + (f"    include {extra_staging_include};\n" if extra_staging_include else "")
            + "}\n"
        ),
        "/etc/nginx/sites-enabled/sibling": (
            "server {\n"
            "    server_name sibling.example;\n"
            "    location ~ \\.php$ {\n"
            "        include /etc/nginx/snippets/sibling-fastcgi.conf;\n"
            "    }\n"
            "}\n"
        ),
        "/etc/nginx/snippets/wpcaregrid-staging-routes.conf": (
            "location ~ \\.php$ {\n"
            "    include /etc/nginx/snippets/wpcaregrid-staging-handler.conf;\n"
            "    include fastcgi_params;\n"
            "}\n"
        ),
        "/etc/nginx/snippets/wpcaregrid-staging-handler.conf": handler_override or f"include {selector_path};\n",
        selector_path: module.PHP_RUNTIME_DIRECTIVES[profile],
        "/etc/nginx/fastcgi_params": "fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\n",
        "/etc/nginx/snippets/sibling-fastcgi.conf": "fastcgi_pass unix:/run/php/php8.4-fpm.sock;\n",
    }
    if extra_staging_include and extra_staging_include not in files:
        files[extra_staging_include] = "location ~ \\.alternate-php$ {\n    include /etc/nginx/snippets/staging-extra-fastcgi.conf;\n}\n"
        files["/etc/nginx/snippets/staging-extra-fastcgi.conf"] = "fastcgi_pass unix:/run/php/php8.4-fpm.sock;\n"
    dump = nginx_dump(files)
    return dump, files


def validate_nginx_fixture(dump, files, resolver_override=None):
    def source_reader(path):
        if path not in files:
            raise ValueError("fixture source missing")
        return files[path]

    def include_resolver(pattern, source_paths):
        if resolver_override is not None:
            return resolver_override(pattern, source_paths)
        return module._resolve_nginx_include(
            pattern,
            source_paths,
            filesystem_glob=lambda resolved: sorted(
                path for path in files if fnmatch.fnmatchcase(path, resolved)
            ),
        )

    NGINX_VALIDATOR(dump, source_reader=source_reader, include_resolver=include_resolver)


def main():
    test_gated_recovery_contract()
    test_migration_failure_reasserts_recovery_fences_without_destructive_rollback()
    test_pre_mutation_exceptions_never_run_privileged_fence_commands()
    test_unverified_helper_identity_blocks_all_privileged_commands()
    test_fatal_inert_launcher_recovery()
    test_fatal_inert_recovery_without_laravel_config_cache()
    test_recovery_readiness_aggregates_without_mutation_or_config_cache()
    test_recovery_readiness_short_circuits_on_helper_identity_failure()
    test_restore_receipt_is_validated_from_root_opened_descriptor()
    if os.name != "nt":
        bash = shutil.which("bash")
        if not bash:
            raise AssertionError("Linux deployment policy tests require Bash for syntax validation")
        subprocess.run(
            [
                bash,
                "-n",
                str(CONTROLLER),
                str(ROOT / "scripts/run-scheduler.sh"),
                str(ROOT / "scripts/run-php-runtime.sh"),
            ],
            check=True,
        )

    invocations = []

    def fake_run(argv, **kwargs):
        invocations.append(argv)
        return subprocess.CompletedProcess(argv, 0, stdout="wpcaregrid-default RUNNING pid 42\n")

    module.run = fake_run
    output = io.StringIO()
    with redirect_stdout(output):
        module.worker("status", "wpcaregrid-default")
    assert output.getvalue() == "wpcaregrid-default RUNNING pid 42\n"
    assert invocations[-1] == ["/usr/bin/supervisorctl", "status", "wpcaregrid-default"]

    status_cases = (
        (0, "wpcaregrid-default RUNNING pid 42, uptime 0:01:00\n", "wpcaregrid-default RUNNING pid 42\n"),
        (3, "wpcaregrid-default STOPPED Oct 17 01:59 PM\n", "wpcaregrid-default STOPPED\n"),
        (3, "wpcaregrid-default FATAL Exited too quickly\n", "wpcaregrid-default FATAL\n"),
    )
    for returncode, response, expected in status_cases:
        module.run = lambda argv, **kwargs: subprocess.CompletedProcess(argv, returncode, stdout=response)
        output = io.StringIO()
        with redirect_stdout(output):
            module.worker("status", "wpcaregrid-default")
        assert output.getvalue() == expected
    module.run = lambda argv, **kwargs: subprocess.CompletedProcess(
        argv, 3, stdout="unrelated-service STOPPED\n"
    )
    try:
        module.worker("status", "wpcaregrid-default")
    except ValueError:
        pass
    else:
        raise AssertionError("unexpected Supervisor status output was accepted")
    module.run = fake_run
    module.dispatch(["db-dump"])
    assert invocations[-1] == ["/usr/bin/mysqldump", "--single-transaction", "--routines", "--triggers", "--databases", "wpcaregrid"]

    for args in (
        ["worker", "status", "unrelated-service"],
        ["worker", "shell", "wpcaregrid-default"],
        ["db-dump", "other_database"],
        ["file-check", "www-data", "read", "../../etc/shadow"],
        ["file-check", "root", "read", "artisan"],
        ["cache-file-secure", "storage/framework/views/../../.env"],
        ["gate-on", "/etc/passwd"],
        ["rollback-allowed", "../etc/passwd"],
        ["supervisorctl", "status"],
        ["run", "/bin/sh", "-c", "id"],
    ):
        expect_rejected(args)

    with tempfile.TemporaryDirectory() as temporary:
        app = Path(temporary) / "app"
        app.mkdir()
        module.APP = app
        (app / "artisan").write_text("safe\n", encoding="utf-8")
        (app / "link").symlink_to("/etc/passwd")
        for relative in ("../etc/passwd", "link", "missing"):
            try:
                module.app_file(relative)
            except (ValueError, OSError):
                continue
            raise AssertionError(f"unsafe application path accepted: {relative}")

    with tempfile.TemporaryDirectory() as temporary:
        approval = Path(temporary) / "approved-rollback-sha"
        rollback_sha = "a" * 40
        approval.write_bytes((rollback_sha + "\n").encode("ascii"))
        approval.chmod(0o600)
        owner = approval.stat().st_uid
        test_mode = None if os.name == "nt" else 0o600
        assert module.read_approved_rollback(approval, expected_owner=owner, expected_mode=test_mode) == rollback_sha
        approval.write_bytes(b"not-a-full-sha\n")
        try:
            module.read_approved_rollback(approval, expected_owner=owner, expected_mode=test_mode)
        except ValueError:
            pass
        else:
            raise AssertionError("malformed rollback approval was accepted")
        link = Path(temporary) / "approval-link"
        link.symlink_to(approval)
        try:
            module.read_approved_rollback(link, expected_owner=owner, expected_mode=test_mode)
        except (OSError, ValueError):
            pass
        else:
            raise AssertionError("symlink rollback approval was accepted")

    module.APPROVED_ROLLBACK = Path("/root-owned-approval-fixture")
    module.read_approved_rollback = lambda: "a" * 40
    module.dispatch(["rollback-allowed", "a" * 40])
    expect_rejected(["rollback-allowed", "b" * 40])

    assert module.php_profile("8.4") == "8.4"
    assert module.php_profile("8.5") == "8.5"
    for profile in ("8.3", "8.6", "/usr/bin/php8.5", "", "8.5;id"):
        try:
            module.php_profile(profile)
        except ValueError:
            pass
        else:
            raise AssertionError(f"unsupported PHP runtime profile accepted: {profile!r}")

    stopped = {name: "STOPPED" for name in module.WORKERS}
    running = {name: "RUNNING" for name in module.WORKERS}
    assert module.runtime_switch_preconditions(True, True, stopped)
    assert not module.runtime_switch_preconditions(False, True, stopped)
    assert not module.runtime_switch_preconditions(True, False, stopped)
    assert not module.runtime_switch_preconditions(True, True, running)
    assert not module.runtime_switch_preconditions(True, True, {"unexpected": "STOPPED"})

    with tempfile.TemporaryDirectory() as temporary:
        marker = Path(temporary) / "workers-disabled"
        marker.write_text("disabled\n", encoding="ascii")
        prior_marker = module.WORKERS_DISABLED
        prior_require = module.require_regular
        prior_run = module.run
        module.WORKERS_DISABLED = marker
        module.require_regular = lambda *args, **kwargs: None
        module.run = lambda *args, **kwargs: (_ for _ in ()).throw(AssertionError("disabled worker reached Supervisor"))
        try:
            module.worker("start", "wpcaregrid-default")
        except ValueError:
            pass
        else:
            raise AssertionError("worker start bypassed the persistent disable marker")
        finally:
            module.WORKERS_DISABLED = prior_marker
            module.require_regular = prior_require
            module.run = prior_run

    for profile in ("8.4", "8.5"):
        valid_runtime = {
            "binary_ok": True,
            "cli_version": profile,
            "fpm_active": True,
            "fpm_binary_ok": True,
            "fpm_executable": module.PHP_RUNTIME_FPM_BINARIES[profile].as_posix(),
            "fpm_version": profile + ".27",
            "socket_type": True,
            "socket_uid": 33,
            "socket_gid": 33,
            "socket_mode": 0o660,
            "expected_uid": 33,
            "expected_gid": 33,
        }
        module.validate_runtime_evidence(profile, **valid_runtime)
        for key, bad_value in (("binary_ok", False), ("cli_version", "8.4" if profile == "8.5" else "8.5"), ("fpm_active", False), ("fpm_binary_ok", False), ("fpm_executable", "/usr/sbin/php-fpm8.4" if profile == "8.5" else "/usr/sbin/php-fpm8.5"), ("fpm_version", "8.4.27" if profile == "8.5" else "8.5.12"), ("socket_type", False), ("socket_uid", 0), ("socket_gid", 0), ("socket_mode", 0o666)):
            invalid_runtime = dict(valid_runtime)
            invalid_runtime[key] = bad_value
            try:
                module.validate_runtime_evidence(profile, **invalid_runtime)
            except ValueError:
                pass
            else:
                raise AssertionError(f"runtime mismatch accepted: {profile} {key}")
    fpm_modules = """[PHP Modules]\nctype\ncurl\ndom\nfileinfo\nfilter\nhash\niconv\nintl\njson\nlibxml\nmbstring\nopenssl\npcre\nPDO\npdo_mysql\nsession\nSimpleXML\nsodium\ntokenizer\nxml\nzip\n[Zend Modules]\nZend OPcache\n"""
    fpm_info = """PHP Version => 8.4\nopcache.validate_timestamps => On => On\nopcache.revalidate_freq => 2 => 2\n"""
    module.validate_fpm_runtime_info(fpm_info, fpm_modules)
    for invalid_info in (
        fpm_info.replace("opcache.validate_timestamps => On => On", "opcache.validate_timestamps => Off => Off"),
        fpm_info.replace("opcache.revalidate_freq => 2 => 2", "opcache.revalidate_freq => 5 => 5"),
        fpm_info.replace("opcache.revalidate_freq => 2 => 2", ""),
    ):
        try:
            module.validate_fpm_runtime_info(invalid_info, fpm_modules)
        except ValueError:
            pass
        else:
            raise AssertionError("incompatible PHP-FPM OPcache configuration was accepted")
    for invalid_modules in (
        fpm_modules.replace("pdo_mysql\n", ""),
        fpm_modules.replace("[PHP Modules]\n", ""),
    ):
        try:
            module.validate_fpm_runtime_info(fpm_info, invalid_modules)
        except ValueError:
            pass
        else:
            raise AssertionError("incomplete PHP-FPM module list was accepted")

    assert all(module.re.fullmatch(r"PHP_RUNTIME_[A-Z0-9_]+", code) for code in module.PREFLIGHT_FAILURE_CODES)
    assert "PHP_RUNTIME_PREFLIGHT_UNCLASSIFIED" in module.PREFLIGHT_FAILURE_CODES
    expect_preflight_code(
        "PHP_RUNTIME_FPM_CONFIG_REJECTED",
        lambda: module.preflight_step(
            "PHP_RUNTIME_FPM_CONFIG_REJECTED",
            lambda: (_ for _ in ()).throw(OSError("synthetic-secret-path")),
        ),
    )

    for code, callback in (
        ("PHP_RUNTIME_FPM_MAIN_PID_UNAVAILABLE", lambda: module.validate_fpm_main_process("8.4", pid="0", executable="", cgroup_paths=set(), command_line_uses_config=False)),
        ("PHP_RUNTIME_FPM_EXECUTABLE_MISMATCH", lambda: module.validate_fpm_main_process("8.4", pid="123", executable="/wrong", cgroup_paths={module.PHP_RUNTIME_FPM_CGROUPS["8.4"]}, command_line_uses_config=True)),
        ("PHP_RUNTIME_FPM_CGROUP_MISMATCH", lambda: module.validate_fpm_main_process("8.4", pid="123", executable=module.PHP_RUNTIME_FPM_BINARIES["8.4"].as_posix(), cgroup_paths=set(), command_line_uses_config=True)),
        ("PHP_RUNTIME_FPM_MAIN_PROCESS_INVALID", lambda: module.validate_fpm_main_process("8.4", pid="123", executable=module.PHP_RUNTIME_FPM_BINARIES["8.4"].as_posix(), cgroup_paths={module.PHP_RUNTIME_FPM_CGROUPS["8.4"]}, command_line_uses_config=False)),
    ):
        expect_preflight_code(code, callback)

    runtime_code_fields = (
        ("binary_ok", False, "PHP_RUNTIME_CLI_BINARY_INVALID"),
        ("cli_version", "8.5", "PHP_RUNTIME_CLI_VERSION_MISMATCH"),
        ("fpm_binary_ok", False, "PHP_RUNTIME_FPM_METADATA_INVALID"),
        ("fpm_active", False, "PHP_RUNTIME_FPM_PROCESS_INACTIVE"),
        ("fpm_executable", "/wrong", "PHP_RUNTIME_FPM_EXECUTABLE_MISMATCH"),
        ("fpm_version", "7.4.0", "PHP_RUNTIME_FPM_VERSION_MISMATCH"),
        ("socket_type", False, "PHP_RUNTIME_FPM_SOCKET_TYPE_INVALID"),
        ("socket_uid", 0, "PHP_RUNTIME_FPM_SOCKET_OWNER_MISMATCH"),
        ("socket_gid", 0, "PHP_RUNTIME_FPM_SOCKET_GROUP_MISMATCH"),
        ("socket_mode", 0o666, "PHP_RUNTIME_FPM_SOCKET_MODE_MISMATCH"),
    )
    for profile in ("8.4", "8.5"):
        valid_runtime = {
            "binary_ok": True,
            "cli_version": profile,
            "fpm_active": True,
            "fpm_binary_ok": True,
            "fpm_executable": module.PHP_RUNTIME_FPM_BINARIES[profile].as_posix(),
            "fpm_version": profile + ".27",
            "socket_type": True,
            "socket_uid": 33,
            "socket_gid": 33,
            "socket_mode": 0o660,
            "expected_uid": 33,
            "expected_gid": 33,
        }
        for field, bad_value, code in runtime_code_fields:
            invalid = dict(valid_runtime)
            invalid[field] = ("8.4" if profile == "8.5" else "8.5") if field == "cli_version" else bad_value
            expect_preflight_code(code, lambda invalid=invalid, profile=profile: module.validate_runtime_evidence(profile, **invalid))

    for code, info, modules in (
        ("PHP_RUNTIME_FPM_MODULE_LIST_UNAVAILABLE", fpm_info, fpm_modules.replace("[PHP Modules]\n", "")),
        ("PHP_RUNTIME_FPM_REQUIRED_MODULES_MISSING", fpm_info, fpm_modules.replace("pdo_mysql\n", "")),
        ("PHP_RUNTIME_FPM_OPCACHE_TIMESTAMPS_DISABLED", fpm_info.replace("On => On", "Off => Off"), fpm_modules),
        ("PHP_RUNTIME_FPM_OPCACHE_INTERVAL_UNAVAILABLE", fpm_info.replace("opcache.revalidate_freq => 2 => 2\n", ""), fpm_modules),
        ("PHP_RUNTIME_FPM_OPCACHE_INTERVAL_UNAVAILABLE", fpm_info.replace("opcache.revalidate_freq => 2 => 2", "invalid"), fpm_modules),
        ("PHP_RUNTIME_FPM_OPCACHE_INTERVAL_TOO_HIGH", fpm_info.replace("2 => 2", "5 => 5"), fpm_modules),
    ):
        expect_preflight_code(code, lambda info=info, modules=modules: module.validate_fpm_runtime_info(info, modules))

    for code, dump in (
        ("PHP_RUNTIME_FPM_POOL_USER_MISMATCH", "[www]\nuser = nobody\ngroup = www-data\nlisten = /run/php/php8.4-fpm.sock\n"),
        ("PHP_RUNTIME_FPM_POOL_GROUP_MISMATCH", "[www]\nuser = www-data\ngroup = nobody\nlisten = /run/php/php8.4-fpm.sock\n"),
        ("PHP_RUNTIME_FPM_POOL_SOCKET_MISMATCH", "[www]\nuser = www-data\ngroup = www-data\nlisten = /run/php/other.sock\n"),
        ("PHP_RUNTIME_FPM_POOL_DUPLICATE_SOCKET", "[www]\nuser = www-data\ngroup = www-data\nlisten = /run/php/php8.4-fpm.sock\n[other]\nlisten = /run/php/php8.4-fpm.sock\n"),
    ):
        expect_preflight_code(code, lambda dump=dump: module.validate_fpm_pool_listen("8.4", dump))

    expect_preflight_code(
        "PHP_RUNTIME_LISTENER_OWNER_MISSING",
        lambda: module.validate_fpm_listener_processes("8.4", 1, 1, [], lambda pid, fd: {}),
    )
    expect_preflight_code(
        "PHP_RUNTIME_LISTENER_PROCESS_UNAVAILABLE",
        lambda: module.validate_fpm_listener_processes("8.4", 1, 1, [(1, 1)], lambda pid, fd: (_ for _ in ()).throw(OSError("synthetic-secret"))),
    )
    expect_preflight_code(
        "PHP_RUNTIME_LISTENER_EXECUTABLE_MISMATCH",
        lambda: module.validate_fpm_listener_processes("8.4", 1, 1, [(1, 1)], lambda pid, fd: {"executable": "/wrong", "cgroup_paths": set(), "fd_is_socket": False}),
    )
    expect_preflight_code(
        "PHP_RUNTIME_LISTENER_CGROUP_MISMATCH",
        lambda: module.validate_fpm_listener_processes("8.4", 1, 1, [(1, 1)], lambda pid, fd: {"executable": module.PHP_RUNTIME_FPM_BINARIES["8.4"].as_posix(), "cgroup_paths": set(), "fd_is_socket": True}),
    )
    expect_preflight_code(
        "PHP_RUNTIME_LISTENER_FD_TYPE_MISMATCH",
        lambda: module.validate_fpm_listener_processes("8.4", 1, 1, [(1, 1)], lambda pid, fd: {"executable": module.PHP_RUNTIME_FPM_BINARIES["8.4"].as_posix(), "cgroup_paths": {module.PHP_RUNTIME_FPM_CGROUPS["8.4"]}, "fd_is_socket": False}),
    )

    original_impl = module._php_runtime_preflight
    module._php_runtime_preflight = lambda *args, **kwargs: (_ for _ in ()).throw(OSError("synthetic-secret"))
    try:
        expect_preflight_code("PHP_RUNTIME_PREFLIGHT_UNCLASSIFIED", lambda: module.php_runtime_preflight("8.4"))
    finally:
        module._php_runtime_preflight = original_impl

    had_geteuid = hasattr(module.os, "geteuid")
    original_geteuid = getattr(module.os, "geteuid", None)
    original_dispatch, original_argv = module.dispatch, module.sys.argv
    error_output = io.StringIO()
    module.os.geteuid = lambda: 0
    module.dispatch = lambda args: (_ for _ in ()).throw(module.RuntimePreflightFailure("PHP_RUNTIME_FPM_CONFIG_REJECTED"))
    module.sys.argv = [str(HELPER), "php-runtime-preflight", "8.4"]
    try:
        with redirect_stderr(error_output):
            assert module.main() == 1
    finally:
        if had_geteuid:
            module.os.geteuid = original_geteuid
        else:
            del module.os.geteuid
        module.dispatch, module.sys.argv = original_dispatch, original_argv
    assert error_output.getvalue().strip() == "PREFLIGHT_FAILURE=PHP_RUNTIME_FPM_CONFIG_REJECTED"
    assert "synthetic-secret" not in error_output.getvalue()

    for profile in ("8.4", "8.5"):
        socket_path = module.PHP_RUNTIME_SOCKETS[profile].as_posix()
        good_fpm_dump = f"""[26-Oct-2026 12:00:00] NOTICE: [global]
[26-Oct-2026 12:00:00] NOTICE:  daemonize = no
[26-Oct-2026 12:00:00] NOTICE: [www]
[26-Oct-2026 12:00:00] NOTICE:  user = www-data
[26-Oct-2026 12:00:00] NOTICE:  group = www-data
[26-Oct-2026 12:00:00] NOTICE:  listen = {socket_path}
"""
        module.validate_fpm_pool_listen(profile, good_fpm_dump)
        wrong_dumps = (
            good_fpm_dump.replace(socket_path, "/run/php/php7.4-fpm.sock"),
            good_fpm_dump.replace("[www]", "[other]"),
            good_fpm_dump.replace(f"listen = {socket_path}\n", f"listen = {socket_path}\n[26-Oct-2026 12:00:00] NOTICE:  listen = /run/php/other.sock\n"),
            good_fpm_dump + f"[26-Oct-2026 12:00:00] NOTICE: [other]\n[26-Oct-2026 12:00:00] NOTICE:  listen = {socket_path}\n",
            good_fpm_dump.replace("user = www-data", "user = deploy"),
            good_fpm_dump.replace("group = www-data", "group = deploy"),
            good_fpm_dump.replace("user = www-data", "user = root"),
            good_fpm_dump.replace("group = www-data", "group = root"),
            good_fpm_dump.replace("user = www-data\n", ""),
            good_fpm_dump.replace("group = www-data\n", ""),
            good_fpm_dump.replace("user = www-data\n", "user = www-data\n[26-Oct-2026 12:00:00] NOTICE:  user = deploy\n"),
            good_fpm_dump.replace("group = www-data\n", "group = www-data\n[26-Oct-2026 12:00:00] NOTICE:  group = root\n"),
        )
        for bad_dump in wrong_dumps:
            try:
                module.validate_fpm_pool_listen(profile, bad_dump)
            except ValueError:
                pass
            else:
                raise AssertionError(f"wrong effective FPM pool listener passed for PHP {profile}")

        main_identity = {
            "pid": 1234,
            "executable": module.PHP_RUNTIME_FPM_BINARIES[profile].as_posix(),
            "cgroup_paths": {module.PHP_RUNTIME_FPM_CGROUPS[profile]},
            "command_line_uses_config": True,
        }
        module.validate_fpm_main_process(profile, **main_identity)
        assert module.command_line_uses_fpm_config(profile, [module.PHP_RUNTIME_FPM_BINARIES[profile].as_posix(), "--nodaemonize", "--fpm-config", module.PHP_RUNTIME_FPM_CONFIGS[profile].as_posix()])
        assert module.command_line_uses_fpm_config(profile, [f"php-fpm: master process ({module.PHP_RUNTIME_FPM_CONFIGS[profile].as_posix()})"])
        assert not module.command_line_uses_fpm_config(profile, [module.PHP_RUNTIME_FPM_BINARIES[profile].as_posix(), "--fpm-config", "/tmp/untrusted-fpm.conf"])
        for key, value in (
            ("executable", module.PHP_RUNTIME_FPM_BINARIES["8.5" if profile == "8.4" else "8.4"].as_posix()),
            ("cgroup_paths", {"/system.slice/unrelated.service"}),
            ("command_line_uses_config", False),
        ):
            invalid_identity = dict(main_identity)
            invalid_identity[key] = value
            try:
                module.validate_fpm_main_process(profile, **invalid_identity)
            except ValueError:
                pass
            else:
                raise AssertionError(f"incorrect PHP {profile} FPM service identity passed")

        listener_line = f"u_str LISTEN 0 511 {socket_path} * 0 users:((\"php-fpm8.{profile[-1]}\",pid=1234,fd=8)) ino:98765 sk:1"
        inode, owners = module.parse_unix_listener_records(listener_line, socket_path)
        assert inode == 98765 and owners == [(1234, 8)]
        valid_evidence = {
            "executable": module.PHP_RUNTIME_FPM_BINARIES[profile].as_posix(),
            "cgroup_paths": {module.PHP_RUNTIME_FPM_CGROUPS[profile]},
            "fd_is_socket": True,
        }
        module.validate_fpm_listener_processes(profile, inode, inode, owners, lambda pid, fd: valid_evidence)
        try:
            module.validate_fpm_listener_processes(profile, inode, inode + 1, owners, lambda pid, fd: valid_evidence)
        except ValueError:
            pass
        else:
            raise AssertionError("listener inode that differs from the configured socket path was accepted")
        for invalid_output in (
            "",
            f"u_str ESTAB 0 511 {socket_path} * 0 users:((\"php-fpm\",pid=1234,fd=8)) ino:98765",
            f"u_str LISTEN 0 511 {socket_path} * 0 ino:98765",
        ):
            try:
                module.parse_unix_listener_records(invalid_output, socket_path)
            except ValueError:
                pass
            else:
                raise AssertionError("stale, inactive, or ownerless FPM socket passed listener validation")
        for invalid_evidence in (
            {"executable": "/usr/bin/python3", "cgroup_paths": {module.PHP_RUNTIME_FPM_CGROUPS[profile]}, "fd_is_socket": True},
            {"executable": module.PHP_RUNTIME_FPM_BINARIES[profile].as_posix(), "cgroup_paths": {"/system.slice/other.service"}, "fd_is_socket": True},
            {"executable": module.PHP_RUNTIME_FPM_BINARIES[profile].as_posix(), "cgroup_paths": {module.PHP_RUNTIME_FPM_CGROUPS[profile]}, "fd_is_socket": False},
        ):
            try:
                module.validate_fpm_listener_processes(profile, inode, inode, owners, lambda pid, fd, item=invalid_evidence: item)
            except ValueError:
                pass
            else:
                raise AssertionError("foreign process, service, or socket inode passed FPM listener validation")

    if os.name != "nt":
        ss_binary = shutil.which("ss")
        if not ss_binary:
            raise AssertionError("Linux listener integration requires the iproute2 ss utility")
        with tempfile.TemporaryDirectory() as temporary:
            live_path = str(Path(temporary) / "live-listener.sock")
            listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
            listener.bind(live_path)
            listener.listen(1)
            try:
                ss = subprocess.run([ss_binary, "-xlepH"], check=True, capture_output=True, text=True, timeout=5)
                inode, owners = module.parse_unix_listener_records(ss.stdout, live_path)
                assert os.getpid() in {pid for pid, _ in owners}, "real Unix listener is not attributed to its owning process"
                targets = [(pid, fd, os.readlink(f"/proc/{pid}/fd/{fd}")) for pid, fd in owners]
                assert os.lstat(live_path).st_ino == inode, "ss listener inode did not match the bound socket path inode"
                assert all(stat.S_ISSOCK(os.stat(f"/proc/{pid}/fd/{fd}").st_mode) for pid, fd in owners), f"ss listener owners no longer hold socket descriptors: {targets!r}"
            finally:
                listener.close()

    for profile in ("8.4", "8.5"):
        for name, command in module.WORKER_COMMANDS.items():
            expected_executable = module.PHP_RUNTIME_BINARIES[profile].as_posix()
            expected_argv = [expected_executable, *command.split()[1:]]
            status = f"{name} RUNNING pid 42, uptime 0:01:00\n"
            module.validate_worker_runtime(profile, name, status, expected_executable, expected_argv)
            for bad_executable, bad_argv in (
                (module.PHP_RUNTIME_BINARIES["8.5" if profile == "8.4" else "8.4"].as_posix(), expected_argv),
                (expected_executable, [expected_executable, "/tmp/unrelated.php"]),
            ):
                try:
                    module.validate_worker_runtime(profile, name, status, bad_executable, bad_argv)
                except ValueError:
                    pass
                else:
                    raise AssertionError("mismatched live worker runtime was accepted")

    if os.name != "nt":
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            profile_files = {"8.4": root / "php84.conf", "8.5": root / "php85.conf"}
            for profile, path in profile_files.items():
                path.write_text(profile, encoding="ascii")
            active_link = root / "active.conf"
            active_link.symlink_to(profile_files["8.4"])
            gate = root / "deploy-maintenance"
            pause = root / "scheduler-paused"
            disabled = root / "workers-disabled"
            for marker in (gate, pause, disabled):
                marker.write_text("active\n", encoding="ascii")
            saved = {
                name: getattr(module, name)
                for name in ("PHP_RUNTIME_LINK", "PHP_RUNTIME_FILES", "GATE", "SCHEDULER_PAUSE", "WORKERS_DISABLED", "open_root_trusted_directory", "active_php_profile", "runtime_snippet", "php_runtime_preflight", "require_regular", "validate_nginx_runtime_dump", "run")
            }
            try:
                module.PHP_RUNTIME_LINK = active_link
                module.PHP_RUNTIME_FILES = profile_files
                module.GATE = gate
                module.SCHEDULER_PAUSE = pause
                module.WORKERS_DISABLED = disabled
                module.open_root_trusted_directory = lambda path: os.open(path, os.O_RDONLY | os.O_DIRECTORY)
                module.active_php_profile = lambda: next(profile for profile, path in profile_files.items() if os.readlink(active_link) == str(path))
                module.runtime_snippet = lambda profile: None
                module.php_runtime_preflight = lambda profile: None
                module.require_regular = lambda path, **kwargs: (_ for _ in ()).throw(ValueError("unsafe marker")) if path.is_symlink() or not path.is_file() else path.stat()
                failure_state = {"fail_nginx_once": False, "fail_reload_once": False}
                nginx_state = {}
                original_nginx_validator = module.validate_nginx_runtime_dump
                module.validate_nginx_runtime_dump = lambda output: validate_nginx_fixture(output, nginx_state["files"])

                def fake_runtime_run(argv, **kwargs):
                    if argv[:2] == ["/usr/bin/supervisorctl", "status"]:
                        name = argv[-1]
                        return subprocess.CompletedProcess(argv, 0, stdout=f"{name} STOPPED\n")
                    if argv == ["/usr/sbin/nginx", "-t"] and failure_state["fail_nginx_once"]:
                        failure_state["fail_nginx_once"] = False
                        raise subprocess.CalledProcessError(1, argv)
                    if argv == ["/usr/bin/systemctl", "reload", "nginx"] and failure_state["fail_reload_once"]:
                        failure_state["fail_reload_once"] = False
                        raise subprocess.CalledProcessError(1, argv)
                    if argv == ["/usr/sbin/nginx", "-T"]:
                        dump, files = nginx_fixture(profile=module.active_php_profile())
                        nginx_state["files"] = files
                        return subprocess.CompletedProcess(argv, 0, stdout=dump)
                    return subprocess.CompletedProcess(argv, 0, stdout="")

                module.run = fake_runtime_run
                module.php_runtime_preflight = lambda profile: (_ for _ in ()).throw(ValueError("synthetic preflight refusal"))
                try:
                    module.switch_php_runtime("8.5")
                except ValueError:
                    pass
                else:
                    raise AssertionError("failed preflight permitted a runtime selector change")
                assert module.active_php_profile() == "8.4", "preflight failure changed the active selector"
                assert gate.is_file() and pause.is_file() and disabled.is_file()
                module.php_runtime_preflight = lambda profile: None
                module.switch_php_runtime("8.5")
                assert module.active_php_profile() == "8.5"
                active_link.unlink()
                active_link.symlink_to(profile_files["8.4"])
                failure_state["fail_nginx_once"] = True
                try:
                    module.switch_php_runtime("8.5")
                except ValueError:
                    pass
                else:
                    raise AssertionError("failed Nginx validation did not abort runtime switch")
                assert module.active_php_profile() == "8.4", "partial runtime transition did not restore previous selector"
                assert gate.is_file() and pause.is_file() and disabled.is_file(), "failed runtime transition removed a fail-closed marker"

                failure_state["fail_reload_once"] = True
                try:
                    module.switch_php_runtime("8.5")
                except ValueError:
                    pass
                else:
                    raise AssertionError("failed Nginx reload did not abort runtime switch")
                assert module.active_php_profile() == "8.4", "reload failure did not restore the previous selector"
                assert gate.is_file() and pause.is_file() and disabled.is_file(), "reload failure removed a fail-closed marker"

                preflight_calls = []

                def fail_post_switch_preflight(profile):
                    preflight_calls.append((profile, module.active_php_profile()))
                    if len(preflight_calls) == 2:
                        raise ValueError("synthetic post-switch listener failure")

                module.php_runtime_preflight = fail_post_switch_preflight
                try:
                    module.switch_php_runtime("8.5")
                except ValueError:
                    pass
                else:
                    raise AssertionError("post-switch listener failure did not abort runtime switch")
                assert preflight_calls[0] == ("8.5", "8.4") and preflight_calls[1] == ("8.5", "8.5")
                assert module.active_php_profile() == "8.4", "post-switch preflight failure did not restore the previous selector"
                assert gate.is_file() and pause.is_file() and disabled.is_file(), "post-switch preflight failure removed a fail-closed marker"
            finally:
                module.validate_nginx_runtime_dump = original_nginx_validator
                for name, value in saved.items():
                    setattr(module, name, value)

    original_trusted_read = module.trusted_file_bytes
    original_run = module.run
    try:
        for profile in ("8.4", "8.5"):
            module.trusted_file_bytes = lambda path, **kwargs: module.PHP_RUNTIME_DIRECTIVES[profile].encode("ascii")
            module.run = lambda argv, **kwargs: subprocess.CompletedProcess(argv, 0, stdout="")
            module.runtime_snippet(profile)
            module.trusted_file_bytes = lambda path, **kwargs: b"fastcgi_pass unix:/run/php/php7.4-fpm.sock;\n"
            try:
                module.runtime_snippet(profile)
            except ValueError:
                pass
            else:
                raise AssertionError("unexpected FPM socket directive was accepted")

        module.SUPERVISOR_CONFIG = Path("/synthetic/wpcaregrid.conf")
        supervisor_fixture = """[program:wpcaregrid-default]\ncommand=php /var/www/wpcaregrid/artisan queue:work database --queue=default --sleep=3 --tries=3 --backoff=60 --timeout=120 --max-time=3600 --memory=256\n\n[program:wpcaregrid-backups]\ncommand=php /var/www/wpcaregrid/artisan queue:work backups --queue=backups --sleep=3 --timeout=3660 --max-time=14400 --memory=512\n"""
        module.trusted_file_bytes = lambda path, **kwargs: supervisor_fixture.encode("utf-8")
        module.run = lambda argv, **kwargs: subprocess.CompletedProcess(argv, 0, stdout="user::rw-\ngroup::r--\nother::r--\n")
        module.supervisor_runtime_contract(allow_legacy=True)
        module.run = lambda argv, **kwargs: subprocess.CompletedProcess(argv, 0, stdout="user::rw-\ngroup::r--\nother::r--\n\n")
        module.supervisor_runtime_contract(allow_legacy=True)
        module.run = lambda argv, **kwargs: subprocess.CompletedProcess(argv, 0, stdout="user::rw-\ngroup::r--\nother::r--\n\n\n")
        try:
            module.supervisor_runtime_contract(allow_legacy=True)
        except ValueError:
            pass
        else:
            raise AssertionError("Supervisor ACL with multiple extra blank lines was accepted")
        for invalid_acl in (
            "user::rw-\ngroup::r--\nother::r-x\n",
            "user::rw-\ngroup::r--\nother::r--\nuser:deploy:r--\n",
            "user::rw-\ngroup::r--\nother::r--\ndefault:user::rwx\n",
        ):
            module.run = lambda argv, **kwargs: subprocess.CompletedProcess(argv, 0, stdout=invalid_acl)
            try:
                module.supervisor_runtime_contract(allow_legacy=True)
            except ValueError:
                pass
            else:
                raise AssertionError("Supervisor ACL with non-baseline entries was accepted")
        module.run = lambda argv, **kwargs: subprocess.CompletedProcess(argv, 0, stdout="user::rw-\ngroup::r--\nother::r--\n")
        rewritten_supervisor = module.rewrite_supervisor_runtime_commands(supervisor_fixture)
        assert "command=" + module.WORKER_COMMANDS["wpcaregrid-default"] in rewritten_supervisor
        assert "command=" + module.WORKER_COMMANDS["wpcaregrid-backups"] in rewritten_supervisor
        assert module.rewrite_supervisor_runtime_commands(rewritten_supervisor) == rewritten_supervisor
        try:
            module.supervisor_runtime_contract()
        except ValueError:
            pass
        else:
            raise AssertionError("legacy implicit Supervisor PHP command passed strict runtime verification")
        supervisor_fixture = supervisor_fixture.replace("command=php ", "command=/usr/local/bin/wpcaregrid-php ")
        module.supervisor_runtime_contract()
        supervisor_fixture += "[program:unexpected]\ncommand=/bin/sh -c id\n"
        module.supervisor_runtime_contract()
        supervisor_fixture = supervisor_fixture.replace("/usr/local/bin/wpcaregrid-php /var/www/wpcaregrid/artisan queue:work backups", "php /var/www/wpcaregrid/artisan queue:work backups")
        try:
            module.supervisor_runtime_contract()
            module.rewrite_supervisor_runtime_commands(supervisor_fixture)
        except ValueError:
            pass
        else:
            raise AssertionError("mixed Supervisor PHP runtimes were accepted")
    finally:
        module.trusted_file_bytes = original_trusted_read
        module.run = original_run

    original_snippet = module.runtime_snippet
    original_active_profile = module.active_php_profile
    original_nginx_validator = module.validate_nginx_runtime_dump
    original_run = module.run
    try:
        module.runtime_snippet = lambda profile: None
        module.active_php_profile = lambda: "8.4"
        good_dump, good_sources = nginx_fixture("8.4")
        validate_nginx_fixture(good_dump, good_sources)
        parsed_sections = module._nginx_dump_sections(good_dump)
        assert parsed_sections == good_sources, "single nginx -T section-separator LF was not removed exactly"
        last_source_path = next(reversed(good_sources))
        assert parsed_sections[last_source_path] == good_sources[last_source_path], (
            "last source section did not preserve its own trailing newline"
        )
        validate_nginx_fixture(good_dump + "\n", good_sources)
        try:
            validate_nginx_fixture(good_dump + "\n\n", good_sources)
        except ValueError:
            pass
        else:
            raise AssertionError("multiple final-section framing LFs were accepted")

        no_final_newline_sources = dict(good_sources)
        no_final_newline_sources[last_source_path] = no_final_newline_sources[last_source_path].rstrip("\n")
        no_final_newline_dump = nginx_dump(no_final_newline_sources)
        validate_nginx_fixture(no_final_newline_dump, no_final_newline_sources)
        validate_nginx_fixture(no_final_newline_dump + "\n", no_final_newline_sources)
        try:
            validate_nginx_fixture(no_final_newline_dump + "\n\n", no_final_newline_sources)
        except ValueError:
            pass
        else:
            raise AssertionError("final section with two extra LFs was accepted")

        empty_wildcard_sources = dict(good_sources)
        root_source = empty_wildcard_sources["/etc/nginx/nginx.conf"]
        empty_wildcard_sources["/etc/nginx/nginx.conf"] = root_source.replace(
            "    include /etc/nginx/sites-enabled/*;\n",
            "    include /etc/nginx/modules-enabled/*.conf;\n"
            "    include /etc/nginx/conf.d/*.conf;\n"
            "    include /etc/nginx/sites-enabled/*;\n",
        )
        def empty_wildcard_resolver(pattern, source_paths):
            return module._resolve_nginx_include(
                pattern,
                source_paths,
                filesystem_glob=lambda resolved: sorted(
                    path for path in empty_wildcard_sources if fnmatch.fnmatchcase(path, resolved)
                ),
                filesystem_listdir=lambda directory: [],
            )
        validate_nginx_fixture(
            nginx_dump(empty_wildcard_sources),
            empty_wildcard_sources,
            empty_wildcard_resolver,
        )
        assert module._resolve_nginx_include(
            "/etc/nginx/modules-enabled/*.conf",
            good_sources,
            filesystem_glob=lambda resolved: [],
            filesystem_listdir=lambda directory: [],
        ) == [], "proven-empty wildcard include was rejected"
        for empty_mismatch_sources, empty_mismatch_matches in (
            (list(good_sources) + ["/etc/nginx/modules-enabled/50-module.conf"], []),
            (list(good_sources), ["/etc/nginx/modules-enabled/50-module.conf"]),
        ):
            try:
                module._resolve_nginx_include(
                    "/etc/nginx/modules-enabled/*.conf",
                    empty_mismatch_sources,
                    filesystem_glob=lambda resolved, matches=empty_mismatch_matches: matches,
                    filesystem_listdir=lambda directory: [],
                )
            except ValueError:
                pass
            else:
                raise AssertionError("empty wildcard include with mismatched sources was accepted")
        try:
            module._resolve_nginx_include(
                "/etc/nginx/modules-enabled/missing.conf",
                good_sources,
                filesystem_glob=lambda resolved: [],
            )
        except ValueError:
            pass
        else:
            raise AssertionError("missing non-wildcard include was accepted as empty")
        try:
            module._resolve_nginx_include(
                "/etc/nginx/modules-enabled/*.conf",
                good_sources,
                filesystem_glob=lambda resolved: [],
                filesystem_listdir=lambda directory: ["50-module.conf"],
            )
        except ValueError:
            pass
        else:
            raise AssertionError("filesystem match omitted by glob was accepted")

        blank_line_sources = dict(good_sources)
        first_source_path = next(iter(blank_line_sources))
        blank_line_sources[first_source_path] += "\n"
        blank_line_sections = module._nginx_dump_sections(nginx_dump(blank_line_sources))
        assert blank_line_sections[first_source_path] == blank_line_sources[first_source_path], (
            "source-owned trailing blank line was not preserved"
        )

        first_source_content = good_sources[first_source_path]
        first_marker = f"# configuration file {first_source_path}:\n"
        next_source_path = list(good_sources)[1]
        one_separator = first_marker + first_source_content + f"\n# configuration file {next_source_path}:\n"
        assert one_separator in good_dump, "Nginx fixture lacks its single framing LF"
        extra_separator_dump = good_dump.replace(
            one_separator,
            first_marker + first_source_content + f"\n\n# configuration file {next_source_path}:\n",
            1,
        )
        try:
            validate_nginx_fixture(extra_separator_dump, good_sources)
        except ValueError:
            pass
        else:
            raise AssertionError("multiple section-separator LFs were accepted as source content")

        changed_dump = good_dump.replace("events {}\n", "events { }\n", 1)
        try:
            validate_nginx_fixture(changed_dump, good_sources)
        except ValueError:
            pass
        else:
            raise AssertionError("real Nginx source-content difference was accepted")

        assert module._resolve_nginx_include(
            "fastcgi_params",
            good_sources,
            filesystem_glob=lambda resolved: sorted(
                path for path in good_sources if fnmatch.fnmatchcase(path, resolved)
            ),
        ) == ["/etc/nginx/fastcgi_params"], "relative include did not resolve against the Nginx configuration prefix"
        assert module._resolve_nginx_include(
            "fastcgi_*",
            good_sources,
            filesystem_glob=lambda resolved: sorted(
                path for path in good_sources if fnmatch.fnmatchcase(path, resolved)
            ),
        ) == ["/etc/nginx/fastcgi_params"], "static relative include mask did not resolve against the Nginx configuration prefix"
        for rejected_include in ("../fastcgi_params", "./fastcgi_params", "missing_params", "$fastcgi_params"):
            try:
                module._resolve_nginx_include(
                    rejected_include,
                    good_sources,
                    filesystem_glob=lambda resolved: sorted(
                        path for path in good_sources if fnmatch.fnmatchcase(path, resolved)
                    ),
                )
            except (ValueError, OSError):
                pass
            else:
                raise AssertionError("unsafe or unresolved relative Nginx include was accepted")
        try:
            module._resolve_nginx_include(
                "fastcgi_*",
                good_sources,
                filesystem_glob=lambda resolved: [
                    "/etc/nginx/fastcgi_params",
                    "/etc/nginx/fastcgi-secret.conf",
                ],
            )
        except ValueError:
            pass
        else:
            raise AssertionError("relative include mask with an unproven filesystem match was accepted")
        for profile in ("8.4", "8.5"):
            module.active_php_profile = lambda selected=profile: selected
            dump, sources = nginx_fixture(profile)
            validate_nginx_fixture(dump, sources)

        sibling_route = good_sources["/etc/nginx/snippets/sibling-fastcgi.conf"]
        assert sibling_route == "fastcgi_pass unix:/run/php/php8.4-fpm.sock;\n", "sibling PHP 8.4 route fixture changed"
        module.active_php_profile = lambda: "8.4"

        bad_fixtures = []
        bad_fixtures.append(nginx_fixture("8.4", staging_name="staging.wpcaregrid.com sibling.example"))
        bad_fixtures.append(nginx_fixture("8.4", staging_name="other.example"))
        bad_fixtures.append(nginx_fixture("8.4", staging_name="staging.wpcaregrid.com *.example"))
        bad_fixtures.append(nginx_fixture("8.4", staging_name=r"staging.wpcaregrid.com ~^sibling\.example$"))

        sibling_selector_dump, sibling_selector_sources = nginx_fixture("8.4")
        sibling_selector_sources["/etc/nginx/sites-enabled/sibling"] = sibling_selector_sources["/etc/nginx/sites-enabled/sibling"].replace(
            "include /etc/nginx/snippets/sibling-fastcgi.conf;", f"include {module.PHP_RUNTIME_LINK.as_posix()};"
        )
        sibling_selector_dump = nginx_dump(sibling_selector_sources)
        bad_fixtures.append((sibling_selector_dump, sibling_selector_sources))

        outside_dump, outside_sources = nginx_fixture("8.4")
        outside_sources["/etc/nginx/nginx.conf"] = outside_sources["/etc/nginx/nginx.conf"].replace(
            "include /etc/nginx/sites-enabled/*;", f"include {module.PHP_RUNTIME_LINK.as_posix()};\n    include /etc/nginx/sites-enabled/*;"
        )
        outside_dump = nginx_dump(outside_sources)
        bad_fixtures.append((outside_dump, outside_sources))

        duplicate_dump, duplicate_sources = nginx_fixture("8.4")
        duplicate_sources["/etc/nginx/snippets/wpcaregrid-staging-handler.conf"] += f"include {module.PHP_RUNTIME_LINK.as_posix()};\n"
        duplicate_dump = nginx_dump(duplicate_sources)
        bad_fixtures.append((duplicate_dump, duplicate_sources))

        competing_path = "/etc/nginx/snippets/staging-extra-location.conf"
        bad_fixtures.append(nginx_fixture("8.4", extra_staging_include=competing_path))

        cycle_dump, cycle_sources = nginx_fixture("8.4", handler_override="include /etc/nginx/snippets/cycle-a.conf;\n")
        cycle_sources["/etc/nginx/snippets/cycle-a.conf"] = "include /etc/nginx/snippets/cycle-b.conf;\n"
        cycle_sources["/etc/nginx/snippets/cycle-b.conf"] = "include /etc/nginx/snippets/cycle-a.conf;\n"
        cycle_dump = nginx_dump(cycle_sources)
        bad_fixtures.append((cycle_dump, cycle_sources))

        missing_dump, missing_sources = nginx_fixture("8.4", handler_override="include /etc/nginx/snippets/missing.conf;\n")
        missing_dump = nginx_dump(missing_sources)
        bad_fixtures.append((missing_dump, missing_sources))

        variable_dump, variable_sources = nginx_fixture("8.4", handler_override="include $unsafe_path;\n")
        variable_dump = nginx_dump(variable_sources)
        bad_fixtures.append((variable_dump, variable_sources))

        for bad_dump, bad_sources in bad_fixtures:
            try:
                validate_nginx_fixture(bad_dump, bad_sources)
            except (ValueError, KeyError, OSError):
                pass
            else:
                raise AssertionError("unsafe Nginx include context or competing PHP route was accepted")

        unproven_wildcard_dump, unproven_wildcard_sources = nginx_fixture("8.4")
        def incomplete_wildcard(pattern, source_paths):
            if pattern == "/etc/nginx/sites-enabled/*":
                return ["/etc/nginx/sites-enabled/staging", "/etc/nginx/sites-enabled/unlisted"]
            return sorted(path for path in source_paths if fnmatch.fnmatchcase(path, pattern))
        try:
            validate_nginx_fixture(unproven_wildcard_dump, unproven_wildcard_sources, incomplete_wildcard)
        except (ValueError, KeyError, OSError):
            pass
        else:
            raise AssertionError("wildcard include with an unlisted filesystem match was accepted")

        mismatched_source_dump, mismatched_sources = nginx_fixture("8.4")
        try:
            module.validate_nginx_runtime_dump(
                mismatched_source_dump,
                source_reader=lambda path: mismatched_sources[path] + "# changed after nginx -T\n",
                include_resolver=lambda pattern, paths: sorted(path for path in paths if fnmatch.fnmatchcase(path, pattern)),
            )
        except ValueError:
            pass
        else:
            raise AssertionError("Nginx dump whose source differs from trusted file was accepted")

        current_sources = {"files": good_sources}
        module.active_php_profile = lambda: "8.4"
        module.validate_nginx_runtime_dump = lambda output: validate_nginx_fixture(output, current_sources["files"])

        def fake_nginx_run(argv, **kwargs):
            return subprocess.CompletedProcess(argv, 0, stdout=good_dump if argv[-1] == "-T" else "")

        module.run = fake_nginx_run
        module.nginx_runtime_contract("8.4")
    finally:
        module.runtime_snippet = original_snippet
        module.active_php_profile = original_active_profile
        module.validate_nginx_runtime_dump = original_nginx_validator
        module.run = original_run

    controller = CONTROLLER.read_text(encoding="utf-8")
    controller_lines = controller.splitlines()
    assert "readonly COMPOSER_BIN='/usr/local/bin/composer'" in controller
    assert "readonly COMPOSER_SHA256='dd158c0afd41d15fac89e1b4e47b5d42911fe316c3687cd5edac98319b25d028'" in controller
    assert "readonly COMPOSER_VERSION='2.11.2'" in controller
    assert '"$PHP_BIN" "$COMPOSER_BIN" "$@"' in controller
    assert 'composer_binary_preflight "$COMPOSER_BIN" "$COMPOSER_SHA256" "$COMPOSER_VERSION" 0 0 "$PHP_BIN"' in controller
    assert '/usr/bin/composer' not in controller, "Composer must not fall back to the obsolete path"

    bash = shutil.which("bash")
    if bash and os.name != "nt":
        with tempfile.TemporaryDirectory(prefix="composer-preflight-") as temporary:
            fixture_dir = Path(temporary)
            composer_fixture = fixture_dir / "composer"
            php_fixture = fixture_dir / "php"
            composer_fixture.write_text("#!/bin/sh\nexit 0\n", encoding="ascii")
            composer_fixture.chmod(0o755)
            php_fixture.write_text(
                "#!/bin/sh\nprintf '%s\\n' \"$FAKE_COMPOSER_VERSION\"\n",
                encoding="ascii",
            )
            php_fixture.chmod(0o755)
            composer_sha = hashlib.sha256(composer_fixture.read_bytes()).hexdigest()
            php_failure_fixture = fixture_dir / "php-failure"
            php_failure_fixture.write_text("#!/bin/sh\nexit 1\n", encoding="ascii")
            php_failure_fixture.chmod(0o755)

            def run_preflight(
                php_binary,
                *,
                expected_version="Composer version 2.11.2 2026-01-01",
                digest=composer_sha,
                uid=os.getuid(),
                gid=os.getgid(),
                path=composer_fixture,
            ):
                return subprocess.run(
                    [
                        bash,
                        "-c",
                        'source "$1"; composer_binary_preflight "$2" "$3" 2.11.2 "$4" "$5" "$6"',
                        "composer-preflight-negative-test",
                        str(CONTROLLER),
                        str(path),
                        digest,
                        str(uid),
                        str(gid),
                        str(php_binary),
                    ],
                    check=False,
                    env={**os.environ, "FAKE_COMPOSER_VERSION": expected_version},
                )

            preflight_harness = r'''source "$1"
path="$2"
digest="$3"
uid="$4"
gid="$5"
php_bin="$6"
composer_binary_preflight "$path" "$digest" 2.11.2 "$uid" "$gid" "$php_bin" || exit 31
if composer_binary_preflight "$path" "$(printf '%064d' 0)" 2.11.2 "$uid" "$gid" "$php_bin"; then exit 32; fi
if composer_binary_preflight "$path" "$digest" 2.11.2 "$((uid + 1))" "$gid" "$php_bin"; then exit 33; fi
if composer_binary_preflight "$path" "$digest" 2.11.2 "$uid" "$((gid + 1))" "$php_bin"; then exit 34; fi
'''
            subprocess.run(
                [
                    bash,
                    "-c",
                    preflight_harness,
                    "composer-preflight-test",
                    str(CONTROLLER),
                    str(composer_fixture),
                    composer_sha,
                    str(os.getuid()),
                    str(os.getgid()),
                    str(php_fixture),
                ],
                check=True,
                env={**os.environ, "FAKE_COMPOSER_VERSION": "Composer version 2.11.2 2026-01-01"},
            )
            wrong_version = run_preflight(php_fixture, expected_version="Composer version 2.11.20 2026-01-01")
            assert wrong_version.returncode != 0, "a similarly prefixed Composer version must be rejected"
            php_failed = run_preflight(php_failure_fixture)
            assert php_failed.returncode != 0, "a failed PHP invocation must fail the Composer preflight"
            bad_mode = subprocess.run(["/usr/bin/chmod", "0700", str(composer_fixture)], check=False)
            assert bad_mode.returncode == 0
            wrong_mode = run_preflight(php_fixture)
            assert wrong_mode.returncode != 0, "non-0755 Composer files must be rejected"
            composer_fixture.chmod(0o755)
            symlink_fixture = fixture_dir / "composer-link"
            symlink_fixture.symlink_to(composer_fixture)
            wrong_type = run_preflight(php_fixture, path=symlink_fixture)
            assert wrong_type.returncode != 0, "Composer symlinks must be rejected"
    gate_line = next(i for i, line in enumerate(controller_lines) if "privileged gate-on" in line and i > 700)
    switch_line = next(i for i, line in enumerate(controller_lines) if "privileged php-runtime-switch \"$PHP_TARGET_PROFILE\"" in line)
    disabled_line = next(i for i, line in enumerate(controller_lines) if "privileged worker-runtime-disable" in line and i > gate_line)
    workers_stop_line = next(i for i, line in enumerate(controller_lines) if "privileged worker stop-all" in line and i > disabled_line)
    assert gate_line < disabled_line < workers_stop_line < switch_line, "runtime switch must follow the active gate, persistent worker disable, and drained workers"
    assert 'workers_running || return 1\n    privileged php-runtime-preflight "$PHP_TARGET_PROFILE" || return 1\n    release_gate || return 1' in controller, "recovery must revalidate the active runtime listener before releasing traffic"
    assert 'workers_stopped || fail \'One or more SaaS workers are not stopped before traffic is reopened.\'\n    privileged php-runtime-preflight "$PHP_TARGET_PROFILE" || fail \'The selected PHP-FPM pool or live listener changed before traffic was reopened.\'\n    release_gate' in controller, "marketing-only deployment must verify workers remain stopped and PHP-FPM remains healthy before traffic is reopened"
    assert "readonly GATE_DIR='/var/lib/wpcaregrid'" in controller
    assert 'readonly WORKERS_DISABLED_FILE="${GATE_DIR}/workers-disabled"' in controller
    assert "readonly SCHEDULER_PAUSE_FILE=\"${GATE_DIR}/scheduler-paused\"" in controller
    assert 'root_marker_present "$WORKERS_DISABLED_FILE"' in controller
    assert 'root_marker_present "$SCHEDULER_PAUSE_FILE"' in controller
    assert 'target_profile="$(read_marketing_release_profile)"' in controller
    assert '[[ "$target_profile" == \'marketing_only\' ]]' in controller
    assert 'check_system_health allow-background-fenced' in controller
    assert 'if (( TARGET_MARKETING_RELEASE )); then' in controller
    assert "TARGET_MARKETING_RELEASE=1" in controller
    marketing_success_start = controller.index('target_profile="$(read_marketing_release_profile)"')
    marketing_success_end = controller.index('mark_gate_released', marketing_success_start)
    marketing_success = controller[marketing_success_start:marketing_success_end]
    forbidden_marketing_operations = (
        'php artisan queue:restart',
        'worker-runtime-enable',
        'php-runtime-supervisor-update',
        'worker start-all',
        'resume_scheduler',
        'wait_for_scheduler',
    )
    assert not any(operation in marketing_success for operation in forbidden_marketing_operations), "marketing-only success must keep background execution fenced"
    assert 'fence_marketing_background "$target_profile"' in marketing_success
    assert 'workers_stopped' in marketing_success
    assert 'workers_running' not in marketing_success
    assert 'pending jobs are retained' in controller
    assert 'queue:clear' not in controller and 'DELETE FROM jobs' not in controller
    target_failure_start = controller.index('if (( TARGET_MARKETING_RELEASE )); then')
    target_failure_end = controller.index('if (( BASELINE_BACKGROUND_RESTRICTED )); then', target_failure_start)
    target_failure = controller[target_failure_start:target_failure_end]
    assert 'worker-runtime-disable' in target_failure and 'stop_workers_for_rollback' in target_failure
    assert 'scheduler-pause' in target_failure and 'workers_stopped' in target_failure
    assert 'release_scheduler_lock_preserving_pause' in target_failure
    assert 'return 1' in target_failure
    forbidden_failure_recovery = ('queue:restart', 'worker-runtime-enable', 'worker start-all', 'resume_scheduler', 'release_gate')
    assert not any(operation in target_failure for operation in forbidden_failure_recovery), "failed marketing-only transition must stay gated and must not resume SaaS work"
    baseline_fenced_start = controller.index('if (( BASELINE_BACKGROUND_RESTRICTED )); then', target_failure_end)
    baseline_fenced_end = controller.index("log 'Guarded recovery restored the marketing-only baseline", baseline_fenced_start)
    baseline_fenced_recovery = controller[baseline_fenced_start:baseline_fenced_end]
    assert 'worker-runtime-disable' in baseline_fenced_recovery and 'scheduler-pause' in baseline_fenced_recovery
    assert 'workers_stopped' in baseline_fenced_recovery and 'allow-background-fenced' in baseline_fenced_recovery
    assert not any(operation in baseline_fenced_recovery for operation in forbidden_failure_recovery[:-1]), "marketing-only baseline recovery must preserve the background fence"
    migration_recheck = 'migration_status="$(php artisan migrate:status --no-interaction)" || return 1\n        grep -q \'Pending\' <<<"$migration_status" && return 1'
    assert migration_recheck in baseline_fenced_recovery
    assert baseline_fenced_recovery.index(migration_recheck) < baseline_fenced_recovery.index('php artisan up') < baseline_fenced_recovery.index('release_gate || return 1'), "pending or unreadable migration status must keep the marketing-only baseline gated"
    migration_status_lines = [line.strip() for line in controller.splitlines() if 'php artisan migrate:status --no-interaction' in line]
    assert len(migration_status_lines) == 4 and all(line.startswith('migration_status="$(php artisan migrate:status --no-interaction)" || ') for line in migration_status_lines), "every migration-status read must fail closed when Artisan exits unsuccessfully"
    assert "php artisan migrate:status --no-interaction | grep" not in controller, "migration-status command errors must not be conflated with no pending migrations"
    target_switch = controller.index('git checkout --detach "$TARGET_SHA"')
    target_flag = controller.index('TARGET_MARKETING_RELEASE=1')
    assert target_flag < target_switch, "failure recovery must know the target is marketing-only before any source switch"

    bash = shutil.which("bash")
    if bash and os.name != "nt":
        script_path = str(CONTROLLER)
        harness = r'''source "$1"
parse_marketing_release_profile 'profile: marketing_only' >/dev/null
if parse_marketing_release_profile 'profile: invitation_beta' >/dev/null; then exit 21; fi
if parse_marketing_release_profile '' >/dev/null; then exit 22; fi
calls=''
privileged() {
    local rendered
    printf -v rendered '%s ' "$@"
    calls+="privileged:${rendered% }|"
}
workers_stopped() { calls+='workers-stopped|'; }
release_scheduler_lock_preserving_pause() { calls+='release-lock-preserve-pause|'; }
root_marker_present() { calls+="marker:$1|"; }
fence_marketing_background marketing_only || exit 23
case "$calls" in
  *'privileged:worker-runtime-disable|'*'privileged:worker stop-all|'*'privileged:scheduler-pause|'*'marker:/var/lib/wpcaregrid/workers-disabled|'*'marker:/var/lib/wpcaregrid/scheduler-paused|'*'workers-stopped|'*'release-lock-preserve-pause|'*) ;;
  *) exit 24 ;;
esac
case "$calls" in *'queue:restart'*|*'worker-runtime-enable'*|*'worker start-all'*|*'scheduler-resume'*) exit 25 ;; esac
prior_calls="$calls"
if fence_marketing_background unknown >/dev/null 2>&1; then exit 26; fi
[[ "$calls" == "$prior_calls" ]] || exit 27
'''
        subprocess.run([bash, "-c", harness, "marketing-fence-policy-test", script_path], check=True)
    recovery_switch_line = next(i for i, line in enumerate(controller_lines) if "privileged php-runtime-switch \"$PHP_BASELINE_PROFILE\"" in line)
    baseline_checkout_line = next(i for i, line in enumerate(controller_lines) if "rollback_code_to_baseline || return 1" in line)
    assert recovery_switch_line < baseline_checkout_line, "runtime must return to baseline before code rollback"
    assert "composer check-platform-reqs --no-dev --lock" in controller
    assert "update-alternatives" not in controller
    assert 'if ! post_deploy_smoke || ! runtime_cache_access_ok || ! workers_stopped; then' in controller
    assert "privileged gate-on || log 'Could not confirm the Nginx gate marker" in controller
    launcher = (ROOT / "scripts/run-php-runtime.sh").read_text(encoding="utf-8")
    assert "queue:work) blocked /var/lib/wpcaregrid/workers-disabled" in launcher
    assert "schedule:run) blocked /var/lib/wpcaregrid/scheduler-paused" in launcher
    assert "/var/lib/wpcaregrid" in launcher.split("for directory in ", 1)[1].split("; do", 1)[0]
    assert '[[ -L "$PROFILE_LINK" && "$(stat -c \'%u\' "$PROFILE_LINK")" == \'0\' ]] || fail' in launcher
    assert "stat --no-dereference" not in launcher
    test_runtime_launcher_stat_symlink_compatibility(launcher)
    assert '[[ -f "$PHP_BIN" && -x "$PHP_BIN" && ! -L "$PHP_BIN" ]]' in launcher
    launcher_hash = hashlib.sha256((ROOT / "scripts/run-php-runtime.sh").read_bytes()).hexdigest()
    assert launcher_hash == module.PHP_RUNTIME_LAUNCHER_SHA256
    assert launcher.index("queue:work) blocked") < launcher.index('exec "$PHP_BIN"')

    controller = CONTROLLER.read_text(encoding="utf-8")
    fixture = DENIAL_FIXTURE.read_text(encoding="utf-8")
    wrapper_hash = hashlib.sha256((ROOT / "scripts/run-scheduler.sh").read_bytes()).hexdigest()
    launcher_hash = hashlib.sha256((ROOT / "scripts/run-php-runtime.sh").read_bytes()).hexdigest()
    assert wrapper_hash == module.SCHEDULER_RUNTIME_SOURCE_SHA256, "root helper scheduler hash must match the reviewed runtime wrapper"
    assert launcher_hash == module.PHP_RUNTIME_LAUNCHER_SHA256, "root helper launcher hash must match the reviewed runtime dispatcher"
    direct_privilege = [
        line.strip()
        for line in controller.splitlines()
        if "sudo -n" in line and "DEPLOY_PRIV_HELPER" not in line and "sudo -n -l" not in line
    ]
    assert not direct_privilege, f"generic sudo operation remains: {direct_privilege}"
    assert "sudo -n \"$DEPLOY_PRIV_HELPER\" \"$@\"" in controller
    assert 'sudo_listing="$(sudo -n -l 2>&1)"' in controller
    assert '[[ "$command_lines" == "(root) NOPASSWD: ${DEPLOY_PRIV_HELPER} *" ]]' in controller
    assert "sudo -n /usr/bin/id" in fixture
    assert "sudo -n /bin/sh" not in fixture and "rm -" not in fixture and "chmod " not in fixture
    assert "install.*DEPLOY_PRIV_HELPER" not in controller

    print("deploy privilege policy passed: fixed operation allowlist, path validation, no generic sudo calls")


def test_runtime_launcher_stat_symlink_compatibility(launcher):
    """GNU stat defaults to reporting the symlink inode without unsupported flags."""
    if os.name == "nt":
        return
    stat_bin = shutil.which("stat")
    if not stat_bin:
        raise AssertionError("Linux runtime launcher test requires GNU stat")
    with tempfile.TemporaryDirectory(prefix="wpcg-runtime-stat-") as directory:
        target = Path(directory) / "profile.conf"
        link = Path(directory) / "runtime.conf"
        target.write_text("synthetic profile\n", encoding="ascii")
        link.symlink_to(target)
        if hasattr(os, "geteuid") and os.geteuid() == 0:
            try:
                import pwd
                other_uid = pwd.getpwnam("nobody").pw_uid
                if other_uid != os.lstat(link).st_uid:
                    os.chown(target, other_uid, -1)
            except (ImportError, KeyError, PermissionError):
                pass
        result = subprocess.run(
            [stat_bin, "-c", "%u", str(link)],
            check=False, capture_output=True, text=True,
        )
        if result.returncode != 0:
            raise AssertionError("GNU stat rejected the launcher-compatible symlink-owner query")
        expected_link_owner = str(os.lstat(link).st_uid)
        assert result.stdout.strip() == expected_link_owner, "stat must report the symlink inode owner"
        assert "stat --no-dereference" not in launcher


if __name__ == "__main__":
    main()
