#!/usr/bin/env python3
"""Durable fail-closed journal for a single staging-next database release.

The journal is deliberately only a transaction coordinator: it does not run SQL,
create backups, open a maintenance gate, or select a database. Callers must pass
independently verified receipts and schema/ledger fingerprints. An interrupted
migration can be resumed only when observed ledger and schema evidence proves
either completion or that no database change occurred.
"""

from __future__ import annotations

import fcntl
import gzip
import hashlib
import json
import os
import re
import select
import secrets
import stat
import subprocess
import time
from contextlib import contextmanager
from dataclasses import dataclass, field
from pathlib import Path
from typing import Callable, Iterator, Mapping


PROFILE = "staging-next"
DATABASE = "wpcg_staging_next"
SCHEMA = 4
SHA_RE = re.compile(r"[0-9a-f]{64}\Z")
PHASE_B_POLICY_PATH = Path("/etc/wpcaregrid-deploy/profiles/staging-next-phase-b-1.4.8.json")
PHASE_B_CREDENTIALS_PATH = Path("/etc/wpcaregrid-deploy/credentials/staging-next-database-1.4.8.json")
PHASE_B_BACKUP_PATH = Path("/var/lib/wpcaregrid-deploy/staging-next/database-backups")
PHASE_B_TARGET_HOST = "127.0.0.1"
PHASE_B_TARGET_PORT = 3306
PHASE_B_RESTORE_HOST = "127.0.0.1"
PHASE_B_RESTORE_PORT = 3307
PHASE_B_WORKERS = ("wpcaregrid-staging-next-default", "wpcaregrid-staging-next-backups")
PHASE_B_SCHEDULER = "wpcaregrid-staging-next-scheduler.timer"
PHASE_B_WORKER_DISABLED_MARKER = Path(
    "/var/lib/wpcaregrid-deploy/staging-next/state/workers-disabled"
)
PHASE_B_SCHEDULER_PAUSED_MARKER = Path(
    "/var/lib/wpcaregrid-deploy/staging-next/state/scheduler-paused"
)
MIGRATION_RE = re.compile(r"[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+\Z")
STATES = (
    "PREPARED",
    "BACKUP_VERIFIED",
    "RESTORE_REHEARSAL_VERIFIED",
    "MIGRATION_REHEARSAL_VERIFIED",
    "FENCES_CLOSED",
    "MIGRATING",
    "MIGRATIONS_VERIFIED",
    "RELEASE_ACTIVATED",
    "ACCEPTANCE_VERIFIED",
    "COMPLETE",
    "RECOVERY_REQUIRED",
)
EVIDENCE_KEYS = {
    "BACKUP_VERIFIED": "backup_sha256",
    "RESTORE_REHEARSAL_VERIFIED": "restore_receipt_sha256",
    "MIGRATION_REHEARSAL_VERIFIED": "migration_rehearsal_receipt_sha256",
    "FENCES_CLOSED": "fence_receipt_sha256",
    "MIGRATIONS_VERIFIED": "migration_receipt_sha256",
    "RELEASE_ACTIVATED": "activation_receipt_sha256",
    "ACCEPTANCE_VERIFIED": "acceptance_receipt_sha256",
    "COMPLETE": "release_receipt_sha256",
}
RESTORE_CHECKPOINT_KEYS = {
    "backup_sha256", "target_schema_sha256", "target_ledger_sha256",
    "target_data_sha256", "restored_schema_sha256", "restored_ledger_sha256",
    "restored_data_sha256", "restore_mysql_uuid_sha256",
    "restore_receipt_sha256",
}
JOURNAL_KEYS = {
    "schema", "profile", "database", "transaction_id", "application_sha",
    "previous_sha", "artifact_sha256", "backup_sha256", "backup_filename", "mysql_uuid_sha256",
    "migrations", "state", "evidence", "migration_cursor", "migration_before",
    "migration_started_at", "updated_at",
}


class TransactionRefused(Exception):
    """Fixed-code refusal suitable for structured operator output."""


@dataclass(frozen=True)
class DatabaseExecutionPolicy:
    """Fully pinned root-owned runtime prerequisites for DB-changing releases."""

    target_uuid_sha256: str
    isolated_uuid_sha256: str
    backup_directory: Path
    credentials_file: Path
    credentials_sha256: str
    target_host: str
    target_port: int
    restore_host: str
    restore_port: int
    maintenance_file: Path
    worker_programs: tuple[str, ...]
    scheduler_unit: str
    worker_disabled_marker: Path
    scheduler_paused_marker: Path
    credentials: Mapping[str, object] = field(repr=False)


def _read_root_file(path: Path, *, mode: int, max_bytes: int) -> bytes:
    try:
        before = os.lstat(path)
        _require(stat.S_ISREG(before.st_mode) and not stat.S_ISLNK(before.st_mode)
                 and before.st_uid == 0 and stat.S_IMODE(before.st_mode) == mode
                 and before.st_size <= max_bytes, "phase_b_file_metadata_invalid")
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | getattr(os, "O_CLOEXEC", 0))
        try:
            after = os.fstat(fd)
            _require(stat.S_ISREG(after.st_mode) and after.st_uid == 0
                     and stat.S_IMODE(after.st_mode) == mode and after.st_ino == before.st_ino
                     and after.st_dev == before.st_dev and after.st_size == before.st_size,
                     "phase_b_file_changed")
            with os.fdopen(fd, "rb", closefd=False) as stream:
                raw = stream.read(max_bytes + 1)
        finally:
            os.close(fd)
        _require(len(raw) == before.st_size, "phase_b_file_read_incomplete")
        return raw
    except TransactionRefused:
        raise
    except OSError:
        raise TransactionRefused("phase_b_file_unavailable") from None


def load_execution_policy(policy_path: Path, pinned_sha256: str | None, *,
                          expected_target_uuid_sha256: str,
                          maintenance_file: Path,
                          test_only_paths: Mapping[str, Path] | None = None) -> DatabaseExecutionPolicy:
    """Read a digest-pinned DBA policy; absent, unknown, or altered policy is HOLD."""
    paths = test_only_paths or {}
    expected_policy = paths.get("policy", PHASE_B_POLICY_PATH)
    expected_credentials = paths.get("credentials", PHASE_B_CREDENTIALS_PATH)
    expected_backup = paths.get("backup", PHASE_B_BACKUP_PATH)
    expected_target_host = paths.get("target_host", PHASE_B_TARGET_HOST)
    expected_target_port = paths.get("target_port", PHASE_B_TARGET_PORT)
    expected_restore_host = paths.get("restore_host", PHASE_B_RESTORE_HOST)
    expected_restore_port = paths.get("restore_port", PHASE_B_RESTORE_PORT)
    expected_worker_marker = paths.get("worker_disabled_marker", PHASE_B_WORKER_DISABLED_MARKER)
    expected_scheduler_marker = paths.get("scheduler_paused_marker", PHASE_B_SCHEDULER_PAUSED_MARKER)
    _require(policy_path == expected_policy and _hash(pinned_sha256), "phase_b_policy_unpinned")
    _require(_hash(expected_target_uuid_sha256), "phase_b_target_identity_unavailable")
    policy_raw = _read_root_file(policy_path, mode=0o644, max_bytes=32_768)
    _require(hashlib.sha256(policy_raw).hexdigest() == pinned_sha256, "phase_b_policy_hash_mismatch")
    try:
        policy = json.loads(policy_raw.decode("utf-8"), object_pairs_hook=_unique_json)
    except (UnicodeError, ValueError, json.JSONDecodeError):
        raise TransactionRefused("phase_b_policy_invalid") from None
    keys = {
        "schema", "profile", "enabled", "target_database", "target_uuid_sha256",
        "isolated_uuid_sha256", "backup_directory", "credentials_file", "credentials_sha256",
        "target_host", "target_port", "restore_host", "restore_port",
        "maintenance_file", "worker_programs", "scheduler_unit",
        "worker_disabled_marker", "scheduler_paused_marker",
    }
    _require(isinstance(policy, dict) and set(policy) == keys
             and type(policy.get("schema")) is int and policy["schema"] == 1
             and policy.get("profile") == PROFILE and policy.get("enabled") is True
             and policy.get("target_database") == DATABASE
             and policy.get("target_uuid_sha256") == expected_target_uuid_sha256
             and _hash(policy.get("isolated_uuid_sha256"))
             and policy["isolated_uuid_sha256"] != expected_target_uuid_sha256
             and policy.get("backup_directory") == str(expected_backup)
             and policy.get("credentials_file") == str(expected_credentials)
             and _hash(policy.get("credentials_sha256"))
             and policy.get("target_host") == expected_target_host
             and type(policy.get("target_port")) is int and policy["target_port"] == expected_target_port
             and policy.get("restore_host") == expected_restore_host
             and type(policy.get("restore_port")) is int and policy["restore_port"] == expected_restore_port
             and policy.get("maintenance_file") == str(maintenance_file)
             and policy.get("worker_programs") == list(PHASE_B_WORKERS)
             and policy.get("scheduler_unit") == PHASE_B_SCHEDULER
             and policy.get("worker_disabled_marker") == str(expected_worker_marker)
             and policy.get("scheduler_paused_marker") == str(expected_scheduler_marker),
             "phase_b_policy_contract_invalid")
    backup_directory = Path(policy["backup_directory"])
    credentials_file = Path(policy["credentials_file"])
    try:
        backup_info = os.lstat(backup_directory)
        _require(stat.S_ISDIR(backup_info.st_mode) and not stat.S_ISLNK(backup_info.st_mode)
                 and backup_info.st_uid == 0 and stat.S_IMODE(backup_info.st_mode) == 0o700,
                 "phase_b_backup_directory_invalid")
    except OSError:
        raise TransactionRefused("phase_b_resource_unavailable") from None
    credential_raw = _read_root_file(credentials_file, mode=0o600, max_bytes=16_384)
    _require(hashlib.sha256(credential_raw).hexdigest() == policy["credentials_sha256"],
             "phase_b_credentials_identity_mismatch")
    try:
        credentials = json.loads(credential_raw.decode("utf-8"), object_pairs_hook=_unique_json)
    except (UnicodeError, ValueError, json.JSONDecodeError):
        raise TransactionRefused("phase_b_credentials_invalid") from None
    _require(isinstance(credentials, dict) and set(credentials) == {"schema", "target", "isolated"}
             and type(credentials.get("schema")) is int and credentials["schema"] == 1,
             "phase_b_credentials_invalid")
    for name, endpoint in credentials.items():
        if name == "schema":
            continue
        _require(isinstance(endpoint, dict) and set(endpoint) == {"username", "password"}
                 and all(isinstance(endpoint.get(field), str) and endpoint[field]
                         and "\x00" not in endpoint[field] and "\n" not in endpoint[field]
                         and "\r" not in endpoint[field] for field in ("username", "password")),
                 "phase_b_credentials_invalid")
    return DatabaseExecutionPolicy(
        target_uuid_sha256=policy["target_uuid_sha256"], isolated_uuid_sha256=policy["isolated_uuid_sha256"],
        backup_directory=backup_directory, credentials_file=credentials_file,
        credentials_sha256=policy["credentials_sha256"],
        target_host=policy["target_host"], target_port=policy["target_port"],
        restore_host=policy["restore_host"], restore_port=policy["restore_port"],
        maintenance_file=maintenance_file, worker_programs=tuple(policy["worker_programs"]),
        scheduler_unit=policy["scheduler_unit"],
        worker_disabled_marker=Path(policy["worker_disabled_marker"]),
        scheduler_paused_marker=Path(policy["scheduler_paused_marker"]), credentials=credentials,
    )


def _fixed_status(argv: list[str], *, expected_code: int, expected_output: str,
                  maximum: int = 4096, runner=subprocess.run) -> bool:
    try:
        result = runner(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                        stderr=subprocess.DEVNULL, timeout=5, check=False,
                        env={"PATH": "/usr/bin:/bin", "LANG": "C"})
    except (OSError, subprocess.TimeoutExpired):
        return False
    return (result.returncode == expected_code and len(result.stdout) <= maximum
            and result.stdout.decode("ascii", "replace").strip() == expected_output)


def _supervisor_worker_stopped(program: str, *, runner=subprocess.run) -> bool:
    if program not in PHASE_B_WORKERS:
        return False
    try:
        result = runner(["/usr/bin/supervisorctl", "status", program], stdin=subprocess.DEVNULL,
                        stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=5,
                        check=False, env={"PATH": "/usr/bin:/bin", "LANG": "C"})
    except (OSError, subprocess.TimeoutExpired):
        return False
    if result.returncode not in (0, 3) or len(result.stdout) > 4096:
        return False
    fields = result.stdout.decode("ascii", "replace").strip().split()
    return len(fields) >= 2 and fields[0] == program and fields[1] == "STOPPED"


def verify_background_fences(policy: DatabaseExecutionPolicy, *,
                              runner=subprocess.run) -> str:
    """Require durable worker/scheduler fences and independently inactive services."""
    for path, expected in (
        (policy.worker_disabled_marker, b"schema=1;profile=staging-next;workers=disabled\n"),
        (policy.scheduler_paused_marker, b"schema=1;profile=staging-next;scheduler=paused\n"),
    ):
        _require(_read_root_file(path, mode=0o600, max_bytes=256) == expected,
                 "phase_b_background_fence_marker_invalid")
    _require(all(_supervisor_worker_stopped(item, runner=runner) for item in policy.worker_programs),
             "phase_b_worker_not_stopped")
    _require(_fixed_status(["/bin/systemctl", "show", "--property=ActiveState", "--value",
                            policy.scheduler_unit], expected_code=0, expected_output="inactive",
                           runner=runner),
             "phase_b_scheduler_not_inactive")
    try:
        result = runner(["/usr/bin/systemctl", "show", "--property=UnitFileState", "--value",
                         policy.scheduler_unit], stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                        stderr=subprocess.DEVNULL, timeout=5, check=False,
                        env={"PATH": "/usr/bin:/bin", "LANG": "C"})
    except (OSError, subprocess.TimeoutExpired):
        raise TransactionRefused("phase_b_scheduler_state_unavailable") from None
    _require(result.returncode == 0 and len(result.stdout) <= 4096
             and result.stdout.decode("ascii", "replace").strip() in {"disabled", "masked"},
             "phase_b_scheduler_not_persistently_paused")
    try:
        census = runner(["/usr/bin/ps", "-eo", "args="], stdin=subprocess.DEVNULL,
                        stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=5,
                        check=False, env={"PATH": "/usr/bin:/bin", "LANG": "C"})
    except (OSError, subprocess.TimeoutExpired):
        raise TransactionRefused("phase_b_consumer_census_unavailable") from None
    _require(census.returncode == 0 and len(census.stdout) <= 1_000_000,
             "phase_b_consumer_census_unavailable")
    consumers = re.compile(
        rb"(?:^|\s)artisan"
        rb"(?:(?:\s+--env(?:=[^\s]+|\s+[^\s]+)?|\s+--[a-z][a-z0-9-]*(?:=[^\s]+)?|\s+-[a-zA-Z]+))*"
        rb"\s+(?:queue:(?:work|listen)|schedule:(?:run|work)|horizon(?::work)?)(?:\s|$)"
    )
    _require(consumers.search(census.stdout) is None, "phase_b_background_consumer_active")
    digest = hashlib.sha256(
        b"workers=stopped;scheduler=inactive-disabled;consumer_census=empty;markers=verified"
    ).hexdigest()
    return digest


def _mysql_defaults(credentials: Mapping[str, object], host: str, port: int) -> tuple[int, str]:
    _require(set(credentials) == {"username", "password"}, "phase_b_credentials_invalid")
    username, password = credentials.get("username"), credentials.get("password")
    _require(isinstance(username, str) and username and isinstance(password, str) and password
             and all(character not in username + password for character in "\x00\r\n"),
             "phase_b_credentials_invalid")
    _require(isinstance(host, str) and host and "\x00" not in host and isinstance(port, int)
             and 1 <= port <= 65535, "phase_b_endpoint_invalid")
    if not hasattr(os, "memfd_create"):
        raise TransactionRefused("phase_b_secret_memfd_unavailable")
    def quote(value: str) -> str:
        return '"' + value.replace("\\", "\\\\").replace('"', '\\"') + '"'
    endpoint = ("user=" + quote(username) + "\npassword=" + quote(password)
                + "\nhost=" + quote(host) + f"\nport={port}\nprotocol=tcp\n")
    # mysqldump reads both [client] and [mysqldump]; explicitly duplicate
    # credentials in its own group for client builds that don't apply the
    # shared group consistently. The option file remains an inherited memfd.
    raw = ("[client]\n" + endpoint + "[mysqldump]\n" + endpoint).encode("utf-8")
    fd = os.memfd_create("wpcg-phase-b-mysql-options", flags=0)
    try:
        # MySQL ignores option files with permissions accessible to other
        # users. memfd defaults are not guaranteed to be private, so enforce
        # the same mode as a protected credential file before writing secrets.
        os.fchmod(fd, 0o600)
        os.write(fd, raw)
        os.lseek(fd, 0, os.SEEK_SET)
    except OSError:
        os.close(fd)
        raise TransactionRefused("phase_b_secret_memfd_unavailable") from None
    return fd, f"/proc/self/fd/{fd}"


def _mysql_query(policy: DatabaseExecutionPolicy, *, isolated: bool, sql: str,
                 database: str | None = None, timeout: float = 15.0, maximum: int = 1_000_000) -> bytes:
    _require(isinstance(sql, str) and 1 <= len(sql) <= 64_000 and "\x00" not in sql,
             "phase_b_query_invalid")
    isolated_credentials = policy.credentials["isolated"]
    endpoint_credentials = isolated_credentials if isolated else policy.credentials["target"]
    host = policy.restore_host if isolated else policy.target_host
    port = policy.restore_port if isolated else policy.target_port
    fd, defaults_path = _mysql_defaults(endpoint_credentials, host, port)
    argv = ["/usr/bin/mysql", f"--defaults-file={defaults_path}", "--batch", "--skip-column-names",
            "--raw", "--default-character-set=utf8mb4"]
    if database is not None:
        _require(re.fullmatch(r"[a-z][a-z0-9_]{0,63}", database) is not None,
                 "phase_b_database_name_invalid")
        argv.extend(["--database", database])
    try:
        result = subprocess.run(argv, input=sql.encode("utf-8"), stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, timeout=timeout, check=False,
                                pass_fds=(fd,), close_fds=True)
    except subprocess.TimeoutExpired:
        raise TransactionRefused("phase_b_mysql_timeout") from None
    except OSError:
        raise TransactionRefused("phase_b_mysql_unavailable") from None
    finally:
        os.close(fd)
    if result.returncode != 0:
        raise TransactionRefused(_mysql_query_failure_code(result.stderr + b"\n" + result.stdout[:16_384]))
    _require(len(result.stdout) <= maximum and len(result.stderr) <= 16_384,
             "phase_b_mysql_query_output_invalid")
    return result.stdout


def _mysql_query_failure_code(stderr_sample: bytes) -> str:
    """Reduce transient mysql-client stderr to numeric server codes only."""
    diagnostic = stderr_sample[:16_384].lower()
    match = re.search(rb"\berror\s+([0-9]{1,5})\s+\(([a-z0-9]{3,8})\)", diagnostic)
    if match is None:
        match = re.search(rb"\berror\s*:\s*([0-9]{1,5})\b", diagnostic)
    if match is not None:
        number = match.group(1).decode("ascii")
        state = match.group(2).decode("ascii") if match.lastindex and match.lastindex > 1 else "UNKNOWN"
        return f"phase_b_mysql_errno_{number}_sqlstate_{state}"
    if b"access denied" in diagnostic:
        return "phase_b_mysql_authorization_failed"
    return "phase_b_mysql_query_failed"


def _database_data_fingerprint(policy: DatabaseExecutionPolicy, *, database: str,
                               isolated: bool, table_names: list[str],
                               maximum_bytes: int = 20 * 1024 * 1024 * 1024,
                               timeout: float = 300.0) -> tuple[str, int]:
    """Hash a canonical, ordered SQL data stream in memory without retaining rows."""
    _require(re.fullmatch(r"wpcg_staging_next(?:_(?:restore|rehearsal)_[0-9a-f]{32})?", database)
             is not None and 0 <= len(table_names) <= 256
             and all(re.fullmatch(r"[A-Za-z0-9_$]{1,64}", name) for name in table_names),
             "phase_b_data_fingerprint_input_invalid")
    if not table_names:
        return hashlib.sha256(b"").hexdigest(), 0
    credentials = policy.credentials["isolated" if isolated else "target"]
    host = policy.restore_host if isolated else policy.target_host
    port = policy.restore_port if isolated else policy.target_port
    option_fd, defaults_path = _mysql_defaults(credentials, host, port)
    argv = [
        "/usr/bin/mysqldump", f"--defaults-file={defaults_path}", "--protocol=tcp",
        f"--host={host}", f"--port={port}", f"--user={credentials['username']}",
        "--single-transaction", "--skip-lock-tables", "--quick", "--no-tablespaces",
        "--set-gtid-purged=OFF",
        "--hex-blob", "--skip-comments", "--default-character-set=utf8mb4", "--tz-utc",
        "--no-create-info", "--skip-triggers", "--skip-add-locks", "--skip-disable-keys",
        "--skip-set-charset", "--skip-extended-insert", "--complete-insert", "--order-by-primary",
        database, *table_names,
    ]
    process = None
    stderr_sample = bytearray()
    stderr_truncated = False
    digest = hashlib.sha256()
    total = 0
    try:
        process = subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                   stderr=subprocess.PIPE, close_fds=True, pass_fds=(option_fd,))
        os.close(option_fd)
        option_fd = -1
        deadline = time.monotonic() + timeout
        streams = {process.stdout.fileno(): "stdout", process.stderr.fileno(): "stderr"}
        while streams:
            remaining = deadline - time.monotonic()
            _require(remaining > 0, "phase_b_data_fingerprint_timeout")
            ready, _, _ = select.select(list(streams), [], [], min(0.5, remaining))
            if not ready:
                continue
            for source_fd in ready:
                kind = streams[source_fd]
                block = os.read(source_fd, 1024 * 1024 if kind == "stdout" else 4096)
                if not block:
                    del streams[source_fd]
                    continue
                if kind == "stdout":
                    total += len(block)
                    _require(total <= maximum_bytes, "phase_b_data_fingerprint_size_limit")
                    digest.update(block)
                elif len(stderr_sample) < 16_384:
                    remaining_sample = 16_384 - len(stderr_sample)
                    stderr_sample.extend(block[:remaining_sample])
                    stderr_truncated = stderr_truncated or len(block) > remaining_sample
                else:
                    stderr_truncated = True
        return_code = process.wait(timeout=max(0.1, deadline - time.monotonic()))
        _require(return_code == 0 and not stderr_truncated,
                 _mysql_query_failure_code(bytes(stderr_sample)) if return_code != 0
                 else "phase_b_data_fingerprint_diagnostic_overflow")
        return digest.hexdigest(), total
    except TransactionRefused:
        if process is not None and process.poll() is None:
            process.kill()
            process.wait()
        raise
    except subprocess.TimeoutExpired:
        if process is not None and process.poll() is None:
            process.kill()
            process.wait()
        raise TransactionRefused("phase_b_data_fingerprint_timeout") from None
    except OSError:
        if process is not None and process.poll() is None:
            process.kill()
            process.wait()
        raise TransactionRefused("phase_b_data_fingerprint_unavailable") from None
    finally:
        if option_fd >= 0:
            os.close(option_fd)


def database_fingerprint(policy: DatabaseExecutionPolicy, *, database: str,
                          isolated: bool) -> dict[str, object]:
    """Return a content-free MySQL identity and canonical schema/ledger digests."""
    _require(re.fullmatch(r"wpcg_staging_next(?:_(?:restore|rehearsal)_[0-9a-f]{32})?", database)
             is not None, "phase_b_database_name_invalid")
    identity = _mysql_query(
        policy, isolated=isolated,
        sql="SELECT VERSION(),@@server_uuid,DATABASE()", database=database, maximum=4096,
    ).decode("utf-8", "strict").rstrip("\n").split("\t")
    _require(len(identity) == 3 and identity[2] == database
             and re.fullmatch(r"8\.4\.[0-9]+(?:[-+][A-Za-z0-9._-]+)?", identity[0]) is not None,
             "phase_b_database_identity_mismatch")
    uuid_digest = hashlib.sha256(identity[1].encode("ascii", "strict")).hexdigest()
    expected_uuid = policy.isolated_uuid_sha256 if isolated else policy.target_uuid_sha256
    _require(uuid_digest == expected_uuid, "phase_b_database_instance_mismatch")

    # Each fixed metadata query is sorted and contains schema definitions only;
    # application rows and credentials never enter the evidence digest.
    schema_queries = (
        "SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES "
        "WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME",
        "SELECT TABLE_NAME,ORDINAL_POSITION,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,"
        "COALESCE(COLUMN_DEFAULT,''),EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS "
        "WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION",
        "SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,"
        "COALESCE(SUB_PART,0),INDEX_TYPE FROM information_schema.STATISTICS "
        "WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX",
        "SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,ORDINAL_POSITION,"
        "CASE WHEN REFERENCED_TABLE_SCHEMA=DATABASE() THEN '<self>' "
        "ELSE COALESCE(REFERENCED_TABLE_SCHEMA,'') END,COALESCE(REFERENCED_TABLE_NAME,''),"
        "COALESCE(REFERENCED_COLUMN_NAME,'') FROM information_schema.KEY_COLUMN_USAGE "
        "WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION",
        "SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_TIMING,ACTION_STATEMENT "
        "FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME",
    )
    schema_hash = hashlib.sha256()
    schema_components: dict[str, str] = {}
    for component, query in zip(("tables", "columns", "indexes", "foreign_keys", "triggers"),
                               schema_queries, strict=True):
        component_hash = hashlib.sha256()
        component_hash.update(query.encode("ascii"))
        component_hash.update(b"\0")
        query_result = _mysql_query(policy, isolated=isolated, sql=query, database=database,
                                    timeout=30.0, maximum=8_000_000)
        component_hash.update(query_result)
        schema_components[component] = component_hash.hexdigest()
        schema_hash.update(query.encode("ascii"))
        schema_hash.update(b"\0")
        schema_hash.update(query_result)
        schema_hash.update(b"\0")
    ledger_raw = _mysql_query(policy, isolated=isolated,
                              sql="SELECT migration FROM migrations ORDER BY migration",
                              database=database, timeout=30.0, maximum=1_000_000)
    try:
        migrations = tuple(line for line in ledger_raw.decode("ascii", "strict").splitlines() if line)
    except UnicodeError:
        raise TransactionRefused("phase_b_migration_ledger_invalid") from None
    _require(len(migrations) == len(set(migrations))
             and all(MIGRATION_RE.fullmatch(item) for item in migrations),
             "phase_b_migration_ledger_invalid")
    table_rows = _mysql_query(
        policy, isolated=isolated,
        sql="SELECT t.TABLE_NAME,COALESCE(t.ENGINE,''),CASE WHEN EXISTS ("
            "SELECT 1 FROM information_schema.STATISTICS s WHERE s.TABLE_SCHEMA=t.TABLE_SCHEMA "
            "AND s.TABLE_NAME=t.TABLE_NAME AND s.INDEX_NAME='PRIMARY') THEN 1 ELSE 0 END "
            "FROM information_schema.TABLES t WHERE t.TABLE_SCHEMA=DATABASE() "
            "AND t.TABLE_TYPE='BASE TABLE' ORDER BY t.TABLE_NAME",
        database=database, timeout=30.0, maximum=1_000_000,
    ).decode("ascii", "strict").splitlines()
    _require(0 < len(table_rows) <= 256, "phase_b_table_set_unbounded")
    table_names = []
    for row in table_rows:
        fields = row.split("\t")
        _require(len(fields) == 3 and re.fullmatch(r"[A-Za-z0-9_$]{1,64}", fields[0]) is not None,
                 "phase_b_table_metadata_invalid")
        _require(fields[1].lower() == "innodb", "phase_b_nontransactional_table_unsupported")
        _require(fields[2] == "1", "phase_b_table_without_primary_key_unsupported")
        table_names.append(fields[0])
    count_selects = []
    for name in table_names:
        # Restrictive identifier validation above makes this fixed quoting safe.
        count_selects.append(
            "SELECT '" + name + "',COUNT(*) FROM `" + name + "`"
        )
    counts_raw = _mysql_query(
        policy, isolated=isolated, sql=" UNION ALL ".join(count_selects) + " ORDER BY 1",
        database=database, timeout=180.0, maximum=2_000_000,
    )
    try:
        counts = []
        for row in counts_raw.decode("ascii", "strict").splitlines():
            fields = row.split("\t")
            _require(len(fields) == 2 and fields[0] in table_names
                     and re.fullmatch(r"0|[1-9][0-9]{0,19}", fields[1]) is not None,
                     "phase_b_table_count_invalid")
            counts.append((fields[0], int(fields[1])))
    except UnicodeError:
        raise TransactionRefused("phase_b_table_count_invalid") from None
    _require(len(counts) == len(table_names)
             and len({name for name, _count in counts}) == len(table_names),
             "phase_b_table_count_incomplete")
    dump_sha256, dump_bytes = _database_data_fingerprint(
        policy, database=database, isolated=isolated, table_names=table_names)
    data_sha256 = hashlib.sha256(json.dumps({
        "counts": counts, "dump_sha256": dump_sha256, "dump_bytes": dump_bytes,
    }, separators=(",", ":"), ensure_ascii=True).encode("ascii")).hexdigest()
    return {
        "database": database,
        "mysql_version": identity[0],
        "mysql_uuid_sha256": uuid_digest,
        "schema_sha256": schema_hash.hexdigest(),
        "schema_components": schema_components,
        "ledger_sha256": hashlib.sha256(ledger_raw).hexdigest(),
        "data_sha256": data_sha256,
        "migrations": migrations,
    }


def _verify_backup_sql(path: Path, *, expected_uid: int | None = None,
                       expected_mode: int | None = None) -> tuple[str, int]:
    digest = hashlib.sha256()
    compressed_size = 0
    forbidden = re.compile(
        rb"(?i)^\s*(?:CREATE\s+DATABASE|DROP\s+DATABASE|USE\s+|SET\s+(?:@@GLOBAL|GLOBAL|PERSIST)|"
        rb"SET\s+@@SESSION\.SQL_LOG_BIN|SET\s+@@SESSION\.GTID_PURGED|SET\s+@@GLOBAL\.GTID_PURGED)\b"
    )
    qualified_schema = re.compile(rb"(?i)`(?:wpcaregrid|mysql|performance_schema|sys)`\s*\.\s*`")
    try:
        before = os.lstat(path)
        _require(stat.S_ISREG(before.st_mode) and not stat.S_ISLNK(before.st_mode)
                 and (expected_uid is None or before.st_uid == expected_uid)
                 and (expected_mode is None or stat.S_IMODE(before.st_mode) == expected_mode),
                 "phase_b_backup_file_metadata_invalid")
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | getattr(os, "O_CLOEXEC", 0))
        try:
            opened = os.fstat(fd)
            _require(stat.S_ISREG(opened.st_mode) and opened.st_ino == before.st_ino
                     and opened.st_dev == before.st_dev and opened.st_size == before.st_size
                     and (expected_uid is None or opened.st_uid == expected_uid)
                     and (expected_mode is None or stat.S_IMODE(opened.st_mode) == expected_mode),
                     "phase_b_backup_file_changed")
            with os.fdopen(fd, "rb", closefd=False) as raw:
                while True:
                    block = raw.read(1024 * 1024)
                    if not block:
                        break
                    digest.update(block)
                    compressed_size += len(block)
                raw.seek(0)
                with gzip.GzipFile(fileobj=raw, mode="rb") as stream:
                    while True:
                        line = stream.readline(1_048_577)
                        if not line:
                            break
                        _require(len(line) <= 1_048_576, "phase_b_backup_line_too_large")
                        _require(not forbidden.search(line) and not qualified_schema.search(line),
                                 "phase_b_backup_sql_unsafe")
        finally:
            os.close(fd)
    except TransactionRefused:
        raise
    except (OSError, EOFError, gzip.BadGzipFile):
        raise TransactionRefused("phase_b_backup_integrity_failed") from None
    _require(compressed_size > 0, "phase_b_backup_empty")
    return digest.hexdigest(), compressed_size


def _backup_failure_code(stderr_sample: bytes) -> str:
    """Map bounded, transient mysqldump stderr to a fixed public code."""
    diagnostic = stderr_sample[:16_384].lower()
    errno = re.search(rb"\b(?:error\s*:\s*|error\s+|errno\s*[=:]?\s*)([0-9]{1,5})\b", diagnostic)
    if errno is None:
        # MySQL clients commonly append the server errno as the final
        # parenthesized token, e.g. "Couldn't execute ... (1142)".
        # Keep only the numeric code; never return the surrounding text.
        errno = re.search(rb"\(([1-9][0-9]{3})\)(?:\s|$)", diagnostic)
    if errno is not None:
        number = errno.group(1).decode("ascii")
        if number == "2002" and b"through socket" in diagnostic:
            return "phase_b_backup_mysql_errno_2002_local_socket_route"
        if number in {"2002", "2003"} and b"can't connect" in diagnostic:
            return f"phase_b_backup_mysql_errno_{number}_network_route"
        return f"phase_b_backup_mysql_errno_{number}"
    if b"access denied" in diagnostic or b"command denied" in diagnostic or b" denied" in diagnostic:
        return "phase_b_backup_authorization_failed"
    if b"unknown option" in diagnostic or b"unknown variable" in diagnostic:
        return "phase_b_backup_client_contract_failed"
    if b"privilege" in diagnostic and (b"doesn't have" in diagnostic or b"does not have" in diagnostic
                                        or b"requires" in diagnostic or b"lacks" in diagnostic
                                        or b"you need" in diagnostic):
        return "phase_b_backup_privilege_missing"
    if b"couldn't execute" in diagnostic or b"could not execute" in diagnostic:
        return "phase_b_backup_metadata_query_failed"
    if diagnostic:
        return "phase_b_backup_server_rejected"
    return "phase_b_backup_command_failed"


def create_database_backup(policy: DatabaseExecutionPolicy, *, application_sha: str,
                           maximum_bytes: int = 20 * 1024 * 1024 * 1024,
                           timeout: float = 300.0) -> tuple[Path, str]:
    """Create a private, non-overwriting, SQL-safety-checked staging DB backup."""
    _require(re.fullmatch(r"[0-9a-f]{40}", application_sha) is not None,
             "phase_b_release_identity_invalid")
    name = f"staging-next-{application_sha}-{time.time_ns()}-{secrets.token_hex(4)}.sql.gz"
    destination = policy.backup_directory / name
    try:
        fd = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    except OSError:
        raise TransactionRefused("phase_b_backup_create_failed") from None
    option_fd, defaults_path = _mysql_defaults(policy.credentials["target"],
                                                policy.target_host, policy.target_port)
    # Pin the validated endpoint on the command line as well as in the
    # private option file. Some mysqldump builds can otherwise fall back to
    # their local socket defaults even while the mysql client honors [client].
    target_credentials = policy.credentials["target"]
    argv = ["/usr/bin/mysqldump", f"--defaults-file={defaults_path}",
            "--protocol=tcp", f"--host={policy.target_host}", f"--port={policy.target_port}",
            f"--user={target_credentials['username']}", "--single-transaction", "--quick",
            "--routines", "--triggers", "--events", "--no-tablespaces", "--set-gtid-purged=OFF",
            "--hex-blob", "--skip-comments", "--default-character-set=utf8mb4", DATABASE]
    process = None
    stderr_sample = bytearray()
    stderr_truncated = False
    try:
        process = subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                   stderr=subprocess.PIPE, close_fds=True, pass_fds=(option_fd,))
        os.close(option_fd)
        option_fd = -1
        deadline = time.monotonic() + timeout
        total = 0
        with os.fdopen(fd, "wb", closefd=True) as output:
            with gzip.GzipFile(fileobj=output, mode="wb", compresslevel=6, mtime=0) as compressed:
                streams = {process.stdout.fileno(): "stdout", process.stderr.fileno(): "stderr"}
                while streams:
                    remaining = deadline - time.monotonic()
                    _require(remaining > 0, "phase_b_backup_timeout")
                    ready, _, _ = select.select(list(streams), [], [], min(0.5, remaining))
                    if not ready:
                        continue
                    for source_fd in ready:
                        stream_kind = streams[source_fd]
                        block = os.read(source_fd, 1024 * 1024 if stream_kind == "stdout" else 4096)
                        if not block:
                            del streams[source_fd]
                            continue
                        if stream_kind == "stdout":
                            total += len(block)
                            _require(total <= maximum_bytes, "phase_b_backup_size_limit")
                            compressed.write(block)
                        elif len(stderr_sample) < 16_384:
                            remaining_sample = 16_384 - len(stderr_sample)
                            stderr_sample.extend(block[:remaining_sample])
                            stderr_truncated = stderr_truncated or len(block) > remaining_sample
                        else:
                            stderr_truncated = True
                compressed.flush()
            output.flush()
            os.fsync(output.fileno())
        return_code = process.wait(timeout=max(0.1, deadline - time.monotonic()))
        if return_code != 0:
            raise TransactionRefused(_backup_failure_code(bytes(stderr_sample)))
        _require(total > 0 and not stderr_truncated, "phase_b_backup_output_invalid")
        digest, _size = _verify_backup_sql(destination, expected_uid=0, expected_mode=0o600)
        return destination, digest
    except TransactionRefused:
        if process is not None and process.poll() is None:
            process.kill()
            process.wait()
        try:
            destination.unlink()
        except OSError:
            pass
        raise
    except (OSError, subprocess.TimeoutExpired):
        if process is not None and process.poll() is None:
            process.kill()
            process.wait()
        try:
            destination.unlink()
        except OSError:
            pass
        raise TransactionRefused("phase_b_backup_command_failed") from None
    finally:
        if option_fd >= 0:
            os.close(option_fd)


def _database_name(prefix: str, tx_id: str) -> str:
    return f"wpcg_staging_next_{prefix}_{tx_id}"


def restore_backup_to_isolated(policy: DatabaseExecutionPolicy, backup: Path, tx_id: str,
                               *, maximum_bytes: int = 20 * 1024 * 1024 * 1024,
                               timeout: float = 600.0) -> tuple[str, str]:
    """Restore one validated dump into two unique schemas on the isolated MySQL instance."""
    _require(re.fullmatch(r"[0-9a-f]{32}", tx_id) is not None, "transaction_id_invalid")
    backup_hash, _size = _verify_backup_sql(backup)
    names = (_database_name("restore", tx_id), _database_name("rehearsal", tx_id))
    for database in names:
        existing = _mysql_query(policy, isolated=True,
                                sql="SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='"
                                + database + "'")
        _require(existing == b"0\n", "phase_b_restore_schema_already_exists")
        _mysql_query(policy, isolated=True,
                     sql=f"CREATE DATABASE `{database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci")
        defaults_fd, defaults_path = _mysql_defaults(policy.credentials["isolated"],
                                                       policy.restore_host, policy.restore_port)
        argv = ["/usr/bin/mysql", f"--defaults-file={defaults_path}", "--batch", "--database", database]
        gzip_process = None
        mysql_process = None
        try:
            gzip_process = subprocess.Popen(["/usr/bin/gzip", "-dc", str(backup)],
                                            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                            stdin=subprocess.DEVNULL, close_fds=True)
            mysql_process = subprocess.Popen(argv, stdin=gzip_process.stdout,
                                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                             close_fds=True, pass_fds=(defaults_fd,))
            gzip_process.stdout.close()
            mysql_status = mysql_process.wait(timeout=timeout)
            gzip_status = gzip_process.wait(timeout=timeout)
            _require(mysql_status == 0 and gzip_status == 0, "phase_b_restore_import_failed")
        except (OSError, subprocess.TimeoutExpired):
            for child in (mysql_process, gzip_process):
                if child is not None and child.poll() is None:
                    child.kill()
                    child.wait()
            raise TransactionRefused("phase_b_restore_import_failed") from None
        finally:
            os.close(defaults_fd)
    return names


def cleanup_isolated_databases(policy: DatabaseExecutionPolicy, tx_id: str) -> None:
    """Remove only this transaction's two generated schemas on the pinned isolated instance."""
    _require(re.fullmatch(r"[0-9a-f]{32}", tx_id) is not None, "transaction_id_invalid")
    names = (_database_name("restore", tx_id), _database_name("rehearsal", tx_id))
    identity = _mysql_query(policy, isolated=True,
                            sql="SELECT @@server_uuid", maximum=4096).decode("ascii", "strict").strip()
    _require(hashlib.sha256(identity.encode("ascii")).hexdigest() == policy.isolated_uuid_sha256,
             "phase_b_database_instance_mismatch")
    for database in names:
        exists = _mysql_query(policy, isolated=True,
                              sql="SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='"
                              + database + "'", maximum=16).decode("ascii", "strict").strip()
        _require(exists in {"0", "1"}, "phase_b_restore_schema_state_invalid")
        if exists == "1":
            _mysql_query(policy, isolated=True, sql=f"DROP DATABASE `{database}`", timeout=30.0)
        remaining = _mysql_query(policy, isolated=True,
                                 sql="SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='"
                                 + database + "'", maximum=16).decode("ascii", "strict").strip()
        _require(remaining == "0", "phase_b_restore_cleanup_failed")


def _require(ok: bool, code: str) -> None:
    if not ok:
        raise TransactionRefused(code)


def _unique_json(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("duplicate_key")
        result[key] = value
    return result


def _hash(value: object) -> bool:
    return isinstance(value, str) and SHA_RE.fullmatch(value) is not None


def _json_bytes(value: Mapping[str, object]) -> bytes:
    return json.dumps(value, sort_keys=True, separators=(",", ":")).encode("utf-8") + b"\n"


def restore_receipt_sha256(evidence: Mapping[str, str]) -> str:
    """Hash immutable pre-migration and isolated-restore observations."""
    fields = RESTORE_CHECKPOINT_KEYS - {"restore_receipt_sha256"}
    _require(set(evidence) == fields and all(_hash(evidence.get(key)) for key in fields),
             "restore_checkpoint_invalid")
    return hashlib.sha256(json.dumps({
        "backup_sha256": evidence["backup_sha256"],
        "target_schema_sha256": evidence["target_schema_sha256"],
        "target_ledger_sha256": evidence["target_ledger_sha256"],
        "target_data_sha256": evidence["target_data_sha256"],
        "restored_schema_sha256": evidence["restored_schema_sha256"],
        "restored_ledger_sha256": evidence["restored_ledger_sha256"],
        "restored_data_sha256": evidence["restored_data_sha256"],
        "restore_mysql_uuid_sha256": evidence["restore_mysql_uuid_sha256"],
    }, sort_keys=True, separators=(",", ":")).encode("utf-8")).hexdigest()


@dataclass(frozen=True)
class TransactionIdentity:
    application_sha: str
    previous_sha: str
    artifact_sha256: str
    backup_sha256: str
    backup_filename: str
    mysql_uuid_sha256: str
    migrations: tuple[str, ...]


class DatabaseReleaseTransaction:
    """One locked, root-owned, durable database release transaction."""

    def __init__(self, state_dir: Path, identity: TransactionIdentity):
        self.state_dir = state_dir
        self.identity = identity
        self.transaction_id = self._identity_id(identity)
        self.journal = state_dir / f"db-release-{self.transaction_id}.json"
        # One database-wide lock prevents two different release identities from
        # running migration transactions concurrently against the same schema.
        self.lock_path = state_dir / "database-release.lock"
        self._lock_fd: int | None = None
        self._state: dict[str, object] | None = None

    @staticmethod
    def _identity_id(identity: TransactionIdentity) -> str:
        values = (identity.application_sha, identity.previous_sha, identity.artifact_sha256,
                  identity.backup_sha256, identity.mysql_uuid_sha256)
        _require(all(isinstance(value, str) and re.fullmatch(r"[0-9a-f]{40}", value)
                     for value in values[:2]), "release_sha_invalid")
        _require(all(_hash(value) for value in values[2:]), "release_hash_invalid")
        _require(isinstance(identity.backup_filename, str)
                 and re.fullmatch(
                     rf"staging-next-{identity.application_sha}-[0-9]{{1,30}}-[0-9a-f]{{8}}\.sql\.gz",
                     identity.backup_filename,
                 ) is not None,
                 "backup_filename_invalid")
        _require(bool(identity.migrations) and len(identity.migrations) <= 1000
                 and len(set(identity.migrations)) == len(identity.migrations)
                 and all(MIGRATION_RE.fullmatch(item) for item in identity.migrations),
                 "migration_list_invalid")
        material = json.dumps({
            "profile": PROFILE, "database": DATABASE, "application_sha": identity.application_sha,
            "previous_sha": identity.previous_sha, "artifact_sha256": identity.artifact_sha256,
            "backup_sha256": identity.backup_sha256, "backup_filename": identity.backup_filename,
            "mysql_uuid_sha256": identity.mysql_uuid_sha256,
            "migrations": identity.migrations,
        }, sort_keys=True, separators=(",", ":")).encode("utf-8")
        return hashlib.sha256(material).hexdigest()[:32]

    def __enter__(self) -> "DatabaseReleaseTransaction":
        self._verify_directory()
        try:
            self._lock_fd = os.open(self.lock_path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
            info = os.fstat(self._lock_fd)
            _require(stat.S_ISREG(info.st_mode) and info.st_uid == 0
                     and stat.S_IMODE(info.st_mode) == 0o600, "transaction_lock_metadata_invalid")
            fcntl.flock(self._lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            self._close_lock()
            raise TransactionRefused("transaction_lock_busy") from None
        except TransactionRefused:
            self._close_lock()
            raise
        except OSError:
            self._close_lock()
            raise TransactionRefused("transaction_lock_unavailable") from None
        try:
            self._reject_other_unfinished_journals()
            self._state = self._load_or_create()
            return self
        except BaseException:
            self._close_lock()
            raise

    def __exit__(self, _kind, _value, _traceback) -> None:
        self._close_lock()

    def _close_lock(self) -> None:
        if self._lock_fd is not None:
            try:
                fcntl.flock(self._lock_fd, fcntl.LOCK_UN)
            finally:
                os.close(self._lock_fd)
                self._lock_fd = None

    def _verify_directory(self) -> None:
        try:
            info = os.lstat(self.state_dir)
        except OSError:
            raise TransactionRefused("transaction_state_directory_unavailable") from None
        _require(stat.S_ISDIR(info.st_mode) and not stat.S_ISLNK(info.st_mode)
                 and info.st_uid == 0 and stat.S_IMODE(info.st_mode) == 0o700,
                 "transaction_state_directory_metadata_invalid")

    def _new_state(self) -> dict[str, object]:
        i = self.identity
        return {
            "schema": SCHEMA, "profile": PROFILE, "database": DATABASE,
            "transaction_id": self.transaction_id, "application_sha": i.application_sha,
            "previous_sha": i.previous_sha, "artifact_sha256": i.artifact_sha256,
            "backup_sha256": i.backup_sha256, "backup_filename": i.backup_filename,
            "mysql_uuid_sha256": i.mysql_uuid_sha256,
            "migrations": list(i.migrations), "state": "PREPARED", "evidence": {},
            "migration_cursor": 0, "migration_before": None, "migration_started_at": None,
            "updated_at": time.time_ns(),
        }

    def _load_or_create(self) -> dict[str, object]:
        try:
            info = os.lstat(self.journal)
        except FileNotFoundError:
            state = self._new_state()
            self._persist(state, create=True)
            return state
        except OSError:
            raise TransactionRefused("transaction_journal_unavailable") from None
        _require(stat.S_ISREG(info.st_mode) and not stat.S_ISLNK(info.st_mode)
                 and info.st_uid == 0 and stat.S_IMODE(info.st_mode) == 0o600
                 and info.st_size <= 256 * 1024, "transaction_journal_metadata_invalid")
        try:
            fd = os.open(self.journal, os.O_RDONLY | os.O_NOFOLLOW)
            try:
                opened = os.fstat(fd)
                _require(opened.st_ino == info.st_ino and opened.st_dev == info.st_dev
                         and opened.st_uid == 0 and stat.S_IMODE(opened.st_mode) == 0o600,
                         "transaction_journal_changed_during_open")
                with os.fdopen(fd, "rb", closefd=False) as stream:
                    raw = stream.read(256 * 1024 + 1)
            finally:
                os.close(fd)
            value = json.loads(raw.decode("utf-8"), object_pairs_hook=_unique_json)
        except TransactionRefused:
            raise
        except (OSError, UnicodeError, ValueError, json.JSONDecodeError):
            raise TransactionRefused("transaction_journal_invalid") from None
        self._validate_state(value)
        return value

    def _reject_other_unfinished_journals(self) -> None:
        try:
            journals = sorted(self.state_dir.glob("db-release-*.json"))
        except OSError:
            raise TransactionRefused("transaction_journal_enumeration_failed") from None
        for path in journals:
            if path == self.journal:
                continue
            try:
                info = os.lstat(path)
                _require(stat.S_ISREG(info.st_mode) and not stat.S_ISLNK(info.st_mode)
                         and info.st_uid == 0 and stat.S_IMODE(info.st_mode) == 0o600
                         and info.st_size <= 256 * 1024, "prior_transaction_journal_metadata_invalid")
                fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
                try:
                    opened = os.fstat(fd)
                    _require(opened.st_ino == info.st_ino and opened.st_dev == info.st_dev,
                             "prior_transaction_journal_changed")
                    with os.fdopen(fd, "rb", closefd=False) as stream:
                        raw = stream.read(256 * 1024 + 1)
                finally:
                    os.close(fd)
                value = json.loads(raw.decode("utf-8"), object_pairs_hook=_unique_json)
            except TransactionRefused:
                raise
            except (OSError, UnicodeError, ValueError, json.JSONDecodeError):
                raise TransactionRefused("prior_transaction_journal_invalid") from None
            _require(isinstance(value, dict) and set(value) == JOURNAL_KEYS
                     and value.get("profile") == PROFILE and value.get("database") == DATABASE
                     and value.get("state") in STATES
                     and path.name == f"db-release-{value.get('transaction_id')}.json",
                     "prior_transaction_journal_identity_invalid")
            if value["state"] != "COMPLETE":
                raise TransactionRefused("unfinished_database_transaction")

    @classmethod
    def load_existing_by_transaction_id(
        cls, state_dir: Path, transaction_id: str,
    ) -> tuple[TransactionIdentity, Mapping[str, object]]:
        """Load one pinned journal by receipt-bound transaction ID, without creating state."""
        _require(re.fullmatch(r"[0-9a-f]{32}", transaction_id) is not None,
                 "transaction_id_invalid")
        try:
            directory = os.lstat(state_dir)
            _require(stat.S_ISDIR(directory.st_mode) and not stat.S_ISLNK(directory.st_mode)
                     and directory.st_uid == 0 and stat.S_IMODE(directory.st_mode) == 0o700,
                     "transaction_state_directory_metadata_invalid")
            path = state_dir / f"db-release-{transaction_id}.json"
            before = os.lstat(path)
            _require(stat.S_ISREG(before.st_mode) and not stat.S_ISLNK(before.st_mode)
                     and before.st_uid == 0 and stat.S_IMODE(before.st_mode) == 0o600
                     and before.st_size <= 256 * 1024, "transaction_journal_metadata_invalid")
            fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
            try:
                opened = os.fstat(fd)
                _require(stat.S_ISREG(opened.st_mode) and opened.st_uid == 0
                         and stat.S_IMODE(opened.st_mode) == 0o600
                         and opened.st_ino == before.st_ino and opened.st_dev == before.st_dev
                         and opened.st_size == before.st_size,
                         "transaction_journal_changed_during_open")
                with os.fdopen(fd, "rb", closefd=False) as stream:
                    raw = stream.read(256 * 1024 + 1)
            finally:
                os.close(fd)
            value = json.loads(raw.decode("utf-8"), object_pairs_hook=_unique_json)
        except TransactionRefused:
            raise
        except (OSError, UnicodeError, ValueError, json.JSONDecodeError):
            raise TransactionRefused("transaction_journal_unavailable") from None
        _require(isinstance(value, dict) and set(value) == JOURNAL_KEYS
                 and value.get("schema") == SCHEMA and value.get("profile") == PROFILE
                 and value.get("database") == DATABASE and value.get("transaction_id") == transaction_id
                 and isinstance(value.get("migrations"), list)
                 and all(isinstance(item, str) for item in value["migrations"]),
                 "transaction_journal_schema_invalid")
        identity = TransactionIdentity(
            application_sha=value.get("application_sha"),
            previous_sha=value.get("previous_sha"),
            artifact_sha256=value.get("artifact_sha256"),
            backup_sha256=value.get("backup_sha256"),
            backup_filename=value.get("backup_filename"),
            mysql_uuid_sha256=value.get("mysql_uuid_sha256"),
            migrations=tuple(value["migrations"]),
        )
        transaction = cls(state_dir, identity)
        _require(transaction.transaction_id == transaction_id,
                 "transaction_journal_identity_invalid")
        transaction._validate_state(value)
        return identity, value

    @classmethod
    def find_unfinished_for_release(
        cls,
        state_dir: Path,
        *,
        application_sha: str,
        previous_sha: str,
        artifact_sha256: str,
        mysql_uuid_sha256: str,
        migrations: tuple[str, ...],
    ) -> tuple[TransactionIdentity, Mapping[str, object]] | None:
        """Locate one exact-release journal for safe backend restart reconciliation.

        The caller must already hold the staging-next deployment lock. Unknown,
        malformed, or different-release unfinished journals block this release.
        """
        try:
            directory = os.lstat(state_dir)
            _require(stat.S_ISDIR(directory.st_mode) and not stat.S_ISLNK(directory.st_mode)
                     and directory.st_uid == 0 and stat.S_IMODE(directory.st_mode) == 0o700,
                     "transaction_state_directory_metadata_invalid")
            paths = sorted(state_dir.glob("db-release-*.json"))
        except TransactionRefused:
            raise
        except OSError:
            raise TransactionRefused("transaction_journal_enumeration_failed") from None

        expected_release = (application_sha, previous_sha, artifact_sha256, mysql_uuid_sha256, migrations)
        pending: tuple[TransactionIdentity, Mapping[str, object]] | None = None
        for path in paths:
            try:
                before = os.lstat(path)
                _require(stat.S_ISREG(before.st_mode) and not stat.S_ISLNK(before.st_mode)
                         and before.st_uid == 0 and stat.S_IMODE(before.st_mode) == 0o600
                         and before.st_size <= 256 * 1024, "transaction_journal_metadata_invalid")
                fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
                try:
                    opened = os.fstat(fd)
                    _require(opened.st_ino == before.st_ino and opened.st_dev == before.st_dev
                             and opened.st_uid == 0 and stat.S_IMODE(opened.st_mode) == 0o600
                             and opened.st_size == before.st_size,
                             "transaction_journal_changed_during_open")
                    with os.fdopen(fd, "rb", closefd=False) as stream:
                        raw = stream.read(256 * 1024 + 1)
                finally:
                    os.close(fd)
                value = json.loads(raw.decode("utf-8"), object_pairs_hook=_unique_json)
            except TransactionRefused:
                raise
            except (OSError, UnicodeError, ValueError, json.JSONDecodeError):
                raise TransactionRefused("transaction_journal_invalid") from None

            _require(isinstance(value, dict) and set(value) == JOURNAL_KEYS
                     and value.get("schema") == SCHEMA and value.get("profile") == PROFILE
                     and value.get("database") == DATABASE
                     and isinstance(value.get("migrations"), list)
                     and all(isinstance(item, str) for item in value["migrations"]),
                     "transaction_journal_schema_invalid")
            identity = TransactionIdentity(
                application_sha=value.get("application_sha"),
                previous_sha=value.get("previous_sha"),
                artifact_sha256=value.get("artifact_sha256"),
                backup_sha256=value.get("backup_sha256"),
                backup_filename=value.get("backup_filename"),
                mysql_uuid_sha256=value.get("mysql_uuid_sha256"),
                migrations=tuple(value["migrations"]),
            )
            transaction = cls(state_dir, identity)
            _require(value.get("transaction_id") == transaction.transaction_id
                     and path.name == f"db-release-{transaction.transaction_id}.json",
                     "transaction_journal_identity_invalid")
            transaction._validate_state(value)
            if value["state"] == "COMPLETE":
                continue
            observed_release = (
                identity.application_sha, identity.previous_sha, identity.artifact_sha256,
                identity.mysql_uuid_sha256, identity.migrations,
            )
            _require(observed_release == expected_release, "unfinished_database_transaction")
            _require(pending is None, "multiple_unfinished_database_transactions")
            pending = (identity, value)
        return pending

    def _validate_state(self, value: object) -> None:
        _require(isinstance(value, dict) and set(value) == JOURNAL_KEYS,
                 "transaction_journal_schema_invalid")
        expected = self._new_state()
        for key in ("schema", "profile", "database", "transaction_id", "application_sha", "previous_sha",
                    "artifact_sha256", "backup_sha256", "backup_filename", "mysql_uuid_sha256", "migrations"):
            _require(value.get(key) == expected[key], "transaction_identity_mismatch")
        _require(value.get("state") in STATES and type(value.get("migration_cursor")) is int
                 and 0 <= value["migration_cursor"] <= len(self.identity.migrations),
                 "transaction_state_invalid")
        _require(isinstance(value.get("evidence"), dict), "transaction_evidence_invalid")
        restore_evidence = value["evidence"].get("RESTORE_REHEARSAL_VERIFIED")
        if restore_evidence is not None:
            _require(isinstance(restore_evidence, dict)
                     and set(restore_evidence) == RESTORE_CHECKPOINT_KEYS
                     and all(_hash(restore_evidence.get(key)) for key in RESTORE_CHECKPOINT_KEYS)
                     and restore_evidence.get("backup_sha256") == self.identity.backup_sha256
                     and restore_receipt_sha256({key: restore_evidence[key]
                                                 for key in RESTORE_CHECKPOINT_KEYS
                                                 if key != "restore_receipt_sha256"})
                     == restore_evidence.get("restore_receipt_sha256"),
                     "transaction_restore_checkpoint_invalid")
        _require(type(value.get("updated_at")) is int and value["updated_at"] > 0,
                 "transaction_timestamp_invalid")
        cursor = value["migration_cursor"]
        before = value.get("migration_before")
        started = value.get("migration_started_at")
        if value["state"] == "MIGRATING" and cursor < len(self.identity.migrations):
            _require(isinstance(before, dict) and set(before) == {
                "schema_sha256", "ledger_sha256", "data_sha256",
                "expected_schema_sha256", "expected_ledger_sha256", "expected_data_sha256",
            } and all(_hash(before.get(key)) for key in before)
                     and type(started) is int and started > 0, "migration_checkpoint_invalid")
        else:
            _require(before is None and started is None, "migration_checkpoint_stale")

    def _persist(self, value: dict[str, object], *, create: bool = False) -> None:
        value["updated_at"] = time.time_ns()
        self._validate_state(value)
        temporary = self.state_dir / f".db-release-{self.transaction_id}-{os.getpid()}-{time.monotonic_ns()}"
        flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW
        try:
            fd = os.open(temporary, flags, 0o600)
            try:
                with os.fdopen(fd, "wb", closefd=False) as stream:
                    stream.write(_json_bytes(value))
                    stream.flush()
                    os.fsync(fd)
            finally:
                os.close(fd)
            if create:
                os.link(temporary, self.journal, follow_symlinks=False)
                temporary.unlink()
            else:
                info = os.lstat(self.journal)
                _require(stat.S_ISREG(info.st_mode) and info.st_uid == 0
                         and stat.S_IMODE(info.st_mode) == 0o600, "transaction_journal_metadata_invalid")
                os.replace(temporary, self.journal)
            dir_fd = os.open(self.state_dir, os.O_RDONLY | os.O_DIRECTORY)
            try:
                os.fsync(dir_fd)
            finally:
                os.close(dir_fd)
        except FileExistsError:
            raise TransactionRefused("transaction_journal_already_exists") from None
        except TransactionRefused:
            raise
        except OSError:
            raise TransactionRefused("transaction_journal_write_failed") from None
        finally:
            try:
                temporary.unlink()
            except FileNotFoundError:
                pass
        self._state = value

    @property
    def state(self) -> Mapping[str, object]:
        self._ensure_open()
        return json.loads(json.dumps(self._state))

    def _ensure_open(self) -> dict[str, object]:
        _require(self._lock_fd is not None and self._state is not None, "transaction_lock_required")
        return self._state

    def advance(self, target: str, evidence: Mapping[str, object]) -> None:
        state = self._ensure_open()
        _require(target in STATES and target not in {"PREPARED", "MIGRATING", "RECOVERY_REQUIRED"},
                 "transaction_transition_invalid")
        current_index = STATES.index(state["state"])
        target_index = STATES.index(target)
        _require(target_index == current_index + 1, "transaction_transition_out_of_order")
        required = EVIDENCE_KEYS.get(target)
        _require(required is not None, "transaction_evidence_missing_or_invalid")
        if target == "RESTORE_REHEARSAL_VERIFIED":
            _require(set(evidence) == RESTORE_CHECKPOINT_KEYS
                     and all(_hash(evidence.get(key)) for key in RESTORE_CHECKPOINT_KEYS),
                     "transaction_evidence_missing_or_invalid")
            _require(evidence["backup_sha256"] == self.identity.backup_sha256
                     and restore_receipt_sha256({key: evidence[key]
                                                 for key in RESTORE_CHECKPOINT_KEYS
                                                 if key != "restore_receipt_sha256"}) == evidence[required],
                     "restore_checkpoint_identity_mismatch")
        else:
            _require(set(evidence) == {required} and _hash(evidence.get(required)),
                     "transaction_evidence_missing_or_invalid")
        if target == "BACKUP_VERIFIED":
            _require(evidence[required] == self.identity.backup_sha256, "backup_identity_mismatch")
        if target == "MIGRATIONS_VERIFIED":
            _require(state.get("migration_cursor") == len(self.identity.migrations),
                     "migration_list_incomplete")
        updated = dict(state)
        updated["state"] = target
        updated["evidence"] = {**state["evidence"], target: dict(evidence)}
        self._persist(updated)

    def mark_recovery_required(self, reason_code: str) -> None:
        """Persist a sanitized stop state after any uncertain post-mutation outcome."""
        state = self._ensure_open()
        _require(state["state"] not in {"COMPLETE", "RECOVERY_REQUIRED"}
                 and isinstance(reason_code, str)
                 and re.fullmatch(r"[a-z][a-z0-9_]{2,95}", reason_code) is not None,
                 "recovery_state_transition_invalid")
        updated = dict(state)
        updated["state"] = "RECOVERY_REQUIRED"
        updated["migration_before"] = None
        updated["migration_started_at"] = None
        updated["evidence"] = {**state["evidence"], "recovery_required": {"reason": reason_code}}
        self._persist(updated)

    def begin_migration(self, name: str, *, observed_schema_sha256: str,
                        observed_ledger_sha256: str, observed_data_sha256: str,
                        expected_schema_sha256: str, expected_ledger_sha256: str,
                        expected_data_sha256: str) -> None:
        state = self._ensure_open()
        _require(state["state"] in {"FENCES_CLOSED", "MIGRATING"}, "migration_state_invalid")
        cursor = state["migration_cursor"]
        _require(cursor < len(self.identity.migrations) and name == self.identity.migrations[cursor],
                 "migration_order_mismatch")
        _require(all(_hash(value) for value in (observed_schema_sha256, observed_ledger_sha256,
                                                 observed_data_sha256, expected_schema_sha256,
                                                 expected_ledger_sha256, expected_data_sha256)),
                 "migration_observation_invalid")
        updated = dict(state)
        updated.update({"state": "MIGRATING", "migration_before": {
            "schema_sha256": observed_schema_sha256, "ledger_sha256": observed_ledger_sha256,
            "data_sha256": observed_data_sha256,
            "expected_schema_sha256": expected_schema_sha256,
            "expected_ledger_sha256": expected_ledger_sha256,
            "expected_data_sha256": expected_data_sha256,
        }, "migration_started_at": time.time_ns()})
        self._persist(updated)

    def complete_migration(self, name: str, *, observed_schema_sha256: str,
                           observed_ledger_sha256: str, observed_data_sha256: str,
                           migration_receipt_sha256: str) -> None:
        state = self._ensure_open()
        cursor = state["migration_cursor"]
        _require(state["state"] == "MIGRATING" and cursor < len(self.identity.migrations)
                 and name == self.identity.migrations[cursor], "migration_completion_order_mismatch")
        _require(_hash(observed_schema_sha256) and _hash(observed_ledger_sha256)
                 and _hash(observed_data_sha256)
                 and _hash(migration_receipt_sha256), "migration_completion_evidence_invalid")
        expected = state["migration_before"]
        _require(observed_schema_sha256 == expected["expected_schema_sha256"]
                 and observed_ledger_sha256 == expected["expected_ledger_sha256"]
                 and observed_data_sha256 == expected["expected_data_sha256"],
                 "migration_postcondition_mismatch")
        updated = dict(state)
        updated["migration_cursor"] = cursor + 1
        if updated["migration_cursor"] < len(self.identity.migrations):
            updated["state"] = "FENCES_CLOSED"
        updated["evidence"] = {**state["evidence"], f"migration:{name}": {
            "schema_sha256": observed_schema_sha256,
            "ledger_sha256": observed_ledger_sha256,
            "data_sha256": observed_data_sha256,
            "receipt_sha256": migration_receipt_sha256,
        }}
        updated["migration_before"] = None
        updated["migration_started_at"] = None
        if updated["migration_cursor"] == len(self.identity.migrations):
            updated["state"] = "MIGRATIONS_VERIFIED"
            updated["evidence"]["MIGRATIONS_VERIFIED"] = {
                "migration_receipt_sha256": migration_receipt_sha256,
            }
        self._persist(updated)

    def reconcile_interrupted_migration(self, *, ledger_contains_started_migration: bool,
                                        observed_schema_sha256: str,
                                        observed_ledger_sha256: str,
                                        observed_data_sha256: str,
                                        migration_receipt_sha256: str | None = None) -> str:
        state = self._ensure_open()
        _require(state["state"] == "MIGRATING" and state["migration_cursor"] < len(self.identity.migrations),
                 "no_interrupted_migration")
        _require(_hash(observed_schema_sha256) and _hash(observed_ledger_sha256)
                 and _hash(observed_data_sha256),
                 "migration_observation_invalid")
        before = state["migration_before"]
        name = self.identity.migrations[state["migration_cursor"]]
        if (ledger_contains_started_migration and _hash(migration_receipt_sha256)
                and observed_schema_sha256 == before["expected_schema_sha256"]
                and observed_ledger_sha256 == before["expected_ledger_sha256"]
                and observed_data_sha256 == before["expected_data_sha256"]):
            self.complete_migration(name, observed_schema_sha256=observed_schema_sha256,
                                    observed_ledger_sha256=observed_ledger_sha256,
                                    observed_data_sha256=observed_data_sha256,
                                    migration_receipt_sha256=migration_receipt_sha256)
            return "COMPLETED_FROM_VERIFIED_LEDGER"
        if (not ledger_contains_started_migration
                and observed_schema_sha256 == before["schema_sha256"]
                and observed_ledger_sha256 == before["ledger_sha256"]
                and observed_data_sha256 == before["data_sha256"]):
            updated = dict(state)
            updated["state"] = "FENCES_CLOSED"
            updated["migration_before"] = None
            updated["migration_started_at"] = None
            self._persist(updated)
            return "SAFE_TO_RETRY_UNCHANGED_DATABASE"
        updated = dict(state)
        updated["state"] = "RECOVERY_REQUIRED"
        updated["evidence"] = {**state["evidence"], "recovery_required": {
            "reason": "partial_or_ambiguous_migration_state",
            "observed_schema_sha256": observed_schema_sha256,
            "observed_ledger_sha256": observed_ledger_sha256,
        }}
        updated["migration_before"] = None
        updated["migration_started_at"] = None
        self._persist(updated)
        return "MANUAL_RECOVERY_REQUIRED"

    def apply_rehearsed_migrations(self, *, expected_after: Mapping[str, Mapping[str, str]],
                                   observe: Callable[[], Mapping[str, object]],
                                   apply_one: Callable[[str], bool]) -> None:
        """Execute only the pinned migration list against a rehearsed schema sequence.

        ``observe`` must query the selected MySQL instance and return the schema
        hash, migration-ledger hash, and migration names. ``apply_one`` must run
        exactly the named reviewed migration through Laravel. These injected
        callbacks are the narrow integration seam; this coordinator enforces
        the durable, restart-safe ordering and never supplies SQL or DB targets.
        """
        state = self._ensure_open()
        _require(state["state"] in {"FENCES_CLOSED", "MIGRATING", "MIGRATIONS_VERIFIED"},
                 "migration_state_invalid")
        _require(set(expected_after) == set(self.identity.migrations),
                 "migration_rehearsal_set_mismatch")
        for name in self.identity.migrations:
            expected = expected_after[name]
            _require(isinstance(expected, Mapping)
                     and set(expected) == {"schema_sha256", "ledger_sha256", "data_sha256"}
                     and all(_hash(expected.get(key)) for key in expected),
                     "migration_rehearsal_evidence_invalid")
        if state["state"] == "MIGRATIONS_VERIFIED":
            _require(state["migration_cursor"] == len(self.identity.migrations),
                     "migration_cursor_invalid")
            return

        while state["migration_cursor"] < len(self.identity.migrations):
            cursor = state["migration_cursor"]
            name = self.identity.migrations[cursor]
            after_expected = expected_after[name]
            if state["state"] == "MIGRATING":
                current = observe()
                _require(isinstance(current, Mapping) and _hash(current.get("schema_sha256"))
                         and _hash(current.get("ledger_sha256"))
                         and _hash(current.get("data_sha256"))
                         and isinstance(current.get("migrations"), (tuple, list)),
                         "migration_observation_invalid")
                recovery = self.reconcile_interrupted_migration(
                    ledger_contains_started_migration=name in current["migrations"],
                    observed_schema_sha256=current["schema_sha256"],
                    observed_ledger_sha256=current["ledger_sha256"],
                    observed_data_sha256=current["data_sha256"],
                    migration_receipt_sha256=self._migration_receipt_hash(
                        name, state["migration_before"], current) if name in current["migrations"] else None,
                )
                if recovery == "MANUAL_RECOVERY_REQUIRED":
                    raise TransactionRefused("manual_migration_recovery_required")
                state = self._ensure_open()
                if recovery == "COMPLETED_FROM_VERIFIED_LEDGER":
                    continue
                # A verified unchanged database permits this one retry on a
                # later explicit invocation; no in-process automatic retry.

            before = observe()
            _require(isinstance(before, Mapping) and _hash(before.get("schema_sha256"))
                     and _hash(before.get("ledger_sha256"))
                     and _hash(before.get("data_sha256"))
                     and isinstance(before.get("migrations"), (tuple, list)),
                     "migration_observation_invalid")
            cursor = state["migration_cursor"]
            if cursor == 0:
                checkpoint = state["evidence"].get("RESTORE_REHEARSAL_VERIFIED")
                _require(isinstance(checkpoint, Mapping), "restore_checkpoint_missing")
                expected_before = {
                    "schema_sha256": checkpoint.get("target_schema_sha256"),
                    "ledger_sha256": checkpoint.get("target_ledger_sha256"),
                    "data_sha256": checkpoint.get("target_data_sha256"),
                }
            else:
                previous_name = self.identity.migrations[cursor - 1]
                expected_before = state["evidence"].get(f"migration:{previous_name}")
                _require(isinstance(expected_before, Mapping), "prior_migration_checkpoint_missing")
            _require(all(before.get(key) == expected_before.get(key)
                         for key in ("schema_sha256", "ledger_sha256", "data_sha256")),
                     "target_changed_since_migration_rehearsal")
            _require(name not in before["migrations"], "migration_already_applied_without_checkpoint")
            self.begin_migration(
                name, observed_schema_sha256=before["schema_sha256"],
                observed_ledger_sha256=before["ledger_sha256"],
                observed_data_sha256=before["data_sha256"],
                expected_schema_sha256=after_expected["schema_sha256"],
                expected_ledger_sha256=after_expected["ledger_sha256"],
                expected_data_sha256=after_expected["data_sha256"],
            )
            try:
                command_passed = apply_one(name) is True
            except Exception:
                command_passed = False
            after = observe()
            _require(isinstance(after, Mapping) and _hash(after.get("schema_sha256"))
                     and _hash(after.get("ledger_sha256"))
                     and _hash(after.get("data_sha256"))
                     and isinstance(after.get("migrations"), (tuple, list)),
                     "migration_observation_invalid")
            if (not command_passed or name not in after["migrations"]
                    or after["schema_sha256"] != after_expected["schema_sha256"]
                    or after["ledger_sha256"] != after_expected["ledger_sha256"]
                    or after["data_sha256"] != after_expected["data_sha256"]):
                recovery = self.reconcile_interrupted_migration(
                    ledger_contains_started_migration=name in after["migrations"],
                    observed_schema_sha256=after["schema_sha256"],
                    observed_ledger_sha256=after["ledger_sha256"],
                    observed_data_sha256=after["data_sha256"],
                    migration_receipt_sha256=self._migration_receipt_hash(name, before, after)
                    if name in after["migrations"] else None,
                )
                if recovery == "MANUAL_RECOVERY_REQUIRED":
                    raise TransactionRefused("manual_migration_recovery_required")
                if recovery == "COMPLETED_FROM_VERIFIED_LEDGER":
                    state = self._ensure_open()
                    continue
                raise TransactionRefused("migration_execution_not_verified")
            self.complete_migration(
                name, observed_schema_sha256=after["schema_sha256"],
                observed_ledger_sha256=after["ledger_sha256"],
                observed_data_sha256=after["data_sha256"],
                migration_receipt_sha256=self._migration_receipt_hash(name, before, after),
            )
            state = self._ensure_open()
        _require(state["state"] == "MIGRATIONS_VERIFIED", "migration_sequence_incomplete")

    def _migration_receipt_hash(self, name: str, before: Mapping[str, object],
                                after: Mapping[str, object]) -> str:
        payload = {
            "transaction_id": self.transaction_id,
            "application_sha": self.identity.application_sha,
            "artifact_sha256": self.identity.artifact_sha256,
            "backup_sha256": self.identity.backup_sha256,
            "mysql_uuid_sha256": self.identity.mysql_uuid_sha256,
            "migration": name,
            "before_schema_sha256": before.get("schema_sha256"),
            "before_ledger_sha256": before.get("ledger_sha256"),
            "after_schema_sha256": after.get("schema_sha256"),
            "after_ledger_sha256": after.get("ledger_sha256"),
            "before_data_sha256": before.get("data_sha256"),
            "after_data_sha256": after.get("data_sha256"),
        }
        return hashlib.sha256(_json_bytes(payload)).hexdigest()
