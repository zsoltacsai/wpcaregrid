#!/usr/bin/env python3
"""Root-owned, phase-allowlisted SSH recovery operator for one staging release."""

try:
    import fcntl
except ImportError:  # Allows non-Linux source tests; execution itself is Linux-only.
    fcntl = None
import hashlib
import ctypes
import errno
import json
import os
from pathlib import Path
try:
    import pwd
except ImportError:  # Source tests may run on Windows; the operator is Linux-only.
    pwd = None
import re
import signal
import stat
import subprocess
import sys
import time
from datetime import datetime, timezone

APP = Path('/var/www/wpcaregrid')
APP_SHA = 'ee3d29818ff1647d82087446dfaf017ca688cb77'
CONFIG = Path('/etc/wpcaregrid/staging-recovery-operator.json')
BUNDLE_BASE = Path('/var/lib/wpcaregrid/staging-recovery-bundles')
USED = Path('/var/lib/wpcaregrid/staging-recovery-operator-used')
LOCK = Path('/run/lock/wpcaregrid-staging-recovery-operator.lock')
HELPER = Path('/usr/local/sbin/wpcaregrid-deploy-priv')
RESTORE_RECEIPT = Path('/var/lib/wpcaregrid/staging-db-recovery-restore-receipt.json')
MAX_CONFIG = 8192
MAX_STDOUT = 65536
MAX_STDERR = 4096
TIMEOUT = 1200
PHASES = {
    'preflight': ('recovery-readiness', False),
    'migrate': ('continue-migrations', True),
    'finalize': ('finalize', True),
}
CONFIG_KEYS = {
    'schema', 'application_sha', 'recovery_tool_sha', 'bundle_path',
    'controller_sha256', 'verifier_sha256', 'helper_sha256', 'backup_sha256',
    'pre_recovery_backup_sha256', 'mysql_uuid_sha256', 'restore_receipt_sha256',
    'expires_at_utc', 'nonce',
}
READINESS_CHECKS = {
    'recovery_inputs', 'worker_runtime', 'worker_gate_marker', 'worker_worker_marker',
    'worker_scheduler_marker', 'worker_nginx_config', 'worker_http_gate',
    'worker_supervisor_contract', 'worker_scheduler_contract',
    'worker_worker_wpcaregrid_backups', 'worker_worker_wpcaregrid_default',
    'worker_process_census', 'protected_backup', 'pre_recovery_backup', 'isolated_restore_receipt',
    'runtime', 'nginx', 'composer', 'database_schema', 'dba_attestation', 'config_cache',
}


class Refusal(Exception):
    pass


def refuse(code):
    raise Refusal(code)


def fixed_file(path, *, owner, group, mode, max_size):
    try:
        info = path.lstat()
    except OSError:
        refuse('operator_artifact_unavailable')
    if (not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode)
            or info.st_uid != owner or info.st_gid != group
            or stat.S_IMODE(info.st_mode) != mode or info.st_size > max_size
            or info.st_nlink != 1):
        refuse('operator_artifact_invalid')
    return info


def sha256(path):
    h = hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda: stream.read(65536), b''):
            h.update(block)
    return h.hexdigest()


def verify_root_controlled_ancestry(path):
    current = path
    chain = []
    while True:
        chain.append(current)
        if current.parent == current:
            break
        current = current.parent
    for item in reversed(chain):
        try:
            info = item.lstat()
        except OSError:
            refuse('operator_artifact_ancestry_unavailable')
        if (not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode)
                or info.st_uid != 0 or info.st_mode & (stat.S_IWGRP | stat.S_IWOTH)):
            refuse('operator_artifact_ancestry_untrusted')


def open_restore_receipt(auth):
    try:
        verify_root_controlled_ancestry(RESTORE_RECEIPT.parent)
        fd = os.open(RESTORE_RECEIPT, os.O_RDONLY | getattr(os, 'O_NOFOLLOW', 0)
                     | getattr(os, 'O_CLOEXEC', 0))
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_gid != 0
                or stat.S_IMODE(info.st_mode) != 0o600 or info.st_nlink != 1
                or info.st_size > 8192):
            os.close(fd)
            refuse('restore_receipt_metadata_invalid')
        digest = hashlib.sha256()
        total = 0
        while True:
            block = os.read(fd, min(4096, 8193 - total))
            if not block:
                break
            digest.update(block)
            total += len(block)
            if total > 8192:
                os.close(fd)
                refuse('restore_receipt_metadata_invalid')
        if total != info.st_size or digest.hexdigest() != auth['restore_receipt_sha256']:
            os.close(fd)
            refuse('restore_receipt_identity_mismatch')
        os.lseek(fd, 0, os.SEEK_SET)
        return fd
    except Refusal:
        raise
    except OSError:
        refuse('restore_receipt_unavailable')


def load_authorization():
    verify_root_controlled_ancestry(CONFIG.parent)
    fixed_file(CONFIG, owner=0, group=0, mode=0o600, max_size=MAX_CONFIG)
    try:
        value = json.loads(CONFIG.read_text(encoding='utf-8'))
    except Exception:
        refuse('operator_authorization_invalid')
    if not isinstance(value, dict) or set(value) != CONFIG_KEYS:
        refuse('operator_authorization_invalid')
    if (value.get('schema') != 1 or value.get('application_sha') != APP_SHA
            or not re.fullmatch(r'[0-9a-f]{40}', str(value.get('recovery_tool_sha', '')))
            or value.get('bundle_path') != str(BUNDLE_BASE / value.get('recovery_tool_sha', ''))
            or any(not re.fullmatch(r'[0-9a-f]{64}', str(value.get(key, '')))
                   for key in ('controller_sha256', 'verifier_sha256', 'helper_sha256',
                               'backup_sha256', 'pre_recovery_backup_sha256',
                               'mysql_uuid_sha256', 'restore_receipt_sha256'))
            or not re.fullmatch(r'[0-9a-f]{32}', str(value.get('nonce', '')))):
        refuse('operator_authorization_invalid')
    try:
        expiry = datetime.strptime(value['expires_at_utc'], '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=timezone.utc)
    except (TypeError, ValueError):
        refuse('operator_authorization_invalid')
    now = datetime.now(timezone.utc)
    if expiry <= now or (expiry - now).total_seconds() > 86400:
        refuse('operator_authorization_expired_or_unbounded')
    return value


def verify_bundle(auth):
    if pwd is None:
        refuse('operator_platform_unsupported')
    bundle = Path(auth['bundle_path'])
    verify_root_controlled_ancestry(bundle.parent)
    try:
        info = bundle.lstat()
    except OSError:
        refuse('operator_bundle_unavailable')
    deploy = pwd.getpwnam('deploy')
    if (not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode)
            or info.st_uid != 0 or info.st_gid != deploy.pw_gid
            or stat.S_IMODE(info.st_mode) != 0o750):
        refuse('operator_bundle_invalid')
    controller = bundle / 'recover-staging-deployment.py'
    verifier = bundle / 'verify-release-ci.py'
    fixed_file(controller, owner=0, group=deploy.pw_gid, mode=0o440, max_size=262144)
    fixed_file(verifier, owner=0, group=deploy.pw_gid, mode=0o440, max_size=131072)
    if sha256(controller) != auth['controller_sha256'] or sha256(verifier) != auth['verifier_sha256']:
        refuse('operator_artifact_hash_mismatch')
    verify_root_controlled_ancestry(HELPER.parent)
    helper_info = fixed_file(HELPER, owner=0, group=0, mode=0o755, max_size=1048576)
    if helper_info.st_size <= 0 or sha256(HELPER) != auth['helper_sha256']:
        refuse('operator_helper_hash_mismatch')
    source = controller.read_text(encoding='utf-8')
    pins = {
        'APP_SHA': auth['application_sha'],
        'HELPER_SHA': auth['helper_sha256'],
        'BACKUP_SHA': auth['backup_sha256'],
        'MYSQL_UUID_SHA': auth['mysql_uuid_sha256'],
    }
    for name, digest in pins.items():
        if not re.search(rf"(?m)^{name}\s*=\s*['\"]{re.escape(digest)}['\"]\s*$", source):
            refuse('operator_provenance_mismatch')
    return controller


def acquire_lock():
    if fcntl is None:
        refuse('operator_platform_unsupported')
    verify_root_controlled_ancestry(LOCK.parent)
    flags = os.O_RDWR | os.O_CREAT | getattr(os, 'O_NOFOLLOW', 0) | getattr(os, 'O_CLOEXEC', 0)
    try:
        fd = os.open(LOCK, flags, 0o600)
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_gid != 0
                or stat.S_IMODE(info.st_mode) != 0o600 or info.st_nlink != 1):
            os.close(fd)
            refuse('operator_lock_invalid')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            os.close(fd)
            refuse('operator_busy')
        return fd
    except Refusal:
        raise
    except OSError:
        refuse('operator_lock_unavailable')


def consume_phase(auth, name):
    verify_root_controlled_ancestry(USED.parent)
    try:
        directory = USED.lstat()
    except OSError:
        refuse('operator_state_unavailable')
    if (not stat.S_ISDIR(directory.st_mode) or stat.S_ISLNK(directory.st_mode)
            or directory.st_uid != 0 or directory.st_gid != 0
            or stat.S_IMODE(directory.st_mode) != 0o700):
        refuse('operator_state_invalid')
    token = hashlib.sha256((auth['nonce'] + ':' + name).encode('ascii')).hexdigest()
    path = USED / (token + '.used')
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, 'O_NOFOLLOW', 0)
    fd = None
    try:
        fd = os.open(path, flags, 0o600)
        os.fchown(fd, 0, 0)
        os.write(fd, (name + '\n').encode('ascii'))
        os.fsync(fd)
    except FileExistsError:
        refuse('operator_phase_already_used')
    except OSError:
        refuse('operator_phase_authorization_failed')
    finally:
        if fd is not None:
            try:
                os.close(fd)
            except OSError:
                refuse('operator_phase_authorization_failed')
    try:
        directory_fd = os.open(USED, os.O_RDONLY | getattr(os, 'O_DIRECTORY', 0)
                               | getattr(os, 'O_NOFOLLOW', 0))
        try:
            os.fsync(directory_fd)
        finally:
            os.close(directory_fd)
    except OSError:
        refuse('operator_phase_authorization_failed')


def read_bounded(process, deadline):
    import selectors
    selector = selectors.DefaultSelector()
    buffers = {process.stdout: bytearray(), process.stderr: bytearray()}
    limits = {process.stdout: MAX_STDOUT, process.stderr: MAX_STDERR}
    for stream in buffers:
        os.set_blocking(stream.fileno(), False)
        selector.register(stream, selectors.EVENT_READ)
    try:
        while selector.get_map():
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                kill_process_group(process)
                raise TimeoutError
            for key, _ in selector.select(min(remaining, 1.0)):
                chunk = os.read(key.fd, 4096)
                if not chunk:
                    selector.unregister(key.fileobj)
                    continue
                target = buffers[key.fileobj]
                target.extend(chunk)
                if len(target) > limits[key.fileobj]:
                    kill_process_group(process)
                    raise ValueError('runner output exceeded limit')
        return bytes(buffers[process.stdout]), bytes(buffers[process.stderr])
    finally:
        selector.close()


def kill_process_group(process):
    if process is None:
        return
    try:
        pgid = os.getpgid(process.pid)
    except ProcessLookupError:
        return
    if pgid != process.pid:
        try:
            process.kill()
        except OSError:
            pass
        return
    try:
        os.killpg(pgid, signal.SIGKILL)
    except ProcessLookupError:
        pass
    except OSError:
        if process.poll() is None:
            try:
                process.kill()
            except OSError:
                pass


def enable_child_subreaper():
    if not sys.platform.startswith('linux'):
        refuse('operator_process_containment_unavailable')
    try:
        libc = ctypes.CDLL(None, use_errno=True)
        if libc.prctl(36, 1, 0, 0, 0) != 0:  # PR_SET_CHILD_SUBREAPER
            refuse('operator_process_containment_unavailable')
    except (AttributeError, OSError):
        refuse('operator_process_containment_unavailable')


def adopted_children():
    path = Path(f'/proc/{os.getpid()}/task/{os.getpid()}/children')
    try:
        value = path.read_text(encoding='ascii').strip()
        children = [int(item) for item in value.split()] if value else []
    except (OSError, ValueError):
        refuse('operator_child_process_cleanup_unverified')
    if any(pid <= 1 for pid in children):
        refuse('operator_child_process_cleanup_unverified')
    return children


def reap_adopted_children():
    deadline = time.monotonic() + 5
    while True:
        children = adopted_children()
        if not children:
            return
        for pid in children:
            try:
                os.kill(pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            except OSError:
                refuse('operator_child_process_cleanup_unverified')
        for pid in children:
            try:
                os.waitpid(pid, os.WNOHANG)
            except ChildProcessError:
                pass
            except OSError as error:
                if error.errno != errno.EINTR:
                    refuse('operator_child_process_cleanup_unverified')
        if time.monotonic() >= deadline:
            refuse('operator_child_process_cleanup_unverified')
        time.sleep(0.05)


def kill_and_reap(process):
    if process is None:
        return
    kill_process_group(process)
    try:
        process.wait(timeout=5)
    except subprocess.TimeoutExpired:
        kill_process_group(process)
        process.wait()
    reap_adopted_children()


def controller_result(controller, auth, phase, restore_receipt_fd):
    internal_phase, one_time = PHASES[phase]
    if one_time:
        consume_phase(auth, phase)
    argv = [
        '/usr/sbin/runuser', '-u', 'deploy', '--', '/usr/bin/python3', '-I', '-S',
        str(controller), internal_phase, '--application-sha', auth['application_sha'],
        '--recovery-tool-sha', auth['recovery_tool_sha'],
        '--restore-receipt-fd', str(restore_receipt_fd),
        '--restore-receipt-sha256', auth['restore_receipt_sha256'],
        '--pre-recovery-backup-sha256', auth['pre_recovery_backup_sha256'],
    ]
    env = {
        'HOME': '/home/deploy', 'USER': 'deploy', 'LOGNAME': 'deploy',
        'PATH': '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8',
    }
    process = None
    deadline = time.monotonic() + TIMEOUT
    try:
        enable_child_subreaper()
        process = subprocess.Popen(argv, cwd=APP, env=env, stdin=subprocess.DEVNULL,
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE, close_fds=True,
                                   pass_fds=(restore_receipt_fd,), start_new_session=True)
        stdout, stderr = read_bounded(process, deadline)
        code = process.wait(timeout=max(0.1, deadline - time.monotonic()))
        children = adopted_children()
        if children:
            reap_adopted_children()
            refuse('operator_child_process_leaked')
    except (TimeoutError, subprocess.TimeoutExpired):
        kill_and_reap(process)
        refuse('operator_execution_timeout')
    except Refusal:
        kill_and_reap(process)
        raise
    except Exception:
        kill_and_reap(process)
        refuse('operator_execution_failed')
    try:
        payload = json.loads(stdout.decode('utf-8')) if stdout else None
    except Exception:
        payload = None
    if phase == 'preflight' and code in (0, 2) and isinstance(payload, dict):
        return project_readiness(payload, auth), code
    if code == 0 and isinstance(payload, dict):
        return project_success(payload, auth, phase), 0
    match = re.fullmatch(rb'RECOVERY=STOP;CODE=([a-z0-9_]+)\n?', stderr)
    if code != 0 and match:
        return {'status': 'STOP', 'phase': phase, 'code': match.group(1).decode('ascii')}, code
    refuse('operator_controller_evidence_invalid')


def project_readiness(payload, auth):
    if (set(payload) != {'RECOVERY_READINESS', 'APPLICATION_SHA', 'RECOVERY_TOOL_SHA',
                         'APPLICATION_CI_RUN_ID', 'RECOVERY_TOOL_CI_RUN_ID', 'CHECKS',
                         'BLOCKERS', 'STAGING_GATE', 'WORKERS', 'SCHEDULER', 'MUTATIONS'}
            or payload.get('APPLICATION_SHA') != auth['application_sha']
            or payload.get('RECOVERY_TOOL_SHA') != auth['recovery_tool_sha']
            or payload.get('RECOVERY_READINESS') not in {'BLOCKED', 'READY_FOR_MIGRATIONS', 'READY_FOR_FINALIZE'}
            or payload.get('STAGING_GATE') != 'CLOSED' or payload.get('MUTATIONS') != 'NONE'
            or payload.get('WORKERS') not in {'FATAL_INERT', 'UNVERIFIED'}
            or payload.get('SCHEDULER') not in {'PAUSED', 'UNVERIFIED'}
            or not isinstance(payload.get('CHECKS'), dict)
            or not isinstance(payload.get('BLOCKERS'), list)):
        refuse('operator_controller_evidence_invalid')
    checks = {}
    for name, result in payload['CHECKS'].items():
        if (name not in READINESS_CHECKS or not isinstance(result, dict)
                or result.get('status') not in {'PASS', 'BLOCKED', 'NOT_EVALUATED', 'REBUILD_DURING_FINALIZE'}):
            refuse('operator_controller_evidence_invalid')
        record = {'status': result['status']}
        if 'code' in result:
            if not isinstance(result['code'], str) or not re.fullmatch(r'[a-z0-9_]{1,80}', result['code']):
                refuse('operator_controller_evidence_invalid')
            record['code'] = result['code']
        checks[name] = record
    if any(not isinstance(item, str) or not re.fullmatch(r'[a-z0-9_]{1,80}', item)
           for item in payload['BLOCKERS']):
        refuse('operator_controller_evidence_invalid')
    for key in ('APPLICATION_CI_RUN_ID', 'RECOVERY_TOOL_CI_RUN_ID'):
        if type(payload.get(key)) is not int or payload[key] <= 0:
            refuse('operator_controller_evidence_invalid')
    statuses = [item['status'] for item in checks.values()]
    if payload['RECOVERY_READINESS'] == 'BLOCKED':
        if not payload['BLOCKERS'] or 'BLOCKED' not in statuses:
            refuse('operator_controller_evidence_invalid')
    elif payload['BLOCKERS'] or any(status in {'BLOCKED', 'NOT_EVALUATED'} for status in statuses):
        refuse('operator_controller_evidence_invalid')
    else:
        if payload['WORKERS'] != 'FATAL_INERT' or payload['SCHEDULER'] != 'PAUSED':
            refuse('operator_controller_evidence_invalid')
        required = {
            'recovery_inputs', 'worker_runtime', 'worker_gate_marker', 'worker_worker_marker',
            'worker_scheduler_marker', 'worker_nginx_config', 'worker_http_gate',
            'worker_supervisor_contract', 'worker_scheduler_contract',
            'worker_worker_wpcaregrid_backups', 'worker_worker_wpcaregrid_default',
            'worker_process_census', 'protected_backup', 'pre_recovery_backup',
            'isolated_restore_receipt', 'runtime', 'nginx', 'composer',
            'database_schema', 'dba_attestation', 'config_cache',
        }
        if not required.issubset(checks) or any(
                checks[name]['status'] != 'PASS' for name in required - {'config_cache'}):
            refuse('operator_controller_evidence_invalid')
        if checks['config_cache']['status'] not in {'PASS', 'REBUILD_DURING_FINALIZE'}:
            refuse('operator_controller_evidence_invalid')
    return {
        'status': payload['RECOVERY_READINESS'], 'application_sha': auth['application_sha'],
        'recovery_tool_sha': auth['recovery_tool_sha'], 'checks': checks,
        'blockers': sorted(set(payload['BLOCKERS'])), 'gate': 'CLOSED',
        'workers': payload['WORKERS'], 'scheduler': payload['SCHEDULER'],
        'mutations': 'NONE',
    }


def project_success(payload, auth, phase):
    common = {'APPLICATION_SHA': auth['application_sha'], 'RECOVERY_TOOL_SHA': auth['recovery_tool_sha']}
    if phase == 'migrate':
        allowed = {'MIGRATIONS', 'TRIGGERS', 'GATE', 'APPLICATION_SHA', 'RECOVERY_TOOL_SHA',
                   'APPLICATION_CI_RUN_ID', 'RECOVERY_TOOL_CI_RUN_ID', 'BACKUP_SHA256',
                   'PRE_RECOVERY_BACKUP_SHA256', 'DBA_ATTESTATION_SHA256', 'RESTORE_RECEIPT_SHA256'}
        if (set(payload) != allowed or payload.get('APPLICATION_SHA') != auth['application_sha']
                or payload.get('RECOVERY_TOOL_SHA') != auth['recovery_tool_sha']
                or payload.get('MIGRATIONS') != 'PASS' or payload.get('TRIGGERS') != 6
                or payload.get('GATE') != 'CLOSED' or payload.get('BACKUP_SHA256') != auth['backup_sha256']
                or payload.get('PRE_RECOVERY_BACKUP_SHA256') != auth['pre_recovery_backup_sha256']
                or not all(isinstance(payload.get(key), str) and re.fullmatch(r'[0-9a-f]{64}', payload[key])
                           for key in ('DBA_ATTESTATION_SHA256', 'RESTORE_RECEIPT_SHA256'))
                or payload['RESTORE_RECEIPT_SHA256'] != auth['restore_receipt_sha256']):
            refuse('operator_controller_evidence_invalid')
        for key in ('APPLICATION_CI_RUN_ID', 'RECOVERY_TOOL_CI_RUN_ID'):
            if type(payload.get(key)) is not int or payload[key] <= 0:
                refuse('operator_controller_evidence_invalid')
        return {'status': 'MIGRATIONS_PASS', 'phase': phase, 'gate': 'CLOSED', **common}
    allowed = {'RECOVERY', 'RECEIPT', 'PUBLIC_HTTP_ACCEPTANCE'}
    if (set(payload) != allowed or payload.get('RECOVERY') != 'VERIFIED_GATE_CLOSED'
            or payload.get('PUBLIC_HTTP_ACCEPTANCE') != 'PENDING_SEPARATE_OWNER_GATE_RELEASE'
            or not isinstance(payload.get('RECEIPT'), str)
            or not re.fullmatch(r'gated-recovery-[0-9TZ]+-' + auth['application_sha'] + r'\.json', payload['RECEIPT'])):
        refuse('operator_controller_evidence_invalid')
    return {'status': 'RECOVERY_VERIFIED_GATE_CLOSED', 'phase': phase,
            'receipt': payload['RECEIPT'], **common}


def main(argv=None):
    argv = sys.argv[1:] if argv is None else argv
    if os.geteuid() != 0 or os.environ.get('SUDO_USER') != 'wpcaregrid-recovery':
        print(json.dumps({'status': 'STOP', 'code': 'operator_identity_invalid'}, separators=(',', ':')))
        return 77
    if len(argv) != 1 or argv[0] not in PHASES:
        print(json.dumps({'status': 'STOP', 'code': 'operator_command_invalid'}, separators=(',', ':')))
        return 64
    phase = argv[0]
    lock_fd = None
    try:
        lock_fd = acquire_lock()
        auth = load_authorization()
        controller = verify_bundle(auth)
        restore_fd = open_restore_receipt(auth)
        try:
            result, code = controller_result(controller, auth, phase, restore_fd)
        finally:
            os.close(restore_fd)
        print(json.dumps(result, sort_keys=True, separators=(',', ':')))
        return 2 if phase == 'preflight' and result['status'] == 'BLOCKED' else code
    except Refusal as error:
        print(json.dumps({'status': 'STOP', 'phase': phase, 'code': str(error)},
                         sort_keys=True, separators=(',', ':')))
        return 1
    finally:
        if lock_fd is not None and fcntl is not None:
            try:
                fcntl.flock(lock_fd, fcntl.LOCK_UN)
                os.close(lock_fd)
            except OSError:
                pass


if __name__ == '__main__':
    raise SystemExit(main())
