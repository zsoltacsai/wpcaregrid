#!/usr/bin/env python3
"""Focused 1.4.11 Phase 2B definer and release pin tests."""
from __future__ import annotations

import hashlib
import importlib.util
import json
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

def load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module

TRANSACTION = load("transaction_1411", ROOT / "scripts/staging_next_db_transaction_1_4_11.py")
HELPER = load("helper_1411", ROOT / "scripts/wpcaregrid-staging-next-deploy-priv-1_4_11.py")
BACKEND = load("backend_1411", ROOT / "scripts/staging_next_deploy_backend_1_4_11.py")
INSTALLER = load("installer_1411", ROOT / "scripts/install_staging_next_helper_1_4_11.py")
MANIFEST_PATH = ROOT / "deploy/release-manifests/staging-next-helper-1.4.11.json"

class Phase2BDefinerTests(unittest.TestCase):
    def test_exact_non_reserved_identity_is_accepted(self):
        for value in ("wpcg-phase2b@localhost", "phase2b-definer@127.0.0.1", "app-definer@db.internal"):
            with self.subTest(value=value):
                self.assertTrue(TRANSACTION._phase2b_expected_definer(value))

    def test_reserved_and_pattern_identities_are_rejected(self):
        for value in (
            "root@localhost", "ROOT@localhost", "mysql@localhost", "mariadb@localhost",
            "sys@localhost", "system@localhost", "mysql.session@localhost",
            "wpcg_phase2b@%", "wpcg_phase2b@db_%", "wpcg_phase2b@db.example%",
            "%@localhost", "wpcg_%@localhost", "wpcg_phase2b@db_host",
            "@localhost", "wpcg_phase2b@", "wpcg@one@two", " wpcg@localhost", None, 4,
        ):
            with self.subTest(value=value):
                self.assertFalse(TRANSACTION._phase2b_expected_definer(value))

    def test_manifest_installer_and_controller_pins_match_exact_artifacts(self):
        manifest = json.loads(MANIFEST_PATH.read_text(encoding="utf-8"))
        paths = {
            "entrypoint": ROOT / "scripts/wpcaregrid-staging-next-deploy-priv-1_4_11.py",
            "backend": ROOT / "scripts/staging_next_deploy_backend_1_4_11.py",
            "transaction_module": ROOT / "scripts/staging_next_db_transaction_1_4_11.py",
            "target_binding": ROOT / "scripts/deployment_target_binding_1_4_11.py",
            "environment_contract": ROOT / "scripts/deployment_environment_contract.py",
        }
        hashes = {key: hashlib.sha256(path.read_bytes()).hexdigest() for key, path in paths.items()}
        self.assertEqual((HELPER.VERSION, HELPER.CONTRACT, BACKEND.HELPER_VERSION, BACKEND.HELPER_CONTRACT),
                         ("1.4.11", manifest["compatibility_contract"], "1.4.11", manifest["compatibility_contract"]))
        for key, path in paths.items():
            self.assertEqual(manifest[f"{key}_path"], path.relative_to(ROOT).as_posix())
            hash_key = "transaction_module_sha256" if key == "transaction_module" else f"{key}_sha256"
            self.assertEqual(manifest[hash_key], hashes[key])
        payload = {"schema": 1, "helper_version": HELPER.VERSION, "compatibility_contract": HELPER.CONTRACT,
                   "entrypoint_sha256": hashes["entrypoint"], "backend_sha256": hashes["backend"],
                   "transaction_module_sha256": hashes["transaction_module"],
                   "target_binding_sha256": hashes["target_binding"],
                   "environment_contract_sha256": hashes["environment_contract"]}
        artifact = hashlib.sha256(json.dumps(payload, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
        self.assertEqual(manifest["helper_artifact_sha256"], artifact)
        self.assertEqual((INSTALLER.VERSION, INSTALLER.CONTRACT, INSTALLER.ARTIFACT_SHA256),
                         (HELPER.VERSION, HELPER.CONTRACT, artifact))
        sys.path.insert(0, str(ROOT))
        from scripts import staging_next_deploy_controller as controller
        self.assertEqual(controller.HELPER, HELPER.ENTRYPOINT)
        self.assertEqual(controller.HELPER_ARTIFACT_SHA256, artifact)

if __name__ == "__main__":
    unittest.main()
