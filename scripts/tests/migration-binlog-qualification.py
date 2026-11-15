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


def mysqlbinlog_query_statement(event):
    lines = [line for line in event.splitlines() if not line.lstrip().startswith('#')]
    body = re.sub(r'(?m)^\\', '', '\n'.join(lines)).strip()
    current_schema = None
    saw_use = False
    statements = split_sql_statements(body)
    if not statements and re.search(r'(?i)\bUSE\b', body):
        # An unterminated quote/comment makes statement boundaries unknown.
        # If USE appears in that unparseable block, do not retain stale state.
        return None, body, True
    for statement in statements:
        is_use, use_schema = parse_mysqlbinlog_use(statement)
        if not is_use:
            continue
        saw_use = True
        current_schema = use_schema
    return current_schema, body, saw_use


def split_sql_statements(text):
    """Strip SQL comments and split at semicolons outside quoted text."""
    cleaned = []
    index = 0
    quote = None
    while index < len(text):
        char = text[index]
        if quote:
            cleaned.append(char)
            if char == '\\' and quote in ("'", '"') and index + 1 < len(text):
                index += 1
                cleaned.append(text[index])
            elif char == quote:
                if index + 1 < len(text) and text[index + 1] == quote:
                    index += 1
                    cleaned.append(text[index])
                else:
                    quote = None
            index += 1
            continue
        if char in ("'", '"', '`'):
            quote = char
            cleaned.append(char)
            index += 1
            continue
        if text.startswith('/*!', index):
            end = text.find('*/', index + 3)
            if end < 0:
                return []
            body = re.sub(r'^\d+', '', text[index + 3:end]).strip()
            cleaned.extend((' ', body, ' '))
            index = end + 2
            continue
        if text.startswith('/*', index):
            end = text.find('*/', index + 2)
            if end < 0:
                return []
            cleaned.append(' ')
            index = end + 2
            continue
        if char == '#' or (text.startswith('--', index) and
                            (index + 2 == len(text) or text[index + 2].isspace())):
            end = text.find('\n', index)
            index = len(text) if end < 0 else end
            cleaned.append(' ')
            continue
        cleaned.append(char)
        index += 1
    text = ''.join(cleaned)
    statements = []
    start = 0
    quote = None
    index = 0
    while index < len(text):
        char = text[index]
        if quote:
            if char == '\\' and quote in ("'", '"'):
                index += 2
                continue
            if char == quote:
                if index + 1 < len(text) and text[index + 1] == quote:
                    index += 2
                    continue
                quote = None
        elif char in ("'", '"', '`'):
            quote = char
        elif char == ';':
            statement = text[start:index].strip()
            if statement:
                statements.append(statement)
            start = index + 1
        index += 1
    tail = text[start:].strip()
    if tail:
        statements.append(tail)
    return statements


def parse_mysqlbinlog_use(statement):
    if not re.match(r'(?is)^USE\b', statement):
        return False, None
    use = re.fullmatch(
        r"(?is)USE\s+(`(?:``|[^`])+`|'(?:''|[^'])+'|[A-Za-z0-9_$-]+)",
        statement,
    )
    if use is None:
        return True, None
    identifier = use.group(1)
    if identifier.startswith('`'):
        return True, identifier[1:-1].replace('``', '`')
    if identifier.startswith("'"):
        return True, identifier[1:-1].replace("''", "'")
    return True, identifier


def split_sql_assignments(text):
    """Split comma-separated SET assignments without splitting quoted values."""
    items = []
    start = 0
    quote = None
    index = 0
    while index < len(text):
        char = text[index]
        if quote:
            if char == '\\' and quote in ("'", '"') and index + 1 < len(text):
                index += 2
                continue
            if char == quote:
                if index + 1 < len(text) and text[index + 1] == quote:
                    index += 2
                    continue
                quote = None
        elif char in ("'", '"', '`'):
            quote = char
        elif char == ',':
            items.append(text[start:index].strip())
            start = index + 1
        index += 1
    if quote:
        return []
    tail = text[start:].strip()
    if tail:
        items.append(tail)
    return items


def allowed_set_prelude(statement):
    if re.fullmatch(r'(?is)SET\s+TIMESTAMP\s*=\s*[0-9]+(?:\.[0-9]+)?', statement):
        return True
    match = re.match(r'(?is)^SET\s+(.+)$', statement)
    if not match:
        return False
    assignments = split_sql_assignments(match.group(1))
    return bool(assignments) and all(
        re.fullmatch(r'(?is)@@SESSION\.[A-Za-z0-9_]+\s*=\s*.+', assignment)
        for assignment in assignments
    )


def logged_trigger_names(event_type, info, expected_names, schema, definer):
    matches, _ = logged_trigger_match(event_type, info, expected_names, schema)
    return matches


def logged_trigger_match(event_type, info, expected_names, schema):
    if event_type != 'Query':
        return set(), 'not_query'
    # Session prelude may be included in the same Query event. Permit only USE,
    # timestamp/session SETs, and mysqlbinlog's observed charset marker.
    user_part = r"(?:`[^`]+`|'[^']+'|[A-Za-z0-9_.$-]+)"
    host_part = r"(?:`[^`]+`|'[^']+'|[A-Za-z0-9_.$:%-]+)"
    definer_part = rf"(?:CURRENT_USER(?:\(\))?|{user_part}\s*@\s*{host_part})"
    create_pattern = re.compile(
        rf"^\s*CREATE\s+(?:DEFINER\s*=\s*{definer_part}\s+)?TRIGGER\s+",
        re.IGNORECASE,
    )
    for statement in split_sql_statements(info):
        is_use, use_schema = parse_mysqlbinlog_use(statement)
        if is_use and use_schema is None:
            return set(), 'malformed_use_prelude'
        if is_use and use_schema.casefold() != schema.casefold():
            return set(), 'wrong_schema'
        allowed_set = allowed_set_prelude(statement)
        if is_use or allowed_set:
            continue
        if re.match(r'(?is)^SET\b', statement):
            variable = re.match(r'(?is)^SET\s+@@SESSION\.([A-Za-z0-9_]+)', statement)
            return set(), f"disallowed_set_{variable.group(1).lower() if variable else 'other'}"
        if re.fullmatch(r'(?i)(?:\\C|C)\s+utf8mb4', statement):
            continue
        if re.match(r'(?i)^(?:\\C|C)\b', statement):
            return set(), 'unsupported_charset_marker'
        create = create_pattern.match(statement)
        if create is None:
            first_word = re.match(r'(?is)^\s*([A-Za-z_]+)', statement)
            if first_word and first_word.group(1).upper() == 'CREATE':
                return set(), 'create_syntax_mismatch'
            if first_word and first_word.group(1).upper() == 'USE':
                return set(), 'malformed_use_prelude'
            if first_word:
                return set(), f"unrecognized_prelude_{first_word.group(1).upper()}"
            return set(), (f"unrecognized_prelude_cp_{ord(statement[0])}"
                           if statement else 'unrecognized_prelude_empty')
        for name in expected_names:
            name_token = rf"(?:`{re.escape(name)}`|'{re.escape(name)}'|{re.escape(name)})"
            if re.match(rf"{name_token}(?:\s|$)", statement[create.end():], re.IGNORECASE):
                return {name}, 'matched'
        return set(), 'trigger_name_mismatch'
    return set(), 'no_ddl_statement'


def mysqlbinlog_query_events(log_text):
    return [event for event, error_code in mysqlbinlog_query_blocks(log_text)
            if error_code == '0']


def mysqlbinlog_query_blocks(log_text):
    blocks = re.split(r'(?m)(?=^# at [0-9]+\s*$)', log_text)
    events = []
    for block in blocks:
        header = re.search(
            r'(?m)^#.*\bserver\s+id\s+[0-9]+\s+end_log_pos\s+[0-9]+\s+'
            r'(?:CRC32\s+0x[0-9a-fA-F]+\s+)?Query\s+'
            r'thread_id=[0-9]+\b.*\berror_code=([0-9]+)\b',
            block,
        )
        if header:
            events.append((block, header.group(1)))
        elif mysqlbinlog_query_statement(block)[2]:
            # If a block carries a USE but its Query header is malformed or
            # unknown, the schema state cannot be trusted for later events.
            events.append((block, None))
    return events


def mysqlbinlog_trigger_match_diagnostics(log_text, expected_names, schema, definer):
    current_schema = None
    matched = set()
    stats = {name: {'events': 0, 'schema': 0, 'ddl': 0} for name in expected_names}
    for event, error_code in mysqlbinlog_query_blocks(log_text):
        event_schema, statement, saw_use = mysqlbinlog_query_statement(event)
        if error_code != '0':
            # A failed Query event containing a USE leaves the stream's schema
            # attribution uncertain. Drop it rather than attributing later DDL
            # to the stale schema and accepting a false match.
            if saw_use:
                current_schema = None
            continue
        if saw_use:
            current_schema = event_schema
        for name in expected_names:
            if re.search(rf'\b{re.escape(name)}\b', event, re.IGNORECASE):
                stats[name]['events'] += 1
                if current_schema is not None and current_schema.casefold() == schema.casefold():
                    stats[name]['schema'] += 1
                name_matches, reason = logged_trigger_match('Query', statement, [name], schema)
                if name_matches:
                    stats[name]['ddl'] += 1
                    if current_schema is not None and current_schema.casefold() == schema.casefold():
                        matched.add(name)
                else:
                    stats[name]['reason'] = reason
    return matched, stats


def mysqlbinlog_logged_trigger_names(log_text, expected_names, schema, definer):
    matched, _ = mysqlbinlog_trigger_match_diagnostics(log_text, expected_names, schema, definer)
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


def phase2b_failure_code(text):
    failures = (
        ('The Phase 2B trigger definer is not configured.', 'DEFINER_CONFIG_MISSING'),
        ('The Phase 2B trigger inventory is incomplete.', 'DEFINER_INVENTORY_INCOMPLETE'),
        ('A Phase 2B trigger has an unexpected DEFINER.', 'DEFINER_MISMATCH'),
    )
    for marker, code in failures:
        if marker in (text or ''):
            return code
    return 'NONE'


def dump_error_class(text):
    value = (text or '').lower()
    if 'unknown option' in value or 'unknown variable' in value or 'unrecognized option' in value:
        return 'client_option_unsupported'
    if 'access denied' in value or 'privilege' in value or 'permission denied' in value:
        return 'client_access_denied'
    if 'unknown database' in value or 'no database selected' in value:
        return 'client_database_selection_failed'
    if 'couldn.t execute' in value or 'error in query' in value:
        return 'client_query_failed'
    if 'can.t connect' in value or 'connection refused' in value or 'server has gone away' in value:
        return 'client_connection_failed'
    if re.search(r'(?i)got error:\s*[0-9]+:', text or ''):
        return 'client_mysql_errno'
    if value.strip():
        return 'client_other_error'
    return 'no_stderr'


def file_sha256_matches(path, expected):
    try:
        return sha256_matches(Path(path).read_bytes(), expected)
    except OSError:
        return False


def sha256_matches(content, expected):
    return hashlib.sha256(content).hexdigest() == expected


def self_test():
    assert classify('SQLSTATE[HY000]: General error: 1419 You do not have the SUPER privilege') == ('HY000', '1419')
    assert classify('ERROR 1644 (45000) at line 1: x') == ('45000', '1644')
    assert dump_error_class("mysqldump: unknown option '--example'") == 'client_option_unsupported'
    assert dump_error_class('mysqldump: Got error: 2002: connection refused') == 'client_connection_failed'
    assert dump_error_class('mysqldump: other failure') == 'client_other_error'
    assert dump_error_class('') == 'no_stderr'
    with tempfile.TemporaryDirectory() as tmp:
        backup = Path(tmp) / 'backup.sql'
        backup.write_bytes(b'synthetic backup')
        receipt = hashlib.sha256(backup.read_bytes()).hexdigest()
        assert file_sha256_matches(backup, receipt)
        assert sha256_matches(backup.read_bytes(), receipt)
        backup.write_bytes(b'corrupted backup')
        assert not file_sha256_matches(backup, receipt)
        assert not sha256_matches(backup.read_bytes(), receipt)
        assert not file_sha256_matches(Path(tmp) / 'missing.sql', receipt)
    original_values = {key: os.environ.get(key) for key in
                       ('WPCG_QUAL_MYSQL_ROOT_PASSWORD', 'WPCG_QUAL_RESTORE_ROOT_PASSWORD')}
    try:
        os.environ['WPCG_QUAL_MYSQL_ROOT_PASSWORD'] = 'synthetic-source-root'
        os.environ['WPCG_QUAL_RESTORE_ROOT_PASSWORD'] = 'synthetic-restore-root'
        assert not {'WPCG_QUAL_MYSQL_ROOT_PASSWORD', 'WPCG_QUAL_RESTORE_ROOT_PASSWORD'} & set(child_environment())
    finally:
        for key, value in original_values.items():
            if value is None:
                os.environ.pop(key, None)
            else:
                os.environ[key] = value
    assert classify('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate') == ('23000', '1062')
    assert phase2b_failure_code('RuntimeException: The Phase 2B trigger definer is not configured.') == 'DEFINER_CONFIG_MISSING'
    assert phase2b_failure_code('RuntimeException: A Phase 2B trigger has an unexpected DEFINER.') == 'DEFINER_MISMATCH'
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
                                  '# at 100\n#260101 00:00:00 server id 1 end_log_pos 200 CRC32 0x1234 Query thread_id=7 error_code=0\n'
                                  'use wpcg_qual_a/*!*/;\nSET TIMESTAMP=1/*!*/;\n'
                                  'CREATE DEFINER=u@% TRIGGER guard_x BEFORE INSERT/*!*/;')
        query_events = mysqlbinlog_query_events(mysqlbinlog_text)
        assert len(query_events) == 1
        assert mysqlbinlog_logged_trigger_names(mysqlbinlog_text, expected,
                                                'wpcg_qual_a', 'u') == {'guard_x'}
        assert not mysqlbinlog_logged_trigger_names(
            '# at 100\n#260101 00:00:00 server id 1 end_log_pos 200 CRC32 0x1234 Query thread_id=7 error_code=1419\n'
            'use wpcg_qual_a; CREATE TRIGGER guard_x BEFORE INSERT',
            expected, 'wpcg_qual_a', 'u')
        assert not mysqlbinlog_logged_trigger_names(
            '# at 50\n#260101 00:00:00 server id 1 end_log_pos 100 CRC32 0x1234 Query thread_id=7 error_code=0\n'
            'use wpcg_qual_a;\n'
            '# at 100\n#260101 00:00:01 server id 1 end_log_pos 200 CRC32 0x1234 Query thread_id=7 error_code=1049\n'
            'use another;\n'
            '# at 200\n#260101 00:00:02 server id 1 end_log_pos 300 CRC32 0x1234 Query thread_id=8 error_code=0\n'
            'CREATE DEFINER=u@% TRIGGER guard_x BEFORE INSERT',
            expected, 'wpcg_qual_a', 'u')
        assert not mysqlbinlog_logged_trigger_names(
            '# at 50\n#260101 00:00:00 server id 1 end_log_pos 100 CRC32 0x1234 Query thread_id=7 error_code=0\n'
            'use wpcg_qual_a;\n'
            '# at 100\n#260101 00:00:01 server id 1 end_log_pos 200 CRC32 0x1234 Query thread_id=7 error_code=1049\n'
            'USE other; /* unterminated\n'
            '# at 200\n#260101 00:00:02 server id 1 end_log_pos 300 CRC32 0x1234 Query thread_id=8 error_code=0\n'
            'CREATE DEFINER=u@% TRIGGER guard_x BEFORE INSERT',
            expected, 'wpcg_qual_a', 'u')
        assert not mysqlbinlog_logged_trigger_names(
            '# at 50\n#260101 00:00:00 server id 1 end_log_pos 100 CRC32 0x1234 Query thread_id=7 error_code=0\n'
            'use wpcg_qual_a;\n'
            '# at 100\n#260101 00:00:01 server id 1 MALFORMED HEADER\n'
            'SET TIMESTAMP=1; USE other; /*! unterminated\n'
            '# at 200\n#260101 00:00:02 server id 1 end_log_pos 300 CRC32 0x1234 Query thread_id=8 error_code=0\n'
            'CREATE DEFINER=u@% TRIGGER guard_x BEFORE INSERT',
            expected, 'wpcg_qual_a', 'u')
        assert not mysqlbinlog_logged_trigger_names(
            '# at 50\n#260101 00:00:00 server id 1 end_log_pos 100 CRC32 0x1234 Query thread_id=7 error_code=0\n'
            'use wpcg_qual_a;\n'
            '# at 100\n#260101 00:00:01 server id 1 end_log_pos 200 CRC32 0x1234 Query thread_id=7 error_code=0\n'
            'SET TIMESTAMP=1; USE `other-db`;\n'
            '# at 200\n#260101 00:00:02 server id 1 end_log_pos 300 CRC32 0x1234 Query thread_id=8 error_code=0\n'
            'CREATE DEFINER=u@% TRIGGER guard_x BEFORE INSERT',
            expected, 'wpcg_qual_a', 'u')
        assert not mysqlbinlog_logged_trigger_names(
            '# at 50\n#260101 00:00:00 server id 1 end_log_pos 100 CRC32 0x1234 Query thread_id=7 error_code=0\n'
            'use wpcg_qual_a;\n'
            '# at 100\n#260101 00:00:01 server id 1 end_log_pos 200 CRC32 0x1234 Query thread_id=7 error_code=1049\n'
            'SET TIMESTAMP=1; USE another;\n'
            '# at 200\n#260101 00:00:02 server id 1 end_log_pos 300 CRC32 0x1234 Query thread_id=8 error_code=0\n'
            'CREATE DEFINER=u@% TRIGGER guard_x BEFORE INSERT',
            expected, 'wpcg_qual_a', 'u')
        assert not mysqlbinlog_logged_trigger_names(
            '# at 50\n#260101 00:00:00 server id 1 end_log_pos 100 CRC32 0x1234 Query thread_id=7 error_code=0\n'
            'use wpcg_qual_a;\n'
            '# at 100\n#260101 00:00:01 server id 1 MALFORMED HEADER\n'
            'SET TIMESTAMP=1; USE another;\n'
            '# at 200\n#260101 00:00:02 server id 1 end_log_pos 300 CRC32 0x1234 Query thread_id=8 error_code=0\n'
            'CREATE DEFINER=u@% TRIGGER guard_x BEFORE INSERT',
            expected, 'wpcg_qual_a', 'u')
        assert not mysqlbinlog_logged_trigger_names(
            '# at 50\n#260101 00:00:00 server id 1 end_log_pos 100 CRC32 0x1234 Query thread_id=7 error_code=0\n'
            'use wpcg_qual_a;\n'
            '# at 100\n#260101 00:00:01 server id 1 MALFORMED HEADER\n'
            'use another;\n'
            '# at 200\n#260101 00:00:02 server id 1 end_log_pos 300 CRC32 0x1234 Query thread_id=8 error_code=0\n'
            'CREATE DEFINER=u@% TRIGGER guard_x BEFORE INSERT',
            expected, 'wpcg_qual_a', 'u')
        assert not mysqlbinlog_logged_trigger_names(
            '# at 100\n#260101 00:00:00 server id 1 end_log_pos 200 CRC32 0x1234 Query thread_id=7\n'
            'use wpcg_qual_a; CREATE TRIGGER guard_x BEFORE INSERT',
            expected, 'wpcg_qual_a', 'u')
        assert not mysqlbinlog_logged_trigger_names(
            '# at 100\n#260101 00:00:00 server id 1 end_log_pos 200 CRC32 0x1234 Table_map Query thread_id=7 error_code=0\n'
            'use wpcg_qual_a; CREATE TRIGGER guard_x BEFORE INSERT',
            expected, 'wpcg_qual_a', 'u')
        escaped_query = ('# at 100\n#260101 00:00:00 server id 1 end_log_pos 200 CRC32 0x1234 Query thread_id=7 error_code=0\n'
                         'use wpcg_qual_a;\n\\CREATE TRIGGER guard_x BEFORE INSERT')
        assert mysqlbinlog_logged_trigger_names(
            escaped_query, expected, 'wpcg_qual_a', 'u') == {'guard_x'}
        assert mysqlbinlog_logged_trigger_names(
            '# at 100\n#260101 00:00:00 server id 1 end_log_pos 200 CRC32 0x1234 Query thread_id=7 error_code=0\nuse wpcg_qual_a;\n'
            '# at 200\n#260101 00:00:01 server id 1 end_log_pos 200 CRC32 0x1234 Query thread_id=8 error_code=0\n'
            'SET TIMESTAMP=2; CREATE DEFINER=u@% TRIGGER guard_x',
            expected, 'wpcg_qual_a', 'u') == {'guard_x'}
        assert not mysqlbinlog_logged_trigger_names(
            '# at 100\n#260101 00:00:00 server id 1 end_log_pos 200 CRC32 0x1234 Query thread_id=7 error_code=0\nuse another;\n'
            '# at 200\n#260101 00:00:01 server id 1 end_log_pos 200 CRC32 0x1234 Query thread_id=8 error_code=0\n'
            'SET TIMESTAMP=2; CREATE DEFINER=u@% TRIGGER guard_x',
            expected, 'wpcg_qual_a', 'u')
        assert not mysqlbinlog_logged_trigger_names(
            '# at 200\n#260101 00:00:01 server id 1 end_log_pos 200 CRC32 0x1234 Query thread_id=8 error_code=0\n'
            'SET TIMESTAMP=2; CREATE DEFINER=u@% TRIGGER guard_x',
            expected, 'wpcg_qual_a', 'u')
        assert not logged_trigger_names(
            'Query', "INSERT INTO x VALUES ('\\nCREATE DEFINER=u@% TRIGGER guard_x')",
            expected, 'wpcg_qual_a', 'u')
        assert not logged_trigger_names(
            'Query', 'DROP TRIGGER guard_x', expected, 'wpcg_qual_a', 'u')
        assert logged_trigger_names(
            'Query', 'CREATE TRIGGER guard_x BEFORE INSERT', expected,
            'wpcg_qual_a', 'u') == {'guard_x'}
        assert logged_trigger_names(
            'Query', 'CREATE TRIGGER `guard_x` BEFORE INSERT', expected,
            'wpcg_qual_a', 'u') == {'guard_x'}
        assert logged_trigger_names(
            'Query', "CREATE TRIGGER 'guard_x' BEFORE INSERT", expected,
            'wpcg_qual_a', 'u') == {'guard_x'}
        assert logged_trigger_names(
            'Query', 'C utf8mb4 ; SET @@session.character_set_client=33; '
            'CREATE DEFINER=`u`@`%` TRIGGER `guard_x` BEFORE INSERT',
            expected, 'wpcg_qual_a', 'u') == {'guard_x'}
        assert logged_trigger_names(
            'Query', r'\C utf8mb4; CREATE TRIGGER guard_x BEFORE INSERT',
            expected, 'wpcg_qual_a', 'u') == {'guard_x'}
        assert not logged_trigger_names(
            'Query', r'\C latin1; CREATE TRIGGER guard_x BEFORE INSERT',
            expected, 'wpcg_qual_a', 'u')
        # Binlog spelling is syntax evidence only; final object metadata verifies the DBA.
        assert logged_trigger_names(
            'Query', 'CREATE DEFINER=other@% TRIGGER guard_x BEFORE INSERT', expected,
            'wpcg_qual_a', 'u') == {'guard_x'}
        assert logged_trigger_names(
            'Query', 'CREATE DEFINER=CURRENT_USER TRIGGER guard_x BEFORE INSERT', expected,
            'wpcg_qual_a', 'u') == {'guard_x'}
        for false_ddl in (
            'CREATE DEFINER=u@% PROCEDURE p() BEGIN DROP TRIGGER guard_x; END',
            'CREATE DEFINER=u@% EVENT e DO DROP TRIGGER guard_x',
            "CREATE DEFINER=u@% VIEW v AS SELECT 'TRIGGER guard_x'",
            'SELECT 1; CREATE TRIGGER guard_x BEFORE INSERT',
            'SELECT 1 /* ; CREATE TRIGGER guard_x */',
            '-- comment ; CREATE TRIGGER guard_x\nSELECT 1',
            '# comment ; CREATE TRIGGER guard_x\nSELECT 1',
        ):
            assert not logged_trigger_names(
                'Query', false_ddl, expected, 'wpcg_qual_a', 'u')
        assert logged_trigger_names(
            'Query', '/* comment ; CREATE PROCEDURE p() */ '
            'CREATE TRIGGER guard_x BEFORE INSERT', expected,
            'wpcg_qual_a', 'u') == {'guard_x'}
        assert logged_trigger_names(
            'Query', '; /* comment */ ;; CREATE TRIGGER guard_x BEFORE INSERT ;;',
            expected, 'wpcg_qual_a', 'u') == {'guard_x'}
        assert not logged_trigger_names(
            'Query', "SET @@session.sql_mode='x; CREATE TRIGGER guard_x'",
            expected, 'wpcg_qual_a', 'u')
        assert logged_trigger_names(
            'Query', "SET @@session.sql_mode='x;y'; CREATE TRIGGER guard_x BEFORE INSERT",
            expected, 'wpcg_qual_a', 'u') == {'guard_x'}
        assert logged_trigger_names(
            'Query', "SET @@session.sql_mode='STRICT,NO_ZERO_DATE', "
            "@@session.time_zone='+00:00'; CREATE TRIGGER guard_x BEFORE INSERT",
            expected, 'wpcg_qual_a', 'u') == {'guard_x'}
        assert not logged_trigger_names(
            'Query', 'SET @@session.sql_mode=1, @@global.log_bin_trust_function_creators=1; '
            'CREATE TRIGGER guard_x BEFORE INSERT', expected, 'wpcg_qual_a', 'u')
        assert not logged_trigger_names(
            'Query', 'SET TIMESTAMP=1, @@global.log_bin_trust_function_creators=1; '
            'CREATE TRIGGER guard_x BEFORE INSERT', expected, 'wpcg_qual_a', 'u')
        assert not logged_trigger_names(
            'Query', 'SET @marker=1; CREATE TRIGGER guard_x BEFORE INSERT',
            expected, 'wpcg_qual_a', 'u')
        assert not logged_trigger_names('Query', 'use wpcg_qual_a; DROP TRIGGER guard_x',
                                        expected, 'wpcg_qual_a', 'u')
        assert not logged_trigger_names('Query', 'use another; CREATE DEFINER=u@% TRIGGER guard_x',
                                        expected, 'wpcg_qual_a', 'u')
        # Binlog matching proves that this DDL name was logged; a separate
        # information_schema contract verifies the installed definer.
        assert logged_trigger_names('Query', 'use wpcg_qual_a; CREATE DEFINER=other@% TRIGGER guard_x',
                                    expected, 'wpcg_qual_a', 'u') == {'guard_x'}
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
RESTORE_HOST = os.environ.get('WPCG_QUAL_RESTORE_HOST', '')
RESTORE_PORT = os.environ.get('WPCG_QUAL_RESTORE_PORT', '')
RESTORE_ROOT_PW = os.environ.get('WPCG_QUAL_RESTORE_ROOT_PASSWORD', '')
MYSQL = shutil.which('mysql')
MYSQLBINLOG = shutil.which('mysqlbinlog')
MYSQLDUMP = shutil.which('mysqldump')
PHP = shutil.which('php')


def child_environment():
    return {key: value for key, value in os.environ.items()
            if key not in ('WPCG_QUAL_MYSQL_ROOT_PASSWORD', 'WPCG_QUAL_RESTORE_ROOT_PASSWORD')}


def run_mysql(user, password, sql, schema=None, host=None, port=None):
    host, port = host or HOST, port or PORT
    cmd = [MYSQL, '--protocol=TCP', '--connect-timeout=5', '--host', host, '--port', port,
           f'--user={user}', '--batch', '--skip-column-names']
    if schema:
        cmd.append(schema)
    cmd += ['--execute', sql]
    child_env = child_environment()
    r = subprocess.run(cmd, env={**child_env, 'MYSQL_PWD': password}, stdin=subprocess.DEVNULL,
                       stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=60)
    return r.returncode, mysql_output(r.stdout), classify(r.stderr)


def admin(sql, schema=None, host=None, port=None, root_password=None):
    password = ROOT_PW if root_password is None else root_password
    rc, out, _ = run_mysql('root', password, sql, schema, host, port)
    if rc:
        raise RuntimeError('mysql_admin_operation_failed')
    return out


def artisan(user, password, schema, *args, timeout=900, phase2b_definer='', host=None, port=None):
    env = {**child_environment(), 'DB_CONNECTION': 'mysql', 'DB_HOST': host or HOST, 'DB_PORT': port or PORT, 'DB_DATABASE': schema,
           'DB_USERNAME': user, 'DB_PASSWORD': password, 'DB_URL': '', 'APP_KEY': APP_KEY,
           'DB_PHASE2B_TRIGGER_DEFINER': phase2b_definer,
           'CACHE_STORE': 'array', 'SESSION_DRIVER': 'array', 'QUEUE_CONNECTION': 'sync',
           'MAIL_MAILER': 'array', 'APP_ENV': 'testing'}
    r = subprocess.run([PHP, 'artisan', *args, '--no-interaction'], cwd=ROOT, env=env,
                       stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                       text=True, timeout=timeout)
    text = r.stdout + '\n' + r.stderr
    return r.returncode, text, classify(text)


def run_up(user, password, schema, migration_file, phase2b_definer='', host=None, port=None):
    code = ('require "vendor/autoload.php";$app=require "bootstrap/app.php";'
            '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
            '$m=require getenv("WPCG_QUAL_MIGRATION");$m->up();echo "OK";')
    env = {**child_environment(), 'DB_CONNECTION': 'mysql', 'DB_HOST': host or HOST, 'DB_PORT': port or PORT, 'DB_DATABASE': schema,
           'DB_USERNAME': user, 'DB_PASSWORD': password, 'DB_URL': '', 'APP_KEY': APP_KEY,
           'DB_PHASE2B_TRIGGER_DEFINER': phase2b_definer,
           'CACHE_STORE': 'array', 'SESSION_DRIVER': 'array', 'APP_ENV': 'testing',
           'WPCG_QUAL_MIGRATION': str(MIGRATIONS / migration_file)}
    r = subprocess.run([PHP, '-r', code], cwd=ROOT, env=env, stdin=subprocess.DEVNULL,
                       stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=120)
    text = r.stdout + '\n' + r.stderr
    return r.returncode, classify(text), phase2b_failure_code(text)


def synthetic_restore_rehearsal(schema, app, app_pw, dba, runtime, runtime_pw, stems, source_rows, workdir):
    """Dump/import synthetic schema into a second trust-OFF MySQL, then prove guards still execute."""
    if not (RESTORE_HOST and RESTORE_PORT and RESTORE_ROOT_PW and MYSQLDUMP):
        return require_contract('synthetic_restore_prerequisites', False,
                                'second_mysql_service_or_mysqldump_unavailable')
    restored = f'wpcg_restore_{secrets.token_hex(5)}'
    dump_path = Path(workdir) / 'synthetic-restore.sql'
    bad_path = Path(workdir) / 'synthetic-restore-corrupt.sql'
    accounts = [app, dba, runtime]
    passwords = {app: app_pw, dba: secrets.token_hex(16), runtime: runtime_pw}
    ok = True
    # A nonempty sentinel makes the restore's data-integrity checks meaningful.
    admin("INSERT INTO deployment_transition_fences (fence_key,state,operation_id,generation,boot_id,journal_seq,"
          "journal_head_hash,evidence_digest,created_at,updated_at,phase2b_state,phase2b_tuple_digest,"
          "phase2b_event_sequence) VALUES ('restore_sentinel','HOLD',"
          "'66666666-6666-4666-8666-666666666666',1,'77777777-7777-4777-8777-777777777777',1,"
          "REPEAT('c',64),REPEAT('d',64),NOW(),NOW(),'HOLD',"
          "'88888888-8888-4888-8888-888888888888',1)", schema)
    sentinel_sql = ("SELECT fence_key,state,operation_id,generation,boot_id,journal_seq,journal_head_hash,"
                    "evidence_digest,phase2b_state,phase2b_tuple_digest,phase2b_event_sequence "
                    "FROM deployment_transition_fences WHERE fence_key='restore_sentinel'")
    source_sentinel = admin(sentinel_sql, schema)
    ok &= require_contract('synthetic_restore_source_sentinel_present', bool(source_sentinel))
    source_ledger = ledger(schema)
    source_uuid = admin('SELECT @@GLOBAL.server_uuid')
    restore_uuid = admin('SELECT @@GLOBAL.server_uuid', host=RESTORE_HOST, port=RESTORE_PORT,
                         root_password=RESTORE_ROOT_PW)
    isolated_instances = bool(source_uuid and restore_uuid and source_uuid != restore_uuid)
    ok &= require_contract('restore_uses_distinct_mysql_instance', isolated_instances)
    if not isolated_instances:
        return False
    target_before = settings(RESTORE_HOST, RESTORE_PORT, RESTORE_ROOT_PW)
    service_ok = (target_before['version'] == '8.4.11' and target_before['log_bin'] == 'ON'
                  and target_before['format'] == 'ROW' and target_before['trust'] == 'OFF')
    ok &= require_contract('restore_service_contract_before', service_ok,
                           f"version={target_before['version']},log_bin={target_before['log_bin']},"
                           f"format={target_before['format']},trust={target_before['trust']}")
    if not service_ok:
        return False
    try:
        env = child_environment()
        env['MYSQL_PWD'] = ROOT_PW
        command = [MYSQLDUMP, '--protocol=TCP', f'--host={HOST}', f'--port={PORT}',
                   '--user=root', '--single-transaction', '--routines', '--triggers', '--events',
                   '--skip-comments', schema]
        dump = subprocess.run(command, env=env, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                              stderr=subprocess.PIPE, timeout=120, check=False)
        dump_stderr = dump.stderr.decode('utf-8', errors='replace')
        dump_sqlstate, dump_errno = classify(dump_stderr)
        if dump.returncode or not dump.stdout or len(dump.stdout) > 32 * 1024 * 1024:
            if len(dump.stdout) > 32 * 1024 * 1024:
                reason = 'dump_size_limit'
            elif dump.returncode:
                reason = dump_error_class(dump_stderr)
            else:
                reason = 'dump_empty'
            version_result = subprocess.run([MYSQLDUMP, '--version'], env=child_environment(), stdin=subprocess.DEVNULL,
                                            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                            timeout=10, check=False)
            version_text = (version_result.stdout + version_result.stderr).decode('utf-8', errors='replace')
            version_match = re.search(r'(?i)\b(Ver\s+[0-9][0-9A-Za-z.+-]*)', version_text)
            client_version = version_match.group(1).replace(' ', '') if version_match else 'unparsed'
            return require_contract('synthetic_backup_created', False,
                                    f'reason={reason},exit={dump.returncode},bytes={len(dump.stdout)},'
                                    f'stderr_bytes={len(dump.stderr)},stderr_sha256={hashlib.sha256(dump.stderr).hexdigest()},'
                                    f'client={client_version},sqlstate={dump_sqlstate},errno={dump_errno}')
        original = dump.stdout
        if re.search(rb'(?im)^\s*(?:CREATE\s+DATABASE|USE\s+)', original):
            return require_contract('synthetic_backup_schema_binding', False,
                                    'database_directives_unexpected_in_single_schema_dump')
        # mysqldump without --databases omits USE/CREATE DATABASE; prepend the
        # unique synthetic clone schema rather than rewriting SQL from the dump.
        original = f'USE `{restored}`;\n'.encode() + original
        ok &= require_contract('synthetic_backup_schema_binding',
                               original.startswith(f'USE `{restored}`;\n'.encode()))
        dump_path.write_bytes(original)
        backup_sha = hashlib.sha256(original).hexdigest()
        ok &= require_contract('synthetic_backup_created', True,
                               f'bytes={len(original)},sha256={backup_sha}')
        config_fingerprint = hashlib.sha256(
            json.dumps({'driver': 'mysql', 'database': restored, 'app_key': APP_KEY,
                        'app_user': app, 'definer': f'{dba}@%'}, sort_keys=True).encode()).hexdigest()
        record('synthetic_restore_application_config_identity', 'OBSERVED',
               f'driver=mysql,config_sha256={config_fingerprint}')
        definer_tokens = len(re.findall(rb'(?i)\bDEFINER\s*=', original))
        ok &= require_contract('synthetic_backup_contains_expected_definers',
                               definer_tokens == len(source_rows)
                               and all(row[5] == f'{dba}@%' for row in source_rows),
                               f'count={definer_tokens},expected={len(source_rows)}')

        # The same receipt validator used on the import path rejects a modified copy.
        bad_path.write_bytes(original[:-1] + bytes([original[-1] ^ 1]))
        corrupted_detected = not sha256_matches(bad_path.read_bytes(), backup_sha)
        ok &= require_contract('corrupt_backup_fails_integrity_check_before_restore', corrupted_detected)
        if not corrupted_detected:
            return False

        backup_intact = file_sha256_matches(dump_path, backup_sha)
        ok &= require_contract('synthetic_backup_integrity_verified_before_import', backup_intact)
        if not backup_intact:
            return False
        import_payload = dump_path.read_bytes()
        backup_payload_valid = sha256_matches(import_payload, backup_sha)
        ok &= require_contract('synthetic_backup_import_payload_matches_receipt', backup_payload_valid)
        if not backup_payload_valid:
            return False

        admin(f'CREATE DATABASE `{restored}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
              host=RESTORE_HOST, port=RESTORE_PORT, root_password=RESTORE_ROOT_PW)
        for account, password in passwords.items():
            admin(f"CREATE USER '{account}'@'%' IDENTIFIED BY '{password}'",
                  host=RESTORE_HOST, port=RESTORE_PORT, root_password=RESTORE_ROOT_PW)
        restore_env = child_environment()
        restore_env['MYSQL_PWD'] = RESTORE_ROOT_PW
        imported = subprocess.run([MYSQL, '--protocol=TCP', '--connect-timeout=5',
                                    f'--host={RESTORE_HOST}', f'--port={RESTORE_PORT}', '--user=root',
                                    '--binary-mode', '--default-character-set=utf8mb4'],
                                   env=restore_env, input=import_payload, stdout=subprocess.PIPE,
                                   stderr=subprocess.PIPE, timeout=180, check=False)
        ok &= require_contract('synthetic_restore_imported', imported.returncode == 0,
                               'mysql_import_succeeded' if imported.returncode == 0 else 'mysql_import_failed')
        if imported.returncode:
            return False

        restored_rows = trigger_rows(restored, RESTORE_HOST, RESTORE_PORT, RESTORE_ROOT_PW)
        ok &= require_contract('restored_trigger_inventory_matches', restored_rows == source_rows,
                               f'count={len(restored_rows)},expected={len(source_rows)}')
        restored_ledger = ledger(restored, RESTORE_HOST, RESTORE_PORT, RESTORE_ROOT_PW)
        source_ledger_is_prefix = (len(source_ledger) < len(stems)
                                   and source_ledger == stems[:len(source_ledger)])
        ok &= require_contract('restored_partial_migration_ledger_matches',
                               restored_ledger == source_ledger and source_ledger_is_prefix,
                               f'ledger={len(restored_ledger)},source={len(source_ledger)},target={len(stems)}')
        restored_sentinel = admin(sentinel_sql, restored, RESTORE_HOST, RESTORE_PORT, RESTORE_ROOT_PW)
        ok &= require_contract('restored_synthetic_sentinel_matches_source',
                               restored_sentinel == source_sentinel)
        source_counts = table_counts(schema)
        target_counts = table_counts(restored, RESTORE_HOST, RESTORE_PORT, RESTORE_ROOT_PW)
        ok &= require_contract('restored_table_row_counts_match_source', target_counts == source_counts,
                               f'tables={len(target_counts)},source_tables={len(source_counts)}')
        target_after = settings(RESTORE_HOST, RESTORE_PORT, RESTORE_ROOT_PW)
        ok &= require_contract('restore_service_contract_after_trust_off_unchanged',
                               target_after == target_before and target_after['trust'] == 'OFF',
                               f"trust={target_after['trust']},log_bin={target_after['log_bin']}")

        admin(f"GRANT {APP_GRANTS} ON `{restored}`.* TO '{app}'@'%';",
              host=RESTORE_HOST, port=RESTORE_PORT, root_password=RESTORE_ROOT_PW)
        admin(f"GRANT {RUNTIME_GRANTS} ON `{restored}`.* TO '{runtime}'@'%';",
              host=RESTORE_HOST, port=RESTORE_PORT, root_password=RESTORE_ROOT_PW)
        for table in ('deployment_transition_fences', 'transition_v2_requests', 'transition_history_events'):
            admin(f"GRANT SELECT ON `{restored}`.`{table}` TO '{dba}'@'%';",
                  host=RESTORE_HOST, port=RESTORE_PORT, root_password=RESTORE_ROOT_PW)
        admin(f"GRANT TRIGGER ON `{restored}`.* TO '{dba}'@'%';",
              host=RESTORE_HOST, port=RESTORE_PORT, root_password=RESTORE_ROOT_PW)
        rc, _, (_, errno) = artisan(app, passwords[app], restored, 'migrate', '--force',
                                    phase2b_definer=f'{dba}@%', host=RESTORE_HOST, port=RESTORE_PORT)
        ok &= require_contract('restored_target_migrations_complete',
                               rc == 0 and ledger(restored, RESTORE_HOST, RESTORE_PORT,
                                                  RESTORE_ROOT_PW) == stems,
                               f'errno={errno},ledger={len(ledger(restored, RESTORE_HOST, RESTORE_PORT, RESTORE_ROOT_PW))},'
                               f'expected={len(stems)}')
        restored_rows = trigger_rows(restored, RESTORE_HOST, RESTORE_PORT, RESTORE_ROOT_PW)
        ok &= require_contract('restored_trigger_inventory_matches', restored_rows == source_rows,
                               f'count={len(restored_rows)},expected={len(source_rows)}')
        admin(f"ALTER USER '{dba}'@'%' ACCOUNT LOCK;",
              host=RESTORE_HOST, port=RESTORE_PORT, root_password=RESTORE_ROOT_PW)
        ok &= require_contract('restored_definer_has_no_global_privilege',
                               global_grants_clean(dba, RESTORE_HOST, RESTORE_PORT, RESTORE_ROOT_PW))
        ok &= require_contract('restored_app_and_runtime_have_no_global_privilege',
                               global_grants_clean(app, RESTORE_HOST, RESTORE_PORT, RESTORE_ROOT_PW)
                               and global_grants_clean(runtime, RESTORE_HOST, RESTORE_PORT, RESTORE_ROOT_PW))

        rc, _, failure = run_up(app, passwords[app], restored,
                                '2026_11_15_000001_verify_phase2b_trigger_definers.php',
                                f'{dba}@%', RESTORE_HOST, RESTORE_PORT)
        ok &= require_contract('restored_schema_verifier_accepts_definer', rc == 0, f'failure={failure}')
        rc, output, _ = artisan(app, passwords[app], restored, 'migrate', '--force',
                                phase2b_definer=f'{dba}@%', host=RESTORE_HOST, port=RESTORE_PORT)
        ok &= require_contract('restored_migrations_are_idempotent', rc == 0 and 'Nothing to migrate' in output,
                               'retry_completed_without_schema_changes')

        # Prove the locked, unprivileged DEFINER still executes against restored synthetic rows.
        op, tup, digest = ('11111111-1111-4111-8111-111111111111', '33333333-3333-4333-8333-333333333333', 'a' * 64)
        admin("INSERT INTO deployment_transition_fences (fence_key,state,operation_id,generation,boot_id,journal_seq,"
              "journal_head_hash,evidence_digest,created_at,updated_at,phase2b_state,phase2b_tuple_digest,"
              "phase2b_event_sequence) VALUES ('global','HOLD','11111111-1111-4111-8111-111111111111',1,"
              "'22222222-2222-4222-8222-222222222222',1,REPEAT('a',64),REPEAT('b',64),NOW(),NOW(),'HOLD',"
              "'33333333-3333-4333-8333-333333333333',1)", restored, RESTORE_HOST, RESTORE_PORT, RESTORE_ROOT_PW)
        request = ("INSERT INTO transition_v2_requests (request_id, request_digest, operation, operation_id, generation,"
                   " source_tuple_digest, target_tuple_digest, history_sequence, receipt_payload, receipt_digest,"
                   f" committed_at, created_at, updated_at) VALUES ('55555555-5555-4555-8555-555555555555','{digest}',"
                   f"'compare_and_set_hold','{op}',1,NULL,'{tup}',1,'{{}}','{'b' * 64}',NOW(6),NOW(6),NOW(6))")
        rc, _, (_, errno) = run_mysql(runtime, passwords[runtime], request, restored, RESTORE_HOST, RESTORE_PORT)
        ok &= require_contract('restored_locked_definer_accepts_runtime_insert', rc == 0, f'errno={errno}')
        history = ("INSERT INTO transition_history_events (event_sequence,operation_id,generation,request_id,"
                   "request_digest,event_type,source_tuple_digest,target_tuple_digest,occurred_at,created_at,updated_at) "
                   f"VALUES (1,'{op}',1,'55555555-5555-4555-8555-555555555555','{digest}',"
                   f"'HOLD_TUPLE_COMMITTED',NULL,'{tup}',NOW(6),NOW(6),NOW(6))")
        rc, _, (_, errno) = run_mysql(runtime, passwords[runtime], history, restored,
                                      RESTORE_HOST, RESTORE_PORT)
        ok &= require_contract('restored_locked_definer_accepts_history_insert', rc == 0, f'errno={errno}')
        rc, _, (_, errno) = run_mysql(runtime, passwords[runtime],
                                      'UPDATE transition_v2_requests SET receipt_digest=receipt_digest', restored,
                                      RESTORE_HOST, RESTORE_PORT)
        ok &= require_contract('restored_runtime_guard_rejects_update', rc != 0 and errno == '1644', f'errno={errno}')
        ok &= require_contract('restored_runtime_cannot_drop_trigger',
                               run_mysql(runtime, passwords[runtime],
                                         'DROP TRIGGER transition_v2_requests_immutable_insert', restored,
                                         RESTORE_HOST, RESTORE_PORT)[0] != 0)
        verify_count = admin(f"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{restored}'",
                             host=RESTORE_HOST, port=RESTORE_PORT, root_password=RESTORE_ROOT_PW)
        ok &= require_contract('restored_schema_inventory_present', int(verify_count) > 0,
                               f'tables={verify_count}')
        record('synthetic_restore_receipt', 'OBSERVED',
               f'backup_sha256={backup_sha},bytes={len(original)},source=synthetic_only')
        return bool(ok)
    finally:
        for sql in (f'DROP DATABASE IF EXISTS `{restored}`',
                    *(f"DROP USER IF EXISTS '{account}'@'%'" for account in accounts)):
            try:
                admin(sql, host=RESTORE_HOST, port=RESTORE_PORT, root_password=RESTORE_ROOT_PW)
            except Exception:  # noqa: BLE001
                pass
        try:
            remaining = admin("SELECT (SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'wpcg\\_restore%'),"
                              "(SELECT COUNT(*) FROM mysql.user WHERE user LIKE 'wpcg\\_qual%')",
                              host=RESTORE_HOST, port=RESTORE_PORT, root_password=RESTORE_ROOT_PW)
            require_contract('synthetic_restore_cleanup_complete', remaining == '0\t0')
        except Exception:  # noqa: BLE001
            record('synthetic_restore_cleanup_complete', 'FAIL', 'readback_failed')


def settings(host=None, port=None, root_password=None):
    row = admin('SELECT VERSION(), @@GLOBAL.log_bin, @@GLOBAL.binlog_format, '
                '@@GLOBAL.log_bin_trust_function_creators', host=host, port=port,
                root_password=root_password).split('\t')
    if len(row) != 4:
        raise RuntimeError('settings_unreadable')
    version, log_bin, fmt, trust = row
    return {'version': version, 'log_bin': 'ON' if log_bin in ('1', 'ON') else 'OFF',
            'format': fmt.upper(), 'trust': 'ON' if trust in ('1', 'ON') else 'OFF'}


def require_contract(label, ok, detail=''):
    record(label, 'PASS' if ok else 'FAIL', detail)
    return bool(ok)


def global_grants_clean(account, host=None, port=None, root_password=None):
    lines = admin(f"SHOW GRANTS FOR '{account}'@'%'", host=host, port=port,
                  root_password=root_password).splitlines()
    return clean_global_grants(lines)


def ledger(schema, host=None, port=None, root_password=None):
    out = admin(f'SELECT migration FROM `{schema}`.`migrations` ORDER BY migration', host=host,
                port=port, root_password=root_password)
    return out.splitlines() if out else []


def table_counts(schema, host=None, port=None, root_password=None):
    names = admin(f"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='{schema}' "
                  'AND TABLE_TYPE="BASE TABLE" ORDER BY TABLE_NAME', host=host, port=port,
                  root_password=root_password).splitlines()
    if not names or any(re.fullmatch(r'[A-Za-z0-9_]{1,64}', name) is None for name in names):
        raise RuntimeError('restore_table_inventory_invalid')
    query = ' UNION ALL '.join(f"SELECT '{name}' AS table_name, COUNT(*) AS row_count "
                               f"FROM `{schema}`.`{name}`" for name in names) + ' ORDER BY 1'
    rows = admin(query, host=host, port=port, root_password=root_password).splitlines()
    return [tuple(row.split('\t')) for row in rows]


def trigger_rows(schema, host=None, port=None, root_password=None):
    out = admin("SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_ORIENTATION, "
                f"DEFINER, SHA2(ACTION_STATEMENT,256) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='{schema}' "
                'ORDER BY TRIGGER_NAME', host=host, port=port, root_password=root_password)
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

    env = child_environment()
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
    record('mysqlbinlog_token_counts', 'OBSERVED',
           f"create={len(re.findall(r'\bCREATE\b', log_text, re.IGNORECASE))},"
           f"trigger={len(re.findall(r'\bTRIGGER\b', log_text, re.IGNORECASE))},"
           f"schema={log_text.upper().count(schema.upper())},definer={log_text.upper().count(dba.upper())}")
    matched = set()
    diagnostics = {name: {'events': 0, 'schema': 0, 'ddl': 0} for name in expected_names}
    for output in output_parts:
        file_text = re.sub(r'/\*!\d*\s*(.*?)\*/', r'\1', output, flags=re.DOTALL)
        file_matches, file_stats = mysqlbinlog_trigger_match_diagnostics(
            file_text, expected_names, schema, dba,
        )
        matched.update(file_matches)
        for name in expected_names:
            for key in ('events', 'schema', 'ddl'):
                diagnostics[name][key] += file_stats[name][key]
            if file_stats[name].get('reason'):
                diagnostics[name]['reason'] = file_stats[name]['reason']
    for name in sorted(expected_names):
        detail = ','.join(f'{key}={diagnostics[name].get(key, 0)}' for key in
                          ('events', 'schema', 'ddl'))
        if name not in matched:
            detail += f",reason={diagnostics[name].get('reason', 'no_matching_event')}"
        record(f'mysqlbinlog_match_{name}', 'PASS' if name in matched else 'FAIL', detail)
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
    if not (ROOT_PW and PHP and MYSQL and MYSQLBINLOG and MYSQLDUMP):
        record('prerequisites', 'FAIL', 'php_mysql_client_or_dump_tool_or_root_password_missing')
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
        tracked = subprocess.run(['git', 'ls-files', '--', 'database/migrations'], cwd=ROOT,
                                 env=child_environment(), text=True,
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
                rc, _, (_, errno) = artisan(dba, dba_pw, schema, 'migrate', '--force', '--realpath', f'--path={only}',
                                            phase2b_definer=f'{dba}@%')
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

            # 7. Restore the interrupted migration ledger into the isolated clone.
            if rc == 0:
                ok &= synthetic_restore_rehearsal(schema, app, app_pw, dba, runtime, runtime_pw,
                                                  stems, trigger_rows(schema), tmp)

        # 8. application account finishes everything else
        rc, output, (state, errno) = artisan(app, app_pw, schema, 'migrate', '--force',
                                             phase2b_definer=f'{dba}@%')
        ok &= require_contract('app_completes_remaining_migrations', rc == 0,
                               f'sqlstate={state},errno={errno},phase2b={phase2b_failure_code(output)}')

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

        # 9a. the forward-only definer gate fails closed before accepting a wrong or missing identity.
        definer_gate = '2026_11_15_000001_verify_phase2b_trigger_definers.php'
        rc, (state, errno), failure = run_up(app, app_pw, schema, definer_gate)
        ok &= require_contract('phase2b_definer_missing_config_rejected',
                               rc != 0 and failure == 'DEFINER_CONFIG_MISSING',
                               f'sqlstate={state},errno={errno},reason={failure}')
        rc, (state, errno), failure = run_up(app, app_pw, schema, definer_gate, 'wpcg_wrong_definer@%')
        ok &= require_contract('phase2b_wrong_definer_rejected',
                               rc != 0 and failure == 'DEFINER_MISMATCH',
                               f'sqlstate={state},errno={errno},reason={failure}')
        rc, _, failure = run_up(app, app_pw, schema, definer_gate, f'{dba}@%')
        ok &= require_contract('phase2b_expected_definer_accepted', rc == 0, f'failure={failure}')

        # 10. the app account can idempotently verify the existing trigger definitions without SUPER.
        for n in trig:
            rc, _, _ = run_up(app, app_pw, schema, n, f'{dba}@%')
            ok &= require_contract('up_rerun_is_idempotent_as_app', rc == 0 and trigger_rows(schema) == rows, n[:-4])
        rc, text, _ = artisan(app, app_pw, schema, 'migrate', '--force', phase2b_definer=f'{dba}@%')
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
    ok &= all(status != 'FAIL' for _, status in RESULTS)
    print(f'WPCG_QUAL|summary|harness={"PASS" if ok else "FAIL"}|migration_privilege_result={path_label}', flush=True)
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
