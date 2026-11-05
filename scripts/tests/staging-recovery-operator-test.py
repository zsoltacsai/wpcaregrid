#!/usr/bin/env python3
"""Synthetic policy tests for the one-time restricted staging recovery operator."""

import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
from types import SimpleNamespace
from contextlib import redirect_stdout
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
PATH = ROOT / 'scripts/staging-recovery-operator.py'
SPEC = importlib.util.spec_from_file_location('staging_recovery_operator', PATH)
OP = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(OP)


def test_fixed_phase_allowlist():
    output = io.StringIO()
    with patch.object(OP.os, 'geteuid', return_value=0, create=True), patch.dict(os.environ, {'SUDO_USER': 'wpcaregrid-recovery'}):
        with redirect_stdout(output):
            result = OP.main(['--application-sha', 'a' * 40])
    assert result == 64
    assert json.loads(output.getvalue()) == {'status': 'STOP', 'code': 'operator_command_invalid'}
    assert set(OP.PHASES) == {'preflight', 'migrate', 'finalize'}
    assert OP.PHASES['preflight'] == ('recovery-readiness', False)
    assert OP.PHASES['migrate'] == ('continue-migrations', True)
    assert OP.PHASES['finalize'] == ('finalize', True)


def test_operator_identity_fail_closed():
    output = io.StringIO()
    with patch.object(OP.os, 'geteuid', return_value=1001, create=True), patch.dict(os.environ, {'SUDO_USER': 'wpcaregrid-recovery'}):
        with redirect_stdout(output):
            result = OP.main(['preflight'])
    assert result == 77
    assert json.loads(output.getvalue()) == {'status': 'STOP', 'code': 'operator_identity_invalid'}


def test_readiness_projection_is_sanitized_and_pinned():
    payload = {
        'RECOVERY_READINESS': 'BLOCKED', 'APPLICATION_SHA': OP.APP_SHA,
        'RECOVERY_TOOL_SHA': 'b' * 40, 'APPLICATION_CI_RUN_ID': 123,
        'RECOVERY_TOOL_CI_RUN_ID': 456,
        'CHECKS': {'runtime': {'status': 'BLOCKED', 'code': 'php84_preflight_failed',
                               'evidence': {'secret': 'must not pass'}},
                   'isolated_restore_receipt': {'status': 'BLOCKED', 'code': 'restore_receipt_unavailable'}},
        'BLOCKERS': ['php84_preflight_failed', 'restore_receipt_unavailable'],
        'STAGING_GATE': 'CLOSED', 'WORKERS': 'UNVERIFIED',
        'SCHEDULER': 'UNVERIFIED', 'MUTATIONS': 'NONE',
    }
    result = OP.project_readiness(payload, {'application_sha': OP.APP_SHA, 'recovery_tool_sha': 'b' * 40})
    assert result['status'] == 'BLOCKED' and result['gate'] == 'CLOSED'
    assert result['checks']['runtime'] == {'status': 'BLOCKED', 'code': 'php84_preflight_failed'}
    assert 'evidence' not in result['checks']['runtime']
    for mutate in (
        lambda row: row.update(APPLICATION_SHA='c' * 40),
        lambda row: row.update(MUTATIONS='APPLIED'),
        lambda row: row.update(BLOCKERS=['raw path /etc/shadow']),
        lambda row: row.update(CHECKS={'unknown': {'status': 'PASS'}}),
        lambda row: row.update(WORKERS='RUNNING'),
        lambda row: row.update(SCHEDULER='ACTIVE'),
        lambda row: (row.update(RECOVERY_READINESS='READY_FOR_MIGRATIONS'),
                     row.update(BLOCKERS=['blocked']),
                     row['CHECKS'].update(runtime={'status': 'BLOCKED'})),
        lambda row: row.update(RECOVERY_READINESS='READY_FOR_FINALIZE'),
    ):
        altered = dict(payload)
        mutate(altered)
        try:
            OP.project_readiness(altered, {'application_sha': OP.APP_SHA, 'recovery_tool_sha': 'b' * 40})
        except OP.Refusal as error:
            assert str(error) == 'operator_controller_evidence_invalid'
        else:
            raise AssertionError('invalid readiness projection was accepted')
    ready = dict(payload)
    required = {
        'recovery_inputs', 'worker_runtime', 'worker_gate_marker', 'worker_worker_marker',
        'worker_scheduler_marker', 'worker_nginx_config', 'worker_http_gate',
        'worker_supervisor_contract', 'worker_scheduler_contract',
        'worker_worker_wpcaregrid_backups', 'worker_worker_wpcaregrid_default',
        'worker_process_census', 'protected_backup', 'pre_recovery_backup',
        'isolated_restore_receipt', 'runtime', 'nginx', 'composer',
        'database_schema', 'dba_attestation', 'config_cache',
    }
    ready.update(RECOVERY_READINESS='READY_FOR_MIGRATIONS', BLOCKERS=[],
                 WORKERS='FATAL_INERT', SCHEDULER='PAUSED',
                 CHECKS={name: {'status': 'PASS'} for name in required})
    ready['CHECKS']['config_cache'] = {'status': 'REBUILD_DURING_FINALIZE'}
    assert OP.project_readiness(ready, {'application_sha': OP.APP_SHA,
                                        'recovery_tool_sha': 'b' * 40})['status'] == 'READY_FOR_MIGRATIONS'
    wrong_runtime_state = dict(ready, WORKERS='UNVERIFIED')
    try:
        OP.project_readiness(wrong_runtime_state, {'application_sha': OP.APP_SHA,
                                                   'recovery_tool_sha': 'b' * 40})
    except OP.Refusal as error:
        assert str(error) == 'operator_controller_evidence_invalid'
    else:
        raise AssertionError('READY preflight with unverified workers was projected')
    missing_gate = dict(ready)
    missing_gate['CHECKS'] = dict(ready['CHECKS'])
    missing_gate['CHECKS'].pop('pre_recovery_backup')
    try:
        OP.project_readiness(missing_gate, {'application_sha': OP.APP_SHA,
                                            'recovery_tool_sha': 'b' * 40})
    except OP.Refusal as error:
        assert str(error) == 'operator_controller_evidence_invalid'
    else:
        raise AssertionError('readiness omitted a required release gate')


def test_migration_and_finalize_receipt_projection():
    auth = {'application_sha': OP.APP_SHA, 'recovery_tool_sha': 'b' * 40,
            'backup_sha256': 'd' * 64, 'pre_recovery_backup_sha256': 'a' * 64,
            'restore_receipt_sha256': 'f' * 64}
    migration = {
        'MIGRATIONS': 'PASS', 'TRIGGERS': 6, 'GATE': 'CLOSED',
        'APPLICATION_SHA': OP.APP_SHA, 'RECOVERY_TOOL_SHA': 'b' * 40,
        'APPLICATION_CI_RUN_ID': 1, 'RECOVERY_TOOL_CI_RUN_ID': 2,
        'BACKUP_SHA256': 'd' * 64, 'DBA_ATTESTATION_SHA256': 'e' * 64,
        'PRE_RECOVERY_BACKUP_SHA256': 'a' * 64,
        'RESTORE_RECEIPT_SHA256': 'f' * 64,
    }
    assert OP.project_success(migration, auth, 'migrate')['status'] == 'MIGRATIONS_PASS'
    for key, value in (('RESTORE_RECEIPT_SHA256', '0' * 64),
                       ('DBA_ATTESTATION_SHA256', 'not-a-hash'),
                       ('APPLICATION_CI_RUN_ID', 0),
                       ('APPLICATION_SHA', 'c' * 40),
                       ('RECOVERY_TOOL_SHA', 'c' * 40),
                       ('PRE_RECOVERY_BACKUP_SHA256', '0' * 64)):
        altered = dict(migration)
        altered[key] = value
        try:
            OP.project_success(altered, auth, 'migrate')
        except OP.Refusal as error:
            assert str(error) == 'operator_controller_evidence_invalid'
        else:
            raise AssertionError(f'invalid migration evidence accepted: {key}')
    receipt = 'gated-recovery-20261105T123456Z-' + OP.APP_SHA + '.json'
    finalized = {'RECOVERY': 'VERIFIED_GATE_CLOSED', 'RECEIPT': receipt,
                 'PUBLIC_HTTP_ACCEPTANCE': 'PENDING_SEPARATE_OWNER_GATE_RELEASE'}
    assert OP.project_success(finalized, auth, 'finalize')['receipt'] == receipt
    finalized['PUBLIC_HTTP_ACCEPTANCE'] = 'OPEN'
    try:
        OP.project_success(finalized, auth, 'finalize')
    except OP.Refusal as error:
        assert str(error) == 'operator_controller_evidence_invalid'
    else:
        raise AssertionError('gate-release evidence was accepted')


def test_single_use_phase_state():
    if os.name == 'nt':
        return
    with tempfile.TemporaryDirectory(prefix='wpcg-operator-state-') as directory:
        path = Path(directory)
        path.chmod(0o700)
        saved = OP.USED
        OP.USED = path
        auth = {'nonce': 'a' * 32}
        real_lstat = Path.lstat

        def trusted_root_directory(candidate, *args, **kwargs):
            if candidate == path:
                return SimpleNamespace(st_mode=stat.S_IFDIR | 0o700, st_uid=0, st_gid=0)
            return real_lstat(candidate, *args, **kwargs)

        try:
            with patch.object(OP, 'verify_root_controlled_ancestry'), \
                    patch.object(Path, 'lstat', trusted_root_directory), \
                    patch.object(OP.os, 'fchown') as fchown:
                OP.consume_phase(auth, 'migrate')
                assert fchown.call_count == 1 and fchown.call_args.args[1:] == (0, 0)
                try:
                    OP.consume_phase(auth, 'migrate')
                except OP.Refusal as error:
                    assert str(error) == 'operator_phase_already_used'
                else:
                    raise AssertionError('phase replay was accepted')
            assert len(list(path.iterdir())) == 1
            info = next(path.iterdir()).stat(follow_symlinks=False)
            assert stat.S_ISREG(info.st_mode) and stat.S_IMODE(info.st_mode) == 0o600
        finally:
            OP.USED = saved


def test_bounded_pipe_capture():
    if os.name == 'nt':
        return
    child = subprocess.Popen([sys.executable, '-c', 'print("ok")'], stdout=subprocess.PIPE,
                             stderr=subprocess.PIPE, start_new_session=True)
    stdout, stderr = OP.read_bounded(child, __import__('time').monotonic() + 5)
    assert child.wait() == 0 and stdout == b'ok\n' and stderr == b''
    child = subprocess.Popen([sys.executable, '-c', 'print("x" * 70000)'], stdout=subprocess.PIPE,
                             stderr=subprocess.PIPE, start_new_session=True)
    try:
        OP.read_bounded(child, __import__('time').monotonic() + 5)
    except ValueError as error:
        assert str(error) == 'runner output exceeded limit'
        child.wait()
    else:
        raise AssertionError('oversized output was accepted')


def test_timeout_kills_forked_descendant_group():
    if os.name == 'nt':
        return
    import time
    OP.enable_child_subreaper()
    program = ('import subprocess,sys; '
               'subprocess.Popen([sys.executable,"-c","import time; time.sleep(30)"]); '
               'print("parent-exited",flush=True)')
    child = subprocess.Popen([sys.executable, '-c', program], stdout=subprocess.PIPE,
                             stderr=subprocess.PIPE, start_new_session=True)
    try:
        try:
            OP.read_bounded(child, time.monotonic() + 0.3)
        except TimeoutError:
            OP.kill_and_reap(child)
        else:
            raise AssertionError('inherited grandchild pipe did not trigger the execution deadline')
        try:
            os.killpg(child.pid, 0)
        except ProcessLookupError:
            pass
        else:
            raise AssertionError('a descendant remained in the timed-out recovery process group')
    finally:
        OP.kill_and_reap(child)


def test_timeout_kills_detached_descendant():
    if os.name == 'nt':
        return
    import time
    OP.enable_child_subreaper()
    program = ('import subprocess,sys; '
               'subprocess.Popen([sys.executable,"-c",'
               '"import os,time; os.setsid(); time.sleep(30)"]); '
               'print("detached-child-started",flush=True)')
    child = subprocess.Popen([sys.executable, '-c', program], stdout=subprocess.PIPE,
                             stderr=subprocess.PIPE, start_new_session=True)
    try:
        try:
            OP.read_bounded(child, time.monotonic() + 0.3)
        except TimeoutError:
            OP.kill_and_reap(child)
        else:
            raise AssertionError('detached grandchild did not hold the bounded pipe open')
        assert OP.adopted_children() == []
    finally:
        OP.kill_and_reap(child)


def main():
    for test in (test_fixed_phase_allowlist, test_operator_identity_fail_closed,
                 test_readiness_projection_is_sanitized_and_pinned,
                 test_migration_and_finalize_receipt_projection,
                 test_single_use_phase_state, test_bounded_pipe_capture,
                 test_timeout_kills_forked_descendant_group,
                 test_timeout_kills_detached_descendant):
        test()
    print('STAGING_RECOVERY_OPERATOR_TESTS=PASS;COUNT=8')


if __name__ == '__main__':
    main()
