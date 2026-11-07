#!/usr/bin/env python3
"""Independently recompute production deployment artifact provenance."""

from __future__ import annotations

import hashlib
import json
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MANIFEST_PATH = ROOT / "deploy/release-manifests/production-deployment-helper-2.0.0.json"
SOURCE_PATHS = {
    "entrypoint_sha256": "scripts/wpcaregrid-deploy-production-priv.py",
    "backend_sha256": "scripts/deployment_backend_v2.py",
    "transaction_module_sha256": "scripts/deployment_transaction_v2.py",
    "environment_contract_sha256": "scripts/deployment_environment_contract.py",
}
SUDOERS_PATH = ROOT / "deploy/sudoers/wpcaregrid-production-v2.0.0"
CONTROLLER_PATH = "scripts/production_deploy_controller.py"
INSTALLER_PATH = "scripts/install_production_helper_2_0_0.py"


def git_tree_bytes(path: str) -> bytes:
    """Read the committed blob so line-ending conversion cannot falsify pins."""
    result = subprocess.run(
        ["git", "cat-file", "blob", f"HEAD:{path}"],
        cwd=ROOT, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    return result.stdout


class ProductionDeploymentArtifactConsistencyTests(unittest.TestCase):
    def test_manifest_matches_exact_tree_bytes_and_controller_pin(self):
        manifest = json.loads(MANIFEST_PATH.read_text(encoding="utf-8"))
        self.assertEqual(manifest["schema"], 1)
        self.assertEqual(manifest["profile"], "production")
        self.assertEqual(manifest["helper_version"], "2.0.0")
        self.assertEqual(manifest["compatibility_contract"], "wpcaregrid-deployment-profile-v2")
        hashes = {key: hashlib.sha256(git_tree_bytes(path)).hexdigest()
                  for key, path in SOURCE_PATHS.items()}
        for key, value in hashes.items():
            self.assertEqual(manifest[key], value, key)
        material = {
            "schema": 1,
            "helper_version": manifest["helper_version"],
            "compatibility_contract": manifest["compatibility_contract"],
            **hashes,
        }
        digest = hashlib.sha256(json.dumps(
            material, sort_keys=True, separators=(",", ":")).encode("utf-8")).hexdigest()
        self.assertEqual(manifest["helper_artifact_sha256"], digest)
        self.assertEqual(manifest["controller"], CONTROLLER_PATH)
        self.assertEqual(manifest["sudoers_template"], SUDOERS_PATH.relative_to(ROOT).as_posix())
        controller = git_tree_bytes(CONTROLLER_PATH)
        controller_hash = hashlib.sha256(controller).hexdigest()
        self.assertEqual(manifest["controller_sha256"], controller_hash)
        self.assertEqual(manifest["sudoers_template_sha256"], hashlib.sha256(
            git_tree_bytes(manifest["sudoers_template"])).hexdigest())
        self.assertEqual(manifest["root_installer"], INSTALLER_PATH)
        installer = git_tree_bytes(INSTALLER_PATH)
        installer_hash = hashlib.sha256(installer).hexdigest()
        self.assertEqual(manifest["root_installer_sha256"], installer_hash)
        installer_text = installer.decode("utf-8")
        installer_pins = {
            "ENTRY_SHA256": manifest["entrypoint_sha256"],
            "BACKEND_SHA256": manifest["backend_sha256"],
            "TRANSACTION_SHA256": manifest["transaction_module_sha256"],
            "ENVIRONMENT_SHA256": manifest["environment_contract_sha256"],
            "HELPER_ARTIFACT_SHA256": manifest["helper_artifact_sha256"],
            "CONTROLLER_SHA256": manifest["controller_sha256"],
            "SUDOERS_SHA256": manifest["sudoers_template_sha256"],
        }
        for constant, digest in installer_pins.items():
            self.assertIsNotNone(re.search(
                rf'^{constant} = "{re.escape(digest)}"$', installer_text, re.M), constant)
        runbook = (ROOT / "docs/PRODUCTION_MARKETING_RELEASE_RUNBOOK.md").read_text(encoding="utf-8")
        install_doc = (ROOT / "docs/PRODUCTION_HELPER_INSTALL_2_0_0.md").read_text(encoding="utf-8")
        for pinned_digest in (*hashes.values(), digest, controller_hash,
                              manifest["sudoers_template_sha256"], installer_hash):
            self.assertIn(f"`{pinned_digest}`", runbook)
            self.assertIn(f"`{pinned_digest}`", install_doc)
        self.assertIn("sync <40-hex-application-SHA>", runbook)
        match = re.search(rb'^HELPER_ARTIFACT_SHA256 = "([0-9a-f]{64})"\r?$', controller, re.M)
        self.assertIsNotNone(match)
        self.assertEqual(match.group(1).decode("ascii"), digest)

    def test_production_manifest_remains_inert_by_default(self):
        manifest = json.loads(MANIFEST_PATH.read_text(encoding="utf-8"))
        self.assertEqual(manifest["phase_b_default"], "DISABLED")
        self.assertEqual(manifest["production_activation"],
                         "BLOCKED_UNTIL_APPROVED_ENVIRONMENT_POLICY_AND_RECOVERY_EVIDENCE")

    def test_candidate_sudoers_is_fixed_to_dedicated_user_and_versioned_entrypoint(self):
        rules = [line for line in SUDOERS_PATH.read_text(encoding="ascii").splitlines()
                 if line and not line.startswith("#")]
        self.assertEqual(len(rules), 5)
        self.assertTrue(all(line.startswith("deploy-production ALL=(root) NOPASSWD: NOSETENV: ")
                            for line in rules))
        self.assertTrue(all("/usr/local/libexec/wpcaregrid-deploy/production/2.0.0/"
                            "wpcaregrid-deploy-production-priv" in line for line in rules))
        self.assertTrue(all("staging-next" not in line and "*" not in line for line in rules))
        self.assertIn("authorize", "\n".join(rules))
        self.assertIn("stage", "\n".join(rules))
        self.assertIn("deploy", "\n".join(rules))


if __name__ == "__main__":
    unittest.main(verbosity=2)
