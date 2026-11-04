#!/usr/bin/env python3
"""Preflight and restore the one pinned staging dump into a disposable schema.

This is deliberately separate from the interrupted-release recovery controller.
It never writes the deployed application database, changes MySQL globals, or
removes a disposable schema.  Raw SQL exists only in process memory and the
mysql client's stdin.
"""

import argparse
import hashlib
import json
import os
import re
import shutil
import socket
import stat
import struct
import subprocess
import sys
import tempfile
import threading
import zlib
from dataclasses import dataclass
from pathlib import Path


APPLICATION_SHA = "ee3d29818ff1647d82087446dfaf017ca688cb77"
RECOVERY_TOOL_SHA = "0c0a30f28c72c0602b9b5e665ac4da4d9328e474"
BACKUP_PATH = Path("/home/deploy/private-backups/wpcaregrid-pre-deploy-20261104T133855Z.sql.gz")
BACKUP_SHA256 = "5a2cce05c8a0cc44cfa76be3619f86bcdb748793dd6fed098fd8ef349b02c431"
MYSQL_UUID_SHA256 = "bc35682d80f0894c2c2c2879552fb8fe13dc2a530868d79d817a6280e31bde1c"
APP_PATH = Path("/var/www/wpcaregrid")
MYSQL = "/usr/bin/mysql"
MYSQL_SERVICE = "mysql.service"
MYSQL_SERVER_EXECUTABLE = "/usr/sbin/mysqld"
MAX_SQL_BYTES = 32 * 1024 * 1024
MAX_SQL_LINES = 250_000
MAX_SQL_TOKENS = 250_000
TARGET_RE = re.compile(r"wpcg_recovery_restore_[0-9]{8}_[0-9a-f]{8}\Z")
IDENT_RE = re.compile(r"[A-Za-z0-9_]+\Z")

CREATE_DB_RE = re.compile(
    rb"CREATE\s+DATABASE\s+"
    rb"(?:/\*!\d{5,6}\s+IF\s+NOT\s+EXISTS\s*\*/\s*)?"
    rb"`?wpcaregrid`?\s+"
    rb"/\*!\d{5,6}\s+DEFAULT\s+CHARACTER\s+SET\s+([A-Za-z0-9_]+)\s+"
    rb"COLLATE\s+([A-Za-z0-9_]+)\s*\*/\s*;\Z",
    re.IGNORECASE,
)
USE_DB_RE = re.compile(rb"USE\s+`?wpcaregrid`?\s*;\Z", re.IGNORECASE)


class RestoreRefused(Exception):
    """Fixed-code refusal; messages must never contain dump or DB values."""


def refuse(code):
    raise RestoreRefused(code)


@dataclass(frozen=True, slots=True)
class Token:
    kind: str
    value: str
    line: int


def _tokens(data, base_line=1):
    """Tokenize executable SQL while ignoring strings and ordinary comments."""
    i = 0
    line = base_line
    n = len(data)
    while i < n:
        c = data[i]
        if c in b" \t\r\n\f":
            if c == 10:
                line += 1
            i += 1
            continue
        if data.startswith(b"--", i) and (i + 2 == n or data[i + 2] <= 32):
            end = data.find(b"\n", i)
            if end < 0:
                return
            i = end + 1
            line += 1
            continue
        if c == 35:  # # comment
            end = data.find(b"\n", i)
            if end < 0:
                return
            i = end + 1
            line += 1
            continue
        if data.startswith(b"/*", i):
            end = data.find(b"*/", i + 2)
            if end < 0:
                refuse("DUMP_COMMENT_UNTERMINATED")
            if data.startswith(b"/*!", i):
                inner = data[i + 3:end]
                inner_line = line
                inner = re.sub(rb"^\s*[0-9]{5,6}\s*", b"", inner, count=1)
                yield from _tokens(inner, inner_line)
            line += data[i:end + 2].count(b"\n")
            i = end + 2
            continue
        if c in (39, 34):  # SQL string/double-quoted token; contents are not executable keywords
            start = i
            start_line = line
            quote = c
            i += 1
            while i < n:
                if data[i] == 92:  # backslash escape
                    i += 2
                    continue
                if data[i] == quote:
                    if i + 1 < n and data[i + 1] == quote:
                        i += 2
                        continue
                    i += 1
                    break
                if data[i] == 10:
                    line += 1
                i += 1
            else:
                refuse("DUMP_STRING_UNTERMINATED")
            yield Token("STRING", data[start:i].decode("utf-8"), start_line)
            continue
        if c == 96:  # backtick identifier
            start_line = line
            i += 1
            out = bytearray()
            while i < n:
                if data[i] == 96:
                    if i + 1 < n and data[i + 1] == 96:
                        out.append(96)
                        i += 2
                        continue
                    i += 1
                    break
                if data[i] == 10:
                    line += 1
                out.append(data[i])
                i += 1
            else:
                refuse("DUMP_IDENTIFIER_UNTERMINATED")
            try:
                value = out.decode("ascii")
            except UnicodeDecodeError:
                refuse("DUMP_IDENTIFIER_UNSUPPORTED")
            yield Token("IDENT", value, start_line)
            continue
        if (65 <= c <= 90) or (97 <= c <= 122) or c in (95, 36):
            start = i
            start_line = line
            i += 1
            while i < n and (
                65 <= data[i] <= 90 or 97 <= data[i] <= 122
                or 48 <= data[i] <= 57 or data[i] in (95, 36)
            ):
                i += 1
            yield Token("WORD", data[start:i].decode("ascii"), start_line)
            continue
        if 48 <= c <= 57:
            start = i
            start_line = line
            i += 1
            while i < n and (48 <= data[i] <= 57 or data[i] in (46, 95)):
                i += 1
            yield Token("NUMBER", data[start:i].decode("ascii"), start_line)
            continue
        yield Token("SYMBOL", chr(c), line)
        i += 1


def _word(token):
    return token.value.upper() if token and token.kind == "WORD" else ""


def _id(token):
    return token.value.lower() if token and token.kind in {"WORD", "IDENT"} else ""


def bounded_tokens(data):
    result = []
    for token in _tokens(data):
        if len(result) >= MAX_SQL_TOKENS:
            refuse("DUMP_TOKEN_LIMIT")
        result.append(token)
    return result


def validate_mysql_client_directives(sql):
    for comment in re.finditer(rb"/\*([!+])(.*?)\*/", sql, re.DOTALL):
        payload = comment.group(2)
        if comment.group(1) == b"!":
            payload = re.sub(rb"^\s*[0-9]{5,6}\s*", b"", payload, count=1)
        if re.search(rb"\\[A-Za-z]", payload) or re.match(rb"(?i)^\s*(?:DELIMITER|CHARSET)\b", payload):
            refuse("MYSQL_CLIENT_COMMAND_UNSUPPORTED")
    active_delimiter = b";"
    for line in sql.splitlines():
        if re.match(rb"^\s*\\", line):
            refuse("MYSQL_CLIENT_COMMAND_UNSUPPORTED")
        if re.match(rb"(?i)^\s*(?:SOURCE|SYSTEM|CONNECT|TEE|NOTEE|PAGER|NOPAGER|PROMPT|CHARSET|STATUS|HELP|QUIT|EXIT|CLEAR|EDIT|EGO|GO|PRINT|REHASH|RESETCONNECTION|WARNINGS|NOWARNING)\b", line):
            refuse("MYSQL_CLIENT_COMMAND_UNSUPPORTED")
        if re.match(rb"(?i)^\s*DELIMITER\b", line):
            match = re.fullmatch(rb"\s*DELIMITER\s+(;;|//|\$\$|;)\s*", line, re.IGNORECASE)
            if not match:
                refuse("MYSQL_DELIMITER_UNSUPPORTED")
            requested = match.group(1)
            if active_delimiter == b";" and requested in {b";;", b"//", b"$$"}:
                active_delimiter = requested
            elif active_delimiter != b";" and requested == b";":
                active_delimiter = b";"
            else:
                refuse("MYSQL_DELIMITER_UNBALANCED")
    if active_delimiter != b";":
        refuse("MYSQL_DELIMITER_UNBALANCED")


def remove_safe_mysqldump_mode_restore(sql):
    lines = sql.splitlines(keepends=True)
    save_re = re.compile(
        rb"\s*/\*!\s*[0-9]{5,6}\s+SET\s+@OLD_SQL_MODE\s*=\s*@@(?:SESSION\s*\.\s*)?SQL_MODE\s*,\s*"
        rb"SQL_MODE\s*=\s*'NO_AUTO_VALUE_ON_ZERO'\s*\*/;\s*\Z", re.IGNORECASE
    )
    restore_re = re.compile(
        rb"\s*/\*!\s*[0-9]{5,6}\s+SET\s+SQL_MODE\s*=\s*@OLD_SQL_MODE\s*\*/;\s*\Z",
        re.IGNORECASE,
    )
    save_indexes, restore_indexes = [], []
    for index, line in enumerate(lines):
        content = line[:-2] if line.endswith(b"\r\n") else line[:-1] if line.endswith(b"\n") else line
        if save_re.fullmatch(content):
            save_indexes.append(index)
        if restore_re.fullmatch(content):
            restore_indexes.append(index)
    if restore_indexes:
        if len(restore_indexes) != 1 or len(save_indexes) != 1 or save_indexes[0] >= restore_indexes[0]:
            refuse("SQL_LEXICAL_MODE_UNSUPPORTED")
        index = restore_indexes[0]
        newline = b"\r\n" if lines[index].endswith(b"\r\n") else b"\n" if lines[index].endswith(b"\n") else b""
        lines[index] = newline
    return b"".join(lines)


def validate_lexical_sql_mode(tokens):
    for i, token in enumerate(tokens):
        if token.kind != "WORD" or token.value.upper() != "SQL_MODE" or i + 1 >= len(tokens):
            continue
        if tokens[i + 1].kind == "SYMBOL" and tokens[i + 1].value == ":":
            if i + 2 < len(tokens) and tokens[i + 2].kind == "SYMBOL" and tokens[i + 2].value == "=":
                refuse("SQL_LEXICAL_MODE_UNSUPPORTED")
            continue
        if tokens[i + 1].kind == "SYMBOL" and tokens[i + 1].value == "=":
            if i + 2 >= len(tokens) or tokens[i + 2].kind != "STRING" or tokens[i + 2].value != "'NO_AUTO_VALUE_ON_ZERO'":
                refuse("SQL_LEXICAL_MODE_UNSUPPORTED")


def audit_sql(sql, target_schema):
    if not TARGET_RE.fullmatch(target_schema):
        refuse("TARGET_SCHEMA_INVALID")
    try:
        sql.decode("utf-8", errors="strict")
    except UnicodeDecodeError:
        refuse("DUMP_ENCODING_INVALID")
    if b"\x00" in sql:
        refuse("DUMP_NUL_BYTE")
    line_breaks = sql.count(b"\r") + sql.count(b"\n") - sql.count(b"\r\n")
    if line_breaks + (1 if sql and sql[-1] not in b"\r\n" else 0) > MAX_SQL_LINES:
        refuse("DUMP_LINE_LIMIT")
    sql = remove_safe_mysqldump_mode_restore(sql)
    validate_mysql_client_directives(sql)

    tokens = bounded_tokens(sql)
    create_db = []
    use_db = []
    expected_tables = []
    forbidden = set()
    schema_refs = 0
    for i, token in enumerate(tokens):
        a, b = _word(token), _word(tokens[i + 1]) if i + 1 < len(tokens) else ""
        c = _word(tokens[i + 2]) if i + 2 < len(tokens) else ""
        d = _word(tokens[i + 3]) if i + 3 < len(tokens) else ""
        e = _word(tokens[i + 4]) if i + 4 < len(tokens) else ""
        if a == "CREATE" and b == "DATABASE":
            create_db.append((token.line, i))
        if a == "CREATE" and b == "SCHEMA":
            forbidden.add("DATABASE_SELECTOR_UNEXPECTED")
        if a == "ALTER" and b in {"DATABASE", "SCHEMA"}:
            forbidden.add("DATABASE_SELECTOR_UNEXPECTED")
        if a == "DROP" and b in {"DATABASE", "SCHEMA"}:
            forbidden.add("DATABASE_DROP")
        if a == "USE":
            use_db.append((token.line, _id(tokens[i + 1]) if i + 1 < len(tokens) else ""))
        if a == "CREATE" and b == "TABLE":
            table_index = i + 2
            if table_index + 2 < len(tokens) and [_word(tokens[j]) for j in range(table_index, table_index + 3)] == ["IF", "NOT", "EXISTS"]:
                table_index += 3
            if table_index >= len(tokens) or not _id(tokens[table_index]):
                forbidden.add("TABLE_DEFINITION_UNEXPECTED")
            else:
                expected_tables.append(tokens[table_index].value)
        if a == "SET" and b in {"PASSWORD", "ROLE"}:
            forbidden.add("PRIVILEGED_OR_SERVER_OPERATION")
        if a == "SET" and b == "DEFAULT" and c == "ROLE":
            forbidden.add("PRIVILEGED_OR_SERVER_OPERATION")
        if a == "SET":
            statement_end = i + 1
            while statement_end < len(tokens) and not (
                tokens[statement_end].kind == "SYMBOL" and tokens[statement_end].value == ";"
            ):
                statement_end += 1
            statement_tokens = tokens[i + 1:statement_end]
            scoped_system_variable = any(
                j + 2 < len(statement_tokens)
                and statement_tokens[j].kind == "SYMBOL" and statement_tokens[j].value == "@"
                and statement_tokens[j + 1].kind == "SYMBOL" and statement_tokens[j + 1].value == "@"
                and _word(statement_tokens[j + 2]) in {"GLOBAL", "PERSIST", "PERSIST_ONLY"}
                for j in range(len(statement_tokens))
            )
            if b in {"GLOBAL", "PERSIST", "PERSIST_ONLY"} or scoped_system_variable:
                forbidden.add("GLOBAL_SETTING")
            if any(_id(item) == "sql_log_bin" for item in tokens[i + 1:statement_end]):
                forbidden.add("REPLICATION_SESSION_SETTING")
        if a == "RESET" and b in {"MASTER", "REPLICA", "SLAVE", "PERSIST"}:
            forbidden.add("REPLICATION_OR_GLOBAL_STATE")
        if a == "RESET" and b == "BINARY" and c == "LOGS":
            forbidden.add("REPLICATION_OR_GLOBAL_STATE")
        if a == "PURGE" and b == "BINARY" and c == "LOGS":
            forbidden.add("REPLICATION_OR_GLOBAL_STATE")
        if a == "CHANGE" and b in {"MASTER", "REPLICATION"}:
            forbidden.add("REPLICATION_CONFIGURATION")
        if a in {"START", "STOP", "RESET"} and b in {"REPLICA", "SLAVE", "GROUP_REPLICATION"}:
            forbidden.add("REPLICATION_CONFIGURATION")
        if a in {"GTID_PURGED", "GTID_EXECUTED", "GTID_NEXT"} or (a == "GTID" and b in {"PURGED", "EXECUTED", "NEXT"}):
            forbidden.add("GTID_STATE")
        if a in {"GRANT", "REVOKE", "SHUTDOWN"}:
            forbidden.add("PRIVILEGED_OR_SERVER_OPERATION")
        if a == "CREATE" and b in {"USER", "ROLE", "EVENT", "SERVER", "TABLESPACE", "PROCEDURE", "FUNCTION", "AGGREGATE", "LOGFILE"}:
            forbidden.add("UNEXPECTED_SERVER_OBJECT")
        if a in {"ALTER", "DROP"} and b in {"USER", "ROLE", "EVENT", "SERVER", "TABLESPACE", "PROCEDURE", "FUNCTION", "LOGFILE"}:
            forbidden.add("UNEXPECTED_SERVER_OBJECT")
        if a in {"CREATE", "ALTER", "DROP"} and b == "UNDO" and c == "TABLESPACE":
            forbidden.add("UNEXPECTED_SERVER_OBJECT")
        if a == "RENAME" and b == "USER":
            forbidden.add("UNEXPECTED_SERVER_OBJECT")
        if a in {"CREATE", "ALTER", "DROP"} and b == "SPATIAL" and c == "REFERENCE" and d == "SYSTEM":
            forbidden.add("UNEXPECTED_SERVER_OBJECT")
        if a == "DEFINER":
            forbidden.add("DEFINER_UNSUPPORTED")
        if a in {"INSTALL", "UNINSTALL"} and b in {"PLUGIN", "COMPONENT"}:
            forbidden.add("PLUGIN_OPERATION")
        if a in {"CREATE", "ALTER", "DROP"} and b == "RESOURCE":
            forbidden.add("UNEXPECTED_SERVER_OBJECT")
        if a == "CREATE" and b == "TEMPORARY" and c == "TABLE":
            forbidden.add("TEMPORARY_TABLE_UNSUPPORTED")
        if a in {"CALL", "CLONE", "DO", "FLUSH", "KILL", "RESTART", "XA", "PREPARE", "EXECUTE", "DEALLOCATE"}:
            forbidden.add("UNEXPECTED_SERVER_OPERATION")
        if a in {"LOCK", "UNLOCK"} and b == "INSTANCE":
            forbidden.add("UNEXPECTED_SERVER_OPERATION")
        if a == "ALTER" and b == "INSTANCE":
            forbidden.add("UNEXPECTED_SERVER_OPERATION")
        if a == "LOAD_FILE":
            forbidden.add("EXTERNAL_FILE_OPERATION")
        if a in {"DATA", "INDEX"} and b == "DIRECTORY":
            forbidden.add("EXTERNAL_FILE_OPERATION")
        if a in {"IMPORT", "DISCARD"} and b == "TABLESPACE":
            forbidden.add("EXTERNAL_FILE_OPERATION")
        if a == "BACKUP" and b in {"DATABASE", "TABLE"}:
            forbidden.add("UNEXPECTED_SERVER_OPERATION")
        if a == "LOAD" and b in {"DATA", "XML"}:
            forbidden.add("EXTERNAL_FILE_OPERATION")
        if a == "INTO" and b in {"OUTFILE", "DUMPFILE"}:
            forbidden.add("EXTERNAL_FILE_OPERATION")

        # Qualified identifiers can address another schema. NEW/OLD and
        # @@SESSION.<name> are local SQL constructs and are explicitly allowed.
        if (i + 2 < len(tokens) and _id(token) and tokens[i + 1].kind == "SYMBOL"
                and tokens[i + 1].value == "." and _id(tokens[i + 2])):
            previous_two = [_word(tokens[j]) if tokens[j].kind == "WORD" else tokens[j].value
                            for j in range(max(0, i - 2), i)]
            if _id(token) not in {"new", "old"} and not (previous_two[-2:] == ["@", "@"] and _id(token) in {"session", "local"}):
                schema_refs += 1

    if forbidden:
        refuse("DUMP_UNSAFE_STATEMENT_" + sorted(forbidden)[0])
    validate_lexical_sql_mode(tokens)
    if len(create_db) != 1 or len(use_db) != 1:
        refuse("DATABASE_SELECTOR_COUNT_MISMATCH")
    if use_db[0][1] != "wpcaregrid":
        refuse("DATABASE_SELECTOR_SOURCE_MISMATCH")
    if schema_refs:
        refuse("CROSS_SCHEMA_REFERENCE")
    if not expected_tables or len(expected_tables) != len(set(expected_tables)) or "migrations" not in expected_tables:
        refuse("EXPECTED_TABLE_SET_INVALID")

    lines = sql.splitlines(keepends=True)
    create_indexes, use_indexes = [], []
    create_match = None
    use_line = None
    for index, line in enumerate(lines):
        content = line[:-2] if line.endswith(b"\r\n") else line[:-1] if line.endswith(b"\n") else line
        if re.search(rb"^\s*CREATE\s+DATABASE\b", content, re.IGNORECASE):
            create_indexes.append(index)
            match = CREATE_DB_RE.fullmatch(content.strip())
            if match:
                create_match = match
        if re.search(rb"^\s*USE\b", content, re.IGNORECASE):
            use_indexes.append(index)
            if USE_DB_RE.fullmatch(content.strip()):
                use_line = index
    if len(create_indexes) != 1 or create_match is None:
        refuse("DATABASE_CREATE_FORMAT_UNEXPECTED")
    if len(use_indexes) != 1 or use_line is None:
        refuse("DATABASE_USE_FORMAT_UNEXPECTED")
    charset = create_match.group(1).decode("ascii").lower()
    collation = create_match.group(2).decode("ascii").lower()
    if not IDENT_RE.fullmatch(charset) or not IDENT_RE.fullmatch(collation):
        refuse("DATABASE_COLLATION_INVALID")

    newline = b"\r\n" if lines[create_indexes[0]].endswith(b"\r\n") else b"\n" if lines[create_indexes[0]].endswith(b"\n") else b""
    lines[create_indexes[0]] = newline
    use_newline = b"\r\n" if lines[use_line].endswith(b"\r\n") else b"\n" if lines[use_line].endswith(b"\n") else b""
    lines[use_line] = f"USE `{target_schema}`;".encode("ascii") + use_newline
    transformed = b"".join(lines)
    return {
        "transformed_sql": transformed,
        "create_sql": f"CREATE DATABASE `{target_schema}` CHARACTER SET {charset} COLLATE {collation}",
        "source_selector_lines": [create_db[0][0], use_db[0][0]],
        "schema_references": 0,
        "charset": charset,
        "collation": collation,
        "expected_tables": expected_tables,
    }


def backup_metadata_valid(info, *, owner_uid, group_gid):
    return stat.S_ISREG(info.st_mode) and info.st_uid == owner_uid and info.st_gid == group_gid and stat.S_IMODE(info.st_mode) == 0o600


def decode_verified_archive(compressed, expected_sha):
    if hashlib.sha256(compressed).hexdigest() != expected_sha:
        refuse("BACKUP_HASH_MISMATCH")
    try:
        decompressor = zlib.decompressobj(16 + zlib.MAX_WBITS)
        sql = decompressor.decompress(compressed, MAX_SQL_BYTES + 1)
        if len(sql) > MAX_SQL_BYTES or decompressor.unconsumed_tail:
            refuse("DUMP_SIZE_LIMIT")
        if not decompressor.eof:
            refuse("BACKUP_GZIP_INVALID")
        if decompressor.unused_data:
            refuse("DUMP_TRAILING_COMPRESSED_DATA")
        remaining = MAX_SQL_BYTES + 1 - len(sql)
        tail = decompressor.flush(remaining)
        if len(sql) + len(tail) > MAX_SQL_BYTES:
            refuse("DUMP_SIZE_LIMIT")
        sql += tail
    except RestoreRefused:
        raise
    except (OSError, EOFError, zlib.error):
        refuse("BACKUP_GZIP_INVALID")
    if not sql:
        refuse("DUMP_EMPTY")
    return sql


def read_verified_dump(path=BACKUP_PATH, expected_sha=BACKUP_SHA256):
    descriptor = None
    try:
        descriptor = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0))
        info = os.fstat(descriptor)
        import pwd
        import grp
        deploy_uid = pwd.getpwnam("deploy").pw_uid
        deploy_gid = grp.getgrnam("deploy").gr_gid
    except OSError:
        if descriptor is not None:
            os.close(descriptor)
        refuse("BACKUP_UNAVAILABLE")
    except (ImportError, KeyError):
        if descriptor is not None:
            os.close(descriptor)
        refuse("BACKUP_METADATA_UNAVAILABLE")
    if not backup_metadata_valid(info, owner_uid=deploy_uid, group_gid=deploy_gid):
        os.close(descriptor)
        refuse("BACKUP_METADATA_INVALID")
    try:
        chunks = []
        total = 0
        while True:
            chunk = os.read(descriptor, min(1024 * 1024, MAX_SQL_BYTES + 1 - total))
            if not chunk:
                break
            chunks.append(chunk)
            total += len(chunk)
            if total > MAX_SQL_BYTES:
                refuse("BACKUP_SIZE_LIMIT")
        compressed = b"".join(chunks)
    except OSError:
        refuse("BACKUP_READ_FAILED")
    finally:
        os.close(descriptor)
    return decode_verified_archive(compressed, expected_sha)


def _git(args):
    result = subprocess.run(
        ["/usr/sbin/runuser", "-u", "deploy", "--", "/usr/bin/git", "-C", str(APP_PATH), *args],
        stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
        check=False, env={"PATH": "/usr/bin:/bin", "HOME": "/home/deploy", "LC_ALL": "C"},
    )
    if result.returncode:
        refuse("SOURCE_PROVENANCE_UNAVAILABLE")
    return result.stdout.decode("ascii", errors="strict").strip()


def verify_source_identity(restore_tool_sha, script_path):
    if not re.fullmatch(r"[0-9a-f]{40}", restore_tool_sha):
        refuse("RESTORE_TOOL_SHA_INVALID")
    if _git(["rev-parse", "HEAD"]) != APPLICATION_SHA:
        refuse("APPLICATION_SHA_MISMATCH")
    if _git(["status", "--porcelain"]):
        refuse("APPLICATION_WORKTREE_DIRTY")
    _git(["cat-file", "-e", f"{RECOVERY_TOOL_SHA}:scripts/recover-staging-deployment.py"])
    expected_blob = _git(["rev-parse", f"{restore_tool_sha}:scripts/isolated-staging-restore.py"])
    try:
        source = script_path.read_bytes()
    except OSError:
        refuse("RESTORE_TOOL_SOURCE_UNAVAILABLE")
    actual_blob = hashlib.sha1(f"blob {len(source)}\0".encode("ascii") + source).hexdigest()
    if actual_blob != expected_blob:
        refuse("RESTORE_TOOL_SOURCE_MISMATCH")


def mysql_argv(args, socket_path):
    if not socket_path:
        refuse("MYSQL_SOCKET_REQUIRED")
    return [MYSQL, "--no-defaults", "--no-login-paths", "--binary-mode",
            "--protocol=socket", "--socket=" + socket_path,
            "--init-command=SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'",
            "--batch", "--skip-column-names", *args]


def mysql_peer_identity_valid(peer_pid, service_pid, executable, cgroup):
    return (
        peer_pid > 1
        and peer_pid == service_pid
        and executable == MYSQL_SERVER_EXECUTABLE
        and re.search(r"(?:^|/)mysql\.service(?:$|/)", cgroup, re.MULTILINE) is not None
    )


def verify_mysql_peer(connection):
    if not hasattr(socket, "SO_PEERCRED"):
        refuse("MYSQL_PEER_CREDENTIALS_UNAVAILABLE")
    try:
        peer_pid, _peer_uid, _peer_gid = struct.unpack(
            "3i", connection.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, struct.calcsize("3i"))
        )
        service = subprocess.run(
            ["/usr/bin/systemctl", "show", "--property=MainPID", "--value", MYSQL_SERVICE],
            stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
            check=False, env={"PATH": "/usr/bin:/bin", "HOME": "/root", "LC_ALL": "C"},
        )
        if service.returncode or not re.fullmatch(rb"[1-9][0-9]*\s*", service.stdout):
            refuse("MYSQL_SERVICE_IDENTITY_UNAVAILABLE")
        service_pid = int(service.stdout.strip())
        executable = os.path.realpath(f"/proc/{peer_pid}/exe")
        cgroup = Path(f"/proc/{peer_pid}/cgroup").read_text(encoding="ascii")
    except RestoreRefused:
        raise
    except (OSError, ValueError, struct.error):
        refuse("MYSQL_PEER_IDENTITY_UNAVAILABLE")
    if not mysql_peer_identity_valid(peer_pid, service_pid, executable, cgroup):
        refuse("MYSQL_PEER_IDENTITY_MISMATCH")


def validate_socket_path(socket_path):
    path = Path(socket_path)
    if not path.is_absolute() or len(os.fsencode(socket_path)) > 107:
        refuse("MYSQL_SOCKET_PATH_INVALID")
    current = Path(path.anchor)
    try:
        parent_owners = []
        for index, part in enumerate(path.parts[1:]):
            current = current / part
            info = current.lstat()
            if stat.S_ISLNK(info.st_mode):
                refuse("MYSQL_SOCKET_PATH_SYMLINK")
            if index < len(path.parts[1:]) - 1 and not stat.S_ISDIR(info.st_mode):
                refuse("MYSQL_SOCKET_PATH_INVALID")
            if index < len(path.parts[1:]) - 1 and info.st_mode & 0o022:
                refuse("MYSQL_SOCKET_PATH_PARENT_WRITABLE")
            if index < len(path.parts[1:]) - 1:
                parent_owners.append(info.st_uid)
        socket_info = current.lstat()
        if not stat.S_ISSOCK(socket_info.st_mode):
            refuse("MYSQL_SOCKET_NOT_UNIX_SOCKET")
        if any(owner not in {0, socket_info.st_uid} for owner in parent_owners):
            refuse("MYSQL_SOCKET_PATH_OWNER_MISMATCH")
    except OSError:
        refuse("MYSQL_SOCKET_UNAVAILABLE")


def _relay(source, destination):
    try:
        while True:
            data = source.recv(65536)
            if not data:
                break
            destination.sendall(data)
    except OSError:
        pass
    finally:
        try:
            destination.shutdown(socket.SHUT_WR)
        except OSError:
            pass


def _mysql(args, socket_path, stdin=None, proxy_root="/run"):
    proxy_directory = None
    listener = None
    backend = None
    client = None
    process = None
    relay_threads = []
    try:
        validate_socket_path(socket_path)
        backend = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        backend.connect(socket_path)
        verify_mysql_peer(backend)
        proxy_directory = tempfile.mkdtemp(prefix="wpcg-mysql-proxy-", dir=proxy_root)
        os.chmod(proxy_directory, 0o700)
        proxy_path = os.path.join(proxy_directory, "mysql.sock")
        if len(os.fsencode(proxy_path)) > 107:
            refuse("MYSQL_PROXY_PATH_TOO_LONG")
        listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        listener.bind(proxy_path)
        listener.listen(1)
        listener.settimeout(30)
        command = mysql_argv(args, proxy_path)
        process = subprocess.Popen(
            command, stdin=subprocess.PIPE if stdin is not None else subprocess.DEVNULL,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            env={"PATH": "/usr/bin:/bin", "HOME": "/root", "LC_ALL": "C", "LANG": "C"},
        )
        try:
            client, _ = listener.accept()
        except OSError:
            process.terminate()
            process.wait(timeout=5)
            refuse("MYSQL_PROXY_CLIENT_UNAVAILABLE")
        relay_threads = [
            threading.Thread(target=_relay, args=(client, backend), daemon=True),
            threading.Thread(target=_relay, args=(backend, client), daemon=True),
        ]
        for thread in relay_threads:
            thread.start()
        stdout, stderr = process.communicate(input=stdin)
        result = subprocess.CompletedProcess(command, process.returncode, stdout, stderr)
    except OSError:
        refuse("MYSQL_CLIENT_UNAVAILABLE")
    finally:
        if process is not None and process.poll() is None:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()
        for connection in (client, listener, backend):
            if connection is not None:
                try:
                    connection.close()
                except OSError:
                    pass
        for thread in relay_threads:
            thread.join(timeout=1)
        if proxy_directory is not None:
            try:
                shutil.rmtree(proxy_directory)
            except OSError:
                refuse("MYSQL_PROXY_CLEANUP_FAILED")
    return result


def _scalar(sql, socket_path):
    result = _mysql(["--execute", sql], socket_path)
    if result.returncode:
        refuse("MYSQL_READ_ONLY_PREFLIGHT_FAILED")
    return result.stdout.decode("ascii", errors="strict").strip()


def verify_mysql_identity(socket_path):
    query = (
        "SELECT CONCAT(VERSION(),'|',@@GLOBAL.log_bin,'|',@@GLOBAL.binlog_format,'|',"
        "@@GLOBAL.log_bin_trust_function_creators,'|',SHA2(@@GLOBAL.server_uuid,256),'|',"
        "FIND_IN_SET('ANSI_QUOTES',@@SESSION.sql_mode)=0,'|',"
        "FIND_IN_SET('NO_BACKSLASH_ESCAPES',@@SESSION.sql_mode)=0,'|',"
        "(SELECT schema_name FROM information_schema.schemata WHERE schema_name='wpcaregrid'));"
    )
    expected = "8.4.11|1|ROW|0|" + MYSQL_UUID_SHA256 + "|1|1|wpcaregrid"
    if _scalar(query, socket_path) != expected:
        refuse("MYSQL_IDENTITY_OR_SETTING_MISMATCH")


def inspect_restore(schema, *, path=BACKUP_PATH, expected_sha=BACKUP_SHA256):
    sql = read_verified_dump(path, expected_sha)
    plan = audit_sql(sql, schema)
    return {
        "backup_sha256": expected_sha,
        "application_sha": APPLICATION_SHA,
        "recovery_tool_sha": RECOVERY_TOOL_SHA,
        "source_selector_lines": plan["source_selector_lines"],
        "schema_references": plan["schema_references"],
        "charset": plan["charset"],
        "collation": plan["collation"],
        "expected_table_count": len(plan["expected_tables"]),
        "transformed_sql": plan["transformed_sql"],
        "create_sql": plan["create_sql"],
    }


def restore(schema, restore_tool_sha, script_path, socket_path):
    if os.geteuid() != 0:
        refuse("DBA_PRIVILEGE_REQUIRED")
    verify_source_identity(restore_tool_sha, script_path)
    validate_socket_path(socket_path)
    verify_mysql_identity(socket_path)
    plan = inspect_restore(schema)
    exists = _scalar(
        "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='" + schema + "';", socket_path
    )
    if exists != "0":
        refuse("TARGET_SCHEMA_ALREADY_EXISTS")
    created = _mysql(["--execute", plan["create_sql"]], socket_path)
    if created.returncode:
        refuse("TARGET_SCHEMA_CREATE_FAILED")
    encoding = plan["charset"] + "|" + plan["collation"]
    actual = _scalar(
        "SELECT CONCAT(DEFAULT_CHARACTER_SET_NAME,'|',DEFAULT_COLLATION_NAME) "
        "FROM information_schema.schemata WHERE schema_name='" + schema + "';", socket_path
    )
    if actual != encoding:
        refuse("TARGET_SCHEMA_ENCODING_MISMATCH")
    imported = _mysql(["--database=" + schema], socket_path, stdin=plan["transformed_sql"])
    if imported.returncode:
        code = "MYSQL_IMPORT_FAILED"
        match = re.search(rb"ERROR ([0-9]{1,5}) \(([A-Z0-9]{5})\)", imported.stderr)
        suffix = f";MYSQL_ERRNO={match.group(1).decode()};SQLSTATE={match.group(2).decode()}" if match else ""
        print(f"RESTORE=FAIL;CODE={code}{suffix};DISPOSABLE_SCHEMA_PRESERVED=YES", file=sys.stderr)
        return 1
    table_result = _mysql(["--execute", "SELECT table_name FROM information_schema.tables WHERE table_schema='" + schema + "' AND table_type='BASE TABLE' ORDER BY table_name;"], socket_path)
    if table_result.returncode:
        refuse("RESTORE_OBJECT_VERIFICATION_FAILED")
    actual_tables = table_result.stdout.decode("utf-8", errors="strict").splitlines()
    if set(actual_tables) != set(plan["expected_tables"]):
        print("RESTORE=FAIL;CODE=RESTORED_TABLE_SET_MISMATCH;DISPOSABLE_SCHEMA_PRESERVED=YES", file=sys.stderr)
        return 1
    print(json.dumps({
        "RESTORE": "PASS", "SCHEMA": schema, "TABLE_COUNT": len(actual_tables),
        "APPLICATION_SHA": APPLICATION_SHA, "RECOVERY_TOOL_SHA": RECOVERY_TOOL_SHA,
        "BACKUP_SHA256": BACKUP_SHA256, "DISPOSABLE_SCHEMA_PRESERVED": "YES",
    }, sort_keys=True, separators=(",", ":")))
    return 0


def main(argv=None):
    parser = argparse.ArgumentParser(allow_abbrev=False)
    parser.add_argument("action", choices=("inspect", "restore"))
    parser.add_argument("--schema", required=True)
    parser.add_argument("--restore-tool-sha", required=True)
    parser.add_argument("--socket")
    args = parser.parse_args(argv)
    try:
        if not TARGET_RE.fullmatch(args.schema):
            refuse("TARGET_SCHEMA_INVALID")
        if args.action == "inspect":
            verify_source_identity(args.restore_tool_sha, Path(__file__).resolve())
            plan = inspect_restore(args.schema)
            print(json.dumps({
                "INSPECTION": "PASS", "SCHEMA": args.schema,
                "APPLICATION_SHA": APPLICATION_SHA, "RECOVERY_TOOL_SHA": RECOVERY_TOOL_SHA,
                "BACKUP_SHA256": BACKUP_SHA256,
                "SOURCE_SELECTOR_LINES": plan["source_selector_lines"],
                "CROSS_SCHEMA_REFERENCES": plan["schema_references"],
                "TARGET_COLLATION": plan["collation"],
                "TRANSFORMATION": "ONE_CREATE_EXCISED_ONE_USE_REWRITTEN",
            }, sort_keys=True, separators=(",", ":")))
            return 0
        if not args.socket:
            refuse("MYSQL_SOCKET_REQUIRED")
        return restore(args.schema, args.restore_tool_sha, Path(__file__).resolve(), args.socket)
    except RestoreRefused as error:
        print(f"RESTORE=STOP;CODE={error}", file=sys.stderr)
        return 1
    except Exception:
        print("RESTORE=STOP;CODE=UNEXPECTED_FAILURE", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
