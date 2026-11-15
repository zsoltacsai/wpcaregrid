#!/usr/bin/env python3
"""Deterministic tests for the Phase 2B DBA receipt safety contract."""

import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("phase2b_dba_handoff", ROOT / "scripts" / "phase2b_dba_handoff.py")
HANDOFF = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(HANDOFF)


def fingerprint(prefix):
    return {"schema_sha256": prefix * 64, "ledger_sha256": (prefix if prefix != "a" else "b") * 64,
            "data_sha256": (prefix if prefix != "c" else "d") * 64,
            "migrations": ["prior_migration"] if prefix in "ab" else ["prior_migration", *[
                name for name, _ in HANDOFF.MIGRATIONS]]}


def make_receipt():
    value = {
        "schema": 1,
        "application_sha": "1" * 40,
        "artifact_sha256": "2" * 64,
        "source_sha256": "3" * 64,
        "target_uuid_sha256": "4" * 64,
        "database": "wpcg_staging_next",
        "mysql": {"log_bin": True, "binlog_format": "ROW", "trust_function_creators": "OFF"},
        "migration_files": [{"name": name + ".php", "sha256": digest}
                            for name, digest in HANDOFF.MIGRATIONS],
        "before": fingerprint("a"), "after": fingerprint("c"),
        "dba": {"approved_definer": "phase2b_guard@localhost", "temporary_super_revoked": True,
                "elevated_sessions_terminated": True, "global_privileges_only_usage": True,
                "account_locked": True, "definer_durable": True, "schema_grants_minimized": True},
        "trigger_inventory": [{"name": f"guard_{n}", "definer": "phase2b_guard@localhost", "valid": True}
                              for n in range(6)],
    }
    value["receipt_sha256"] = HANDOFF.receipt_digest(value)
    return value


class Phase2BDbaHandoffTests(unittest.TestCase):
    def validate(self, value):
        return HANDOFF.validate_receipt(value, application_sha="1" * 40,
            artifact_sha256="2" * 64, source_sha256="3" * 64,
            target_uuid_sha256="4" * 64, expected_before=fingerprint("a"),
            expected_after=fingerprint("c"), expected_definer="phase2b_guard@localhost")

    def reject(self, value, code):
        with self.assertRaises(HANDOFF.HandoffRefused) as error:
            self.validate(value)
        self.assertEqual(error.exception.code, code)

    def test_valid_receipt_and_idempotent_resume(self):
        value = make_receipt()
        digest = self.validate(value)
        self.assertEqual(self.validate(value), digest)

    def test_missing_receipt_fails_closed(self):
        self.reject(None, "phase2b_dba_receipt_missing")

    def test_wrong_release_sha_fails_closed(self):
        value = make_receipt()
        value["application_sha"] = "9" * 40
        value["receipt_sha256"] = HANDOFF.receipt_digest(value)
        self.reject(value, "phase2b_dba_release_sha_mismatch")

    def test_wrong_migration_hash_fails_closed(self):
        value = make_receipt()
        value["migration_files"][0]["sha256"] = "0" * 64
        value["receipt_sha256"] = HANDOFF.receipt_digest(value)
        self.reject(value, "phase2b_dba_migration_hash_mismatch")

    def test_wrong_ledger_or_target_fingerprint_fails_closed(self):
        value = make_receipt()
        value["after"]["ledger_sha256"] = "e" * 64
        value["receipt_sha256"] = HANDOFF.receipt_digest(value)
        self.reject(value, "phase2b_dba_after_fingerprint_mismatch")

    def test_failed_revoke_or_session_cleanup_fails_closed(self):
        for field in ("temporary_super_revoked", "elevated_sessions_terminated"):
            with self.subTest(field=field):
                value = make_receipt()
                value["dba"][field] = False
                value["receipt_sha256"] = HANDOFF.receipt_digest(value)
                self.reject(value, "phase2b_dba_cleanup_unverified")

    def test_wrong_trust_setting_fails_closed(self):
        value = make_receipt()
        value["mysql"]["trust_function_creators"] = "ON"
        value["receipt_sha256"] = HANDOFF.receipt_digest(value)
        self.reject(value, "phase2b_dba_mysql_contract_mismatch")


if __name__ == "__main__":
    unittest.main()
