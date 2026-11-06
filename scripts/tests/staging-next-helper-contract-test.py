#!/usr/bin/env python3
"""Root-container tests for the versioned helper's exact policy binding."""

from __future__ import annotations

import hashlib
import importlib.util
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location(
    "staging_next_priv", ROOT / "scripts" / "wpcaregrid-staging-next-deploy-priv.py")
HELPER = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = HELPER
SPEC.loader.exec_module(HELPER)


class StagingNextHelperContractTests(unittest.TestCase):
    def setUp(self):
        if os.name != "posix" or os.geteuid() != 0:
            self.skipTest("root-owned helper policy must be exercised in the disposable Linux container")
        self.temp = tempfile.TemporaryDirectory(prefix="wpcg-helper-test-", dir="/root")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.entrypoint = self.root / "entrypoint.py"
        self.backend = self.root / "backend.py"
        self.policy = self.root / "policy.json"
        self.entrypoint.write_bytes((ROOT / "scripts" / "wpcaregrid-staging-next-deploy-priv.py").read_bytes())
        self.backend.write_bytes((ROOT / "scripts" / "staging_next_deploy_backend.py").read_bytes())
        os.chmod(self.entrypoint, 0o755)
        os.chmod(self.backend, 0o644)
        self._patch = mock.patch.multiple(HELPER, ENTRYPOINT=self.entrypoint, BACKEND=self.backend, POLICY=self.policy)
        self._patch.start()
        self.addCleanup(self._patch.stop)
        self.entry_hash = hashlib.sha256(self.entrypoint.read_bytes()).hexdigest()
        self.backend_hash = hashlib.sha256(self.backend.read_bytes()).hexdigest()
        self.artifact_hash = self._artifact_hash(self.entry_hash, self.backend_hash)
        self.policy.write_text(json.dumps({
            "schema": 1, "profile": "staging-next", "enabled": True, "allowed_uid": 1000,
            "app_dir": "/var/www/wpcaregrid-staging-new", "database": "wpcg_staging_next",
            "host": "staging-next.wpcaregrid.com", "php_fpm_uid": 33, "php_fpm_gid": 33,
            "mysql_server_uuid_sha256": "a" * 64,
            "integration_qa_reviewer": "qa-reviewer", "m17_appsec_reviewer": "appsec-reviewer",
            "helper_version": "1.3.1", "compatibility_contract": "wpcaregrid-staging-next-deploy-v3",
            "entrypoint_sha256": self.entry_hash, "backend_sha256": self.backend_hash,
            "helper_artifact_sha256": self.artifact_hash,
            "operations": ["authorize", "deploy", "preflight", "stage"],
        }, sort_keys=True, separators=(",", ":")), encoding="utf-8")
        os.chmod(self.policy, 0o644)

    @staticmethod
    def _artifact_hash(entry_hash, backend_hash):
        payload = json.dumps({
            "schema": 1, "helper_version": "1.3.1",
            "compatibility_contract": "wpcaregrid-staging-next-deploy-v3",
            "entrypoint_sha256": entry_hash, "backend_sha256": backend_hash,
        }, sort_keys=True, separators=(",", ":")).encode("utf-8")
        return hashlib.sha256(payload).hexdigest()

    def test_exact_root_owned_policy_loads_only_pinned_staging_helper(self):
        backend, profile, uid, operation, app_sha, artifact_sha = HELPER._load_backend(
            str(self.entrypoint), ["preflight"], "1000")
        self.assertEqual((uid, operation, app_sha, artifact_sha), (1000, "preflight", None, None))
        self.assertEqual((profile.name, profile.application_link, backend.DATABASE), (
            "staging-next", Path("/var/www/wpcaregrid-staging-new"), "wpcg_staging_next"))
        self.assertEqual(profile.helper_artifact_sha256, self.artifact_hash)
        self.assertEqual(profile.php_fpm_gid, 33)
        self.assertEqual(profile.php_fpm_uid, 33)
        self.assertEqual((backend.HELPER_VERSION, backend.HELPER_CONTRACT), (
            "1.3.1", "wpcaregrid-staging-next-deploy-v3"))
        self.assertEqual(backend.__name__, "_wpcaregrid_staging_next_backend_v1_3")

    def test_wrong_caller_and_runtime_target_override_are_rejected(self):
        with self.assertRaises(HELPER.Refused) as error:
            HELPER._load_backend(str(self.entrypoint), ["preflight"], "1001")
        self.assertEqual(str(error.exception), "caller_identity_invalid")
        with self.assertRaises(HELPER.Refused) as error:
            HELPER._load_backend(str(self.entrypoint), ["production", "a" * 40, "b" * 64], "1000")
        self.assertEqual(str(error.exception), "operation_not_allowed")
        backend, profile, uid, operation, app_sha, artifact_sha = HELPER._load_backend(
            str(self.entrypoint), ["authorize", "a" * 40], "1000")
        self.assertEqual((uid, operation, app_sha, artifact_sha), (1000, "authorize", "a" * 40, None))

    def test_artifact_hash_and_policy_operation_changes_fail_closed(self):
        original = json.loads(self.policy.read_text(encoding="utf-8"))
        for mutation, expected in (
            (lambda p: p.update(backend_sha256="f" * 64), "backend_identity_mismatch"),
            (lambda p: p.update(helper_artifact_sha256="f" * 64), "helper_artifact_identity_mismatch"),
            (lambda p: p.update(operations=["authorize", "deploy", "preflight", "stage", "production"]),
             "policy_target_or_contract_invalid"),
        ):
            changed = dict(original)
            mutation(changed)
            self.policy.write_text(json.dumps(changed, sort_keys=True, separators=(",", ":")), encoding="utf-8")
            with self.subTest(expected=expected), self.assertRaises(HELPER.Refused) as error:
                HELPER._load_backend(str(self.entrypoint), ["preflight"], "1000")
            self.assertEqual(str(error.exception), expected)


if __name__ == "__main__":
    unittest.main(verbosity=2)
