#!/usr/bin/env python3
"""Validate normalized, non-secret release environment evidence.

This module is deliberately offline and read-only. It never collects host data,
executes commands, or writes application state.
"""

from __future__ import annotations

import hashlib
import json
import re
from datetime import datetime
from typing import Any


MAX_INPUT_BYTES = 65_536
SCHEMA_VERSION = 1
HASH40 = re.compile(r"^[0-9a-f]{40}$")
HASH64 = re.compile(r"^[0-9a-f]{64}$")
VERSION = re.compile(r"^\d+\.\d+(?:\.\d+)?$")
OS_VERSION = re.compile(r"^\d+\.\d+(?:\.\d+)?$")
PHP_VERSION = re.compile(r"^8\.(?:4|5)\.\d+$")
OS_ID = re.compile(r"^[a-z][a-z0-9._-]{1,63}$")
SOCKET_METADATA = re.compile(r"^[a-z_][a-z0-9_-]{0,31}:[a-z_][a-z0-9_-]{0,31}:0[0-7]{3}$")
FILE_METADATA = re.compile(
    r"^(?:root|deploy|www-data|other):(?:root|deploy|www-data|other):0[0-7]{3,4}:(?:regular|directory|symlink)$"
)

UNKNOWN_REASONS = frozenset(
    {
        "command_unavailable",
        "permission_denied",
        "timeout",
        "host_identity_unverified",
        "source_changed",
        "output_invalid",
        "output_limit_exceeded",
        "redaction_failed",
        "evidence_unavailable",
    }
)
OPTIONAL_REASONS = frozenset({"systemd_not_used"})

# Fact identifiers are part of the v1 contract. No caller-defined keys are accepted.
FACT_TYPES: dict[str, str] = {
    "host.identity_fingerprint_sha256": "hash64",
    "platform.os_id": "os_id",
    "platform.os_version": "os_version",
    "platform.architecture": "architecture",
    "platform.kernel_family": "kernel",
    "platform.systemd_version": "version",
    "runtime.php_cli_version": "php_version",
    "runtime.php_fpm_version": "php_version",
    "runtime.profile": "php_profile",
    "runtime.required_extensions": "extensions",
    "runtime.selector_state": "selector_state",
    "runtime.fpm_socket_metadata": "socket_metadata",
    "database.engine": "database_engine",
    "database.server_version": "version",
    "database.instance_fingerprint_sha256": "hash64",
    "database.binary_logging": "boolean",
    "database.binlog_format": "binlog_format",
    "database.trust_function_creators": "boolean",
    "database.privilege_profile": "db_privilege_profile",
    "web.nginx_version": "version",
    "web.nginx_config_test": "boolean",
    "web.vhost_classification": "vhost_classification",
    "web.php_handler": "php_handler",
    "web.selector_contract": "selector_contract",
    "worker.supervisor_version": "version",
    "worker.default_state": "worker_state",
    "worker.default_status_rc": "exit_code",
    "worker.default_pid": "pid",
    "worker.default_pid_rc": "exit_code",
    "worker.backups_state": "worker_state",
    "worker.backups_status_rc": "exit_code",
    "worker.backups_pid": "pid",
    "worker.backups_pid_rc": "exit_code",
    "worker.consumer_census": "consumer_census",
    "scheduler.mechanism": "scheduler_mechanism",
    "scheduler.lock_active": "boolean",
    "scheduler.paused": "boolean",
    "scheduler.worker_fence_active": "boolean",
    "deployment.application_sha": "hash40",
    "deployment.worktree_clean": "boolean",
    "deployment.layout": "deployment_layout",
    "deployment.config_cache": "cache_state",
    "filesystem.application_path_metadata": "file_metadata",
    "filesystem.maintenance_marker_metadata": "file_metadata",
    "filesystem.worker_marker_metadata": "file_metadata",
    "filesystem.scheduler_marker_metadata": "file_metadata",
    "filesystem.nginx_vhost_metadata": "file_metadata",
    "filesystem.php_selector_metadata": "file_metadata",
    "filesystem.supervisor_config_metadata": "file_metadata",
    "filesystem.scheduler_wrapper_metadata": "file_metadata",
    "filesystem.recovery_bundle_metadata": "file_metadata",
    "filesystem.backup_file_metadata": "file_metadata",
    "artifact.helper_sha256": "hash64",
    "artifact.controller_sha256": "hash64",
    "artifact.verifier_sha256": "hash64",
    "recovery.backup_sha256": "hash64",
    "recovery.backup_integrity": "boolean",
    "recovery.isolated_restore": "restore_state",
    "recovery.rollback_sha": "hash40",
    "recovery.pending_migrations": "count",
    "recovery.dba_attestation": "attestation_state",
}

OPTIONAL_FACTS = frozenset({"platform.systemd_version"})
COMPATIBILITY_FACTS = frozenset(FACT_TYPES)
REQUIRED_COMPATIBILITY_FACTS = COMPATIBILITY_FACTS - OPTIONAL_FACTS
TOP_LEVEL_KEYS = frozenset(
    {
        "schema_version",
        "environment",
        "release_sha",
        "collector_sha",
        "collected_at_utc",
        "overall",
        "facts",
    }
)
REQUIRED_EXTENSIONS = frozenset(
    {
        "ctype", "curl", "dom", "fileinfo", "filter", "hash", "iconv", "intl",
        "json", "libxml", "mbstring", "openssl", "pcntl", "pcre", "pdo_mysql",
        "session", "simplexml", "tokenizer", "zip",
    }
)


class ContractError(ValueError):
    """A fixed-code validation failure safe to expose to callers."""

    def __init__(self, code: str):
        super().__init__(code)
        self.code = code


def _fail(code: str) -> None:
    raise ContractError(code)


def _unique_object(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            _fail("DUPLICATE_JSON_KEY")
        result[key] = value
    return result


def _reject_constant(_value: str) -> None:
    _fail("NON_JSON_NUMBER")


def _canonical(value: Any) -> bytes:
    return json.dumps(
        value, ensure_ascii=True, sort_keys=True, separators=(",", ":"), allow_nan=False
    ).encode("ascii")


def evidence_sha256(fact: str, value: Any) -> str:
    """Digest a fact identifier and normalized value, never raw source output."""
    return hashlib.sha256(fact.encode("ascii") + b"\0" + _canonical(value)).hexdigest()


def _valid_value(kind: str, value: Any) -> bool:
    if kind == "hash40":
        return isinstance(value, str) and HASH40.fullmatch(value) is not None
    if kind == "hash64":
        return isinstance(value, str) and HASH64.fullmatch(value) is not None
    if kind == "version":
        return isinstance(value, str) and VERSION.fullmatch(value) is not None
    if kind == "php_version":
        return isinstance(value, str) and PHP_VERSION.fullmatch(value) is not None
    if kind == "os_id":
        return isinstance(value, str) and OS_ID.fullmatch(value) is not None
    if kind == "os_version":
        return isinstance(value, str) and OS_VERSION.fullmatch(value) is not None
    if kind == "architecture":
        return isinstance(value, str) and value in {"x86_64", "aarch64"}
    if kind == "kernel":
        return isinstance(value, str) and value == "linux"
    if kind == "php_profile":
        return isinstance(value, str) and value in {"8.4", "8.5"}
    if kind == "extensions":
        return (
            isinstance(value, list)
            and all(isinstance(item, str) and item in REQUIRED_EXTENSIONS for item in value)
            and value == sorted(set(value))
            and REQUIRED_EXTENSIONS.issubset(set(value))
        )
    if kind == "selector_state":
        return isinstance(value, str) and value in {"php84", "php85", "missing", "invalid"}
    if kind == "socket_metadata":
        return isinstance(value, str) and SOCKET_METADATA.fullmatch(value) is not None
    if kind == "file_metadata":
        return isinstance(value, str) and FILE_METADATA.fullmatch(value) is not None
    if kind == "database_engine":
        return isinstance(value, str) and value in {"mysql", "mariadb"}
    if kind == "db_privilege_profile":
        return isinstance(value, str) and value in {
            "least_privilege", "broader_than_allowed", "database_admin"
        }
    if kind == "boolean":
        return type(value) is bool
    if kind == "binlog_format":
        return isinstance(value, str) and value in {"ROW", "MIXED", "STATEMENT"}
    if kind == "php_handler":
        return isinstance(value, str) and value in {"direct_php84", "selector_php84", "direct_php85", "selector_php85", "missing", "invalid"}
    if kind == "vhost_classification":
        return isinstance(value, str) and value in {
            "staging_wpcaregrid", "production_wpcaregrid", "missing", "ambiguous", "invalid"
        }
    if kind == "selector_contract":
        return isinstance(value, str) and value in {
            "php84_selector", "php85_selector", "direct_php84", "direct_php85", "missing", "invalid"
        }
    if kind == "worker_state":
        return isinstance(value, str) and value in {"RUNNING", "STOPPED", "FATAL", "STARTING", "BACKOFF"}
    if kind == "exit_code":
        return type(value) is int and 0 <= value <= 255
    if kind == "pid":
        return type(value) is int and value >= 0
    if kind == "consumer_census":
        return isinstance(value, str) and value in {"CLEAR", "ACTIVE"}
    if kind == "scheduler_mechanism":
        return isinstance(value, str) and value in {"cron", "systemd"}
    if kind == "deployment_layout":
        return isinstance(value, str) and value in {"current_checkout", "release_symlink"}
    if kind == "cache_state":
        return isinstance(value, str) and value in {"PRESENT", "ABSENT"}
    if kind == "restore_state":
        return isinstance(value, str) and value in {"PASS", "FAIL"}
    if kind == "count":
        return type(value) is int and value >= 0
    if kind == "attestation_state":
        return isinstance(value, str) and value in {"ABSENT", "MIGRATION_WINDOW", "RESTORATION_VERIFIED", "INVALID"}
    return False


def _parse(source: bytes | str | dict[str, Any]) -> dict[str, Any]:
    if isinstance(source, dict):
        # Public callers normally pass bytes; deep JSON round-trip enforces JSON types.
        try:
            encoded = _canonical(source)
        except (TypeError, ValueError, UnicodeEncodeError, RecursionError):
            _fail("JSON_VALUE_INVALID")
    elif isinstance(source, str):
        try:
            encoded = source.encode("utf-8", errors="strict")
        except UnicodeEncodeError:
            _fail("JSON_INVALID")
    elif isinstance(source, bytes):
        encoded = source
    else:
        _fail("INPUT_TYPE_INVALID")
    if len(encoded) > MAX_INPUT_BYTES:
        _fail("INPUT_TOO_LARGE")
    try:
        value = json.loads(
            encoded.decode("utf-8", errors="strict"),
            object_pairs_hook=_unique_object,
            parse_constant=_reject_constant,
        )
    except ContractError:
        raise
    except (UnicodeDecodeError, json.JSONDecodeError, RecursionError, ValueError):
        _fail("JSON_INVALID")
    if not isinstance(value, dict):
        _fail("TOP_LEVEL_INVALID")
    return value


def _timestamp_valid(value: Any) -> bool:
    if not isinstance(value, str) or not re.fullmatch(r"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ", value):
        return False
    try:
        datetime.strptime(value, "%Y-%m-%dT%H:%M:%SZ")
    except ValueError:
        return False
    return True


def _validate_fact(key: str, item: Any) -> dict[str, Any]:
    if key not in FACT_TYPES or not isinstance(item, dict):
        _fail("FACT_INVALID")
    status = item.get("status")
    if not isinstance(status, str) or status not in {"VERIFIED", "UNKNOWN", "NOT_APPLICABLE", "MISMATCH"}:
        _fail("FACT_STATUS_INVALID")
    allowed_keys = {"status", "value", "evidence_sha256"} if status in {"VERIFIED", "MISMATCH"} else {"status", "reason_code"}
    if set(item) != allowed_keys:
        _fail("FACT_SHAPE_INVALID")
    if status in {"VERIFIED", "MISMATCH"}:
        value = item["value"]
        digest = item["evidence_sha256"]
        if not _valid_value(FACT_TYPES[key], value):
            _fail("FACT_VALUE_INVALID")
        if not isinstance(digest, str) or not HASH64.fullmatch(digest) or digest != evidence_sha256(key, value):
            _fail("FACT_EVIDENCE_INVALID")
        return {"status": status, "value": value, "evidence_sha256": digest}
    reason = item["reason_code"]
    if status == "UNKNOWN":
        if not isinstance(reason, str) or reason not in UNKNOWN_REASONS:
            _fail("FACT_REASON_INVALID")
    elif key not in OPTIONAL_FACTS or not isinstance(reason, str) or reason not in OPTIONAL_REASONS:
        _fail("FACT_NOT_APPLICABLE_INVALID")
    return {"status": status, "reason_code": reason}


def _validate_worker_pair(facts: dict[str, dict[str, Any]], prefix: str) -> None:
    state = facts[f"worker.{prefix}_state"]
    pid = facts[f"worker.{prefix}_pid"]
    if state["status"] not in {"VERIFIED", "MISMATCH"} or pid["status"] not in {"VERIFIED", "MISMATCH"}:
        return
    worker_state = state["value"]
    process_id = pid["value"]
    inconsistent = (
        worker_state in {"FATAL", "STOPPED"} and process_id != 0
    ) or (worker_state == "RUNNING" and process_id == 0)
    if inconsistent:
        _fail("WORKER_STATE_INCONSISTENT")


def _validate_runtime_profile(facts: dict[str, dict[str, Any]]) -> None:
    profile = facts["runtime.profile"]
    cli = facts["runtime.php_cli_version"]
    fpm = facts["runtime.php_fpm_version"]
    if any(item["status"] not in {"VERIFIED", "MISMATCH"} for item in (profile, cli, fpm)):
        return
    cli_line = ".".join(cli["value"].split(".")[:2])
    fpm_line = ".".join(fpm["value"].split(".")[:2])
    if cli_line != profile["value"] or fpm_line != profile["value"]:
        _fail("RUNTIME_PROFILE_INCONSISTENT")


def validate_snapshot(source: bytes | str | dict[str, Any]) -> dict[str, Any]:
    """Validate and return a normalized snapshot, without echoing raw input."""
    snapshot = _parse(source)
    if set(snapshot) != TOP_LEVEL_KEYS:
        _fail("ENVELOPE_SHAPE_INVALID")
    if type(snapshot["schema_version"]) is not int or snapshot["schema_version"] != SCHEMA_VERSION:
        _fail("SCHEMA_VERSION_UNSUPPORTED")
    if not isinstance(snapshot["environment"], str) or snapshot["environment"] not in {"staging", "production"}:
        _fail("ENVIRONMENT_INVALID")
    for name in ("release_sha", "collector_sha"):
        if not isinstance(snapshot[name], str) or HASH40.fullmatch(snapshot[name]) is None:
            _fail("IDENTITY_SHA_INVALID")
    if not _timestamp_valid(snapshot["collected_at_utc"]):
        _fail("TIMESTAMP_INVALID")
    facts = snapshot["facts"]
    if not isinstance(facts, dict) or set(facts) != set(FACT_TYPES):
        _fail("FACT_SET_INVALID")
    normalized_facts = {key: _validate_fact(key, facts[key]) for key in sorted(FACT_TYPES)}
    _validate_worker_pair(normalized_facts, "default")
    _validate_worker_pair(normalized_facts, "backups")
    _validate_runtime_profile(normalized_facts)
    application_sha = normalized_facts["deployment.application_sha"]
    if (
        application_sha["status"] == "VERIFIED"
        and application_sha["value"] != snapshot["release_sha"]
    ):
        _fail("RELEASE_SHA_INCONSISTENT")
    statuses = {item["status"] for item in normalized_facts.values()}
    derived = "BLOCKED" if "MISMATCH" in statuses else "UNKNOWN" if "UNKNOWN" in statuses else "VERIFIED"
    if snapshot["overall"] != derived:
        _fail("OVERALL_STATUS_INCONSISTENT")
    return {
        "schema_version": SCHEMA_VERSION,
        "environment": snapshot["environment"],
        "release_sha": snapshot["release_sha"],
        "collector_sha": snapshot["collector_sha"],
        "collected_at_utc": snapshot["collected_at_utc"],
        "overall": derived,
        "facts": normalized_facts,
    }


def compare_expected(
    snapshot_source: bytes | str | dict[str, Any],
    expected_values: dict[str, Any],
    expected_identity: dict[str, Any],
) -> dict[str, Any]:
    """Return per-fact comparisons plus a fail-closed overall readiness status.

    Promotion comparison requires explicit target identities and the complete
    non-optional compatibility contract; diagnostics cannot imply readiness.
    """
    snapshot = validate_snapshot(snapshot_source)
    if not isinstance(expected_identity, dict):
        _fail("EXPECTED_IDENTITY_INVALID")
    identity_keys = {"environment", "release_sha", "collector_sha"}
    if set(expected_identity) != identity_keys:
        _fail("EXPECTED_IDENTITY_SET_INVALID")
    if (
        not isinstance(expected_identity["environment"], str)
        or expected_identity["environment"] not in {"staging", "production"}
    ):
        _fail("EXPECTED_ENVIRONMENT_INVALID")
    for name in ("release_sha", "collector_sha"):
        if not isinstance(expected_identity[name], str) or HASH40.fullmatch(expected_identity[name]) is None:
            _fail("EXPECTED_IDENTITY_SHA_INVALID")
    if not isinstance(expected_values, dict):
        _fail("EXPECTED_CONTRACT_INVALID")
    if any(key not in COMPATIBILITY_FACTS for key in expected_values):
        _fail("EXPECTED_FACT_INVALID")
    if set(expected_values) != REQUIRED_COMPATIBILITY_FACTS:
        _fail("EXPECTED_FACT_SET_INVALID")
    for key, value in expected_values.items():
        if not _valid_value(FACT_TYPES[key], value):
            _fail("EXPECTED_VALUE_INVALID")
    if expected_values["deployment.application_sha"] != expected_identity["release_sha"]:
        _fail("EXPECTED_RELEASE_SHA_INCONSISTENT")
    results = []
    for key in sorted(expected_values):
        observed = snapshot["facts"][key]
        if observed["status"] == "UNKNOWN":
            state = "UNKNOWN"
        elif observed["status"] == "NOT_APPLICABLE":
            state = "UNKNOWN"
        elif observed["status"] == "MISMATCH" or observed["value"] != expected_values[key]:
            state = "MISMATCH"
        else:
            state = "PASS"
        results.append({"fact": key, "status": state})
    identity_results = [
        {
            "identity": name,
            "status": "PASS" if snapshot[name] == expected_identity[name] else "MISMATCH",
        }
        for name in ("environment", "release_sha", "collector_sha")
    ]
    overall = (
        "PASS"
        if snapshot["overall"] == "VERIFIED"
        and all(result["status"] == "PASS" for result in results)
        and all(result["status"] == "PASS" for result in identity_results)
        else "BLOCKED"
    )
    return {"overall": overall, "identity": identity_results, "facts": results}
