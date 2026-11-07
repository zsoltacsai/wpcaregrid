#!/usr/bin/env python3
"""Independently bind installed staging-next helper bytes to release pins."""

from __future__ import annotations

import hashlib
import importlib.util
import json
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from scripts import staging_next_deploy_backend as BACKEND
from scripts import staging_next_deploy_controller as CONTROLLER

_HELPER_SPEC = importlib.util.spec_from_file_location("staging_next_priv", ROOT / "scripts" / "wpcaregrid-staging-next-deploy-priv.py")
assert _HELPER_SPEC is not None and _HELPER_SPEC.loader is not None
HELPER = importlib.util.module_from_spec(_HELPER_SPEC)
_HELPER_SPEC.loader.exec_module(HELPER)


MANIFEST = ROOT / "deploy" / "release-manifests" / f"staging-next-helper-{HELPER.VERSION}.json"
ENTRYPOINT = ROOT / "scripts" / "wpcaregrid-staging-next-deploy-priv.py"
BACKEND_FILE = ROOT / "scripts" / "staging_next_deploy_backend.py"
TRANSACTION_FILE = ROOT / "scripts" / "staging_next_db_transaction.py"


class StagingNextHelperConsistencyTests(unittest.TestCase):
    def test_versioned_manifest_matches_final_artifact_bytes_and_all_pins(self):
        manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
        entry_raw = ENTRYPOINT.read_bytes()
        backend_raw = BACKEND_FILE.read_bytes()
        transaction_raw = TRANSACTION_FILE.read_bytes()
        entry_sha = hashlib.sha256(entry_raw).hexdigest()
        backend_sha = hashlib.sha256(backend_raw).hexdigest()
        transaction_sha = hashlib.sha256(transaction_raw).hexdigest()
        self.assertEqual(set(manifest), {
            "schema", "profile", "helper_version", "compatibility_contract",
            "entrypoint_path", "entrypoint_sha256", "backend_path", "backend_sha256",
            "transaction_module_path", "transaction_module_sha256", "helper_artifact_sha256",
        })
        self.assertEqual(manifest["schema"], 1)
        self.assertEqual(manifest["profile"], "staging-next")
        self.assertEqual(manifest["helper_version"], HELPER.VERSION)
        self.assertEqual(manifest["compatibility_contract"], HELPER.CONTRACT)
        self.assertEqual(manifest["entrypoint_path"], "scripts/wpcaregrid-staging-next-deploy-priv.py")
        self.assertEqual(manifest["backend_path"], "scripts/staging_next_deploy_backend.py")
        self.assertEqual(manifest["transaction_module_path"], "scripts/staging_next_db_transaction.py")
        self.assertEqual(manifest["entrypoint_sha256"], entry_sha)
        self.assertEqual(manifest["backend_sha256"], backend_sha)
        self.assertEqual(manifest["transaction_module_sha256"], transaction_sha)
        digest_payload = json.dumps({
            "schema": 1,
            "helper_version": HELPER.VERSION,
            "compatibility_contract": HELPER.CONTRACT,
            "entrypoint_sha256": entry_sha,
            "backend_sha256": backend_sha,
            "transaction_module_sha256": transaction_sha,
        }, sort_keys=True, separators=(",", ":")).encode("utf-8")
        artifact_sha = hashlib.sha256(digest_payload).hexdigest()
        self.assertEqual(manifest["helper_artifact_sha256"], artifact_sha)
        self.assertEqual(CONTROLLER.HELPER_ARTIFACT_SHA256, artifact_sha)
        self.assertEqual(BACKEND.HELPER_VERSION, HELPER.VERSION)
        self.assertEqual(BACKEND.HELPER_CONTRACT, HELPER.CONTRACT)
        self.assertEqual(CONTROLLER.HELPER, HELPER.ENTRYPOINT)


if __name__ == "__main__":
    unittest.main(verbosity=2)
