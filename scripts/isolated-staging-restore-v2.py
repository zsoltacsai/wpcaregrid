#!/usr/bin/env python3
"""Versioned isolated staging restore gated by root-protected evidence.

The release-bound path requires a root-owned, mode-0600 manifest and destination
policy. Those files are a trust anchor installed after the Owner/operator has
verified their contents; this program does not fetch or invent release evidence.
The historical v1 tool remains independent and unchanged.
"""

import argparse
import datetime
import hashlib
import json
import os
import re
import secrets
import stat
import subprocess
import sys
import time
import zlib
from dataclasses import dataclass
from pathlib import Path


APPLICATION_SHA = "ee3d29818ff1647d82087446dfaf017ca688cb77"
RECOVERY_TOOL_SHA = "0c0a30f28c72c0602b9b5e665ac4da4d9328e474"
BACKUP_PATH = Path("/home/deploy/private-backups/wpcaregrid-pre-deploy-20261104T133855Z.sql.gz")
BACKUP_SHA256 = "5a2cce05c8a0cc44cfa76be3619f86bcdb748793dd6fed098fd8ef349b02c431"
BACKUP_ID = "wpcaregrid-pre-deploy-20261104T133855Z"
MAX_COMPRESSED_BACKUP_BYTES = 32 * 1024 * 1024
MAX_UNCOMPRESSED_SQL_BYTES = 32 * 1024 * 1024
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


def wrap_single_schema_dump(sql, source_schema, charset, collation):
    """Add the constrained database selector framing omitted by single-schema mysqldump."""
    if (not isinstance(sql, bytes) or not IDENT_RE.fullmatch(source_schema)
            or not IDENT_RE.fullmatch(charset) or not IDENT_RE.fullmatch(collation)):
        refuse("DUMP_SELECTOR_METADATA_INVALID")
    header = (f"CREATE DATABASE /*!32312 IF NOT EXISTS*/ `{source_schema}` "
              f"/*!40100 DEFAULT CHARACTER SET {charset} COLLATE {collation} */;\n"
              f"USE `{source_schema}`;\n").encode("ascii")
    if len(sql) + len(header) > MAX_UNCOMPRESSED_SQL_BYTES:
        refuse("DUMP_SIZE_LIMIT")
    return header + sql


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


def remove_safe_mysqldump_trigger_session_wrappers(sql):
    """Remove only mysqldump's paired trigger-session save/restore scaffolding.

    Import runs with a pinned UTF-8 session. The trigger's stored SQL mode is
    retained and validated separately; only the dump client's temporary
    connection-state bookkeeping is removed.
    """
    lines = sql.splitlines(keepends=True)
    wrappers = {
        "sql_mode": re.compile(
            rb"\s*/\*!\s*[0-9]{5,6}\s+SET\s+@saved_sql_mode\s*=\s*@@(?:SESSION\s*\.\s*)?sql_mode\s*\*/\s*;\s*\Z", re.I),
        "character_set_client": re.compile(
            rb"\s*/\*!\s*[0-9]{5,6}\s+SET\s+@saved_cs_client\s*=\s*@@(?:SESSION\s*\.\s*)?character_set_client\s*\*/\s*;\s*\Z", re.I),
        "character_set_results": re.compile(
            rb"\s*/\*!\s*[0-9]{5,6}\s+SET\s+@saved_cs_results\s*=\s*@@(?:SESSION\s*\.\s*)?character_set_results\s*\*/\s*;\s*\Z", re.I),
        "collation_connection": re.compile(
            rb"\s*/\*!\s*[0-9]{5,6}\s+SET\s+@saved_col_connection\s*=\s*@@(?:SESSION\s*\.\s*)?collation_connection\s*\*/\s*;\s*\Z", re.I),
    }
    restores = {
        "sql_mode": re.compile(rb"\s*/\*!\s*[0-9]{5,6}\s+SET\s+sql_mode\s*=\s*@saved_sql_mode\s*\*/\s*;\s*\Z", re.I),
        "character_set_client": re.compile(rb"\s*/\*!\s*[0-9]{5,6}\s+SET\s+character_set_client\s*=\s*@saved_cs_client\s*\*/\s*;\s*\Z", re.I),
        "character_set_results": re.compile(rb"\s*/\*!\s*[0-9]{5,6}\s+SET\s+character_set_results\s*=\s*@saved_cs_results\s*\*/\s*;\s*\Z", re.I),
        "collation_connection": re.compile(rb"\s*/\*!\s*[0-9]{5,6}\s+SET\s+collation_connection\s*=\s*@saved_col_connection\s*\*/\s*;\s*\Z", re.I),
    }
    charset_setup = re.compile(
        rb"\s*/\*!\s*[0-9]{5,6}\s+SET\s+(?:character_set_client|character_set_results)\s*=\s*utf8mb4\s*\*/\s*;\s*\Z", re.I)
    collation_setup = re.compile(
        rb"\s*/\*!\s*[0-9]{5,6}\s+SET\s+collation_connection\s*=\s*utf8mb4_[A-Za-z0-9_]+\s*\*/\s*;\s*\Z", re.I)
    counts = {name: [0, 0] for name in wrappers}
    remove = set()
    for index, line in enumerate(lines):
        content = line.rstrip(b"\r\n")
        matched = False
        for name, pattern in wrappers.items():
            if pattern.fullmatch(content):
                counts[name][0] += 1
                remove.add(index)
                matched = True
                break
            if restores[name].fullmatch(content):
                counts[name][1] += 1
                remove.add(index)
                matched = True
                break
        if not matched and (charset_setup.fullmatch(content) or collation_setup.fullmatch(content)):
            remove.add(index)
    if any(saved != restored for saved, restored in counts.values()):
        refuse("MYSQLDUMP_TRIGGER_SESSION_WRAPPER_UNBALANCED")
    return b"".join(b"" if index in remove else line for index, line in enumerate(lines))


def validate_lexical_sql_mode(tokens):
    # These modes do not alter SQL tokenization/quoting, so preserving common
    # server modes on dumped triggers is safe for the fixed UTF-8 parser.
    # Quoting-related modes such as ANSI_QUOTES and NO_BACKSLASH_ESCAPES are
    # intentionally absent and remain fail-closed.
    safe_modes = {
        "ALLOW_INVALID_DATES", "ERROR_FOR_DIVISION_BY_ZERO", "NO_AUTO_VALUE_ON_ZERO",
        "NO_ENGINE_SUBSTITUTION", "NO_ZERO_DATE", "NO_ZERO_IN_DATE", "ONLY_FULL_GROUP_BY",
        "STRICT_ALL_TABLES", "STRICT_TRANS_TABLES",
    }
    for i, token in enumerate(tokens):
        if _id(token) != "sql_mode" or i + 1 >= len(tokens):
            continue
        if tokens[i + 1].kind == "SYMBOL" and tokens[i + 1].value == ":":
            if i + 2 < len(tokens) and tokens[i + 2].kind == "SYMBOL" and tokens[i + 2].value == "=":
                refuse("SQL_LEXICAL_MODE_UNSUPPORTED")
            continue
        if tokens[i + 1].kind == "SYMBOL" and tokens[i + 1].value == "=":
            statement_end = next((j for j in range(i + 2, len(tokens))
                                  if tokens[j].kind == "SYMBOL" and tokens[j].value == ";"), len(tokens))
            # MySQL concatenates adjacent string literals. Accept exactly one
            # complete literal through the statement terminator; validating
            # only the first STRING would let a second literal append a
            # lexer-changing mode such as ANSI_QUOTES.
            if (i + 2 >= len(tokens) or tokens[i + 2].kind != "STRING"
                    or statement_end != i + 3):
                refuse("SQL_LEXICAL_MODE_UNSUPPORTED")
            literal = tokens[i + 2].value
            if not re.fullmatch(r"'[A-Za-z0-9_, ]*'", literal):
                refuse("SQL_LEXICAL_MODE_UNSUPPORTED")
            modes = [part.strip().upper() for part in literal[1:-1].split(",") if part.strip()]
            if len(modes) != len(set(modes)) or any(mode not in safe_modes for mode in modes):
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
    sql = remove_safe_mysqldump_trigger_session_wrappers(sql)
    validate_mysql_client_directives(sql)

    tokens = bounded_tokens(sql)
    # The importer and auditor must agree on both SQL string escaping and the
    # mysql client command language. Charset switching can make a backslash
    # escape parsed differently by MySQL than by this UTF-8 tokenizer; reject
    # client commands and any SQL-side client charset change before import.
    for token in tokens:
        if token.kind == "SYMBOL" and token.value == "\\":
            refuse("MYSQL_CLIENT_COMMAND_UNSUPPORTED")
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
            set_tokens = tokens[i + 1:statement_end]
            if b == "CHARSET" or (b == "CHARACTER" and c == "SET") or any(
                    _id(item) == "names" for item in set_tokens):
                forbidden.add("MYSQL_CHARSET_CHANGE_UNSUPPORTED")
            for j, item in enumerate(set_tokens):
                is_charset_var = _id(item) in {
                    "character_set_client", "character_set_connection",
                    "character_set_results", "character_set_database",
                }
                is_system_read = (j >= 2 and set_tokens[j - 1].kind == "SYMBOL"
                                  and set_tokens[j - 1].value == "@"
                                  and set_tokens[j - 2].kind == "SYMBOL"
                                  and set_tokens[j - 2].value == "@")
                has_assignment = (j + 1 < len(set_tokens)
                                  and set_tokens[j + 1].kind == "SYMBOL"
                                  and set_tokens[j + 1].value in {"=", ":"})
                if is_charset_var and (not is_system_read or has_assignment):
                    forbidden.add("MYSQL_CHARSET_CHANGE_UNSUPPORTED")
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
        if a == "CREATE" and b in {"VIEW", "OR"} and (b == "VIEW" or c == "REPLACE"):
            forbidden.add("UNEXPECTED_SERVER_OBJECT")
        if a in {"ALTER", "DROP"} and b in {"USER", "ROLE", "EVENT", "SERVER", "TABLESPACE", "PROCEDURE", "FUNCTION", "LOGFILE"}:
            forbidden.add("UNEXPECTED_SERVER_OBJECT")
        if a in {"ALTER", "DROP"} and b == "VIEW":
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
            if (approved_definer is None or i + 5 >= len(tokens)
                    or tokens[i + 1].kind != "SYMBOL" or tokens[i + 1].value != "="
                    or tokens[i + 2].kind not in {"IDENT", "STRING", "WORD"}
                    or tokens[i + 3].kind != "SYMBOL" or tokens[i + 3].value != "@"
                    or tokens[i + 4].kind not in {"IDENT", "STRING", "WORD"}
                    or _word(tokens[i + 5]) != "TRIGGER"
                    or i == 0 or _word(tokens[i - 1]) != "CREATE"):
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
        sql = decompressor.decompress(compressed, MAX_UNCOMPRESSED_SQL_BYTES + 1)
        if len(sql) > MAX_UNCOMPRESSED_SQL_BYTES or decompressor.unconsumed_tail:
            refuse("DUMP_SIZE_LIMIT")
        if not decompressor.eof:
            refuse("BACKUP_GZIP_INVALID")
        if decompressor.unused_data:
            refuse("DUMP_TRAILING_COMPRESSED_DATA")
        remaining = MAX_UNCOMPRESSED_SQL_BYTES + 1 - len(sql)
        tail = decompressor.flush(remaining)
        if len(sql) + len(tail) > MAX_UNCOMPRESSED_SQL_BYTES:
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
            chunk = os.read(descriptor, min(1024 * 1024, MAX_COMPRESSED_BACKUP_BYTES + 1 - total))
            if not chunk:
                break
            chunks.append(chunk)
            total += len(chunk)
            if total > MAX_COMPRESSED_BACKUP_BYTES:
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
    """Read a root-owned, mode-0600 release manifest without following symlinks.

    The root-owned file is the trust anchor for this offline restore operation.
    It must be installed only by the authorized operator after independently
    matching its backup, release, source, and destination fields to protected
    release records. This check authenticates filesystem ownership and content
    integrity; it does not claim a cryptographic signature from GitHub.
    """
    manifest, digest = read_protected_json(_path, owner_uid=0, maximum=256 * 1024)
    return _manifest_object(manifest), digest


SHA256_RE = re.compile(r"[0-9a-f]{64}\Z")
RELEASE_SHA_RE = re.compile(r"[0-9a-f]{40}\Z")
BACKUP_ID_RE = re.compile(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\Z")
TABLE_RE = re.compile(r"[A-Za-z0-9_$]{1,64}\Z")
DEFINER_RE = re.compile(r"[A-Za-z0-9_.-]{1,64}@[A-Za-z0-9_.:%-]{1,255}\Z")
MYSQL_HOST_RE = re.compile(r"(?:127\.0\.0\.1|localhost|wpcg_restore_[a-f0-9]{10})\Z")
MYSQL_VERSION_RE = re.compile(r"8\.4\.[0-9]+(?:[-+][A-Za-z0-9._-]+)?\Z")


def _strict_json_pairs(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            refuse("MANIFEST_JSON_DUPLICATE_KEY")
        result[key] = value
    return result


def read_protected_json(path, *, owner_uid, maximum):
    """Read bounded JSON from a regular, non-symlinked 0600 file."""
    descriptor = None
    try:
        path = Path(path)
        if not path.is_absolute() or path != Path(os.path.normpath(str(path))):
            refuse("PROTECTED_FILE_PATH_INVALID")
        _check_protected_path_parents(path, owner_uid=owner_uid)
        descriptor = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0)
                             | getattr(os, "O_CLOEXEC", 0))
        info = os.fstat(descriptor)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != owner_uid
                or stat.S_IMODE(info.st_mode) != 0o600 or info.st_size > maximum):
            refuse("PROTECTED_FILE_METADATA_INVALID")
        chunks = []
        total = 0
        while True:
            block = os.read(descriptor, min(65536, maximum + 1 - total))
            if not block:
                break
            chunks.append(block)
            total += len(block)
            if total > maximum:
                refuse("PROTECTED_FILE_SIZE_LIMIT")
        raw = b"".join(chunks)
    except RestoreRefused:
        raise
    except OSError:
        refuse("PROTECTED_FILE_UNAVAILABLE")
    finally:
        if descriptor is not None:
            os.close(descriptor)
    try:
        value = json.loads(raw.decode("utf-8", "strict"), object_pairs_hook=_strict_json_pairs)
    except RestoreRefused:
        raise
    except (UnicodeError, json.JSONDecodeError, TypeError, ValueError):
        refuse("PROTECTED_JSON_INVALID")
    if not isinstance(value, dict):
        refuse("PROTECTED_JSON_INVALID")
    return value, hashlib.sha256(raw).hexdigest()


def _check_protected_path_parents(path, *, owner_uid):
    """Reject parent symlinks and writable ancestors (except sticky /tmp)."""
    for parent in reversed(path.parents):
        try:
            info = parent.lstat()
        except OSError:
            refuse("PROTECTED_FILE_PATH_INVALID")
        if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode):
            refuse("PROTECTED_FILE_PATH_INVALID")
        sticky_tmp = (info.st_uid == 0 and bool(info.st_mode & stat.S_ISVTX)
                      and str(parent) == "/tmp")
        trusted_ancestor = (info.st_uid in {0, owner_uid} and not (info.st_mode & 0o022))
        if not sticky_tmp and not trusted_ancestor:
            refuse("PROTECTED_FILE_PATH_UNSAFE")


def _require_hex(value, pattern, code):
    if not isinstance(value, str) or not pattern.fullmatch(value):
        refuse(code)


def _require_string(value, code, *, maximum=256):
    if (not isinstance(value, str) or not value or len(value) > maximum
            or any(ord(character) < 33 for character in value)):
        refuse(code)


def _manifest_object(manifest, *, now=None):
    required = {
        "schema_version", "manifest_id", "release", "backup", "source_identity",
        "destination_policy_sha256", "target_schema", "mysql", "approved_definer",
        "expected", "database_charset", "database_collation", "config", "issued_at",
        "expires_at", "owner_authorization_id",
    }
    if (set(manifest) != required or type(manifest.get("schema_version")) is not int
            or manifest["schema_version"] != 2):
        refuse("RELEASE_MANIFEST_SCHEMA_INVALID")
    _require_string(manifest["manifest_id"], "RELEASE_MANIFEST_ID_INVALID", maximum=80)
    _require_string(manifest["owner_authorization_id"], "RELEASE_MANIFEST_OWNER_AUTH_INVALID", maximum=128)
    release = manifest["release"]
    if not isinstance(release, dict) or set(release) != {"application_sha", "base_sha", "artifact_sha256"}:
        refuse("RELEASE_MANIFEST_RELEASE_INVALID")
    for key in ("application_sha", "base_sha"):
        _require_hex(release[key], RELEASE_SHA_RE, "RELEASE_MANIFEST_RELEASE_INVALID")
    _require_hex(release["artifact_sha256"], SHA256_RE, "RELEASE_MANIFEST_RELEASE_INVALID")
    backup = manifest["backup"]
    if not isinstance(backup, dict) or set(backup) != {
            "id", "path", "sha256", "size_bytes", "source_schema", "source_application_sha"}:
        refuse("RELEASE_MANIFEST_BACKUP_INVALID")
    if not isinstance(backup["id"], str) or not BACKUP_ID_RE.fullmatch(backup["id"]):
        refuse("RELEASE_MANIFEST_BACKUP_INVALID")
    if not isinstance(backup["path"], str) or not Path(backup["path"]).is_absolute():
        refuse("RELEASE_MANIFEST_BACKUP_INVALID")
    _require_hex(backup["sha256"], SHA256_RE, "RELEASE_MANIFEST_BACKUP_INVALID")
    _require_hex(backup["source_application_sha"], RELEASE_SHA_RE, "RELEASE_MANIFEST_BACKUP_INVALID")
    if (type(backup["size_bytes"]) is not int
            or not 1 <= backup["size_bytes"] <= MAX_COMPRESSED_BACKUP_BYTES):
        refuse("RELEASE_MANIFEST_BACKUP_INVALID")
    if backup["source_schema"] != "wpcaregrid":
        refuse("RELEASE_MANIFEST_BACKUP_INVALID")
    if (manifest["database_charset"] != "utf8mb4"
            or not isinstance(manifest["database_collation"], str)
            or not re.fullmatch(r"utf8mb4_[a-z0-9_]{1,48}", manifest["database_collation"])):
        refuse("RELEASE_MANIFEST_DATABASE_FORMAT_INVALID")
    source = manifest["source_identity"]
    if not isinstance(source, dict) or set(source) != {
            "environment_id", "host_id", "database_id", "server_uuid", "database", "application_sha"}:
        refuse("SOURCE_IDENTITY_EVIDENCE_INVALID")
    for field in ("environment_id", "host_id", "database_id", "server_uuid"):
        _require_string(source[field], "SOURCE_IDENTITY_EVIDENCE_INVALID", maximum=256)
    if source["database"] != "wpcaregrid":
        refuse("SOURCE_IDENTITY_EVIDENCE_INVALID")
    _require_hex(source["application_sha"], RELEASE_SHA_RE, "SOURCE_IDENTITY_EVIDENCE_INVALID")
    # A pre-deployment recovery backup is produced by the currently deployed
    # application, while the target release is the code whose migrations will
    # be rehearsed on a separate restored clone. Bind those two identities
    # independently; requiring equality would reject every normal upgrade.
    if source["application_sha"] != backup["source_application_sha"]:
        refuse("SOURCE_BACKUP_BINDING_MISMATCH")
    _require_hex(manifest["destination_policy_sha256"], SHA256_RE, "DESTINATION_POLICY_HASH_INVALID")
    target_schema = manifest["target_schema"]
    if not isinstance(target_schema, str) or not TARGET_RE.fullmatch(target_schema):
        refuse("TARGET_SCHEMA_INVALID")
    mysql = manifest["mysql"]
    if not isinstance(mysql, dict) or set(mysql) != {"version", "server_uuid", "log_bin", "binlog_format", "trust_function_creators"}:
        refuse("MYSQL_EXPECTATION_INVALID")
    if not isinstance(mysql["version"], str) or not MYSQL_VERSION_RE.fullmatch(mysql["version"]):
        refuse("MYSQL_EXPECTATION_INVALID")
    _require_string(mysql["server_uuid"], "MYSQL_EXPECTATION_INVALID", maximum=64)
    if mysql["log_bin"] is not True or mysql["binlog_format"] != "ROW" or mysql["trust_function_creators"] is not False:
        refuse("MYSQL_EXPECTATION_UNSAFE")
    definer = manifest["approved_definer"]
    if not isinstance(definer, str) or not DEFINER_RE.fullmatch(definer):
        refuse("DEFINER_ALLOWLIST_INVALID")
    definer_user, _definer_host = definer.split("@", 1)
    if definer_user.lower() in {"root", "mysql.sys", "mysql.session", "mysql.infoschema"}:
        refuse("DEFINER_ALLOWLIST_INVALID")
    expected = manifest["expected"]
    expected_keys = {"table_names", "table_row_counts", "schema_sha256", "ledger_sha256", "data_sha256", "triggers", "migrations", "definer_select_tables"}
    if not isinstance(expected, dict) or set(expected) != expected_keys:
        refuse("EXPECTED_INVENTORY_INVALID")
    tables = expected["table_names"]
    if (not isinstance(tables, list) or not tables or "migrations" not in tables
            or len(tables) > 256 or any(not isinstance(name, str) or not TABLE_RE.fullmatch(name) for name in tables)
            or len(set(tables)) != len(tables)):
        refuse("EXPECTED_INVENTORY_INVALID")
    counts = expected["table_row_counts"]
    if (not isinstance(counts, dict) or set(counts) != set(tables)
            or any(type(count) is not int or count < 0 for count in counts.values())):
        refuse("EXPECTED_ROW_COUNTS_INVALID")
    for key in ("schema_sha256", "ledger_sha256", "data_sha256"):
        _require_hex(expected[key], SHA256_RE, "EXPECTED_INVENTORY_INVALID")
    migrations = expected["migrations"]
    if (not isinstance(migrations, list) or not migrations
            or any(not isinstance(name, str) or not re.fullmatch(r"[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+", name) for name in migrations)
            or len(set(migrations)) != len(migrations)):
        refuse("EXPECTED_LEDGER_INVALID")
    definer_select_tables = expected["definer_select_tables"]
    if (not isinstance(definer_select_tables, list) or len(definer_select_tables) > 64
            or any(not isinstance(name, str) or name not in tables for name in definer_select_tables)
            or len(set(definer_select_tables)) != len(definer_select_tables)
            or (expected["triggers"] and not definer_select_tables)):
        refuse("EXPECTED_DEFINER_GRANTS_INVALID")
    triggers = expected["triggers"]
    if not isinstance(triggers, list) or len(triggers) > 64:
        refuse("EXPECTED_TRIGGER_INVENTORY_INVALID")
    trigger_names = set()
    for trigger in triggers:
        if (not isinstance(trigger, dict) or set(trigger) != {
                "name", "table", "event", "timing", "orientation", "definer", "action_sha256"}):
            refuse("EXPECTED_TRIGGER_INVENTORY_INVALID")
        for key in ("name", "table", "event", "timing", "orientation", "definer"):
            _require_string(trigger[key], "EXPECTED_TRIGGER_INVENTORY_INVALID", maximum=256)
        if (not TABLE_RE.fullmatch(trigger["name"]) or trigger["table"] not in tables
                or trigger["event"] not in {"INSERT", "UPDATE", "DELETE"}
                or trigger["timing"] not in {"BEFORE", "AFTER"}
                or trigger["orientation"] != "ROW" or trigger["definer"] != definer
                or trigger["name"] in trigger_names):
            refuse("EXPECTED_TRIGGER_INVENTORY_INVALID")
        _require_hex(trigger["action_sha256"], SHA256_RE, "EXPECTED_TRIGGER_INVENTORY_INVALID")
        trigger_names.add(trigger["name"])
    config = manifest["config"]
    if not isinstance(config, dict) or set(config) != {"app_key_fingerprint_sha256", "identity_sha256"}:
        refuse("CONFIG_EXPECTATION_INVALID")
    _require_hex(config["app_key_fingerprint_sha256"], SHA256_RE, "CONFIG_EXPECTATION_INVALID")
    _require_hex(config["identity_sha256"], SHA256_RE, "CONFIG_EXPECTATION_INVALID")
    for field in ("issued_at", "expires_at"):
        _require_string(manifest[field], "RELEASE_MANIFEST_TIME_INVALID", maximum=40)
    try:
        issued = datetime.datetime.fromisoformat(manifest["issued_at"].replace("Z", "+00:00"))
        expires = datetime.datetime.fromisoformat(manifest["expires_at"].replace("Z", "+00:00"))
    except ValueError:
        refuse("RELEASE_MANIFEST_TIME_INVALID")
    current = now or datetime.datetime.now(datetime.timezone.utc)
    if issued.tzinfo is None or expires.tzinfo is None or issued > current or expires <= current or expires <= issued:
        refuse("RELEASE_MANIFEST_EXPIRED_OR_INVALID")
    return manifest


def _policy_object(policy):
    required = {"schema_version", "policy_id", "destination_identity", "mysql", "isolation"}
    if not isinstance(policy, dict) or set(policy) != required or policy.get("schema_version") != 1:
        refuse("DESTINATION_POLICY_INVALID")
    _require_string(policy["policy_id"], "DESTINATION_POLICY_INVALID", maximum=96)
    identity = policy["destination_identity"]
    if not isinstance(identity, dict) or set(identity) != {"environment_id", "host_id"}:
        refuse("DESTINATION_POLICY_INVALID")
    for field in ("environment_id", "host_id"):
        _require_string(identity[field], "DESTINATION_POLICY_INVALID", maximum=256)
    mysql = policy["mysql"]
    if not isinstance(mysql, dict) or set(mysql) != {
            "host", "port", "server_uuid", "defaults_file", "client", "dump_client"}:
        refuse("DESTINATION_POLICY_INVALID")
    if not isinstance(mysql["host"], str) or not MYSQL_HOST_RE.fullmatch(mysql["host"]):
        refuse("DESTINATION_POLICY_NOT_ISOLATED")
    if type(mysql["port"]) is not int or not 1 <= mysql["port"] <= 65535:
        refuse("DESTINATION_POLICY_INVALID")
    _require_string(mysql["server_uuid"], "DESTINATION_POLICY_INVALID", maximum=64)
    if (not isinstance(mysql["defaults_file"], str) or not Path(mysql["defaults_file"]).is_absolute()
            or mysql["client"] != "/usr/bin/mysql" or mysql["dump_client"] != "/usr/bin/mysqldump"):
        refuse("DESTINATION_POLICY_INVALID")
    isolation = policy["isolation"]
    if (not isinstance(isolation, dict) or set(isolation) != {"approved", "kind", "network_id", "public_ports"}
            or isolation["approved"] is not True
            or isolation["kind"] != "disposable_mysql_container"
            or isolation["public_ports"] is not False
            or not isinstance(isolation["network_id"], str)
            or not re.fullmatch(r"wpcg_restore_net_[a-f0-9]{10}", isolation["network_id"])):
        refuse("DESTINATION_POLICY_NOT_ISOLATED")
    return policy


def verify_manifest_and_policy(manifest_path, policy_path, *, owner_uid=0):
    manifest, manifest_sha = read_protected_json(manifest_path, owner_uid=owner_uid, maximum=256 * 1024)
    policy, policy_sha = read_protected_json(policy_path, owner_uid=owner_uid, maximum=32 * 1024)
    manifest = _manifest_object(manifest)
    policy = _policy_object(policy)
    if manifest["destination_policy_sha256"] != policy_sha:
        refuse("DESTINATION_POLICY_HASH_MISMATCH")
    source = manifest["source_identity"]
    dest = policy["destination_identity"]
    mysql = policy["mysql"]
    if (source["environment_id"] == dest["environment_id"] or source["host_id"] == dest["host_id"]
            or source["server_uuid"] == mysql["server_uuid"]
            or manifest["mysql"]["server_uuid"] != mysql["server_uuid"]):
        refuse("DESTINATION_NOT_ISOLATED")
    return manifest, manifest_sha, policy, policy_sha


def _mysql_argv(policy, *, database=None, execute=None, dump=False, tables=()):
    mysql = policy["mysql"]
    executable = mysql["dump_client"] if dump else mysql["client"]
    argv = [executable, f"--defaults-file={mysql['defaults_file']}", "--protocol=tcp",
            f"--host={mysql['host']}", f"--port={mysql['port']}"]
    if dump:
        argv.extend(["--single-transaction", "--skip-lock-tables", "--quick", "--no-tablespaces",
                     "--set-gtid-purged=OFF", "--hex-blob", "--skip-comments",
                     "--default-character-set=utf8mb4", "--tz-utc", "--no-create-info",
                     "--skip-triggers", "--skip-add-locks", "--skip-disable-keys",
                     "--skip-set-charset", "--skip-extended-insert", "--complete-insert",
                     "--order-by-primary"])
        argv.append(database)
        argv.extend(tables)
    else:
        argv.extend(["--connect-timeout=5", "--batch", "--raw", "--skip-column-names",
                     "--default-character-set=utf8mb4",
                     "--init-command=SET SESSION sql_mode=''"])
        if database:
            argv.extend(["--database", database])
        if execute is not None:
            argv.extend(["--execute", execute])
        else:
            argv.append("--binary-mode")
    return argv


def _mysql_child_env():
    # Credentials are read only from the root-protected option file. Avoid
    # passing inherited environment secrets to MySQL client processes.
    return {"PATH": "/usr/bin:/bin", "LANG": "C", "LC_ALL": "C"}


def mysql_query(policy, sql, *, database=None, timeout=30, maximum=8_000_000):
    try:
        result = subprocess.run(_mysql_argv(policy, database=database, execute=sql),
                                stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, timeout=timeout, check=False,
                                env=_mysql_child_env(), close_fds=True)
    except (OSError, subprocess.TimeoutExpired):
        refuse("MYSQL_QUERY_UNAVAILABLE")
    if result.returncode != 0 or len(result.stdout) > maximum or len(result.stderr) > 16_384:
        refuse("MYSQL_QUERY_FAILED")
    return result.stdout


def _safe_dump_hash(policy, schema, table_names, *, maximum=20 * 1024 * 1024 * 1024, timeout=300):
    argv = _mysql_argv(policy, database=schema, dump=True, tables=table_names)
    process = None
    digest = hashlib.sha256()
    total = 0
    try:
        process = subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                   stderr=subprocess.PIPE, close_fds=True, env=_mysql_child_env())
        deadline = time.monotonic() + timeout
        streams = {process.stdout.fileno(): "stdout", process.stderr.fileno(): "stderr"}
        stderr_size = 0
        while streams:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                refuse("MYSQL_DUMP_TIMEOUT")
            ready, _, _ = __import__("select").select(list(streams), [], [], min(0.5, remaining))
            for fd in ready:
                kind = streams[fd]
                block = os.read(fd, 1024 * 1024 if kind == "stdout" else 4096)
                if not block:
                    del streams[fd]
                elif kind == "stdout":
                    total += len(block)
                    if total > maximum:
                        refuse("MYSQL_DUMP_SIZE_LIMIT")
                    digest.update(block)
                else:
                    stderr_size += len(block)
                    if stderr_size > 16_384:
                        refuse("MYSQL_DUMP_DIAGNOSTIC_LIMIT")
        if process.wait(timeout=max(0.1, deadline - time.monotonic())) != 0:
            refuse("MYSQL_DUMP_FAILED")
    except RestoreRefused:
        if process is not None and process.poll() is None:
            process.kill()
            process.wait()
        raise
    except (OSError, subprocess.TimeoutExpired):
        if process is not None and process.poll() is None:
            process.kill()
            process.wait()
        refuse("MYSQL_DUMP_FAILED")
    return digest.hexdigest(), total


def _mysql_identity(policy):
    raw = mysql_query(policy,
        "SELECT VERSION(),@@server_uuid,@@GLOBAL.log_bin,@@GLOBAL.binlog_format,"
        "@@GLOBAL.log_bin_trust_function_creators")
    try:
        fields = raw.decode("ascii", "strict").rstrip("\n").split("\t")
    except UnicodeError:
        refuse("MYSQL_IDENTITY_INVALID")
    if len(fields) != 5:
        refuse("MYSQL_IDENTITY_INVALID")
    return {"version": fields[0], "server_uuid": fields[1], "log_bin": fields[2],
            "binlog_format": fields[3], "trust_function_creators": fields[4]}


SCHEMA_QUERIES = (
    ("database_defaults", "SELECT DEFAULT_CHARACTER_SET_NAME,DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()"),
    ("tables", "SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME"),
    ("columns", "SELECT TABLE_NAME,ORDINAL_POSITION,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COALESCE(COLUMN_DEFAULT,''),EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION"),
    ("indexes", "SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COALESCE(SUB_PART,0),INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX"),
    ("foreign_keys", "SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,ORDINAL_POSITION,CASE WHEN REFERENCED_TABLE_SCHEMA=DATABASE() THEN '<self>' ELSE COALESCE(REFERENCED_TABLE_SCHEMA,'') END,COALESCE(REFERENCED_TABLE_NAME,''),COALESCE(REFERENCED_COLUMN_NAME,'') FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION"),
    ("constraints", "SELECT tc.TABLE_NAME,tc.CONSTRAINT_NAME,tc.CONSTRAINT_TYPE,COALESCE(cc.CHECK_CLAUSE,'') FROM information_schema.TABLE_CONSTRAINTS tc LEFT JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA=DATABASE() ORDER BY tc.TABLE_NAME,tc.CONSTRAINT_NAME"),
    ("partitions", "SELECT TABLE_NAME,COALESCE(PARTITION_NAME,''),COALESCE(SUBPARTITION_NAME,''),COALESCE(PARTITION_METHOD,''),COALESCE(PARTITION_EXPRESSION,''),COALESCE(PARTITION_DESCRIPTION,'') FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,PARTITION_ORDINAL_POSITION,SUBPARTITION_ORDINAL_POSITION"),
)
TRIGGER_QUERY = ("SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_TIMING,"
                 "ACTION_ORIENTATION,DEFINER,SHA2(ACTION_STATEMENT,256) FROM information_schema.TRIGGERS "
                 "WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME")
OBJECT_QUERY = ("SELECT 'views',COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() UNION ALL "
                "SELECT 'routines',COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() UNION ALL "
                "SELECT 'events',COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE() ORDER BY 1")
LEDGER_QUERY = "SELECT migration FROM migrations ORDER BY migration"


def observe_schema(policy, schema, *, expected_defaults=None):
    """Read-only schema/trigger fingerprint of one restored database.

    Shared by restore verification (``_inventory``) and migration rehearsal
    receipts so that both sides hash exactly the same observations. The hash
    does not depend on the schema name. ``expected_defaults`` is an optional
    ``(charset, collation)`` pair that is enforced while reading.
    """
    schema_hash = hashlib.sha256()
    components = {}
    for name, query in SCHEMA_QUERIES:
        raw = mysql_query(policy, query, database=schema)
        if name == "database_defaults" and expected_defaults is not None:
            if raw != (expected_defaults[0] + "\t" + expected_defaults[1] + "\n").encode("ascii"):
                refuse("DATABASE_DEFAULTS_MISMATCH")
        components[name] = hashlib.sha256(query.encode("ascii") + b"\0" + raw).hexdigest()
        schema_hash.update(query.encode("ascii") + b"\0" + raw + b"\0")
    trigger_raw = mysql_query(policy, TRIGGER_QUERY, database=schema)
    components["triggers"] = hashlib.sha256(TRIGGER_QUERY.encode("ascii") + b"\0" + trigger_raw).hexdigest()
    schema_hash.update(TRIGGER_QUERY.encode("ascii") + b"\0" + trigger_raw + b"\0")
    try:
        trigger_rows = [line.split("\t") for line in trigger_raw.decode("ascii", "strict").splitlines() if line]
    except UnicodeError:
        refuse("TRIGGER_INVENTORY_INVALID")
    triggers = []
    for row in trigger_rows:
        if len(row) != 7:
            refuse("TRIGGER_INVENTORY_INVALID")
        triggers.append({"name": row[0], "event": row[1], "table": row[2], "timing": row[3],
                         "orientation": row[4], "definer": row[5], "action_sha256": row[6]})
    object_raw = mysql_query(policy, OBJECT_QUERY, database=schema, maximum=256)
    components["non_table_objects"] = hashlib.sha256(OBJECT_QUERY.encode("ascii") + b"\0" + object_raw).hexdigest()
    schema_hash.update(OBJECT_QUERY.encode("ascii") + b"\0" + object_raw + b"\0")
    return {"schema_sha256": schema_hash.hexdigest(), "schema_components": components,
            "triggers": triggers, "object_raw": object_raw}


def observe_ledger(policy, schema):
    ledger_raw = mysql_query(policy, LEDGER_QUERY, database=schema)
    try:
        migrations = [line for line in ledger_raw.decode("ascii", "strict").splitlines() if line]
    except UnicodeError:
        refuse("MIGRATION_LEDGER_INVALID")
    return migrations, hashlib.sha256(ledger_raw).hexdigest()


def observe_row_counts(policy, schema, tables):
    count_sql = " UNION ALL ".join("SELECT '" + name + "',COUNT(*) FROM `" + name + "`" for name in tables)
    counts_raw = mysql_query(policy, count_sql + " ORDER BY 1", database=schema, timeout=180)
    try:
        counts = {}
        for row in counts_raw.decode("ascii", "strict").splitlines():
            fields = row.split("\t")
            if len(fields) != 2 or fields[0] not in tables or not re.fullmatch(r"0|[1-9][0-9]{0,19}", fields[1]):
                refuse("ROW_COUNT_INVENTORY_INVALID")
            counts[fields[0]] = int(fields[1])
    except UnicodeError:
        refuse("ROW_COUNT_INVENTORY_INVALID")
    return counts


def _inventory(policy, schema, manifest, plan):
    tables = plan["expected_tables"]
    if tables != manifest["expected"]["table_names"]:
        refuse("BACKUP_TABLE_INVENTORY_MISMATCH")
    observed_schema = observe_schema(
        policy, schema, expected_defaults=(manifest["database_charset"], manifest["database_collation"]))
    normalized = observed_schema["triggers"]
    if normalized != manifest["expected"]["triggers"]:
        refuse("TRIGGER_DEFINITION_MISMATCH")
    if observed_schema["object_raw"] not in (b"events\t0\nroutines\t0\nviews\t0\n", b"events\t0\nroutines\t0\nviews\t0"):
        refuse("UNEXPECTED_SCHEMA_OBJECT_PRESENT")
    schema_sha = observed_schema["schema_sha256"]
    if schema_sha != manifest["expected"]["schema_sha256"]:
        refuse("SCHEMA_FINGERPRINT_MISMATCH")
    migrations, ledger_sha = observe_ledger(policy, schema)
    if migrations != manifest["expected"]["migrations"] or ledger_sha != manifest["expected"]["ledger_sha256"]:
        refuse("MIGRATION_LEDGER_MISMATCH")
    counts = observe_row_counts(policy, schema, tables)
    if counts != manifest["expected"]["table_row_counts"]:
        refuse("ROW_COUNT_MISMATCH")
    data_sha, data_bytes = _safe_dump_hash(policy, schema, tables)
    if data_sha != manifest["expected"]["data_sha256"]:
        refuse("DATA_FINGERPRINT_MISMATCH")
    return {"schema_sha256": schema_sha, "schema_components": observed_schema["schema_components"],
            "ledger_sha256": ledger_sha, "data_sha256": data_sha, "data_bytes": data_bytes,
            "table_row_counts": counts, "migrations": migrations, "triggers": normalized}


def _normalized_grant(line):
    if not isinstance(line, str) or any(ord(c) < 32 for c in line):
        refuse("DEFINER_GRANT_INVENTORY_INVALID")
    return " ".join(line.replace("`", "").replace("'", "")
                     .replace('"', "").split()).casefold()


def _grant_pattern_identifier(name):
    """Quote literal underscores in MySQL privilege pattern identifiers."""
    return name.replace("\\", "\\\\").replace("_", "\\_")


def verify_definer_lifecycle(policy, schema, approved_definer, select_tables):
    """Require the imported trigger account to exist, be locked, and have exact grants."""
    if not TARGET_RE.fullmatch(schema) or not DEFINER_RE.fullmatch(approved_definer):
        refuse("DEFINER_ACCOUNT_CONTRACT_INVALID")
    user, host = approved_definer.split("@", 1)
    if not isinstance(select_tables, list) or any(not isinstance(name, str) or not TABLE_RE.fullmatch(name) for name in select_tables):
        refuse("DEFINER_ACCOUNT_CONTRACT_INVALID")
    locked = mysql_query(policy,
        "SELECT account_locked FROM mysql.user WHERE User='" + user + "' AND Host='" + host + "'",
        database=schema, maximum=32)
    if locked not in (b"Y\n", b"Y"):
        refuse("DEFINER_ACCOUNT_NOT_LOCKED")
    grants_raw = mysql_query(policy, "SHOW GRANTS FOR '" + user + "'@'" + host + "'",
                             database=schema, maximum=16_384)
    try:
        observed = {_normalized_grant(line) for line in grants_raw.decode("utf-8", "strict").splitlines() if line}
    except UnicodeError:
        refuse("DEFINER_GRANT_INVENTORY_INVALID")
    grant_schema = _grant_pattern_identifier(schema)
    expected = {_normalized_grant("GRANT USAGE ON *.* TO " + user + "@" + host),
                _normalized_grant("GRANT TRIGGER ON " + grant_schema + ".* TO " + user + "@" + host)}
    expected.update(_normalized_grant("GRANT SELECT ON " + grant_schema + "." + _grant_pattern_identifier(table)
                                      + " TO " + user + "@" + host)
                    for table in select_tables)
    if observed != expected:
        refuse("DEFINER_GRANTS_NOT_MINIMAL")
    return {"account_exists": True, "account_locked": True,
            "global_privileges": ["USAGE"], "schema_privileges": ["TRIGGER"],
            "select_tables": sorted(select_tables), "grants_sha256": hashlib.sha256(
                "\n".join(sorted(observed)).encode("utf-8")).hexdigest()}


def _config_identity(path, *, owner_uid=0):
    """Return full-config and APP_KEY fingerprints; never return secret values."""
    descriptor = None
    try:
        path = Path(path)
        if not path.is_absolute() or path != Path(os.path.normpath(str(path))):
            refuse("RECOVERED_CONFIG_PATH_INVALID")
        _check_protected_path_parents(path, owner_uid=owner_uid)
        descriptor = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0))
        info = os.fstat(descriptor)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != owner_uid or stat.S_IMODE(info.st_mode) != 0o600 or info.st_size > 1024 * 1024:
            refuse("RECOVERED_CONFIG_METADATA_INVALID")
        raw = bytearray()
        while len(raw) <= 1024 * 1024:
            block = os.read(descriptor, min(4096, 1024 * 1024 + 1 - len(raw)))
            if not block:
                break
            raw.extend(block)
        if len(raw) > 1024 * 1024:
            refuse("RECOVERED_CONFIG_SIZE_LIMIT")
    except RestoreRefused:
        raise
    except OSError:
        refuse("RECOVERED_CONFIG_UNAVAILABLE")
    finally:
        if descriptor is not None:
            os.close(descriptor)
    key_values = []
    try:
        for line in bytes(raw).decode("utf-8", "strict").splitlines():
            if line.startswith("APP_KEY="):
                key_values.append(line[len("APP_KEY="):])
    except UnicodeError:
        refuse("RECOVERED_CONFIG_INVALID")
    if len(key_values) != 1 or not key_values[0] or "\x00" in key_values[0]:
        refuse("RECOVERED_CONFIG_APP_KEY_INVALID")
    return (hashlib.sha256(bytes(raw)).hexdigest(),
            hashlib.sha256(key_values[0].encode("utf-8")).hexdigest())


def _atomic_json(path, value, *, owner_uid=0, replace=False):
    path = Path(path)
    if not path.is_absolute() or not path.parent.is_dir() or path.parent.is_symlink():
        refuse("RECEIPT_PATH_INVALID")
    try:
        _check_protected_path_parents(path, owner_uid=owner_uid)
        parent = path.parent.stat()
        if (parent.st_uid != owner_uid or parent.st_mode & 0o022
                or not stat.S_ISDIR(parent.st_mode)):
            refuse("RECEIPT_DIRECTORY_METADATA_INVALID")
        if path.exists() and not replace:
            refuse("RECEIPT_ALREADY_EXISTS")
        payload = json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=True).encode("ascii") + b"\n"
        tmp = path.parent / ("." + path.name + "." + secrets.token_hex(8) + ".tmp")
        fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o600)
        try:
            view = memoryview(payload)
            while view:
                view = view[os.write(fd, view):]
            os.fsync(fd)
            os.fchown(fd, owner_uid, -1)
        finally:
            os.close(fd)
        if replace:
            os.replace(tmp, path)
        else:
            os.link(tmp, path, follow_symlinks=False)
            os.unlink(tmp)
        dir_fd = os.open(path.parent, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
        try:
            os.fsync(dir_fd)
        finally:
            os.close(dir_fd)
    except RestoreRefused:
        raise
    except OSError:
        refuse("RECEIPT_WRITE_FAILED")
    return hashlib.sha256(payload).hexdigest()


def _preflight_receipt_paths(receipt_path, *, owner_uid):
    path = Path(receipt_path)
    if not path.is_absolute() or not path.parent.is_dir() or path.parent.is_symlink():
        refuse("RECEIPT_PATH_INVALID")
    parent = path.parent.stat()
    if parent.st_uid != owner_uid or parent.st_mode & 0o022:
        refuse("RECEIPT_DIRECTORY_METADATA_INVALID")
    _check_protected_path_parents(path, owner_uid=owner_uid)
    for candidate in (path, path.with_suffix(".pending.json"), path.with_suffix(".cleanup.json")):
        if candidate.exists() or candidate.is_symlink():
            refuse("RECEIPT_ALREADY_EXISTS")


def _run_mysql_import(policy, payload, timeout=600):
    argv = _mysql_argv(policy)
    try:
        result = subprocess.run(argv, input=payload, stdin=None, stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, timeout=timeout, check=False,
                                env=_mysql_child_env(), close_fds=True)
    except (OSError, subprocess.TimeoutExpired):
        refuse("MYSQL_IMPORT_FAILED")
    if result.returncode != 0 or len(result.stdout) > 4096 or len(result.stderr) > 16_384:
        refuse("MYSQL_IMPORT_FAILED")


def _create_database(policy, sql):
    result = mysql_query(policy, sql, timeout=30, maximum=4096)
    if result:
        refuse("TARGET_DATABASE_CREATE_UNEXPECTED_OUTPUT")


def _drop_database(policy, schema):
    if not TARGET_RE.fullmatch(schema):
        refuse("CLEANUP_SCHEMA_INVALID")
    current = _mysql_identity(policy)
    if current["server_uuid"] != policy["mysql"]["server_uuid"]:
        refuse("CLEANUP_DESTINATION_IDENTITY_MISMATCH")
    exists = mysql_query(policy,
        "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='" + schema + "'", maximum=16)
    if exists not in (b"0\n", b"1\n", b"0", b"1"):
        refuse("CLEANUP_SCHEMA_STATE_INVALID")
    if exists.strip() == b"1":
        mysql_query(policy, "DROP DATABASE `" + schema + "`", timeout=60, maximum=4096)
    remaining = mysql_query(policy,
        "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='" + schema + "'", maximum=16)
    if remaining.strip() != b"0":
        refuse("RESTORE_CLEANUP_FAILED")


def _restore_impl(manifest_path, policy_path, config_path, receipt_path, *, owner_uid):
    _preflight_receipt_paths(receipt_path, owner_uid=owner_uid)
    manifest, manifest_sha, policy, policy_sha = verify_manifest_and_policy(
        manifest_path, policy_path, owner_uid=owner_uid)
    backup = manifest["backup"]
    actual_config_sha, actual_app_key_sha = _config_identity(config_path, owner_uid=owner_uid)
    if (actual_config_sha != manifest["config"]["identity_sha256"]
            or actual_app_key_sha != manifest["config"]["app_key_fingerprint_sha256"]):
        refuse("RECOVERED_CONFIG_APP_KEY_MISMATCH")
    # Check owner-protected option-file before any MySQL process can consume it.
    read_protected_json_metadata(policy["mysql"]["defaults_file"], owner_uid=owner_uid, mode=0o600)
    compressed = read_backup_for_manifest(backup, owner_uid=owner_uid)
    sql = decode_verified_archive(compressed, backup["sha256"])
    plan = audit_sql(sql, manifest["target_schema"], manifest["approved_definer"])
    if (plan["expected_tables"] != manifest["expected"]["table_names"]
            or plan["charset"] != manifest["database_charset"]
            or plan["collation"] != manifest["database_collation"]):
        refuse("BACKUP_INVENTORY_MISMATCH")
    identity = _mysql_identity(policy)
    expected_mysql = manifest["mysql"]
    if (identity["version"] != expected_mysql["version"] or identity["server_uuid"] != expected_mysql["server_uuid"]
            or identity["log_bin"] != "1" or identity["binlog_format"] != "ROW"
            or identity["trust_function_creators"] != "0"):
        refuse("DESTINATION_MYSQL_CONTRACT_MISMATCH")
    exists = mysql_query(policy,
        "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='" + manifest["target_schema"] + "'", maximum=16)
    if exists.strip() != b"0":
        refuse("TARGET_SCHEMA_ALREADY_EXISTS")
    created = False
    create_attempted = False
    started = datetime.datetime.now(datetime.timezone.utc).isoformat()
    journal = {
        "journal_version": 1, "manifest_sha256": manifest_sha, "destination_policy_sha256": policy_sha,
        "target_schema": manifest["target_schema"], "destination_server_uuid": identity["server_uuid"],
        "state": "CREATE_PENDING", "created_at": started,
    }
    journal_path = Path(receipt_path).with_suffix(".pending.json")
    _atomic_json(journal_path, journal, owner_uid=owner_uid)
    try:
        create_attempted = True
        _create_database(policy, plan["create_sql"])
        created = True
        definer_contract = verify_definer_lifecycle(
            policy, manifest["target_schema"], manifest["approved_definer"],
            manifest["expected"]["definer_select_tables"])
        _run_mysql_import(policy, plan["transformed_sql"])
        post_import_definer_contract = verify_definer_lifecycle(
            policy, manifest["target_schema"], manifest["approved_definer"],
            manifest["expected"]["definer_select_tables"])
        if post_import_definer_contract != definer_contract:
            refuse("DEFINER_CONTRACT_CHANGED_DURING_IMPORT")
        post_import_identity = _mysql_identity(policy)
        if post_import_identity != identity:
            refuse("DESTINATION_MYSQL_SETTINGS_CHANGED_DURING_IMPORT")
        observed = _inventory(policy, manifest["target_schema"], manifest, plan)
        completed = datetime.datetime.now(datetime.timezone.utc).isoformat()
        receipt = {
            "receipt_version": 2, "classification": "release_bound_isolated_restore",
            "release_bound": True, "restore": "PASS", "release": manifest["release"],
            "backup": {"id": backup["id"], "sha256": backup["sha256"],
                       "size_bytes": backup["size_bytes"],
                       "source_application_sha": backup["source_application_sha"]},
            "manifest_sha256": manifest_sha, "destination_policy_sha256": policy_sha,
            "source_identity": manifest["source_identity"],
            "destination_identity": {**policy["destination_identity"], "mysql_server_uuid": identity["server_uuid"]},
            "target_schema": manifest["target_schema"], "started_at": started, "completed_at": completed,
            "observed": observed,
            "definer_lifecycle": definer_contract,
            "assertions": {
                "archive_hash_and_integrity": True,
                "source_identity_matches_protected_manifest": True,
                "destination_mysql_instance_matches_protected_policy": True,
                "destination_network_isolation_attested_by_root_policy": True,
                "complete_schema_verified": True,
                "complete_trigger_definition_and_definer_identity_verified": True,
                "definer_account_locked_with_exact_minimal_grants_verified": True,
                "exact_data_integrity_verified": True, "migration_ledger_verified": True,
                "protected_config_and_app_key_recovery_verified": True,
                "mysql_binlog_row_trust_off_verified": True,
                "global_mysql_settings_unchanged_during_import": True,
            },
            "cleanup": {"status": "retained_for_migration_rehearsal", "journal": str(journal_path)},
        }
        journal["state"] = "RESTORE_VERIFIED"
        receipt_payload = json.dumps(receipt, sort_keys=True, separators=(",", ":"), ensure_ascii=True).encode("ascii") + b"\n"
        journal["receipt_sha256"] = hashlib.sha256(receipt_payload).hexdigest()
        _atomic_json(journal_path, journal, owner_uid=owner_uid, replace=True)
        _atomic_json(receipt_path, receipt, owner_uid=owner_uid)
        return receipt
    except Exception:
        if created:
            try:
                _drop_database(policy, manifest["target_schema"])
                Path(receipt_path).unlink(missing_ok=True)
                Path(journal_path).unlink(missing_ok=True)
            except Exception:
                refuse("RESTORE_FAILED_CLEANUP_HOLD")
        elif create_attempted:
            try:
                current = _mysql_identity(policy)
                if current["server_uuid"] != policy["mysql"]["server_uuid"]:
                    refuse("CLEANUP_DESTINATION_IDENTITY_MISMATCH")
                exists_after_error = mysql_query(policy,
                    "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='" + manifest["target_schema"] + "'",
                    maximum=16)
                if exists_after_error.strip() == b"0":
                    Path(journal_path).unlink(missing_ok=True)
                elif exists_after_error.strip() == b"1":
                    journal["state"] = "CREATE_STATE_UNKNOWN"
                    _atomic_json(journal_path, journal, owner_uid=owner_uid, replace=True)
                    refuse("RESTORE_CREATE_STATE_UNKNOWN_HOLD")
                else:
                    refuse("RESTORE_CREATE_STATE_UNKNOWN_HOLD")
            except RestoreRefused:
                raise
            except Exception:
                refuse("RESTORE_CREATE_STATE_UNKNOWN_HOLD")
        else:
            Path(journal_path).unlink(missing_ok=True)
        raise


def restore(manifest_path, policy_path, config_path, receipt_path):
    if os.geteuid() != 0:
        refuse("ROOT_EXECUTION_REQUIRED")
    return _restore_impl(manifest_path, policy_path, config_path, receipt_path, owner_uid=0)


def read_protected_json_metadata(path, *, owner_uid, mode):
    path = Path(path)
    if not path.is_absolute() or path != Path(os.path.normpath(str(path))):
        refuse("PROTECTED_FILE_PATH_INVALID")
    _check_protected_path_parents(path, owner_uid=owner_uid)
    try:
        info = os.stat(path, follow_symlinks=False)
    except OSError:
        refuse("PROTECTED_FILE_UNAVAILABLE")
    if not stat.S_ISREG(info.st_mode) or info.st_uid != owner_uid or stat.S_IMODE(info.st_mode) != mode:
        refuse("PROTECTED_FILE_METADATA_INVALID")


def read_backup_for_manifest(backup, *, owner_uid):
    path = Path(backup["path"])
    if not path.is_absolute() or path != Path(os.path.normpath(str(path))):
        refuse("BACKUP_PATH_INVALID")
    _check_protected_path_parents(path, owner_uid=owner_uid)
    descriptor = None
    try:
        descriptor = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0))
        info = os.fstat(descriptor)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != owner_uid or stat.S_IMODE(info.st_mode) != 0o600:
            refuse("BACKUP_METADATA_INVALID")
        if info.st_size != backup["size_bytes"] or info.st_size > MAX_COMPRESSED_BACKUP_BYTES:
            refuse("BACKUP_SIZE_OR_IDENTITY_MISMATCH")
        chunks = []
        total = 0
        while True:
            block = os.read(descriptor, min(1024 * 1024, MAX_COMPRESSED_BACKUP_BYTES + 1 - total))
            if not block:
                break
            total += len(block)
            if total > MAX_COMPRESSED_BACKUP_BYTES:
                refuse("BACKUP_SIZE_LIMIT")
            chunks.append(block)
        compressed = b"".join(chunks)
    except RestoreRefused:
        raise
    except OSError:
        refuse("BACKUP_UNAVAILABLE")
    finally:
        if descriptor is not None:
            os.close(descriptor)
    if hashlib.sha256(compressed).hexdigest() != backup["sha256"]:
        refuse("BACKUP_HASH_MISMATCH")
    return compressed


def _cleanup_restore_impl(manifest_path, policy_path, receipt_path, *, owner_uid):
    manifest, manifest_sha, policy, policy_sha = verify_manifest_and_policy(
        manifest_path, policy_path, owner_uid=owner_uid)
    receipt, receipt_sha = read_protected_json(receipt_path, owner_uid=owner_uid, maximum=512 * 1024)
    journal_path = Path(receipt_path).with_suffix(".pending.json")
    journal, _journal_sha = read_protected_json(journal_path, owner_uid=owner_uid, maximum=32 * 1024)
    if (receipt.get("classification") != "release_bound_isolated_restore" or receipt.get("restore") != "PASS"
            or receipt.get("manifest_sha256") != manifest_sha
            or receipt.get("destination_policy_sha256") != policy_sha
            or journal.get("manifest_sha256") != manifest_sha
            or journal.get("destination_policy_sha256") != policy_sha
            or journal.get("receipt_sha256") != receipt_sha
            or journal.get("target_schema") != manifest["target_schema"]
            or journal.get("destination_server_uuid") != policy["mysql"]["server_uuid"]):
        refuse("CLEANUP_RECEIPT_BINDING_INVALID")
    _drop_database(policy, manifest["target_schema"])
    cleanup_receipt = {
        "cleanup_receipt_version": 1,
        "classification": "release_bound_isolated_restore_cleanup",
        "restore_receipt_sha256": receipt_sha,
        "manifest_sha256": manifest_sha,
        "destination_policy_sha256": policy_sha,
        "target_schema": manifest["target_schema"],
        "destination_server_uuid": policy["mysql"]["server_uuid"],
        "completed_at": datetime.datetime.now(datetime.timezone.utc).isoformat(),
        "cleanup": "PASS",
    }
    cleanup_path = Path(receipt_path).with_suffix(".cleanup.json")
    _atomic_json(cleanup_path, cleanup_receipt, owner_uid=owner_uid)
    Path(journal_path).unlink()
    return cleanup_receipt


def cleanup_restore(manifest_path, policy_path, receipt_path):
    if os.geteuid() != 0:
        refuse("ROOT_EXECUTION_REQUIRED")
    return _cleanup_restore_impl(manifest_path, policy_path, receipt_path, owner_uid=0)


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
            "source_identity_matches_protected_manifest": False,
            "destination_mysql_instance_matches_protected_policy": False,
            "destination_network_isolation_attested_by_root_policy": False,
            "complete_schema_verified": False,
            "complete_trigger_definition_and_definer_verified": False,
            "exact_data_integrity_verified": False,
            "protected_config_and_app_key_recovery_verified": False,
            "global_mysql_settings_unchanged_during_import": False,
            "release_manifest_verified": False,
        },
        "unverified": ["source_identity", "isolated_destination", "schema_inventory",
                       "trigger_definitions", "exact_data_integrity", "protected_config_app_key",
                       "release_binding"],
    }


def main(argv=None):
    parser = argparse.ArgumentParser(allow_abbrev=False)
    parser.add_argument("action", choices=("inspect", "restore", "cleanup"))
    parser.add_argument("--schema")
    parser.add_argument("--backup-id")
    parser.add_argument("--backup-sha256")
    parser.add_argument("--release-manifest")
    parser.add_argument("--destination-policy")
    parser.add_argument("--config-path")
    parser.add_argument("--receipt")
    args = parser.parse_args(argv)
    try:
        if args.action == "restore":
            if os.geteuid() != 0:
                refuse("ROOT_EXECUTION_REQUIRED")
            required = (args.release_manifest, args.destination_policy, args.config_path, args.receipt)
            if any(not value for value in required):
                refuse("RELEASE_MANIFEST_AND_ISOLATED_DESTINATION_REQUIRED")
            receipt = restore(*required)
            print(json.dumps(receipt, sort_keys=True, separators=(",", ":")))
            return 0
        if args.action == "cleanup":
            if os.geteuid() != 0:
                refuse("ROOT_EXECUTION_REQUIRED")
            if not args.release_manifest or not args.destination_policy or not args.receipt:
                refuse("CLEANUP_EVIDENCE_REQUIRED")
            receipt = cleanup_restore(args.release_manifest, args.destination_policy, args.receipt)
            print(json.dumps(receipt, sort_keys=True, separators=(",", ":")))
            return 0
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
