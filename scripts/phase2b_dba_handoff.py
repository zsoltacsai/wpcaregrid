#!/usr/bin/env python3
"""Fail-closed receipt contract for the Phase 2B DBA migration boundary.

This module validates evidence only. It never connects to MySQL, changes grants,
or executes migrations; those actions belong to the separately authorized DBA.
"""

from __future__ import annotations

import hashlib
import json
import re
from typing import Mapping


MIGRATIONS = (
    ("2026_11_02_000002_create_transition_v2_requests_table",
     "f616d65af26cce1677582b48d3165b8e5811169f1a8735d221b8e5d82ae494da"),
    ("2026_11_02_000003_create_transition_history_events_table",
     "fec0ae70fcd373bce7f21ec5dc274c9f53d9961d5c0833b92ab437912741e5c1"),
)
HASH_RE = re.compile(r"[0-9a-f]{64}\Z")
SHA_RE = re.compile(r"[0-9a-f]{40}\Z")
RECEIPT_SCHEMA = 1


class HandoffRefused(ValueError):
    """A fixed-code rejection safe to expose in controller output."""

    def __init__(self, code: str):
        self.code = code
        super().__init__(code)


def receipt_digest(receipt: Mapping[str, object]) -> str:
    """Hash canonical receipt contents, excluding its self-referential digest."""
    body = {key: value for key, value in receipt.items() if key != "receipt_sha256"}
    return hashlib.sha256(json.dumps(body, sort_keys=True, separators=(",", ":"),
                                         ensure_ascii=True).encode("ascii")).hexdigest()


def validate_receipt(receipt: object, *, application_sha: str, artifact_sha256: str,
                     target_uuid_sha256: str, source_sha256: str,
                     expected_before: Mapping[str, str],
                     expected_after: Mapping[str, str],
                     expected_definer: str) -> str:
    """Validate a DBA handoff receipt against the exact paused release and DB state.

    The receipt must explicitly attest to the safe operating conditions and
    post-revoke cleanup. Any absent, unexpected, or mismatched evidence is HOLD.
    """
    if not isinstance(receipt, dict):
        raise HandoffRefused("phase2b_dba_receipt_missing")
    required = {
        "schema", "application_sha", "artifact_sha256", "source_sha256",
        "target_uuid_sha256", "database", "mysql", "migration_files",
        "before", "after", "dba", "trigger_inventory", "receipt_sha256",
    }
    if set(receipt) != required:
        raise HandoffRefused("phase2b_dba_receipt_shape_invalid")
    if receipt.get("schema") != RECEIPT_SCHEMA:
        raise HandoffRefused("phase2b_dba_receipt_schema_invalid")
    if receipt.get("application_sha") != application_sha or not SHA_RE.fullmatch(application_sha):
        raise HandoffRefused("phase2b_dba_release_sha_mismatch")
    for key, expected in (("artifact_sha256", artifact_sha256),
                          ("source_sha256", source_sha256),
                          ("target_uuid_sha256", target_uuid_sha256)):
        if not HASH_RE.fullmatch(expected) or receipt.get(key) != expected:
            raise HandoffRefused(f"phase2b_dba_{key}_mismatch")
    if receipt.get("database") != "wpcg_staging_next":
        raise HandoffRefused("phase2b_dba_database_mismatch")
    migration_files = receipt.get("migration_files")
    expected_files = [{"name": name + ".php", "sha256": digest} for name, digest in MIGRATIONS]
    if migration_files != expected_files:
        raise HandoffRefused("phase2b_dba_migration_hash_mismatch")
    mysql = receipt.get("mysql")
    if not isinstance(mysql, dict) or mysql != {
        "log_bin": True, "binlog_format": "ROW", "trust_function_creators": "OFF",
    }:
        raise HandoffRefused("phase2b_dba_mysql_contract_mismatch")
    for label, observed, expected in (("before", receipt.get("before"), expected_before),
                                      ("after", receipt.get("after"), expected_after)):
        if not isinstance(observed, dict) or set(observed) != {
            "schema_sha256", "ledger_sha256", "data_sha256", "migrations",
        } or any(observed.get(key) != expected.get(key)
                 for key in ("schema_sha256", "ledger_sha256", "data_sha256", "migrations")):
            raise HandoffRefused(f"phase2b_dba_{label}_fingerprint_mismatch")
    if not isinstance(expected_definer, str) or not re.fullmatch(r"[^@\s]{1,64}@[^@\s]{1,255}", expected_definer):
        raise HandoffRefused("phase2b_dba_definer_contract_invalid")
    dba = receipt.get("dba")
    if not isinstance(dba, dict) or dba != {
        "approved_definer": expected_definer,
        "temporary_super_revoked": True,
        "elevated_sessions_terminated": True,
        "global_privileges_only_usage": True,
        "account_locked": True,
        "definer_durable": True,
        "schema_grants_minimized": True,
    }:
        raise HandoffRefused("phase2b_dba_cleanup_unverified")
    inventory = receipt.get("trigger_inventory")
    if (not isinstance(inventory, list) or len(inventory) != 6
            or any(not isinstance(item, dict) or item.get("definer") != expected_definer
                   or item.get("valid") is not True for item in inventory)
            or len({item.get("name") for item in inventory if isinstance(item, dict)}) != 6):
        raise HandoffRefused("phase2b_dba_trigger_inventory_mismatch")
    digest = receipt_digest(receipt)
    if receipt.get("receipt_sha256") != digest:
        raise HandoffRefused("phase2b_dba_receipt_hash_mismatch")
    return digest
