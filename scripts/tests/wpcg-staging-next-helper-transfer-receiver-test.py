from __future__ import annotations

import hashlib
import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path


RECEIVER = Path(__file__).resolve().parents[1] / "wpcg-staging-next-helper-transfer-receiver.sh"
ROOT = Path(__file__).resolve().parents[2]
NAMES = (
    "wpcaregrid-staging-next-deploy-priv-1_4_10.py",
    "staging_next_deploy_backend_1_4_10.py",
    "staging_next_db_transaction_1_4_10.py",
    "deployment_target_binding_1_4_10.py",
    "deployment_environment_contract.py",
)


class ReceiverTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="wpcg-receiver-")
        self.root = Path(self.temp.name)
        self.private = self.root / "home/deploy/.private"
        self.private.mkdir(parents=True)
        self.private.chmod(0o700)
        self.nonce = "12345678-1234-4234-8234-123456789abc"
        self.env = {**os.environ, "WPCG_RECEIVER_TEST": "1", "WPCG_RECEIVER_TEST_ROOT": str(self.root)}

    def tearDown(self):
        self.temp.cleanup()

    def call_receiver(self, *args):
        return subprocess.run(["/bin/sh", str(RECEIVER), *args], env=self.env,
                              text=True, capture_output=True, check=False)

    def begin_and_write(self, data=None):
        begin = self.call_receiver("begin", "1.4.10", self.nonce)
        self.assertEqual(begin.returncode, 0, begin.stderr)
        stage = self.private / f".wpcg-helper-upload-{self.nonce}"
        values = []
        for index, name in enumerate(NAMES):
            content = (data or {}).get(name, f"artifact-{index}".encode())
            path = stage / name
            path.write_bytes(content)
            path.chmod(0o600)
            values.extend((name, hashlib.sha256(content).hexdigest()))
        return stage, values

    def test_preflight_and_create_no_overwrite_publish(self):
        preflight = self.call_receiver("preflight", "1.4.10", self.nonce)
        self.assertEqual(preflight.returncode, 0, preflight.stderr)
        stage, values = self.begin_and_write()
        result = self.call_receiver("publish", "1.4.10", self.nonce, *values)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("TRANSFER=PASS", result.stdout)
        dest = self.private / "wpcg-staging-next-helper-1.4.10"
        self.assertEqual({p.name for p in dest.iterdir()}, set(NAMES))
        for name in NAMES:
            self.assertEqual(os.stat(dest / name).st_nlink, 1)
        self.assertFalse(stage.exists())
        second = self.call_receiver("publish", "1.4.10", self.nonce, *values)
        self.assertNotEqual(second.returncode, 0)
        self.assertIn("destination_exists", second.stderr)

    def test_missing_private_is_reported_read_only_then_created_exclusively_on_stage(self):
        shutil.rmtree(self.private)
        preflight = self.call_receiver("preflight", "1.4.10", self.nonce)
        self.assertEqual(preflight.returncode, 0, preflight.stderr)
        self.assertIn("PRIVATE=absent_will_create_on_stage", preflight.stdout)
        self.assertFalse(self.private.exists())
        begin = self.call_receiver("begin", "1.4.10", self.nonce)
        self.assertEqual(begin.returncode, 0, begin.stderr)
        self.assertEqual(os.stat(self.private).st_mode & 0o777, 0o700)

    def test_wrong_hash_fails_closed_and_keeps_upload_for_review(self):
        stage, values = self.begin_and_write()
        values[1] = "0" * 64
        result = self.call_receiver("publish", "1.4.10", self.nonce, *values)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("staged_file_hash_mismatch", result.stderr)
        self.assertTrue(stage.is_dir())
        self.assertFalse((self.private / "wpcg-staging-next-helper-1.4.10").exists())

    def test_preexisting_final_destination_is_never_overwritten(self):
        stage, values = self.begin_and_write()
        dest = self.private / "wpcg-staging-next-helper-1.4.10"
        dest.mkdir(mode=0o700)
        (dest / "operator-file").write_text("preserve")
        result = self.call_receiver("publish", "1.4.10", self.nonce, *values)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("destination_exists", result.stderr)
        self.assertEqual((dest / "operator-file").read_text(), "preserve")
        self.assertTrue(stage.exists())

    def test_publish_all_separates_helper_from_root_operations_and_normalizes_scp_modes(self):
        version = "1.4.12"
        stage = self.private / f".wpcg-helper-upload-{self.nonce}"
        begin = self.call_receiver("begin", version, self.nonce)
        self.assertEqual(begin.returncode, 0, begin.stderr)
        helper_names = (
            "wpcaregrid-staging-next-deploy-priv-1_4_12.py",
            "staging_next_deploy_backend_1_4_12.py",
            "staging_next_db_transaction_1_4_12.py",
            "deployment_target_binding_1_4_12.py",
            "deployment_environment_contract_1_4_12.py",
        )
        ops_names = ("wpcg_staging_next_runtime_layout.py",
                     "wpcg-staging-next-path-transition-1_0.py",
                     "install_staging_next_helper_1_4_12.py")
        names = helper_names + ops_names
        pairs = []
        for index, name in enumerate(names):
            data = f"artifact-{index}".encode()
            path = stage / name
            path.write_bytes(data)
            path.chmod(0o644)  # SCP from Windows may not preserve a private POSIX mode.
            pairs.extend((name, hashlib.sha256(data).hexdigest()))
        result = self.call_receiver("publish-all", version, self.nonce, *pairs)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("FILES=8;OVERWRITE=NO", result.stdout)
        helper_dir = self.private / "wpcg-staging-next-helper-1.4.12"
        ops_dir = self.private / "wpcg-staging-next-ops-1.0"
        self.assertEqual({path.name for path in helper_dir.iterdir()}, set(helper_names))
        self.assertEqual({path.name for path in ops_dir.iterdir()}, set(ops_names))
        for path in list(helper_dir.iterdir()) + list(ops_dir.iterdir()):
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            self.assertEqual(path.stat().st_nlink, 1)
        self.assertFalse(stage.exists())

    def test_hp_adapter_is_pinned_to_manifest_receiver_and_both_root_tools(self):
        adapter = (ROOT / "scripts/stage-staging-next-helper.ps1").read_text(encoding="utf-8")
        manifest_path = ROOT / "deploy/release-manifests/staging-next-helper-1.4.12.json"
        manifest_digest = hashlib.sha256(manifest_path.read_bytes()).hexdigest()
        receiver_digest = hashlib.sha256(RECEIVER.read_bytes()).hexdigest()
        self.assertIn("$PinnedHelperVersion = '1.4.12'", adapter)
        self.assertIn("$PinnedManifestPath = 'deploy/release-manifests/staging-next-helper-1.4.12.json'", adapter)
        self.assertIn(f"$PinnedManifestSha256 = '{manifest_digest}'", adapter)
        self.assertIn(f"$PinnedReceiverSha256 = '{receiver_digest}'", adapter)
        for name in ("wpcg_staging_next_runtime_layout.py", "wpcg-staging-next-path-transition-1_0.py",
                     "install_staging_next_helper_1_4_12.py"):
            digest = hashlib.sha256((ROOT / "scripts" / name).read_bytes()).hexdigest()
            self.assertIn(f"'{name}' = '{digest}'", adapter)
        self.assertNotIn("REPLACE_AFTER_REVIEW", adapter)
        self.assertIn("publish-all", adapter)
        self.assertIn("StrictHostKeyChecking=yes", adapter)
        self.assertIn("$ExpectedPrimarySHA", adapter)


if __name__ == "__main__":
    unittest.main()
