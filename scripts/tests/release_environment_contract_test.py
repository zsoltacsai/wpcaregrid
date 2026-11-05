#!/usr/bin/env python3
from __future__ import annotations

import copy
import hashlib
import json
import sys
import unittest
from pathlib import Path


sys.path.insert(0, str(Path(__file__).parents[1]))
import release_environment_contract as contract


VALUES = {
    "hash40": "1" * 40,
    "hash64": "2" * 64,
    "os_id": "ubuntu",
    "os_version": "24.04",
    "architecture": "x86_64",
    "kernel": "linux",
    "version": "8.4.11",
    "php_version": "8.4.11",
    "php_profile": "8.4",
    "extensions": [
        "ctype", "curl", "dom", "fileinfo", "filter", "hash", "iconv", "intl",
        "json", "libxml", "mbstring", "openssl", "pcntl", "pcre", "pdo_mysql",
        "session", "simplexml", "tokenizer", "zip",
    ],
    "selector_state": "php84",
    "socket_metadata": "www-data:www-data:0660",
    "file_metadata": "root:root:0644:regular",
    "database_engine": "mysql",
    "db_privilege_profile": "least_privilege",
    "boolean": True,
    "binlog_format": "ROW",
    "php_handler": "selector_php84",
    "vhost_classification": "staging_wpcaregrid",
    "selector_contract": "php84_selector",
    "worker_state": "RUNNING",
    "exit_code": 0,
    "pid": 321,
    "consumer_census": "CLEAR",
    "scheduler_mechanism": "cron",
    "deployment_layout": "current_checkout",
    "cache_state": "PRESENT",
    "restore_state": "PASS",
    "count": 0,
    "attestation_state": "RESTORATION_VERIFIED",
}


def valid_snapshot() -> dict:
    facts = {}
    for name, kind in contract.FACT_TYPES.items():
        value = copy.deepcopy(VALUES[kind])
        facts[name] = {
            "status": "VERIFIED",
            "value": value,
            "evidence_sha256": contract.evidence_sha256(name, value),
        }
    return {
        "schema_version": 1,
        "environment": "staging",
        "release_sha": "3" * 40,
        "collector_sha": "4" * 40,
        "collected_at_utc": "2026-11-05T12:00:00Z",
        "overall": "VERIFIED",
        "facts": facts,
    }


def encoded(value: dict) -> bytes:
    return json.dumps(value, sort_keys=True, separators=(",", ":")).encode()


def expected_contract(snapshot: dict) -> dict:
    return {
        name: copy.deepcopy(snapshot["facts"][name].get("value", VALUES[contract.FACT_TYPES[name]]))
        for name in contract.REQUIRED_COMPATIBILITY_FACTS
    }


class ReleaseEnvironmentContractTest(unittest.TestCase):
    def assert_contract_code(self, code: str, source) -> None:
        with self.assertRaises(contract.ContractError) as raised:
            contract.validate_snapshot(source)
        self.assertEqual(raised.exception.code, code)

    def test_valid_allowlisted_snapshot_is_verified(self) -> None:
        actual = contract.validate_snapshot(encoded(valid_snapshot()))
        self.assertEqual(actual["overall"], "VERIFIED")
        self.assertEqual(set(actual["facts"]), set(contract.FACT_TYPES))

    def test_missing_required_fact_fails_closed(self) -> None:
        snapshot = valid_snapshot()
        del snapshot["facts"]["database.binlog_format"]
        self.assert_contract_code("FACT_SET_INVALID", encoded(snapshot))

    def test_explicit_unknown_fact_cannot_be_projected_as_verified(self) -> None:
        snapshot = valid_snapshot()
        snapshot["facts"]["worker.default_state"] = {
            "status": "UNKNOWN", "reason_code": "permission_denied"
        }
        snapshot["overall"] = "UNKNOWN"
        normalized = contract.validate_snapshot(encoded(snapshot))
        self.assertEqual(normalized["overall"], "UNKNOWN")
        result = contract.compare_expected(
            normalized,
            expected_contract(normalized),
        )
        self.assertEqual(result["overall"], "BLOCKED")
        self.assertIn(
            {"fact": "worker.default_state", "status": "UNKNOWN"}, result["facts"]
        )

    def test_unknown_reason_and_unexpected_fact_are_rejected(self) -> None:
        snapshot = valid_snapshot()
        snapshot["facts"]["worker.default_state"] = {
            "status": "UNKNOWN", "reason_code": "raw permission denied at /secret/path"
        }
        snapshot["overall"] = "UNKNOWN"
        self.assert_contract_code("FACT_REASON_INVALID", encoded(snapshot))

        snapshot = valid_snapshot()
        snapshot["facts"]["database.password"] = {
            "status": "VERIFIED", "value": "secret", "evidence_sha256": "2" * 64
        }
        self.assert_contract_code("FACT_SET_INVALID", encoded(snapshot))

    def test_schema_version_mismatch_is_rejected(self) -> None:
        snapshot = valid_snapshot()
        snapshot["schema_version"] = 2
        self.assert_contract_code("SCHEMA_VERSION_UNSUPPORTED", encoded(snapshot))

    def test_invalid_evidence_digest_and_inconsistent_overall_are_rejected(self) -> None:
        snapshot = valid_snapshot()
        snapshot["facts"]["database.binlog_format"]["evidence_sha256"] = "0" * 64
        self.assert_contract_code("FACT_EVIDENCE_INVALID", encoded(snapshot))

        snapshot = valid_snapshot()
        snapshot["overall"] = "UNKNOWN"
        self.assert_contract_code("OVERALL_STATUS_INCONSISTENT", encoded(snapshot))

    def test_secret_like_value_is_rejected_without_echo(self) -> None:
        secret = "sk_live_synthetic_do_not_echo"
        snapshot = valid_snapshot()
        snapshot["facts"]["runtime.php_cli_version"]["value"] = secret
        snapshot["facts"]["runtime.php_cli_version"]["evidence_sha256"] = contract.evidence_sha256(
            "runtime.php_cli_version", secret
        )
        with self.assertRaises(contract.ContractError) as raised:
            contract.validate_snapshot(encoded(snapshot))
        self.assertEqual(raised.exception.code, "FACT_VALUE_INVALID")
        self.assertNotIn(secret, str(raised.exception))

    def test_mismatch_blocks_and_compatibility_reports_mismatch(self) -> None:
        snapshot = valid_snapshot()
        name = "runtime.profile"
        value = "8.5"
        snapshot["facts"][name] = {
            "status": "MISMATCH",
            "value": value,
            "evidence_sha256": contract.evidence_sha256(name, value),
        }
        for version_fact in ("runtime.php_cli_version", "runtime.php_fpm_version"):
            version = "8.5.1"
            snapshot["facts"][version_fact] = {
                "status": "VERIFIED",
                "value": version,
                "evidence_sha256": contract.evidence_sha256(version_fact, version),
            }
        snapshot["overall"] = "BLOCKED"
        result = contract.validate_snapshot(encoded(snapshot))
        self.assertEqual(result["overall"], "BLOCKED")
        comparison = contract.compare_expected(result, expected_contract(result) | {name: "8.4"})
        self.assertEqual(comparison["overall"], "BLOCKED")
        self.assertIn({"fact": name, "status": "MISMATCH"}, comparison["facts"])

    def test_comparison_is_deterministic_and_does_not_mutate_input(self) -> None:
        snapshot = valid_snapshot()
        before = copy.deepcopy(snapshot)
        expected = expected_contract(snapshot)
        first = contract.compare_expected(snapshot, expected)
        second = contract.compare_expected(snapshot, dict(reversed(list(expected.items()))))
        self.assertEqual(first, second)
        self.assertEqual(first["overall"], "PASS")
        self.assertTrue(all(item["status"] == "PASS" for item in first["facts"]))
        self.assertEqual(snapshot, before)

    def test_release_comparison_rejects_unknown_or_malformed_expectations(self) -> None:
        snapshot = valid_snapshot()
        with self.assertRaises(contract.ContractError) as raised:
            contract.compare_expected(snapshot, expected_contract(snapshot) | {"database.password": "secret"})
        self.assertEqual(raised.exception.code, "EXPECTED_FACT_INVALID")
        with self.assertRaises(contract.ContractError) as raised:
            contract.compare_expected(snapshot, {"runtime.profile": "8.3"})
        self.assertEqual(raised.exception.code, "EXPECTED_FACT_SET_INVALID")
        with self.assertRaises(contract.ContractError) as raised:
            contract.compare_expected(snapshot, expected_contract(snapshot) | {"runtime.profile": "8.3"})
        self.assertEqual(raised.exception.code, "EXPECTED_VALUE_INVALID")

    def test_partial_matching_fact_cannot_mask_unrelated_unknown_or_mismatch(self) -> None:
        for status, overall in (("UNKNOWN", "UNKNOWN"), ("MISMATCH", "BLOCKED")):
            with self.subTest(status=status):
                snapshot = valid_snapshot()
                if status == "UNKNOWN":
                    snapshot["facts"]["database.trust_function_creators"] = {
                        "status": "UNKNOWN", "reason_code": "permission_denied"
                    }
                else:
                    name = "database.trust_function_creators"
                    value = False
                    snapshot["facts"][name] = {
                        "status": "MISMATCH", "value": value,
                        "evidence_sha256": contract.evidence_sha256(name, value),
                    }
                snapshot["overall"] = overall
                result = contract.validate_snapshot(snapshot)
                with self.assertRaises(contract.ContractError) as raised:
                    contract.compare_expected(result, {"runtime.profile": "8.4"})
                self.assertEqual(raised.exception.code, "EXPECTED_FACT_SET_INVALID")

    def test_complete_comparison_blocks_matching_runtime_when_other_required_fact_is_unknown_or_mismatch(self) -> None:
        for fact_status, expected_overall in (("UNKNOWN", "BLOCKED"), ("MISMATCH", "BLOCKED")):
            with self.subTest(fact_status=fact_status):
                snapshot = valid_snapshot()
                fact = "database.trust_function_creators"
                if fact_status == "UNKNOWN":
                    snapshot["facts"][fact] = {"status": "UNKNOWN", "reason_code": "permission_denied"}
                    snapshot["overall"] = "UNKNOWN"
                else:
                    value = False
                    snapshot["facts"][fact] = {
                        "status": "MISMATCH", "value": value,
                        "evidence_sha256": contract.evidence_sha256(fact, value),
                    }
                    snapshot["overall"] = "BLOCKED"
                expected = expected_contract(snapshot)
                expected["runtime.profile"] = "8.4"
                result = contract.compare_expected(snapshot, expected)
                self.assertEqual(result["overall"], expected_overall)
                self.assertIn({"fact": "runtime.profile", "status": "PASS"}, result["facts"])
                self.assertIn(
                    {"fact": fact, "status": fact_status}, result["facts"]
                )

    def test_only_declared_optional_fact_may_be_not_applicable(self) -> None:
        snapshot = valid_snapshot()
        snapshot["facts"]["platform.systemd_version"] = {
            "status": "NOT_APPLICABLE", "reason_code": "systemd_not_used"
        }
        normalized = contract.validate_snapshot(encoded(snapshot))
        self.assertEqual(normalized["overall"], "VERIFIED")

        snapshot = valid_snapshot()
        snapshot["facts"]["platform.os_id"] = {
            "status": "NOT_APPLICABLE", "reason_code": "systemd_not_used"
        }
        self.assert_contract_code("FACT_NOT_APPLICABLE_INVALID", encoded(snapshot))

    def test_os_version_and_vhost_routing_facts_are_required_and_typed(self) -> None:
        snapshot = valid_snapshot()
        del snapshot["facts"]["platform.os_version"]
        self.assert_contract_code("FACT_SET_INVALID", encoded(snapshot))
        snapshot = valid_snapshot()
        snapshot["facts"]["platform.os_version"] = {
            "status": "VERIFIED", "value": "24.04;secret",
            "evidence_sha256": contract.evidence_sha256("platform.os_version", "24.04;secret"),
        }
        self.assert_contract_code("FACT_VALUE_INVALID", encoded(snapshot))
        for name, bad in (("web.vhost_classification", "ambiguous"),
                          ("web.selector_contract", "invalid")):
            snapshot = valid_snapshot()
            snapshot["facts"][name] = {
                "status": "MISMATCH", "value": bad,
                "evidence_sha256": contract.evidence_sha256(name, bad),
            }
            snapshot["overall"] = "BLOCKED"
            self.assertEqual(contract.validate_snapshot(snapshot)["overall"], "BLOCKED")

    def test_duplicate_keys_oversized_and_malformed_json_are_rejected(self) -> None:
        self.assert_contract_code("DUPLICATE_JSON_KEY", b'{"schema_version":1,"schema_version":1}')
        self.assert_contract_code("INPUT_TOO_LARGE", b" " * (contract.MAX_INPUT_BYTES + 1))
        self.assert_contract_code("JSON_INVALID", b"{not-json}")

    def test_worker_state_pid_inconsistency_is_rejected(self) -> None:
        snapshot = valid_snapshot()
        snapshot["facts"]["worker.default_state"]["value"] = "FATAL"
        snapshot["facts"]["worker.default_state"]["evidence_sha256"] = contract.evidence_sha256(
            "worker.default_state", "FATAL"
        )
        snapshot["facts"]["worker.default_pid"]["value"] = 321
        snapshot["facts"]["worker.default_pid"]["evidence_sha256"] = contract.evidence_sha256(
            "worker.default_pid", 321
        )
        self.assert_contract_code("WORKER_STATE_INCONSISTENT", encoded(snapshot))

    def test_fatal_zero_pid_with_recorded_exit_codes_is_valid_evidence(self) -> None:
        snapshot = valid_snapshot()
        observations = {
            "worker.default_state": "FATAL",
            "worker.default_status_rc": 3,
            "worker.default_pid": 0,
            "worker.default_pid_rc": 7,
        }
        for name, value in observations.items():
            snapshot["facts"][name] = {
                "status": "VERIFIED",
                "value": value,
                "evidence_sha256": contract.evidence_sha256(name, value),
            }
        self.assertEqual(contract.validate_snapshot(encoded(snapshot))["overall"], "VERIFIED")

    def test_running_zero_pid_and_stopped_nonzero_pid_are_rejected(self) -> None:
        for state, pid in (("RUNNING", 0), ("STOPPED", 321)):
            with self.subTest(state=state, pid=pid):
                snapshot = valid_snapshot()
                for name, value in (("worker.default_state", state), ("worker.default_pid", pid)):
                    snapshot["facts"][name] = {
                        "status": "VERIFIED",
                        "value": value,
                        "evidence_sha256": contract.evidence_sha256(name, value),
                    }
                self.assert_contract_code("WORKER_STATE_INCONSISTENT", encoded(snapshot))

    def test_runtime_profile_must_match_cli_and_fpm_versions(self) -> None:
        snapshot = valid_snapshot()
        name = "runtime.php_fpm_version"
        value = "8.5.1"
        snapshot["facts"][name] = {
            "status": "VERIFIED",
            "value": value,
            "evidence_sha256": contract.evidence_sha256(name, value),
        }
        self.assert_contract_code("RUNTIME_PROFILE_INCONSISTENT", encoded(snapshot))

    def test_malformed_unhashable_status_is_rejected_with_fixed_code(self) -> None:
        snapshot = valid_snapshot()
        snapshot["facts"]["worker.default_state"]["status"] = []
        self.assert_contract_code("FACT_STATUS_INVALID", encoded(snapshot))

    def test_evidence_digest_is_of_normalized_fact_not_raw_source(self) -> None:
        value = {"major": 8, "minor": 4}
        first = contract.evidence_sha256("runtime.profile", value)
        second = hashlib.sha256(
            b'runtime.profile\0{"major":8,"minor":4}'
        ).hexdigest()
        self.assertEqual(first, second)

    def test_ci_workflow_is_pr_safe_pinned_and_runs_only_synthetic_tests(self) -> None:
        workflow = (Path(__file__).parents[2] / ".github/workflows/release-environment-contract.yml").read_text(
            encoding="utf-8"
        )
        self.assertIn("pull_request:", workflow)
        self.assertNotIn("pull_request_target", workflow)
        self.assertIn("permissions:\n  contents: read", workflow)
        self.assertNotIn("secrets.", workflow)
        self.assertNotIn("upload-artifact", workflow)
        self.assertIn("actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1", workflow)
        self.assertIn("python3 scripts/tests/release_environment_contract_test.py", workflow)
        self.assertIn("python3 -m py_compile scripts/release_environment_contract.py", workflow)
        self.assertNotIn("stg1.wpcaregrid.com", workflow)
        self.assertNotIn("scripts/deploy-staging.sh", workflow)


if __name__ == "__main__":
    unittest.main()
