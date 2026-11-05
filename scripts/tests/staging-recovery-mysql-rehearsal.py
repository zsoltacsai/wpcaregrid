#!/usr/bin/env python3
"""Disposable MySQL 8.4 rehearsal of the interrupted two-migration recovery path."""

import hashlib
import gzip
import importlib.util
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import sys
import tempfile
import types

ROOT = Path(__file__).resolve().parents[2]
RECOVERY_PATH = ROOT / 'scripts/recover-staging-deployment.py'
SPEC = importlib.util.spec_from_file_location('recovery_rehearsal', RECOVERY_PATH)
RECOVERY = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RECOVERY)
TARGETS = set(RECOVERY.MIGRATIONS)
TARGET_FILES = RECOVERY.MIGRATION_FILES


def require(condition, code):
    if not condition:
        raise RuntimeError(code)


def main():
    host = os.environ.get('WPCG_RECOVERY_MYSQL_HOST', '127.0.0.1')
    port = os.environ.get('WPCG_RECOVERY_MYSQL_PORT', '3308')
    root_password = os.environ.get('WPCG_RECOVERY_MYSQL_ROOT_PASSWORD')
    php = shutil.which('php')
    mysql = shutil.which('mysql')
    require(bool(root_password) and php is not None and mysql is not None,
            'rehearsal_prerequisite_unavailable')

    suffix = secrets.token_hex(6)
    database = f'wpcg_recovery_ci_{suffix}'
    username = f'wpcg_r_{suffix}'
    password = secrets.token_hex(24)
    created_database = False
    created_user = False
    migration_window_entered = False
    pre_recovery_dir = None
    composer_test = None
    old_run = RECOVERY.run
    old_values = {name: getattr(RECOVERY, name) for name in (
        'APP', 'PHP', 'verify_fatal_inert_protected_state', 'verify_backup',
        'verify_restore_receipt', 'verify_provenance', 'verify_application_checkout', 'load_attestation',
        'verify_pre_recovery_backup', 'PRE_RECOVERY_BACKUP_DIR',
        '_readiness_runtime', '_readiness_composer', 'verify_profile', 'verify_health',
        'verify_laravel_config_cache', 'write_receipt', 'COMPOSER', 'COMPOSER_SHA',
    )}
    admin_env = {**os.environ, 'MYSQL_PWD': root_password}
    db_env = {
        **os.environ, 'DB_CONNECTION': 'mysql', 'DB_HOST': host, 'DB_PORT': port,
        'DB_DATABASE': database, 'DB_USERNAME': username, 'DB_PASSWORD': password,
        'DB_URL': '', 'APP_KEY': 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'CACHE_STORE': 'array', 'SESSION_DRIVER': 'array', 'QUEUE_CONNECTION': 'sync',
        'MAIL_MAILER': 'array', 'APP_ENV': 'testing',
    }

    def mysql_admin(sql):
        result = subprocess.run(
            [mysql, '--protocol=TCP', '--connect-timeout=5', '--host', host,
             '--port', port, '--user=root', '--batch', '--skip-column-names',
             '--execute', sql], env=admin_env, stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=30,
        )
        if result.returncode:
            raise RuntimeError('mysql_admin_operation_failed')
        return result.stdout.strip()

    def php_run(code):
        result = subprocess.run(
            [php, '-r', code], cwd=ROOT, env=db_env, stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=90,
        )
        return result

    try:
        pre_recovery_dir = Path(tempfile.mkdtemp(prefix='wpcg-pre-recovery-rehearsal-'))
        pre_recovery_dir.chmod(0o700)
        pre_recovery_bytes = gzip.compress(b'synthetic partial staging database backup\n')
        pre_recovery_sha = hashlib.sha256(pre_recovery_bytes).hexdigest()
        pre_recovery_path = pre_recovery_dir / (
            f'wpcaregrid-pre-recovery-{RECOVERY.APP_SHA}-{pre_recovery_sha[:16]}.sql.gz'
        )
        pre_recovery_path.write_bytes(pre_recovery_bytes)
        pre_recovery_path.chmod(0o600)
        RECOVERY.PRE_RECOVERY_BACKUP_DIR = pre_recovery_dir

        settings = mysql_admin(
            "SELECT VERSION(),@@GLOBAL.log_bin,@@GLOBAL.binlog_format,"
            "@@GLOBAL.log_bin_trust_function_creators"
        ).split('\t')
        require(len(settings) == 4 and settings[0] == '8.4.11'
                and settings[1] in ('1', 'ON') and settings[2].upper() == 'ROW'
                and settings[3] in ('0', 'OFF'), 'mysql_rehearsal_service_contract_invalid')
        absent = mysql_admin(
            "SELECT CONCAT((SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='"
            + database + "'), '|', (SELECT COUNT(*) FROM mysql.user WHERE user='"
            + username + "'))"
        )
        require(absent == '0|0', 'generated_database_identity_collision')
        mysql_admin(f"CREATE DATABASE `{database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
        created_database = True
        mysql_admin(f"CREATE USER '{username}'@'%' IDENTIFIED BY '{password}'")
        created_user = True
        mysql_admin(
            f"GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, TRIGGER "
            f"ON `{database}`.* TO '{username}'@'%'")

        install = subprocess.run(
            [php, 'artisan', 'migrate:install', '--no-interaction'], cwd=ROOT, env=db_env,
            stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            text=True, timeout=90,
        )
        require(install.returncode == 0, 'migration_repository_setup_failed')

        base_file = '2026_10_30_000001_create_deployment_transition_fences.php'
        base = php_run(
            '$app=require "bootstrap/app.php";'
            '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
            '$migration=require "database/migrations/' + base_file + '";'
            '$migration->up(); echo "BASELINE_OK";'
        )
        require(base.returncode == 0 and base.stdout.strip() == 'BASELINE_OK',
                'partial_baseline_setup_failed')

        seed = php_run(
            '$app=require "bootstrap/app.php";'
            '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
            '$files=glob(base_path("database/migrations/*.php")); $batch=1;'
            'foreach($files as $file){$name=basename($file,".php");'
            'if(!in_array($name,' + json.dumps(sorted(TARGETS)) + ',true))'
            '{Illuminate\\Support\\Facades\\DB::table("migrations")->insert(["migration"=>$name,"batch"=>$batch]);}}'
            'echo "LEDGER_OK";'
        )
        require(seed.returncode == 0 and seed.stdout.strip() == 'LEDGER_OK',
                'migration_ledger_fixture_failed')

        mysql_admin('SET GLOBAL log_bin_trust_function_creators=OFF')
        migration_file = TARGET_FILES[0]
        failed = php_run(
            '$app=require "bootstrap/app.php";'
            '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
            '$migration=require "database/migrations/' + migration_file + '";'
            'try{$migration->up(); echo "UNEXPECTED_SUCCESS"; exit(3);}catch(Throwable $e){'
            '$x=$e; while($x && !($x instanceof PDOException)){$x=$x->getPrevious();}'
            '$i=$x instanceof PDOException ? $x->errorInfo : null;'
            'echo json_encode(["sqlstate"=>$i[0]??$e->getCode(),"errno"=>isset($i[1])?(int)$i[1]:null]);}'
        )
        require(failed.returncode == 0, 'off_setting_trigger_rejection_not_captured')
        failure = json.loads(failed.stdout)
        require(failure == {'sqlstate': 'HY000', 'errno': 1419},
                'off_setting_trigger_error_contract_mismatch')
        partial = mysql_admin(
            "SELECT CONCAT((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='"
            + database + "' AND table_name='transition_v2_requests'),'|',"
            "(SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='"
            + database + "' AND event_object_table='transition_v2_requests'))"
        )
        require(partial == '1|0', 'partial_schema_after_1419_mismatch')

        php_snapshot = subprocess.run(
            [php, '-r', RECOVERY.PHP_SNAPSHOT], cwd=ROOT, env=db_env,
            stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            text=True, timeout=90,
        )
        require(php_snapshot.returncode == 0, 'php_snapshot_failed')
        snapshot = json.loads(php_snapshot.stdout)
        pending, applied = RECOVERY.validate_snapshot(snapshot, phase='migrate', setting=False)
        require(pending == TARGETS and RECOVERY.MIGRATIONS[0] not in applied,
                'controller_partial_snapshot_rejected')
        require(RECOVERY.validate_schema(snapshot, RECOVERY.TABLES[0]),
                'controller_partial_json_schema_rejected')

        migration_window_entered = True
        mysql_admin('SET GLOBAL log_bin_trust_function_creators=ON')
        require(mysql_admin('SELECT @@GLOBAL.log_bin_trust_function_creators') in ('1', 'ON'),
                'migration_window_setting_not_confirmed')
        ci_run_ids = {RECOVERY.APP_SHA: 1234, 'f' * 40: 5678}
        RECOVERY.APP = ROOT
        RECOVERY.PHP = php
        RECOVERY.verify_fatal_inert_protected_state = lambda: None
        RECOVERY.verify_backup = lambda: {'sha256': RECOVERY.BACKUP_SHA}
        RECOVERY.verify_restore_receipt = lambda *_args: '9' * 64
        RECOVERY.verify_provenance = lambda app_sha, tool_sha, _package: ci_run_ids
        RECOVERY.verify_application_checkout = lambda _sha: None
        RECOVERY.load_attestation = lambda _phase: 'a' * 64
        RECOVERY._readiness_runtime = lambda: {'php': '8.4'}
        RECOVERY._readiness_composer = lambda: {'platform_requirements': 'PASS'}

        def real_test_run(argv, *, cwd=ROOT, timeout=90, check=True):
            result = subprocess.run(argv, cwd=cwd, env=db_env, stdin=subprocess.DEVNULL,
                                    stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
                                    timeout=timeout, check=False)
            if result.returncode and check:
                raise RECOVERY.RecoveryError('rehearsal_controller_command_failed')
            if len(result.stdout) > 131072:
                raise RECOVERY.RecoveryError('rehearsal_output_limit')
            return result
        RECOVERY.run = real_test_run

        migrated = RECOVERY.phase_migrate(RECOVERY.APP_SHA, 'f' * 40, ROOT,
                                          None, '9' * 64, pre_recovery_sha)
        require(migrated['MIGRATIONS'] == 'PASS' and migrated['TRIGGERS'] == 6
                and migrated['GATE'] == 'CLOSED'
                and migrated['RESTORE_RECEIPT_SHA256'] == '9' * 64
                and migrated['PRE_RECOVERY_BACKUP_SHA256'] == pre_recovery_sha,
                'controller_migration_continuation_failed')
        after_migrate = RECOVERY.snapshot()
        pending, applied = RECOVERY.validate_migration_result(after_migrate)
        require(not pending and applied == TARGETS, 'target_migration_ledger_incomplete')
        require(sum(len(after_migrate['tables'][table]['triggers']) for table in RECOVERY.TABLES) == 6
                and all(trigger['definition_valid'] is True
                        for table in RECOVERY.TABLES
                        for trigger in after_migrate['tables'][table]['triggers']),
                'target_trigger_definitions_invalid')

        mysql_admin('SET GLOBAL log_bin_trust_function_creators=OFF')
        final_snapshot = RECOVERY.snapshot()
        RECOVERY.validate_snapshot(final_snapshot, phase='finalize', setting=False)
        receipt_calls = []
        RECOVERY.verify_profile = lambda: None
        RECOVERY.verify_health = lambda: None
        RECOVERY.load_attestation = lambda _phase: 'b' * 64
        composer_test = pre_recovery_dir / f'composer-test-{suffix}'
        composer_fd = os.open(composer_test, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o700)
        with os.fdopen(composer_fd, 'wb') as composer_stream:
            composer_stream.write(b'synthetic composer executable')
        RECOVERY.COMPOSER = composer_test
        RECOVERY.COMPOSER_SHA = hashlib.sha256(RECOVERY.COMPOSER.read_bytes()).hexdigest()
        RECOVERY.verify_laravel_config_cache = lambda: None
        RECOVERY.write_receipt = lambda *args: receipt_calls.append(args) or 'synthetic-gated-receipt'
        def final_run(argv, **kwargs):
            if len(argv) > 1 and argv[1] == '-r':
                return real_test_run(argv, **kwargs)
            return subprocess.CompletedProcess(argv, 0, stdout='', stderr='')
        RECOVERY.run = final_run
        original_lstat = Path.lstat
        def synthetic_lstat(path):
            if path == RECOVERY.COMPOSER:
                return types.SimpleNamespace(st_mode=0o100755, st_uid=0, st_gid=0)
            return original_lstat(path)
        Path.lstat = synthetic_lstat
        try:
            finalized = RECOVERY.phase_finalize(RECOVERY.APP_SHA, 'f' * 40, ROOT,
                                                None, '9' * 64, pre_recovery_sha)
        finally:
            Path.lstat = original_lstat
        require(finalized['RECOVERY'] == 'VERIFIED_GATE_CLOSED'
                and finalized['PUBLIC_HTTP_ACCEPTANCE'] == 'PENDING_SEPARATE_OWNER_GATE_RELEASE'
                and finalized['RECEIPT'] == 'synthetic-gated-receipt'
                and len(receipt_calls) == 1, 'controller_gated_finalization_failed')

    finally:
        mysql_admin = locals().get('mysql_admin')
        try:
            if mysql_admin:
                if migration_window_entered:
                    mysql_admin('SET GLOBAL log_bin_trust_function_creators=OFF')
                    require(mysql_admin('SELECT @@GLOBAL.log_bin_trust_function_creators') in ('0', 'OFF'),
                            'migration_window_setting_restore_failed')
                if created_database:
                    mysql_admin(f'DROP DATABASE `{database}`')
                if created_user:
                    mysql_admin(f"DROP USER '{username}'@'%'")
                if created_database or created_user:
                    residual = mysql_admin(
                        "SELECT CONCAT((SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='"
                        + database + "'), '|', (SELECT COUNT(*) FROM mysql.user WHERE user='"
                        + username + "'))"
                    )
                    require(residual == '0|0', 'rehearsal_mysql_cleanup_incomplete')
        finally:
            RECOVERY.run = old_run
            for name, value in old_values.items():
                setattr(RECOVERY, name, value)
            if composer_test is not None:
                composer_test.unlink(missing_ok=True)
                require(not composer_test.exists(), 'rehearsal_composer_cleanup_incomplete')
            if pre_recovery_dir is not None:
                shutil.rmtree(pre_recovery_dir)
                require(not pre_recovery_dir.exists(), 'rehearsal_temp_cleanup_incomplete')

    print('STAGING_RECOVERY_MYSQL_REHEARSAL=PASS;MYSQL=8.4.11;INITIAL_TRIGGER_ERRNO=1419;SQLSTATE=HY000;MIGRATIONS=2;TRIGGERS=6;GATE=CLOSED;CLEANUP=PASS')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        code = str(error) if str(error).replace('_', '').isalnum() else 'rehearsal_failed'
        print(f'STAGING_RECOVERY_MYSQL_REHEARSAL=FAIL;CODE={code}', file=sys.stderr)
        raise SystemExit(1)
