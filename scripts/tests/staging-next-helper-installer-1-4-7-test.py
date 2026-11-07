#!/usr/bin/env python3
"""Synthetic tests for staging-next helper 1.4.7 installer safety."""

from __future__ import annotations

import hashlib
import importlib.util
import os
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[2]
INSTALLER_PATH = ROOT / "scripts" / "install_staging_next_helper_1_4_7.py"
SPEC = importlib.util.spec_from_file_location("wpcg_installer_test", INSTALLER_PATH)
assert SPEC is not None and SPEC.loader is not None
INSTALLER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(INSTALLER)


class StagingNextHelperInstallerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="wpcg-installer-test-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.owner_uid = os.getuid() if hasattr(os, "getuid") else 1000
        self.owner_gid = os.getgid() if hasattr(os, "getgid") else self.owner_uid
        # CI uses an unprivileged account; ownership remains the current test
        # identity while production defaults stay hard-coded to root:root.
        self.fchown_patch = patch.object(
            os, "fchown", lambda _fd, _uid, _gid: None, create=True)
        self.fchown_patch.start()
        self.addCleanup(self.fchown_patch.stop)

    def test_atomic_publish_verifies_and_never_overwrites(self):
        target = self.root / "artifact"
        created = []
        payload = b"approved bytes\n"
        INSTALLER._publish(target, payload, 0o600, created,
                           owner_uid=self.owner_uid, owner_gid=self.owner_gid)
        self.assertEqual(target.read_bytes(), payload)
        self.assertEqual(len(created), 1)
        with self.assertRaises(INSTALLER.InstallError):
            INSTALLER._publish(target, b"replacement", 0o600, created,
                               owner_uid=self.owner_uid, owner_gid=self.owner_gid)
        self.assertEqual(target.read_bytes(), payload)
        self.assertTrue(INSTALLER._rollback(created, None))
        self.assertFalse(target.exists())

    def test_post_link_verification_failure_is_tracked_and_rolled_back(self):
        target = self.root / "artifact"
        created = []
        with patch.object(INSTALLER, "_sha256_file_nofollow", side_effect=INSTALLER.InstallError("injected")):
            with self.assertRaises(INSTALLER.InstallError):
                INSTALLER._publish(target, b"approved bytes", 0o600, created,
                                   owner_uid=self.owner_uid, owner_gid=self.owner_gid)
        self.assertTrue(target.exists())
        self.assertEqual(len(created), 1)
        self.assertTrue(INSTALLER._rollback(created, None))
        self.assertFalse(target.exists())

    def test_rollback_refuses_to_delete_changed_file(self):
        target = self.root / "artifact"
        created = []
        INSTALLER._publish(target, b"approved bytes", 0o600, created,
                           owner_uid=self.owner_uid, owner_gid=self.owner_gid)
        target.write_bytes(b"unexpected replacement")
        self.assertFalse(INSTALLER._rollback(created, None))
        self.assertEqual(target.read_bytes(), b"unexpected replacement")

    def test_directory_rollback_checks_owner_mode_inode_and_emptiness(self):
        directory = self.root / "created"
        directory.mkdir(mode=0o700)
        info = directory.stat()
        identity = (directory, info.st_dev, info.st_ino, info.st_uid, info.st_gid,
                    INSTALLER.stat.S_IMODE(info.st_mode))
        self.assertTrue(INSTALLER._rollback([], identity))
        self.assertFalse(directory.exists())

        directory.mkdir(mode=0o700)
        info = directory.stat()
        identity = (directory, info.st_dev, info.st_ino, info.st_uid, info.st_gid,
                    INSTALLER.stat.S_IMODE(info.st_mode))
        (directory / "unexpected").write_text("keep", encoding="utf-8")
        self.assertFalse(INSTALLER._rollback([], identity))
        self.assertTrue((directory / "unexpected").exists())

    def test_source_directory_must_contain_only_the_three_pinned_artifacts(self):
        source_dir = self.root / "sources"
        source_dir.mkdir()
        for name in (INSTALLER.ENTRY_NAME, INSTALLER.BACKEND_NAME, INSTALLER.TRANSACTION_NAME):
            (source_dir / name).write_bytes(b"synthetic")
        with patch.object(INSTALLER, "SOURCE_DIR", source_dir):
            INSTALLER._verify_source_directory_contents()
            (source_dir / "unexpected.txt").write_text("extra", encoding="utf-8")
            with self.assertRaises(INSTALLER.InstallError):
                INSTALLER._verify_source_directory_contents()

    def test_source_bytes_require_exact_hash_and_regular_file(self):
        source = self.root / "entry.py"
        payload = b"pinned source\n"
        source.write_bytes(payload)
        source.chmod(0o600)
        deploy_uid = os.getuid() if hasattr(os, "getuid") else 1000
        with patch.object(INSTALLER.pwd, "getpwnam",
                          return_value=SimpleNamespace(pw_uid=deploy_uid)):
            self.assertEqual(INSTALLER._read_source(source, expected_sha=hashlib.sha256(payload).hexdigest()),
                             payload)
            with self.assertRaises(INSTALLER.InstallError):
                INSTALLER._read_source(source, expected_sha="0" * 64)
        symlink = self.root / "entry-link.py"
        symlink.symlink_to(source)
        with patch.object(INSTALLER.pwd, "getpwnam",
                          return_value=SimpleNamespace(pw_uid=deploy_uid)):
            with self.assertRaises(INSTALLER.InstallError):
                INSTALLER._read_source(symlink, expected_sha=hashlib.sha256(payload).hexdigest())

    def test_phase_b_policy_must_be_absent_even_when_dangling_symlink(self):
        policy = self.root / "phase-b.json"
        INSTALLER._verify_phase_b_disabled(policy)
        policy.symlink_to(self.root / "missing-target")
        with self.assertRaises(INSTALLER.InstallError):
            INSTALLER._verify_phase_b_disabled(policy)

    def test_sudoers_is_fixed_to_versioned_staging_next_operations(self):
        rules = INSTALLER._sudoers("deploy-staging-next").decode("ascii")
        self.assertIn("/staging-next/1.4.7/wpcaregrid-deploy-staging-next-priv preflight", rules)
        self.assertIn("authorize", rules)
        self.assertIn("stage", rules)
        self.assertIn("deploy", rules)
        self.assertNotIn("ALL", rules.split(": ")[-1])
        self.assertNotIn("production", rules)
        self.assertNotIn("/var/www/wpcaregrid\n", rules)

    def test_sudoers_validation_does_not_publish_activation_file(self):
        target = self.root / "sudoers"
        with patch.object(INSTALLER, "SUDOERS", target), \
                patch.object(INSTALLER, "_run", return_value=SimpleNamespace(returncode=0)):
            INSTALLER._run_sudoers_validation(INSTALLER._sudoers("deploy-staging-next"))
        self.assertFalse(target.exists())

    def test_installation_probe_is_read_only_and_does_not_call_preflight(self):
        captured = []

        def run(argv, **kwargs):
            captured.append((argv, kwargs))
            return SimpleNamespace(returncode=0,
                                   stdout=("{\"result\":\"PASS\",\"profile\":\"staging-next\","
                                           f"\"helper_version\":\"{INSTALLER.VERSION}\","
                                           f"\"helper_contract\":\"{INSTALLER.CONTRACT}\","
                                           f"\"helper_artifact_sha256\":\"{INSTALLER.ARTIFACT_SHA256}\"}}\n"
                                           .encode("utf-8")),
                                   stderr=b"")

        with patch.object(INSTALLER, "_run", side_effect=run):
            INSTALLER._positive_installation_probe()
        self.assertEqual(captured[0][0][-2:], [str(INSTALLER.ENTRYPOINT), "verify-install"])
        self.assertNotIn("preflight", captured[0][0])
        self.assertEqual(captured[0][1]["extra_env"], {"SUDO_UID": str(INSTALLER.EXPECTED_DEPLOY_UID)})

    def test_installation_probe_fails_closed_on_helper_refusal(self):
        with patch.object(INSTALLER, "_run", return_value=SimpleNamespace(
                returncode=3, stdout=b"", stderr=b"DEPLOYMENT=HOLD;CODE=identity_invalid\n")):
            with self.assertRaises(INSTALLER.InstallError) as error:
                INSTALLER._positive_installation_probe()
        self.assertEqual(str(error.exception), "positive_installation_probe_failed")

    def test_nginx_validation_executes_only_authenticated_byte_buffers(self):
        transaction = b"LOADED_FROM_VERIFIED_BUFFER = True\n"
        backend = (
            b"import sys\n"
            b"def _verify_staging_next_nginx_route(raw):\n"
            b"    assert raw == b'dump'\n"
            b"    assert sys.modules['_wpcg_staging_next_db_transaction_v1'].LOADED_FROM_VERIFIED_BUFFER\n"
        )
        calls = [SimpleNamespace(returncode=0, stdout=b""),
                 SimpleNamespace(returncode=0, stdout=b"dump")]
        with patch.object(INSTALLER, "_run", side_effect=calls):
            INSTALLER._verify_nginx(backend, transaction)


if __name__ == "__main__":
    unittest.main(verbosity=2)
