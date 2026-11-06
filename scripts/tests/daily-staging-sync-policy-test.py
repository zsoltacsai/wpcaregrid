#!/usr/bin/env python3
"""Synthetic release-manifest decision tests; no host or GitHub calls."""

import importlib.util
import sys
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("daily_sync", ROOT / "scripts" / "daily_staging_sync_policy.py")
POLICY = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = POLICY
SPEC.loader.exec_module(POLICY)


def evidence(value="PASS"):
    if value != "PASS":
        return {name: value for name in POLICY.REQUIRED_EVIDENCE}
    return {
        "environment_identity": {
            "status": "PASS", "profile": "staging-next", "app_dir": POLICY.APP_DIR,
            "database": POLICY.DATABASE, "host": POLICY.HOST,
        },
        "artifact_integrity": {
            "status": "PASS", "application_sha": "a" * 40, "artifact_sha256": "b" * 64,
        },
        "exact_head_ci": {"status": "PASS", "commit_sha": "e" * 40, "run_id": 1001},
        "merge_sha_ci": {"status": "PASS", "commit_sha": "a" * 40, "run_id": 1002},
        "integration_qa": {"status": "PASS", "commit_sha": "e" * 40},
        "m17_appsec": {"status": "PASS", "commit_sha": "e" * 40},
    }


def manifest(**changes):
    value = {
        "schema": 1,
        "profile": "staging-next",
        "app_dir": POLICY.APP_DIR,
        "database": POLICY.DATABASE,
        "host": POLICY.HOST,
        "application_sha": "a" * 40,
        "exact_head_sha": "e" * 40,
        "merge_sha": "a" * 40,
        "artifact_sha256": "b" * 64,
        "helper_version": POLICY.HELPER_VERSION,
        "helper_sha256": "c" * 64,
        "helper_contract": POLICY.HELPER_CONTRACT,
        "required_evidence": evidence(),
        "database_changes": True,
        "database_evidence": {name: "PASS" for name in POLICY.DATABASE_EVIDENCE},
    }
    value.update(changes)
    return value


def deployed(**changes):
    value = {
        "profile": "staging-next",
        "app_dir": POLICY.APP_DIR,
        "database": POLICY.DATABASE,
        "host": POLICY.HOST,
        "application_sha": "d" * 40,
        "artifact_sha256": "f" * 64,
        "helper_version": POLICY.HELPER_VERSION,
        "helper_sha256": "c" * 64,
        "helper_contract": POLICY.HELPER_CONTRACT,
    }
    value.update(changes)
    return value


class DailyStagingSyncPolicyTest(unittest.TestCase):
    def decide(self, candidate=None, active=None):
        return POLICY.evaluate_sync(manifest() if candidate is None else candidate, deployed() if active is None else active)

    def test_same_approved_release_is_noop(self):
        result = self.decide(active=deployed(application_sha="a" * 40, artifact_sha256="b" * 64))
        self.assertEqual((result.status, result.reasons), ("NO_OP", ()))

    def test_candidate_artifact_must_match_deployed_hash_for_noop(self):
        result = self.decide(active=deployed(application_sha="a" * 40))
        self.assertEqual(result.status, "HOLD")
        self.assertEqual(result.reasons, ("deployment_operation_not_in_approved_helper_contract",))

    def test_changed_release_is_held_until_a_reviewed_deployment_operation_exists(self):
        result = self.decide()
        self.assertEqual(result.status, "HOLD")
        self.assertEqual(result.reasons, ("deployment_operation_not_in_approved_helper_contract",))

    def test_old_staging_production_and_alternate_targets_are_rejected(self):
        for key, value in [("app_dir", "/var/www/wpcaregrid"), ("database", "wpcaregrid"),
                           ("host", "wpcaregrid.com"), ("profile", "production")]:
            with self.subTest(key=key):
                self.assertEqual(self.decide(manifest(**{key: value})).reasons, ("release_target_mismatch",))
        self.assertEqual(self.decide(active=deployed(app_dir="/var/www/wpcaregrid")).reasons,
                         ("deployed_target_mismatch",))

    def test_each_acceptance_gate_is_required(self):
        for gate in POLICY.REQUIRED_EVIDENCE:
            invalid = evidence()
            invalid[gate] = dict(invalid[gate])
            invalid[gate]["status"] = "UNKNOWN"
            reason = {
                "environment_identity": "environment_identity_unverified",
                "artifact_integrity": "artifact_integrity_unverified",
                "exact_head_ci": "exact_head_ci_unverified",
                "merge_sha_ci": "merge_sha_ci_unverified",
                "integration_qa": "integration_qa_unverified",
                "m17_appsec": "m17_appsec_unverified",
            }[gate]
            with self.subTest(gate=gate):
                self.assertEqual(self.decide(manifest(required_evidence=invalid)).reasons,
                                 (reason,))

    def test_evidence_is_bound_to_release_commit_artifact_and_target(self):
        invalid_cases = [
            ("environment_identity", {"status": "PASS", "profile": "production",
             "app_dir": POLICY.APP_DIR, "database": POLICY.DATABASE, "host": POLICY.HOST},
             "environment_identity_unverified"),
            ("artifact_integrity", {"status": "PASS", "application_sha": "a" * 40,
             "artifact_sha256": "9" * 64}, "artifact_integrity_unverified"),
            ("exact_head_ci", {"status": "PASS", "commit_sha": "0" * 40, "run_id": 1001},
             "exact_head_ci_unverified"),
            ("merge_sha_ci", {"status": "PASS", "commit_sha": "0" * 40, "run_id": 1002},
             "merge_sha_ci_unverified"),
            ("integration_qa", {"status": "PASS", "commit_sha": "0" * 40},
             "integration_qa_unverified"),
            ("m17_appsec", {"status": "PASS", "commit_sha": "0" * 40},
             "m17_appsec_unverified"),
        ]
        for gate, record, reason in invalid_cases:
            with self.subTest(gate=gate):
                proof = evidence()
                proof[gate] = record
                self.assertEqual(self.decide(manifest(required_evidence=proof)).reasons, (reason,))

    def test_malformed_ci_run_id_and_release_merge_sha_fail_closed(self):
        proof = evidence()
        proof["exact_head_ci"]["run_id"] = "1001"
        self.assertEqual(self.decide(manifest(required_evidence=proof)).reasons,
                         ("exact_head_ci_unverified",))
        self.assertEqual(self.decide(manifest(merge_sha="9" * 40)).reasons,
                         ("release_commit_identity_invalid",))

    def test_artifact_and_application_hashes_must_be_exact(self):
        for key, value in [("application_sha", "a" * 39), ("artifact_sha256", "x" * 64)]:
            with self.subTest(key=key):
                self.assertEqual(self.decide(manifest(**{key: value})).status, "HOLD")

    def test_helper_version_and_contract_are_decoupled_but_must_match_install(self):
        self.assertEqual(self.decide().reasons, ("deployment_operation_not_in_approved_helper_contract",))
        self.assertEqual(self.decide(active=deployed(helper_sha256="e" * 64)).reasons,
                         ("helper_identity_mismatch",))
        self.assertEqual(self.decide(manifest(helper_version="2.0.0")).reasons,
                         ("helper_identity_mismatch",))
        self.assertEqual(self.decide(manifest(helper_contract="unknown")).reasons,
                         ("helper_identity_mismatch",))

    def test_database_changing_release_requires_all_recovery_evidence(self):
        for missing in POLICY.DATABASE_EVIDENCE:
            proof = {name: "PASS" for name in POLICY.DATABASE_EVIDENCE if name != missing}
            with self.subTest(missing=missing):
                self.assertEqual(self.decide(manifest(database_evidence=proof)).reasons,
                                 ("database_safety_evidence_incomplete",))

    def test_no_database_change_requires_empty_database_evidence(self):
        result = self.decide(manifest(database_changes=False, database_evidence={}))
        self.assertEqual(result.reasons, ("deployment_operation_not_in_approved_helper_contract",))
        self.assertEqual(self.decide(manifest(database_changes=False)).reasons,
                         ("unexpected_database_evidence",))

    def test_unknown_or_extra_manifest_fields_fail_closed(self):
        value = manifest()
        value["production_path"] = "/var/www/wpcaregrid-placeholder"
        self.assertEqual(self.decide(value).reasons, ("evidence_fields_invalid",))
        self.assertEqual(POLICY.evaluate_sync(None, deployed()).reasons, ("evidence_shape_invalid",))
        self.assertEqual(self.decide(manifest(schema=True)).reasons, ("release_target_mismatch",))


if __name__ == "__main__":
    unittest.main()
