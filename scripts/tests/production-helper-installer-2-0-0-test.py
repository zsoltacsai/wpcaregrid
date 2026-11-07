#!/usr/bin/env python3
"""Synthetic safety tests for production helper installer 2.0.0."""

from __future__ import annotations

import hashlib
import importlib.util
import json
import os
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[2]
INSTALLER_PATH = ROOT / "scripts/install_production_helper_2_0_0.py"
SPEC = importlib.util.spec_from_file_location("production_helper_installer_test", INSTALLER_PATH)
assert SPEC is not None and SPEC.loader is not None
INSTALLER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(INSTALLER)


class ProductionHelperInstallerTests(unittest.TestCase):
    def setUp(self):
        if os.name != "posix" or not hasattr(os, "fchown"):
            self.skipTest("installer filesystem semantics require Linux/POSIX")
        self.temp = tempfile.TemporaryDirectory(
            prefix="wpcg-production-installer-", dir=str(Path.home()))
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.uid = os.getuid()
        self.gid = os.getgid()

    def test_disabled_policy_has_exact_schema_null_environment_and_no_phase_b(self):
        policy = json.loads(INSTALLER._disabled_policy().decode("utf-8"))
        self.assertEqual(set(policy), INSTALLER.POLICY_KEYS)
        self.assertEqual(policy["profile"], "production")
        self.assertIs(policy["enabled"], False)
        self.assertEqual(policy["operations"], list(INSTALLER.OPERATIONS))
        self.assertIsNone(policy["phase_b_policy_sha256"])
        self.assertTrue(all(policy[key] is None for key in INSTALLER.ENVIRONMENT_FIELDS))
        serialized = json.dumps(policy)
        self.assertNotIn("staging-next", serialized)
        self.assertNotIn("/var/www/wpcaregrid/", serialized)

    def test_sudoers_template_is_exactly_five_fixed_production_operations(self):
        raw = (ROOT / "deploy/sudoers/wpcaregrid-production-v2.0.0").read_bytes()
        INSTALLER._verify_sudoers_source(raw)
        rules = [line for line in raw.decode("ascii").splitlines()
                 if line.strip() and not line.lstrip().startswith("#")]
        self.assertEqual(len(rules), 5)
        self.assertTrue(all("staging" not in line and "*" not in line for line in rules))
        self.assertTrue(all(str(INSTALLER.ENTRYPOINT) in line for line in rules))

    def test_effective_sudo_listing_must_contain_only_the_five_fixed_operations(self):
        prefix = f"(root) NOPASSWD: NOSETENV: {INSTALLER.ENTRYPOINT} "
        output = "\n".join(prefix + operation for operation in INSTALLER.OPERATIONS).encode()
        with patch.object(INSTALLER, "_run", return_value=SimpleNamespace(
                returncode=0, stdout=output, stderr=b"")):
            INSTALLER._verify_installed_sudoers("deploy-production", lambda *_a, **_k: None)
        extra = output + b"\n(root) NOPASSWD: /bin/sh"
        with patch.object(INSTALLER, "_run", return_value=SimpleNamespace(
                returncode=0, stdout=extra, stderr=b"")):
            with self.assertRaises(INSTALLER.InstallError):
                INSTALLER._verify_installed_sudoers("deploy-production", lambda *_a, **_k: None)

    def test_atomic_publication_never_overwrites_and_identity_checked_rollback(self):
        target = self.root / "artifact"
        created = []
        payload = b"approved production helper bytes\n"
        INSTALLER._publish(target, payload, 0o600, created,
                           owner_uid=self.uid, owner_gid=self.gid)
        self.assertEqual(target.read_bytes(), payload)
        self.assertEqual(len(created), 1)
        with self.assertRaises(INSTALLER.InstallError):
            INSTALLER._publish(target, b"replacement", 0o600, created,
                               owner_uid=self.uid, owner_gid=self.gid)
        self.assertEqual(target.read_bytes(), payload)
        self.assertTrue(INSTALLER._rollback(created, []))
        self.assertFalse(target.exists())

    def test_rollback_preserves_file_changed_after_publication(self):
        target = self.root / "artifact"
        created = []
        INSTALLER._publish(target, b"pinned bytes", 0o600, created,
                           owner_uid=self.uid, owner_gid=self.gid)
        target.write_bytes(b"unexpected modification")
        self.assertFalse(INSTALLER._rollback(created, []))
        self.assertEqual(target.read_bytes(), b"unexpected modification")

    def test_source_read_rejects_hash_mismatch_and_symlink(self):
        source = self.root / "source.py"
        payload = b"pinned source\n"
        source.write_bytes(payload)
        source.chmod(0o600)
        digest = hashlib.sha256(payload).hexdigest()
        self.assertEqual(INSTALLER._read_source(source, digest, deploy_uid=self.uid), payload)
        with self.assertRaises(INSTALLER.InstallError):
            INSTALLER._read_source(source, "0" * 64, deploy_uid=self.uid)
        link = self.root / "source-link.py"
        link.symlink_to(source)
        with self.assertRaises(INSTALLER.InstallError):
            INSTALLER._read_source(link, digest, deploy_uid=self.uid)

    def test_exact_source_directory_rejects_unexpected_files(self):
        source_dir = self.root / "sources"
        source_dir.mkdir(mode=0o700)
        source_dir.chmod(0o700)
        name = "synthetic-source.py"
        payload = b"synthetic source\n"
        file = source_dir / name
        file.write_bytes(payload)
        file.chmod(0o600)
        hashes = {name: hashlib.sha256(payload).hexdigest()}
        with patch.object(INSTALLER, "SOURCE_DIR", source_dir):
            INSTALLER._check_source_directory(self.uid)
            with patch.object(INSTALLER, "SOURCE_HASHES", hashes):
                self.assertEqual(INSTALLER._read_sources(self.uid), {name: payload})
            (source_dir / "extra.txt").write_text("unexpected", encoding="utf-8")
            with patch.object(INSTALLER, "SOURCE_HASHES", hashes):
                with self.assertRaises(INSTALLER.InstallError):
                    INSTALLER._read_sources(self.uid)

    def test_missing_dedicated_production_identity_fails_before_any_host_checks(self):
        with patch.object(INSTALLER.os, "geteuid", return_value=0), \
                patch.object(INSTALLER.pwd, "getpwnam", side_effect=KeyError("deploy-production")):
            with self.assertRaises(INSTALLER.InstallError) as error:
                INSTALLER._preflight(runner=lambda *_args, **_kwargs: self.fail("must not run commands"))
        self.assertEqual(str(error.exception), "required_identity_missing")

    def test_production_identity_must_be_distinct_from_staging_next(self):
        def get_user(name):
            return SimpleNamespace(pw_uid=1000 if name == "deploy" else 996)

        with patch.object(INSTALLER.os, "geteuid", return_value=0), \
                patch.object(INSTALLER.pwd, "getpwnam", side_effect=get_user):
            with self.assertRaises(INSTALLER.InstallError) as error:
                INSTALLER._preflight(deploy_user="deploy-staging-next")
        self.assertEqual(str(error.exception), "production_deploy_identity_overlaps_staging")

    def test_invalid_existing_sudoers_stops_before_installation(self):
        with patch.object(INSTALLER, "_run", return_value=SimpleNamespace(
                returncode=1, stdout=b"", stderr=b"syntax error")):
            with self.assertRaises(INSTALLER.InstallError) as error:
                INSTALLER._verify_existing_sudoers(lambda *_a, **_k: None)
        self.assertEqual(str(error.exception), "existing_sudoers_invalid")


if __name__ == "__main__":
    unittest.main(verbosity=2)
