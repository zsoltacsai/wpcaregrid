#!/usr/bin/env python3
"""Disposable MySQL 8.4 qualification of the COMPLETE migration set (test-only).

Runs the exact migration list of the checked-out tree against a throwaway MySQL 8.4 service that has
log_bin=ON, binlog_format=ROW and log_bin_trust_function_creators=OFF, as an application account that
has no SUPER and no global privilege. It never changes the trust setting (it must read OFF before and
after). Only synthetic data is used. Raw MySQL/PHP output is never printed: every result is a short
classification (case, status, sanitized detail).

Statuses: PASS / FAIL (harness contract broken) / OBSERVED (measured, no contract asserted) /
UNRESOLVED (a question the harness could not settle). Nothing here edits migrations or helpers.

Environment: WPCG_QUAL_MYSQL_HOST (default 127.0.0.1), WPCG_QUAL_MYSQL_PORT (default 3309),
WPCG_QUAL_MYSQL_ROOT_PASSWORD (disposable service only). `--self-test` runs the offline checks only.
"""

import hashlib
import json
import os
import re
import secrets
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MIGRATIONS = ROOT / 'database' / 'migrations'
NAME = re.compile(r'^[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]{1,160}\.php$')
APP_GRANTS = 'SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, TRIGGER'
RUNTIME_GRANTS = 'SELECT, INSERT, UPDATE, DELETE'
APP_KEY = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
KNOWN_GUARDED_TABLES = {'transition_v2_requests', 'transition_history_events'}
RESULTS = []


# ---------------------------------------------------------------- pure helpers (offline self-tested)

def safe(value):
    return re.sub(r'[^A-Za-z0-9_.=,:+/-]', '_', str(value))[:160]


def record(case, status, detail=''):
    RESULTS.append((case, status))
    print(f'WPCG_QUAL|case={safe(case)}|status={status}|detail={safe(detail)}', flush=True)


def migration_inventory(directory):
    names = sorted(p.name for p in Path(directory).iterdir() if p.is_file() and p.suffix == '.php')
    if not names or any(NAME.fullmatch(n) is None for n in names):
        raise RuntimeError('migration_inventory_invalid')
    return names


def trigger_migrations(directory, names):
    return [n for n in names if 'CREATE TRIGGER' in (Path(directory) / n).read_text(encoding='utf-8')]


def contiguous_trigger_migrations(names, trigger_names):
    positions = [names.index(name) for name in trigger_names]
    return len(positions) == 2 and positions[1] == positions[0] + 1


def clean_global_grants(lines):
    global_lines = [line for line in lines if ' ON *.* ' in line.upper()]
    return len(global_lines) == 1 and re.fullmatch(
        r'GRANT USAGE ON [*][.][*] TO .+', global_lines[0].strip(), re.IGNORECASE
    ) is not None


def binlog_trigger_identifier(info):
    match = re.search(r'\bTRIGGER\s+[\x60\']?([A-Za-z0-9_]+)', info, re.IGNORECASE)
    return match.group(1) if match else None


def logged_trigger_names(event_type, info, expected_names, schema, definer, current_schema=None):
    if event_type != 'Query':
        return set()
    # Bind the DDL to this run's schema and definer; DROP or older runs cannot match.
    # mysqlbinlog may emit session SET statements between USE and the DDL, and it
    # emits USE only when a connection's default database changes. The caller
    # carries that context per thread_id across Query events.
    quote = r"[\x60']?"
    use_pattern = re.compile(rf"(?im)^\s*use\s+{quote}([A-Za-z0-9_]+){quote}\s*;")
    create_pattern = re.compile(
        rf"\bCREATE\s+DEFINER\s*=\s*{quote}{re.escape(definer)}{quote}@{quote}%{quote}\s+TRIGGER\s+",
        re.IGNORECASE,
    )
    selected_schema = current_schema
    uses = list(use_pattern.finditer(info))
    if uses:
        selected_schema = uses[-1].group(1)
    if selected_schema is None or selected_schema.casefold() != schema.casefold():
        return set()
    for name in expected_names:
        create = create_pattern.search(info)
        if create is None or re.match(rf"{quote}{re.escape(name)}{quote}(?:\s|$)",
                                      info[create.end():], re.IGNORECASE) is None:
            continue
        if not uses or uses[-1].start() < create.start():
            return {name}
    return set()


def mysqlbinlog_query_events(log_text):
    blocks = re.split(r'(?m)(?=^# at [0-9]+\s*$)', log_text)
    return [block for block in blocks if re.search(r'(?m)^#.*\bQuery\b', block)]


def mysqlbinlog_logged_trigger_names(log_text, expected_names, schema, definer):
    current_schema_by_thread = {}
    matched = set()
    for event in mysqlbinlog_query_events(log_text):
        thread = re.search(r'\bthread_id=(\d+)\b', event)
        if thread is None:
            continue
        thread_id = thread.group(1)
        # Apply a USE at the beginning of this event before evaluating its DDL.
        use = re.search(r'(?im)^\s*use\s+[\x60\']?([A-Za-z0-9_]+)[\x60\']?\s*;', event)
        if use is not None:
            current_schema_by_thread[thread_id] = use.group(1)
        matched.update(logged_trigger_names(
            'Query', event, expected_names, schema, definer,
            current_schema=current_schema_by_thread.get(thread_id),
        ))
    return matched


def created_tables(directory, name):
    return re.findall(r"Schema::create\('([a-z0-9_]+)'", (Path(directory) / name).read_text(encoding='utf-8'))


def mysql_output(text):
    # Keep tabs in an empty final field; remove only record terminators.
    return text.rstrip('\r\n')


def classify(text):
    """Return (sqlstate, errno) from PHP/PDO or mysql-client error text, else ('NONE','NONE')."""
    m = re.search(r'SQLSTATE\[([0-9A-Z]{5})\]: [^:\n]+: ([0-9]{1,5})\b', text or '')
    if m:
        return m.group(1), m.group(2)
    m = re.search(r'ERROR ([0-9]{1,5}) \(([0-9A-Z]{5})\)', text or '')
    if m:
        return m.group(2), m.group(1)
    return 'NONE', 'NONE'


def self_test():
    assert classify('SQLSTATE[HY000]: General error: 1419 You do not have the SUPER privilege') == ('HY000', '1419')
    assert classify('ERROR 1644 (45000) at line 1: x') == ('45000', '1644')
    assert classify('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate') == ('23000', '1062')
    assert classify('all good') == ('NONE', 'NONE')
    assert safe('a b\nc;`x') == 'a_b_c__x'
    binlog_row = mysql_output('bin.000001\t4\tStop\t1\t4\t\n')
    assert binlog_row.endswith('\t') and len(binlog_row.split('\t', 5)) == 6
    with tempfile.TemporaryDirectory() as tmp:
        d = Path(tmp)
        (d / '2026_01_01_000001_a.php').write_text('<?php // plain', encoding='utf-8')
        (d / '2026_01_01_000002_b.php').write_text("Schema::create('t_b', f); DB::unprepared(\"CREATE TRIGGER x\");", encoding='utf-8')
        (d / 'README.md').write_text('x', encoding='utf-8')
        names = migration_inventory(d)
        assert names == ['2026_01_01_000001_a.php', '2026_01_01_000002_b.php']
        assert trigger_migrations(d, names) == ['2026_01_01_000002_b.php']
        assert not contiguous_trigger_migrations(names, ['2026_01_01_000002_b.php'])
        assert clean_global_grants(["GRANT USAGE ON *.* TO 'u'@'%'"])
        assert not clean_global_grants(["GRANT USAGE ON *.* TO 'u'@'%'", "GRANT FILE ON *.* TO 'u'@'%' "])
        assert not clean_global_grants(["GRANT ALL PRIVILEGES ON *.* TO 'u'@'%' WITH GRANT OPTION"])
        expected = ['guard_x']
        assert binlog_trigger_identifier('use db; CREATE DEFINER=u@% TRIGGER guard_name BEFORE INSERT') == 'guard_name'
        assert binlog_trigger_identifier('use db; DROP TRIGGER guard_name') == 'guard_name'
        assert binlog_trigger_identifier('QUERY WITHOUT DDL') is None
        mysqlbinlog_text = re.sub(r'/\*!\d*\s*(.*?)\*/', r'\1',
                                  '# at 100\n#260101 00:00:00 server id 1 Query thread_id=7\n'
                                  'use wpcg_qual_a/*!*/;\nSET TIMESTAMP=1/*!*/;\n'
                                  'CREATE DEFINER=u@% TRIGGER guard_x BEFORE INSERT/*!*/;')
        query_events = mysqlbinlog_query_events(mysqlbinlog_text)
        assert len(query_events) == 1
        assert mysqlbinlog_logged_trigger_names(mysqlbinlog_text, expected,
                                                'wpcg_qual_a', 'u') == {'guard_x'}
        split_events = mysqlbinlog_query_events(
            '# at 100\n#260101 00:00:00 server id 1 Query thread_id=7\nuse wpcg_qual_a;\n'
            '# at 200\n#260101 00:00:01 server id 1 Query thread_id=7\n'
            'SET TIMESTAMP=2; CREATE DEFINER=u@% TRIGGER guard_x')
        assert len(split_events) == 2
        assert mysqlbinlog_logged_trigger_names(
            '# at 100\n#260101 00:00:00 server id 1 Query thread_id=7\nuse wpcg_qual_a;\n'
            '# at 200\n#260101 00:00:01 server id 1 Query thread_id=7\n'
            'SET TIMESTAMP=2; CREATE DEFINER=u@% TRIGGER guard_x',
            expected, 'wpcg_qual_a', 'u') == {'guard_x'}
        assert not mysqlbinlog_logged_trigger_names(
            '# at 100\n#260101 00:00:00 server id 1 Query thread_id=7\nuse wpcg_qual_a;\n'
            '# at 200\n#260101 00:00:01 server id 1 Query thread_id=8\n'
            'SET TIMESTAMP=2; CREATE DEFINER=u@% TRIGGER guard_x',
            expected, 'wpcg_qual_a', 'u')
        assert not mysqlbinlog_logged_trigger_names(
            '# at 100\n#260101 00:00:00 server id 1 Query thread_id=7\nuse another;\n'
            '# at 200\n#260101 00:00:01 server id 1 Query thread_id=7\n'
            'SET TIMESTAMP=2; CREATE DEFINER=u@% TRIGGER guard_x',
            expected, 'wpcg_qual_a', 'u')
        assert not logged_trigger_names('Query', 'use wpcg_qual_a; DROP TRIGGER guard_x',
                                        expected, 'wpcg_qual_a', 'u')
        assert not logged_trigger_names('Query', 'use another; CREATE DEFINER=u@% TRIGGER guard_x',
                                        expected, 'wpcg_qual_a', 'u')
        assert not logged_trigger_names('Query', 'use wpcg_qual_a; CREATE DEFINER=other@% TRIGGER guard_x',
                                        expected, 'wpcg_qual_a', 'u')
        assert not logged_trigger_names('Query', 'use wpcg_qual_a; CREATE DEFINER=u@% TRIGGER guard_x_suffix',
                                        expected, 'wpcg_qual_a', 'u')
        assert not logged_trigger_names('Query', 'CREATE TABLE guard_x (id INT)',
                                        expected, 'wpcg_qual_a', 'u')
        assert not logged_trigger_names('Table_map', 'TRIGGER guard_x',
                                        expected, 'wpcg_qual_a', 'u')
        assert created_tables(d, names[1]) == ['t_b']
        (d / 'bad name.php').write_text('x', encoding='utf-8')
        try:
            migration_inventory(d)
            raise AssertionError('bad name accepted')
        except RuntimeError:
            pass
    print('WPCG_QUAL|case=self_test|status=PASS|detail=offline')


# ---------------------------------------------------------------- environment

HOST = os.environ.get('WPCG_QUAL_MYSQL_HOST', '127.0.0.1')
PORT = os.environ.get('WPCG_QUAL_MYSQL_PORT', '3309')
ROOT_PW = os.environ.get('WPCG_QUAL_MYSQL_ROOT_PASSWORD', '')
MYSQL = shutil.which('mysql')
MYSQLBINLOG = shutil.which('mysqlbinlog')
PHP = shutil.which('php')


def run_mysql(user, password, sql, schema=None):
    cmd = [MYSQL, '--protocol=TCP', '--connect-timeout=5', '--host', HOST, '--port', PORT,
           f'--user={user}', '--batch', '--skip-column-names']
    if schema:
        cmd.append(schema)
    cmd += ['--execute', sql]
    child_env = {key: value for key, value in os.environ.items()
                 if key != 'WPCG_QUAL_MYSQL_ROOT_PASSWORD'}
    r = subprocess.run(cmd, env={**child_env, 'MYSQL_PWD': password}, stdin=subprocess.DEVNULL,
                       stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=60)
    return r.returncode, mysql_output(r.stdout), classify(r.stderr)


def admin(sql, schema=None):
    rc, out, _ = run_mysql('root', ROOT_PW, sql, schema)
    if rc:
        raise RuntimeError('mysql_admin_operation_failed')
    return out


def artisan(user, password, schema, *args, timeout=900):
    env = {**os.environ, 'DB_CONNECTION': 'mysql', 'DB_HOST': HOST, 'DB_PORT': PORT, 'DB_DATABASE': schema,
           'DB_USERNAME': user, 'DB_PASSWORD': password, 'DB_URL': '', 'APP_KEY': APP_KEY,
           'CACHE_STORE': 'array', 'SESSION_DRIVER': 'array', 'QUEUE_CONNECTION': 'sync',
           'MAIL_MAILER': 'array', 'APP_ENV': 'testing'}
    env.pop('WPCG_QUAL_MYSQL_ROOT_PASSWORD', None)
    r = subprocess.run([PHP, 'artisan', *args, '--no-interaction'], cwd=ROOT, env=env,
                       stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                       text=True, timeout=timeout)
    text = r.stdout + '\n' + r.stderr
    return r.returncode, text, classify(text)


def run_up(user, password, schema, migration_file):
    code = ('require "vendor/autoload.php";$app=require "bootstrap/app.php";'
            '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
            '$m=require getenv("WPCG_QUAL_MIGRATION");$m->up();echo "OK";')
    env = {**os.environ, 'DB_CONNECTION': 'mysql', 'DB_HOST': HOST, 'DB_PORT': PORT, 'DB_DATABASE': schema,
           'DB_USERNAME': user, 'DB_PASSWORD': password, 'DB_URL': '', 'APP_KEY': APP_KEY,
           'CACHE_STORE': 'array', 'SESSION_DRIVER': 'array', 'APP_ENV': 'testing',
           'WPCG_QUAL_MIGRATION': str(MIGRATIONS / migration_file)}
    env.pop('WPCG_QUAL_MYSQL_ROOT_PASSWORD', None)
    r = subprocess.run([PHP, '-r', code], cwd=ROOT, env=env, stdin=subprocess.DEVNULL,
                       stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=120)
    return r.returncode, classify(r.stdout + '\n' + r.stderr)


def settings():
    row = admin('SELECT VERSION(), @@GLOBAL.log_bin, @@GLOBAL.binlog_format, '
                '@@GLOBAL.log_bin_trust_function_creators').split('\t')
    if len(row) != 4:
        raise RuntimeError('settings_unreadable')
    version, log_bin, fmt, trust = row
    return {'version': version, 'log_bin': 'ON' if log_bin in ('1', 'ON') else 'OFF',
            'format': fmt.upper(), 'trust': 'ON' if trust in ('1', 'ON') else 'OFF'}


def require_contract(label, ok, detail=''):
    record(label, 'PASS' if ok else 'FAIL', detail)
    return bool(ok)


def global_grants_clean(account):
    lines = admin(f"SHOW GRANTS FOR '{account}'@'%'").splitlines()
    return clean_global_grants(lines)


def ledger(schema):
    out = admin(f'SELECT migration FROM `{schema}`.`migrations` ORDER BY migration')
    return out.splitlines() if out else []


def trigger_rows(schema):
    out = admin("SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_ORIENTATION, "
                f"DEFINER, SHA2(ACTION_STATEMENT,256) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='{schema}' "
                'ORDER BY TRIGGER_NAME')
    return [line.split('\t') for line in out.splitlines()] if out else []


def trigger_ddl_logged(expected_names, schema, dba):
    rows = admin('SHOW BINARY LOGS').splitlines()
    if not rows or len(rows) > 20 or not MYSQLBINLOG:
        return False, 'binlog_reader_or_file_count_invalid'
    files = []
    total_bytes = 0
    for row in rows:
        columns = row.split('\t')
        if len(columns) < 2 or re.fullmatch(r'[A-Za-z0-9_.-]{1,128}', columns[0]) is None:
            return False, 'binlog_file_row_invalid'
        try:
            size = int(columns[1])
        except ValueError:
            return False, 'binlog_file_size_invalid'
        if size < 4:
            return False, 'binlog_file_size_invalid'
        total_bytes += size
        if total_bytes > 32 * 1024 * 1024:
            return False, 'binlog_read_size_limit'
        files.append((columns[0], size))

    env = {key: value for key, value in os.environ.items()
           if key != 'WPCG_QUAL_MYSQL_ROOT_PASSWORD'}
    env['MYSQL_PWD'] = ROOT_PW
    output_parts = []
    output_bytes = 0
    for log_name, size in files:
        command = [MYSQLBINLOG, '--read-from-remote-server', '--protocol=tcp',
                   f'--host={HOST}', f'--port={PORT}', '--user=root',
                   f'--stop-position={size}', log_name]
        try:
            result = subprocess.run(command, env=env, stdin=subprocess.DEVNULL,
                                    stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                    text=True, timeout=60)
        except (OSError, subprocess.TimeoutExpired):
            return False, 'mysqlbinlog_read_failed'
        if result.returncode:
            return False, 'mysqlbinlog_read_failed'
        output_bytes += len(result.stdout.encode('utf-8'))
        if output_bytes > 64 * 1024 * 1024:
            return False, 'mysqlbinlog_output_size_limit'
        output_parts.append(result.stdout)

    # mysqlbinlog decorates statements with version comments such as /*!*/;.
    # Remove those wrappers only in memory; never emit raw log content.
    log_text = re.sub(r'/\*!\d*\s*(.*?)\*/', r'\1', ''.join(output_parts), flags=re.DOTALL)
    candidates = sorted(set(re.findall(r'\bTRIGGER\s+[\x60\']?([A-Za-z0-9_]+)',
                                      log_text, re.IGNORECASE)))
    name_hits = sorted(name for name in expected_names if name.upper() in log_text.upper())
    record('mysqlbinlog_token_counts', 'OBSERVED',
           f"create={len(re.findall(r'\bCREATE\b', log_text, re.IGNORECASE))},"
           f"trigger={len(re.findall(r'\bTRIGGER\b', log_text, re.IGNORECASE))},"
           f"schema={log_text.upper().count(schema.upper())},definer={log_text.upper().count(dba.upper())}")
    record('mysqlbinlog_trigger_candidates', 'OBSERVED', '+'.join(candidates) or 'none')
    record('mysqlbinlog_expected_name_hits', 'OBSERVED', '+'.join(name_hits) or 'none')
    matched = mysqlbinlog_logged_trigger_names(log_text, expected_names, schema, dba)
    record('mysqlbinlog_matched_trigger_names', 'OBSERVED', '+'.join(sorted(matched)) or 'none')
    return matched == set(expected_names), (
        f'files={len(files)},bytes={total_bytes},matched={len(matched)},expected={len(expected_names)}'
    )


def table_exists(schema, table):
    return admin(f"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{schema}' AND TABLE_NAME='{table}'") == '1'


# ---------------------------------------------------------------- privilege probe

def probe(probe_schema, label, grants, suffix):
    account = f'wpcg_qual_p_{suffix}'
    pw = secrets.token_hex(16)
    try:
        admin(f"CREATE USER '{account}'@'%' IDENTIFIED BY '{pw}'")
        admin(f"GRANT SELECT, INSERT, UPDATE, CREATE, DROP, TRIGGER ON `{probe_schema}`.* TO '{account}'@'%'")
        for grant in grants:
            try:
                admin(grant.format(account=account))
            except RuntimeError:
                return 'GRANT_UNAVAILABLE'
        rc, _, (_, errno) = run_mysql(
            account, pw, 'CREATE TRIGGER wpcg_probe_trg BEFORE INSERT ON probe_t FOR EACH ROW SET NEW.id = 1',
            probe_schema)
        if rc == 0:
            admin('DROP TRIGGER IF EXISTS wpcg_probe_trg', probe_schema)
            return 'CREATE_OK'
        return f'errno{errno}'
    finally:
        try:
            admin(f"DROP USER IF EXISTS '{account}'@'%'")
        except RuntimeError:
            pass


# ---------------------------------------------------------------- main qualification

def main():
    self_test()
    if '--self-test' in sys.argv:
        return 0
    if not (ROOT_PW and PHP and MYSQL and MYSQLBINLOG):
        record('prerequisites', 'FAIL', 'php_mysql_client_or_root_password_missing')
        return 1

    suffix = secrets.token_hex(5)
    schema, probe_schema = f'wpcg_qual_{suffix}', f'wpcg_qualp_{suffix}'
    app, runtime, dba = (f'wpcg_qual_migrate_{suffix}', f'wpcg_qual_runtime_{suffix}',
                        f'wpcg_qual_dba_{suffix}')
    app_pw, runtime_pw, dba_pw = secrets.token_hex(16), secrets.token_hex(16), secrets.token_hex(16)
    ok = True
    privilege = None
    path_label = 'UNRESOLVED_NONE'
    before = None
    tmp = Path(tempfile.mkdtemp(prefix='wpcg-qual-'))
    try:
        # 0. exact migration list, derived and cross-checked three ways
        names = migration_inventory(MIGRATIONS)
        stems = [n[:-4] for n in names]
        tracked = subprocess.run(['git', 'ls-files', '--', 'database/migrations'], cwd=ROOT, text=True,
                                 stdout=subprocess.PIPE, stderr=subprocess.DEVNULL).stdout.split()
        tracked_php = sorted(Path(t).name for t in tracked if t.endswith('.php'))
        digest = hashlib.sha256('\n'.join(names).encode()).hexdigest()
        ok &= require_contract('inventory_matches_git', tracked_php == names, f'count={len(names)}')
        record('inventory', 'OBSERVED', f'count={len(names)},list_sha256={digest}')
        trig = trigger_migrations(MIGRATIONS, names)
        ok &= require_contract('trigger_migrations_derived', len(trig) >= 1, f'count={len(trig)}')
        ok &= require_contract('trigger_migrations_are_adjacent', contiguous_trigger_migrations(names, trig),
                               'the harness requires the two guarded migrations to be consecutive')
        guarded = {t for n in trig for t in created_tables(MIGRATIONS, n)}
        ok &= require_contract('guarded_tables_known_to_harness', guarded == KNOWN_GUARDED_TABLES,
                               'extend_harness_if_this_fails')
        first_trigger = trig[0]
        expected_before_failure = [n[:-4] for n in names if n < first_trigger]

        # 1. disposable service contract, readback BEFORE
        before = settings()
        ok &= require_contract('service_contract_before',
                               before['version'] == '8.4.11' and before['log_bin'] == 'ON'
                               and before['format'] == 'ROW' and before['trust'] == 'OFF',
                               f"version={before['version']},log_bin={before['log_bin']},"
                               f"format={before['format']},trust={before['trust']}")
        if not ok:
            return 1
        clash = admin("SELECT (SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'wpcg\\_qual%'),"
                      "(SELECT COUNT(*) FROM mysql.user WHERE user LIKE 'wpcg\\_qual%')")
        ok &= require_contract('no_identity_collision', clash == '0\t0')

        # 2. disposable schema and separate migration, definer, and restricted runtime identities.
        admin(f'CREATE DATABASE `{schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
        admin(f'CREATE DATABASE `{probe_schema}`')
        admin(f'CREATE TABLE `{probe_schema}`.probe_t (id INT)')
        for account, pw in ((app, app_pw), (dba, dba_pw), (runtime, runtime_pw)):
            admin(f"CREATE USER '{account}'@'%' IDENTIFIED BY '{pw}'")
        for account in (app, dba):
            admin(f"GRANT {APP_GRANTS} ON `{schema}`.* TO '{account}'@'%'")
        admin(f"GRANT {RUNTIME_GRANTS} ON `{schema}`.* TO '{runtime}'@'%'")
        ok &= require_contract('accounts_have_no_global_privilege',
                               all(global_grants_clean(a) for a in (app, dba, runtime)))

        # 3. privilege probe in a separate disposable schema (never the application account)
        ladder = [
            ('TRIGGER_SCHEMA_ONLY', []),
            ('SET_ANY_DEFINER', ["GRANT SET_ANY_DEFINER ON *.* TO '{account}'@'%'"]),
            ('SET_USER_ID', ["GRANT SET_USER_ID ON *.* TO '{account}'@'%'"]),
            ('SUPER', ["GRANT SUPER ON *.* TO '{account}'@'%'"]),
        ]
        outcomes = {}
        for label, grants in ladder:
            outcomes[label] = probe(probe_schema, label, grants, suffix)
            record(f'probe_{label}', 'OBSERVED', outcomes[label])
        ok &= require_contract('probe_baseline_trigger_only_rejected', outcomes['TRIGGER_SCHEMA_ONLY'] == 'errno1419')
        for label in ('SET_ANY_DEFINER', 'SET_USER_ID', 'SUPER'):
            if outcomes[label] == 'CREATE_OK':
                privilege = label
                break
        if privilege is None:
            path_label = 'UNRESOLVED_NONE'
        elif privilege == 'SUPER':
            path_label = 'UNRESOLVED_SUPER_ONLY'
        else:
            path_label = f'CANDIDATE_GLOBAL_{privilege}'
        record('least_privilege_path', 'UNRESOLVED' if path_label.startswith('UNRESOLVED') else 'OBSERVED', path_label)
        ok &= require_contract('probe_found_some_working_privilege', privilege is not None)

        # 4. full migration set as the application account (TRIGGER present, no SUPER, trust OFF): errno 1419
        rc, _, _ = artisan(app, app_pw, schema, 'migrate:install')
        ok &= require_contract('migrate_install_as_app', rc == 0)
        rc, _, (state, errno) = artisan(app, app_pw, schema, 'migrate', '--force')
        ok &= require_contract('full_migrate_fails_with_1419', rc != 0 and errno == '1419', f'sqlstate={state},errno={errno}')
        ok &= require_contract('ledger_stops_before_first_trigger_migration', ledger(schema) == expected_before_failure,
                               f'ledger_rows={len(ledger(schema))},expected={len(expected_before_failure)}')

        def partial_state():
            rows = trigger_rows(schema)
            tables = {t: table_exists(schema, t) for t in sorted(guarded)}
            return tables, len(rows)

        first_tables = set(created_tables(MIGRATIONS, first_trigger))
        tables, count = partial_state()
        ok &= require_contract('partial_ddl_table_present_without_triggers',
                               all(tables[t] for t in first_tables) and count == 0,
                               f'tables={tables},triggers={count}')
        ok &= require_contract('later_trigger_tables_absent', all(not tables[t] for t in guarded - first_tables))

        # 5. re-run as the application account: same failure, no progress (idempotent failure)
        rc, _, (_, errno) = artisan(app, app_pw, schema, 'migrate', '--force')
        ok &= require_contract('rerun_as_app_fails_identically', rc != 0 and errno == '1419'
                               and ledger(schema) == expected_before_failure and partial_state() == (tables, count))

        # 6. DBA-separated install of ONLY the trigger migrations (byte-identical copies), then privilege removed
        if privilege is None:
            record('dba_separation', 'UNRESOLVED', 'no_working_privilege_found')
            ok = False
        else:
            only = tmp / 'trigger-migrations'
            only.mkdir(mode=0o700)
            for n in trig:
                shutil.copyfile(MIGRATIONS / n, only / n)
                same = hashlib.sha256((only / n).read_bytes()).hexdigest() == hashlib.sha256((MIGRATIONS / n).read_bytes()).hexdigest()
                ok &= require_contract('trigger_migration_copy_identical', same, n[:-4])
            admin(f"GRANT {privilege} ON *.* TO '{dba}'@'%'")
            try:
                active_before = settings()
                ok &= require_contract('trust_off_while_global_privilege_active',
                                       active_before['version'] == '8.4.11' and active_before['log_bin'] == 'ON'
                                       and active_before['format'] == 'ROW' and active_before['trust'] == 'OFF')
                rc, _, (_, errno) = artisan(dba, dba_pw, schema, 'migrate', '--force', '--realpath', f'--path={only}')
                active_after = settings()
                ok &= require_contract('trust_off_after_trigger_migrations_before_revoke',
                                       active_after['version'] == '8.4.11' and active_after['log_bin'] == 'ON'
                                       and active_after['format'] == 'ROW' and active_after['trust'] == 'OFF')
            finally:
                admin(f"REVOKE {privilege} ON *.* FROM '{dba}'@'%'")
            ok &= require_contract('dba_installs_trigger_migrations', rc == 0,
                                   f'privilege={privilege},errno={errno},label={path_label}')
            ok &= require_contract('dba_global_privilege_revoked', global_grants_clean(dba))
            ok &= require_contract('app_still_no_global_privilege', global_grants_clean(app))

            # 7. application account finishes everything else
            rc, _, (_, errno) = artisan(app, app_pw, schema, 'migrate', '--force')
            ok &= require_contract('app_completes_remaining_migrations', rc == 0, f'errno={errno}')

        # 8. complete ledger == exact derived list, nothing pending
        rows = ledger(schema)
        ok &= require_contract('ledger_equals_exact_list', rows == stems, f'ledger={len(rows)},list={len(stems)}')
        rc, text, _ = artisan(app, app_pw, schema, 'migrate:status')
        pending = len(re.findall(r'(?m)\bPending\b', text))
        record('migrate_status_pending', 'PASS' if rc == 0 and pending == 0 else 'FAIL', f'pending={pending}')
        ok &= rc == 0 and pending == 0

        # 9. trigger count, structure, definer, definitions
        rows = trigger_rows(schema)
        expected_triggers = 3 * len(trig)
        ok &= require_contract('trigger_count', len(rows) == expected_triggers, f'count={len(rows)},expected={expected_triggers}')
        shape_ok = all(r[4] == 'ROW' and r[3] == 'BEFORE' and r[2] in ('INSERT', 'UPDATE', 'DELETE')
                       and r[1] in guarded and r[0] == f'{r[1]}_immutable_{r[2].lower()}' for r in rows)
        per_table = {t: sorted(r[2] for r in rows if r[1] == t) for t in guarded}
        ok &= require_contract('trigger_shape', shape_ok and all(v == ['DELETE', 'INSERT', 'UPDATE'] for v in per_table.values()))
        definers = sorted({r[5] for r in rows})
        ok &= require_contract('trigger_definer_is_dba_account', definers == [f'{dba}@%'], f'distinct_definers={len(definers)}')
        record('trigger_definition_sha256', 'OBSERVED',
               hashlib.sha256('|'.join(f'{r[0]}:{r[6]}' for r in rows).encode()).hexdigest())
        binlog_ok, binlog_detail = trigger_ddl_logged([r[0] for r in rows], schema, dba)
        ok &= require_contract('trigger_ddl_present_in_bounded_binlog_read', binlog_ok, binlog_detail)

        # 10. idempotence: reviewed up() re-run as the app account verifies definitions without SUPER/trust
        for n in trig:
            rc, _ = run_up(app, app_pw, schema, n)
            ok &= require_contract('up_rerun_is_idempotent_as_app', rc == 0 and trigger_rows(schema) == rows, n[:-4])
        rc, text, _ = artisan(app, app_pw, schema, 'migrate', '--force')
        ok &= require_contract('migrate_rerun_nothing_to_migrate', rc == 0 and ledger(schema) == stems)

        # 11. guard semantics use a separate DML-only runtime account, never the migration account.
        op, boot, rid, h1, h2, tup = ('11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222',
                                      '33333333-3333-4333-8333-333333333333', 'a' * 64, 'b' * 64, 'c' * 64)
        admin("INSERT INTO deployment_transition_fences (fence_key, state, operation_id, generation, boot_id, journal_seq,"
              " journal_head_hash, evidence_digest, created_at, updated_at, phase2b_state, phase2b_tuple_digest,"
              f" phase2b_event_sequence) VALUES ('global','HOLD','{op}',1,'{boot}',1,'{h1}','{h2}',NOW(),NOW(),'HOLD','{tup}',1)",
              schema)

        def req(rid_, op_name='compare_and_set_hold', seq=1):
            return ("INSERT INTO transition_v2_requests (request_id, request_digest, operation, operation_id, generation,"
                    " source_tuple_digest, target_tuple_digest, history_sequence, receipt_payload, receipt_digest,"
                    f" committed_at, created_at, updated_at) VALUES ('{rid_}','{h1}','{op_name}','{op}',1,NULL,'{tup}',{seq},"
                    f"'{{}}','{h2}',NOW(6),NOW(6),NOW(6))")

        def hist(rid_, event='HOLD_TUPLE_COMMITTED', seq=1):
            return ("INSERT INTO transition_history_events (event_sequence, operation_id, generation, request_id,"
                    " request_digest, event_type, source_tuple_digest, target_tuple_digest, occurred_at, created_at,"
                    f" updated_at) VALUES ({seq},'{op}',1,'{rid_}','{h1}','{event}',NULL,'{tup}',NOW(6),NOW(6),NOW(6))")

        def as_runtime(sql):
            return run_mysql(runtime, runtime_pw, sql, schema)

        rc, _, (_, errno) = as_runtime(req(rid, op_name='other_operation'))
        ok &= require_contract('guard_rejects_invalid_request_insert', rc != 0 and errno == '1644', f'errno={errno}')
        rc, _, (_, errno) = as_runtime(req(rid))
        ok &= require_contract('guard_accepts_valid_request_insert', rc == 0, f'errno={errno}')
        rc, _, (_, errno) = as_runtime(req(rid))
        # the MySQL guard has no conflict clause (SQLite only): a duplicate is stopped by the unique key instead
        ok &= require_contract('duplicate_request_insert_rejected', rc != 0 and errno in ('1062', '1644'), f'errno={errno}')
        rc, _, (_, errno) = as_runtime("UPDATE transition_v2_requests SET receipt_digest = receipt_digest")
        ok &= require_contract('guard_rejects_request_update', rc != 0 and errno == '1644', f'errno={errno}')
        rc, _, (_, errno) = as_runtime('DELETE FROM transition_v2_requests')
        ok &= require_contract('guard_rejects_request_delete', rc != 0 and errno == '1644', f'errno={errno}')
        rc, _, (_, errno) = as_runtime(hist(rid, event='OTHER_EVENT'))
        ok &= require_contract('guard_rejects_invalid_history_insert', rc != 0 and errno == '1644', f'errno={errno}')
        rc, _, (_, errno) = as_runtime(hist(rid))
        ok &= require_contract('guard_accepts_valid_history_insert', rc == 0, f'errno={errno}')
        rc, _, (_, errno) = as_runtime('UPDATE transition_history_events SET event_type = event_type')
        ok &= require_contract('guard_rejects_history_update', rc != 0 and errno == '1644', f'errno={errno}')
        rc, _, (_, errno) = as_runtime('DELETE FROM transition_history_events')
        ok &= require_contract('guard_rejects_history_delete', rc != 0 and errno == '1644', f'errno={errno}')
        rc, _, (_, errno) = as_runtime('DROP TRIGGER transition_v2_requests_immutable_insert')
        ok &= require_contract('runtime_cannot_drop_guard_trigger', rc != 0 and errno in ('1142', '1227'),
                               f'errno={errno}')
        ok &= require_contract('guard_count_unchanged_after_runtime_drop_attempt',
                               len(trigger_rows(schema)) == expected_triggers)

        # 12. Definer lifecycle: retain only its read dependencies and lock the account.
        admin(f"REVOKE ALL PRIVILEGES ON `{schema}`.* FROM '{dba}'@'%'")
        admin(f"GRANT SELECT ON `{schema}`.deployment_transition_fences TO '{dba}'@'%'")
        admin(f"GRANT SELECT ON `{schema}`.transition_v2_requests TO '{dba}'@'%'")
        admin(f"GRANT SELECT ON `{schema}`.transition_history_events TO '{dba}'@'%'")
        admin(f"GRANT TRIGGER ON `{schema}`.* TO '{dba}'@'%'")
        admin(f"ALTER USER '{dba}'@'%' ACCOUNT LOCK")
        definer_grants = admin(f"SHOW GRANTS FOR '{dba}'@'%'").splitlines()
        normalized_grants = [line.replace('`', '').upper() for line in definer_grants]
        allowed_definer = global_grants_clean(dba) and all(
            (line.startswith('GRANT USAGE ON *.* TO ') and 'WITH GRANT OPTION' not in line)
            or line.startswith(f'GRANT SELECT ON {schema.upper()}.DEPLOYMENT_TRANSITION_FENCES TO ')
            or line.startswith(f'GRANT SELECT ON {schema.upper()}.TRANSITION_V2_REQUESTS TO ')
            or line.startswith(f'GRANT SELECT ON {schema.upper()}.TRANSITION_HISTORY_EVENTS TO ')
            or line.startswith(f'GRANT TRIGGER ON {schema.upper()}.* TO ')
            for line in normalized_grants
        )
        ok &= require_contract('definer_locked_with_minimal_grants', allowed_definer)
        rid2 = '44444444-4444-4444-8444-444444444444'
        admin("UPDATE deployment_transition_fences SET phase2b_event_sequence = 2 WHERE fence_key = 'global'", schema)
        rc, _, (_, errno) = as_runtime(req(rid2, seq=2))
        ok &= require_contract('locked_minimal_definer_request_insert', rc == 0, f'errno={errno}')
        rc, _, (_, errno) = as_runtime(hist(rid2, seq=2))
        ok &= require_contract('locked_minimal_definer_history_insert', rc == 0, f'errno={errno}')

    except Exception as error:  # noqa: BLE001 - report a code, never raw output
        record('harness_exception', 'FAIL', type(error).__name__)
        ok = False
    finally:
        for sql in (f'DROP DATABASE IF EXISTS `{schema}`', f'DROP DATABASE IF EXISTS `{probe_schema}`',
                    f"DROP USER IF EXISTS '{app}'@'%'", f"DROP USER IF EXISTS '{dba}'@'%'",
                    f"DROP USER IF EXISTS '{runtime}'@'%'"):
            try:
                admin(sql)
            except Exception:  # noqa: BLE001
                pass
        shutil.rmtree(tmp, ignore_errors=True)
        try:
            left = admin("SELECT (SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'wpcg\\_qual%'),"
                         "(SELECT COUNT(*) FROM mysql.user WHERE user LIKE 'wpcg\\_qual%')")
            ok &= require_contract('cleanup_complete', left == '0\t0')
            after = settings()
            ok &= require_contract('service_contract_after_trust_off_unchanged',
                                   before is not None and after == before and after['trust'] == 'OFF',
                                   f"trust={after['trust']},log_bin={after['log_bin']},format={after['format']}")
        except Exception:  # noqa: BLE001
            record('cleanup_complete', 'FAIL', 'readback_failed')
            ok = False
    print(f'WPCG_QUAL|summary|harness={"PASS" if ok else "FAIL"}|migration_privilege_result={path_label}', flush=True)
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
