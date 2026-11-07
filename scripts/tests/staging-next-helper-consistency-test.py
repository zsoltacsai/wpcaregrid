#!/usr/bin/env python3
"""Independently bind the 1.4.6 helper bytes and preserve the 1.4.5 pins."""

from __future__ import annotations

import hashlib
import importlib.util
import json
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from scripts import staging_next_deploy_controller as CONTROLLER


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


HELPER = load_module("staging_next_priv_1_4_6", ROOT / "scripts" / "wpcaregrid-staging-next-deploy-priv-1_4_6.py")
INSTALLER = load_module("staging_next_installer_1_4_6", ROOT / "scripts" / "install_staging_next_helper_1_4_6.py")
MANIFEST = ROOT / "deploy" / "release-manifests" / "staging-next-helper-1.4.6.json"
FILES = {
    "entrypoint": ROOT / "scripts" / "wpcaregrid-staging-next-deploy-priv-1_4_6.py",
    "backend": ROOT / "scripts" / "staging_next_deploy_backend_1_4_6.py",
    "transaction_module": ROOT / "scripts" / "staging_next_db_transaction_1_4_6.py",
}


HELPER_147 = load_module("staging_next_priv_1_4_7", ROOT / "scripts" / "wpcaregrid-staging-next-deploy-priv-1_4_7.py")


class StagingNextHelperConsistencyTests(unittest.TestCase):
    def test_146_manifest_entrypoint_installer_controller_and_sources_share_all_pins(self):
        manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
        hashes = {key: hashlib.sha256(path.read_bytes()).hexdigest() for key, path in FILES.items()}
        self.assertEqual(set(manifest), {
            "schema", "profile", "helper_version", "compatibility_contract", "entrypoint_path",
            "entrypoint_sha256", "backend_path", "backend_sha256", "transaction_module_path",
            "transaction_module_sha256", "helper_artifact_sha256",
        })
        self.assertEqual((HELPER.VERSION, HELPER.CONTRACT),
                         ("1.4.6", "wpcaregrid-staging-next-deploy-v10"))
        self.assertEqual((manifest["helper_version"], manifest["compatibility_contract"]),
                         (HELPER.VERSION, HELPER.CONTRACT))
        for key, path in FILES.items():
            self.assertEqual(manifest[f"{key}_path"], path.relative_to(ROOT).as_posix())
            hash_key = "transaction_module_sha256" if key == "transaction_module" else f"{key}_sha256"
            self.assertEqual(manifest[hash_key], hashes[key])
        payload = {
            "schema": 1, "helper_version": HELPER.VERSION,
            "compatibility_contract": HELPER.CONTRACT,
            "entrypoint_sha256": hashes["entrypoint"], "backend_sha256": hashes["backend"],
            "transaction_module_sha256": hashes["transaction_module"],
        }
        artifact_sha = hashlib.sha256(json.dumps(payload, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
        self.assertEqual(manifest["helper_artifact_sha256"], artifact_sha)
        self.assertEqual(CONTROLLER.HELPER_ARTIFACT_SHA256, artifact_sha)
        self.assertEqual(CONTROLLER.HELPER, HELPER.ENTRYPOINT)
        self.assertEqual(INSTALLER.VERSION, HELPER.VERSION)
        self.assertEqual(INSTALLER.CONTRACT, HELPER.CONTRACT)
        self.assertEqual(INSTALLER.ENTRY_SHA256, hashes["entrypoint"])
        self.assertEqual(INSTALLER.BACKEND_SHA256, hashes["backend"])
        self.assertEqual(INSTALLER.TRANSACTION_SHA256, hashes["transaction_module"])
        self.assertEqual(INSTALLER.ARTIFACT_SHA256, artifact_sha)


    def test_147_manifest_entrypoint_installer_controller_and_sources_share_all_pins(self):
        manifest_path = ROOT / "deploy" / "release-manifests" / "staging-next-helper-1.4.7.json"
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        files = {
            "entrypoint": ROOT / "scripts" / "wpcaregrid-staging-next-deploy-priv-1_4_7.py",
            "backend": ROOT / "scripts" / "staging_next_deploy_backend_1_4_7.py",
            "transaction_module": ROOT / "scripts" / "staging_next_db_transaction_1_4_7.py",
        }
        hashes = {key: hashlib.sha256(path.read_bytes()).hexdigest() for key, path in files.items()}
        installer = load_module("staging_next_installer_1_4_7", ROOT / "scripts" / "install_staging_next_helper_1_4_7.py")
        self.assertEqual((HELPER_147.VERSION, HELPER_147.CONTRACT),
                         ("1.4.7", "wpcaregrid-staging-next-deploy-v11"))
        backend = load_module("staging_next_backend_1_4_7", files["backend"])
        self.assertEqual((backend.HELPER_VERSION, backend.HELPER_CONTRACT),
                         (HELPER_147.VERSION, HELPER_147.CONTRACT))
        self.assertEqual((manifest["helper_version"], manifest["compatibility_contract"]),
                         (HELPER_147.VERSION, HELPER_147.CONTRACT))
        for key, path in files.items():
            self.assertEqual(manifest[f"{key}_path"], path.relative_to(ROOT).as_posix())
            hash_key = "transaction_module_sha256" if key == "transaction_module" else f"{key}_sha256"
            self.assertEqual(manifest[hash_key], hashes[key])
        payload = {"schema": 1, "helper_version": HELPER_147.HELPER_VERSION,
                   "compatibility_contract": HELPER_147.HELPER_CONTRACT,
                   "entrypoint_sha256": hashes["entrypoint"], "backend_sha256": hashes["backend"],
                   "transaction_module_sha256": hashes["transaction_module"]}
        artifact_sha = hashlib.sha256(json.dumps(payload, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
        self.assertEqual(manifest["helper_artifact_sha256"], artifact_sha)
        self.assertEqual(CONTROLLER.HELPER_ARTIFACT_SHA256, artifact_sha)
        transaction = load_module("staging_next_transaction_1_4_7", files["transaction_module"])
        self.assertEqual(transaction.PHASE_B_POLICY_PATH,
                         Path("/etc/wpcaregrid-deploy/profiles/staging-next-phase-b-1.4.7.json"))
        self.assertEqual(transaction.PHASE_B_CREDENTIALS_PATH,
                         Path("/etc/wpcaregrid-deploy/credentials/staging-next-database-1.4.7.json"))
        self.assertEqual(CONTROLLER.HELPER, HELPER_147.ENTRYPOINT)
        self.assertEqual((installer.VERSION, installer.CONTRACT),
                         (HELPER_147.HELPER_VERSION, HELPER_147.HELPER_CONTRACT))
        self.assertEqual((installer.ENTRY_SHA256, installer.BACKEND_SHA256,
                          installer.TRANSACTION_SHA256, installer.ARTIFACT_SHA256),
                         (hashes["entrypoint"], hashes["backend"], hashes["transaction_module"], artifact_sha))

    def test_145_installer_and_manifest_artifact_pin_remain_unchanged(self):
        legacy = json.loads((ROOT / "deploy" / "release-manifests" / "staging-next-helper-1.4.5.json")
                            .read_text(encoding="utf-8"))
        legacy_installer = (ROOT / "scripts" / "install_staging_next_helper_1_4_5.py").read_text(encoding="utf-8")
        self.assertEqual(legacy["helper_version"], "1.4.5")
        self.assertEqual(legacy["helper_artifact_sha256"],
                         "7c8eeab748381b2b6de4ade68160ce8d4d79c2748fc99366bf9f584358bedebf")
        self.assertIn(legacy["helper_artifact_sha256"], legacy_installer)
        self.assertEqual(legacy["backend_sha256"],
                         "56b731914127b60e99f50f601930bbae8953490e1427003759225a9571a7135b")


if __name__ == "__main__":
    unittest.main(verbosity=2)
