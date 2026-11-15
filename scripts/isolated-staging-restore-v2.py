#!/usr/bin/env python3
"""Version 2 of the isolated staging restore evidence gate.

This is deliberately separate from the interrupted-release recovery controller.
It audits a pinned historical archive only. It will not connect to MySQL or
create a restore schema without trusted release and isolation evidence.
"""

import argparse
import hashlib
import json
import os
import re
import stat
import sys
import zlib
from dataclasses import dataclass
from pathlib import Path


APPLICATION_SHA = "ee3d29818ff1647d82087446dfaf017ca688cb77"
RECOVERY_TOOL_SHA = "0c0a30f28c72c0602b9b5e665ac4da4d9328e474"
BACKUP_PATH = Path("/home/deploy/private-backups/wpcaregrid-pre-deploy-20261104T133855Z.sql.gz")
BACKUP_SHA256 = "5a2cce05c8a0cc44cfa76be3619f86bcdb748793dd6fed098fd8ef349b02c431"
BACKUP_ID = "wpcaregrid-pre-deploy-20261104T133855Z"
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


def audit_sql(sql, target_schema, approved_definer=None):
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
            # Accept only one exact, externally pinned account. Any syntactic
            # variation or second account fails closed.
            if (approved_definer is None or i + 4 >= len(tokens)
                    or tokens[i + 1].kind != "SYMBOL" or tokens[i + 1].value != "="
                    or tokens[i + 2].kind not in {"IDENT", "STRING", "WORD"}
                    or tokens[i + 3].kind != "SYMBOL" or tokens[i + 3].value != "@"
                    or tokens[i + 4].kind not in {"IDENT", "STRING", "WORD"}):
                forbidden.add("DEFINER_UNSUPPORTED")
            else:
                account = tokens[i + 2].value.strip("'\"`") + "@" + tokens[i + 4].value.strip("'\"`")
                if account != approved_definer:
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
        "expected_triggers": [tokens[i + 1].value for i in range(len(tokens) - 1)
                              if _word(tokens[i]) == "TRIGGER"
                              and (i > 0 and (_word(tokens[i - 1]) == "CREATE"
                                   or any(_word(tokens[j]) == "CREATE" for j in range(max(0, i - 6), i))))],
        # The input dump is immutable and authenticated; record its content hash
        # as the data baseline. Per-table counts are independently captured
        # after import in the receipt and never confused with an expected count.
        "expected_row_counts": [],
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


def inspect_restore(schema, *, path=BACKUP_PATH, expected_sha=BACKUP_SHA256, approved_definer=None):
    sql = read_verified_dump(path, expected_sha)
    plan = audit_sql(sql, schema, approved_definer)
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


def validate_identity_bindings(source, destination):
    """Validate identity field shape only; this does not authenticate a manifest."""
    required_source = {"environment_id", "host_id", "database_id", "release_sha"}
    required_destination = {"environment_id", "host_id", "instance_uuid", "approved_isolation_policy"}
    if not isinstance(source, dict) or set(source) != required_source:
        refuse("SOURCE_IDENTITY_EVIDENCE_INVALID")
    if not isinstance(destination, dict) or set(destination) != required_destination:
        refuse("DESTINATION_IDENTITY_EVIDENCE_INVALID")
    for value in list(source.values()) + list(destination.values()):
        if not isinstance(value, str) or not value or len(value) > 256 or any(ord(c) < 33 for c in value):
            refuse("IDENTITY_FIELD_INVALID")
    source_identity = (source["environment_id"], source["host_id"], source["database_id"])
    destination_identity = (destination["environment_id"], destination["host_id"], destination["instance_uuid"])
    if (source_identity == destination_identity or source["environment_id"] == destination["environment_id"]
            or source["host_id"] == destination["host_id"]
            or source["database_id"] == destination["instance_uuid"]):
        refuse("DESTINATION_NOT_ISOLATED")
    if not re.fullmatch(r"[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89aAbB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}", destination["instance_uuid"]):
        refuse("DESTINATION_INSTANCE_UUID_INVALID")
    if not re.fullmatch(r"approved-isolated-recovery:[A-Za-z0-9_.-]+", destination["approved_isolation_policy"]):
        refuse("DESTINATION_POLICY_NOT_APPROVED")
    if not re.fullmatch(r"[0-9a-f]{40}", source["release_sha"]):
        refuse("SOURCE_RELEASE_SHA_INVALID")
    return True


def verify_release_manifest(_path):
    """No owner-protected manifest verifier/key is configured in this version."""
    refuse("TRUSTED_RELEASE_MANIFEST_VERIFIER_UNAVAILABLE")


def historical_receipt(*, backup_sha, application_sha, recovery_tool_sha, started_at, completed_at,
                       archive_integrity_verified):
    if backup_sha != BACKUP_SHA256:
        refuse("BACKUP_HASH_PIN_MISMATCH")
    return {
        "receipt_version": 2,
        "classification": "historical_non_release_bound",
        "release_bound": False,
        "restore": "not_performed",
        "backup_id": BACKUP_ID,
        "backup_sha256": backup_sha,
        "backup_date": "2026-11-04",
        "application_sha": application_sha,
        "recovery_tool_sha": recovery_tool_sha,
        "started_at": started_at,
        "completed_at": completed_at,
        "assertions": {
            "archive_hash_and_integrity": bool(archive_integrity_verified),
            "source_environment_host_database_bound": False,
            "destination_instance_bound_and_isolated": False,
            "complete_schema_verified": False,
            "complete_trigger_definition_and_definer_verified": False,
            "exact_data_integrity_verified": False,
            "protected_config_and_app_key_recovery_verified": False,
            "release_manifest_verified": False,
        },
        "unverified": ["source_identity", "isolated_destination", "schema_inventory",
                       "trigger_definitions", "exact_data_integrity", "protected_config_app_key",
                       "release_binding"],
    }


def restore(*_args, **_kwargs):
    # A schema on the local mysql.service is not an isolated recovery system.
    # Until a trusted, owner-protected manifest verifier and external-destination
    # adapter are configured, this successor must not perform a real restore.
    refuse("RELEASE_MANIFEST_AND_ISOLATED_DESTINATION_REQUIRED")


def main(argv=None):
    parser = argparse.ArgumentParser(allow_abbrev=False)
    parser.add_argument("action", choices=("inspect", "restore"))
    parser.add_argument("--schema")
    parser.add_argument("--backup-id")
    parser.add_argument("--backup-sha256")
    parser.add_argument("--release-manifest")
    args = parser.parse_args(argv)
    try:
        if args.action == "restore":
            return restore()
        if args.backup_id != BACKUP_ID:
            refuse("BACKUP_ID_MISMATCH")
        if args.backup_sha256 != BACKUP_SHA256:
            refuse("BACKUP_HASH_PIN_MISMATCH")
        if args.release_manifest:
            verify_release_manifest(args.release_manifest)
        if not args.schema or not TARGET_RE.fullmatch(args.schema):
            refuse("TARGET_SCHEMA_INVALID")
        if args.action == "inspect":
            started = __import__("datetime").datetime.now(__import__("datetime").timezone.utc).isoformat()
            sql = read_verified_dump()
            plan = audit_sql(sql, args.schema)
            completed = __import__("datetime").datetime.now(__import__("datetime").timezone.utc).isoformat()
            receipt = historical_receipt(
                backup_sha=BACKUP_SHA256, application_sha=APPLICATION_SHA,
                recovery_tool_sha=RECOVERY_TOOL_SHA, started_at=started,
                completed_at=completed, archive_integrity_verified=True,
            )
            receipt["inspection"] = "pass"
            receipt["source_selector_lines"] = plan["source_selector_lines"]
            receipt["target_collation"] = plan["collation"]
            print(json.dumps(receipt, sort_keys=True, separators=(",", ":")))
            return 0
    except RestoreRefused as error:
        print(f"RESTORE=STOP;CODE={error}", file=sys.stderr)
        return 1
    except Exception:
        print("RESTORE=STOP;CODE=UNEXPECTED_FAILURE", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
