#!/usr/bin/env python3
"""POSIX integration tests: staging-next helper 1.4.9 uses the target binding on its real paths.

Filesystem checks run against a real temporary tree (real symlinks, hardlinks,
file modes and flock). Only the fixed production path constants are replaced by
a fixture target so no host path is touched.
"""

from __future__ import annotations

import dataclasses
import fcntl
import os
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from scripts import staging_next_deploy_backend_1_4_9 as BE  # noqa: E402

BIND = BE._BINDING_MODULE
REAL = BE._TARGET
LEGACY = BIND.select_target("legacy-staging")
SHA = "a" * 40
BACKUP_NAME = f"staging-next-{SHA}-1762600000000000000-abcdef01.sql.gz"


class Fixture(unittest.TestCase):
    def setUp(self):
        self.root = Path(os.path.realpath(tempfile.mkdtemp(prefix="wpcg-binding-it-")))
        self.addCleanup(shutil.rmtree, self.root, ignore_errors=True)
        self.state_root = self.root / "stn"
        self.state = self.state_root / "state"
        self.backups = self.state_root / "database-backups"
        for directory in (self.state, self.backups, self.root / "app"):
            directory.mkdir(parents=True)
        self.state.chmod(0o700)
        self.backups.chmod(0o700)
        self.target = dataclasses.replace(
            REAL, app_dir=str(self.root / "app"), state_root=str(self.state_root),
            backup_root=str(self.backups), lock_file=str(self.state / "deploy.lock"),
            maintenance_marker=str(self.state / "maintenance"),
            worker_fence_marker=str(self.state / "workers-disabled"),
            scheduler_fence_marker=str(self.state / "scheduler-paused"))
        registry = {"staging-next": self.target, "legacy-staging": LEGACY}
        for patcher in (mock.patch.object(BIND, "TARGETS", registry),
                        mock.patch.object(BE, "_TARGET", self.target)):
            patcher.start()
            self.addCleanup(patcher.stop)
        self.uid = os.getuid()
        self.profile = dataclasses.replace(
            BE.PRODUCTION_PROFILE, allowed_uid=self.uid, expected_owner_uid=self.uid,
            php_fpm_uid=33, php_fpm_gid=33, application_link=self.root / "app",
            releases_dir=self.state_root / "releases", incoming_dir=self.state_root / "incoming",
            inbox_dir=self.state_root / "inbox", state_dir=self.state,
            receipts_dir=self.state_root / "receipts",
            shared_env_file=self.state_root / "shared" / ".env",
            shared_storage_dir=self.state_root / "shared" / "storage",
            shared_vendor_dir=self.state_root / "shared" / "vendor",
            basic_auth_file=None, phase_b_policy_file=BE.PHASE_B_POLICY_PATH)
        self.assertFalse(self.profile.test_only)

    def refusal(self, call, *args, **kwargs):
        with self.assertRaises(BE.DeployRefused) as ctx:
            call(*args, **kwargs)
        return str(ctx.exception)

    def marker(self, name, mode=0o600):
        path = self.state / name
        path.write_bytes(b"marker")
        path.chmod(mode)
        return path

    def policy(self, worker, scheduler):
        return SimpleNamespace(worker_disabled_marker=worker, scheduler_paused_marker=scheduler)


class RealConstantsTests(unittest.TestCase):
    def test_real_helper_constants_agree_with_the_binding(self):
        self.assertEqual(REAL.name, "staging-next")
        self.assertEqual(str(BE.PRODUCTION_PROFILE.application_link), REAL.app_dir)
        self.assertEqual(BE.DATABASE, REAL.database)
        self.assertEqual(str(BE.PRODUCTION_PROFILE.state_dir / "deploy.lock"), REAL.lock_file)
        for path in (BE.PRODUCTION_PROFILE.releases_dir, BE.PRODUCTION_PROFILE.incoming_dir,
                     BE.PRODUCTION_PROFILE.inbox_dir, BE.PRODUCTION_PROFILE.state_dir,
                     BE.PRODUCTION_PROFILE.receipts_dir, BE.PRODUCTION_PROFILE.shared_env_file):
            self.assertEqual(BIND.check_path(REAL, str(path), "state", must_exist=False), str(path))

    def test_real_unmodified_profile_passes_the_identity_binding(self):
        profile = dataclasses.replace(BE.PRODUCTION_PROFILE, allowed_uid=1, php_fpm_uid=33, php_fpm_gid=33)
        with mock.patch.object(BIND, "check_path", side_effect=lambda *a, **k: a[1]):
            BE._bound_identity(profile)


class IdentityTests(Fixture):
    def test_fixture_profile_passes_safe_profile(self):
        BE._safe_profile(self.profile)

    def test_legacy_application_directory_is_refused(self):
        legacy = dataclasses.replace(self.profile, application_link=Path(LEGACY.app_dir))
        self.assertEqual(self.refusal(BE._safe_profile, legacy), "target_binding_target_pair_cross_environment")

    def test_state_directory_of_the_other_target_or_elsewhere_is_refused(self):
        for state, code in ((Path(LEGACY.state_root), "target_binding_path_belongs_to_other_target"),
                            (Path("/tmp/elsewhere"), "target_binding_path_outside_target")):
            self.assertEqual(self.refusal(BE._safe_profile, dataclasses.replace(self.profile, state_dir=state)), code)

    def test_symlinked_state_component_is_refused(self):
        real = self.root / "real-state"
        real.mkdir()
        link = self.state_root / "linked"
        link.symlink_to(real, target_is_directory=True)
        profile = dataclasses.replace(self.profile, releases_dir=link / "releases")
        self.assertEqual(self.refusal(BE._safe_profile, profile), "target_binding_path_symlink")

    def test_state_directory_with_wrong_owner_or_writable_mode_is_refused(self):
        self.state.chmod(0o770)
        self.assertEqual(self.refusal(BE._safe_profile, self.profile), "target_binding_path_permission_mismatch")
        self.state.chmod(0o700)
        wrong_owner = dataclasses.replace(self.profile, expected_owner_uid=self.uid + 1)
        self.assertEqual(self.refusal(BE._safe_profile, wrong_owner), "target_binding_path_permission_mismatch")


class LockTests(Fixture):
    def test_validated_lock_path_gives_real_cross_process_exclusion(self):
        lock = self.state / "deploy.lock"
        BE._bound_lock(self.profile, lock)
        fd = os.open(lock, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        self.addCleanup(os.close, fd)
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        code = ("import fcntl,os,sys\n"
                "fd=os.open(sys.argv[1],os.O_RDWR)\n"
                "try:\n    fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)\nexcept BlockingIOError:\n    sys.exit(7)\n")
        result = subprocess.run([sys.executable, "-I", "-c", code, str(lock)], check=False)
        self.assertEqual(result.returncode, 7)
        BE._bound_lock(self.profile, lock)  # path stays valid while held

    def test_lock_of_another_target_or_arbitrary_path_is_refused(self):
        for path, code in ((Path(LEGACY.lock_file), "target_binding_path_belongs_to_other_target"),
                           (Path(LEGACY.extra_locks[0]), "target_binding_path_belongs_to_other_target"),
                           (self.state / "other.lock", "target_binding_path_outside_target"),
                           (self.root / "x.lock", "target_binding_path_outside_target"),
                           (self.state / "deploy.lock" / "child", "target_binding_path_outside_target")):
            self.assertEqual(self.refusal(BE._bound_lock, self.profile, path), code, path)

    def test_symlinked_lock_file_is_refused(self):
        target = self.root / "elsewhere.lock"
        target.write_bytes(b"")
        (self.state / "deploy.lock").symlink_to(target)
        self.assertEqual(self.refusal(BE._bound_lock, self.profile, self.state / "deploy.lock"),
                         "target_binding_path_symlink")

    def test_binding_is_skipped_only_for_test_profiles(self):
        test_profile = dataclasses.replace(self.profile, test_only=True)
        BE._bound_lock(test_profile, Path("/tmp/anything.lock"))
        BE._bound_backup(test_profile, Path("/tmp/anything.sql.gz"))


class MarkerTests(Fixture):
    def test_regular_owned_markers_pass(self):
        worker, scheduler = self.marker("workers-disabled"), self.marker("scheduler-paused")
        BE._bound_markers(self.profile, self.policy(worker, scheduler))

    def test_marker_of_the_other_target_is_refused(self):
        scheduler = self.marker("scheduler-paused")
        self.assertEqual(self.refusal(BE._bound_markers, self.profile,
                                      self.policy(Path(LEGACY.worker_fence_marker), scheduler)),
                         "target_binding_path_belongs_to_other_target")

    def test_missing_symlinked_or_writable_markers_are_refused(self):
        scheduler = self.marker("scheduler-paused")
        self.assertEqual(self.refusal(BE._bound_markers, self.profile,
                                      self.policy(self.state / "workers-disabled", scheduler)),
                         "target_binding_path_missing")
        (self.state / "workers-disabled").symlink_to(scheduler)
        self.assertEqual(self.refusal(BE._bound_markers, self.profile,
                                      self.policy(self.state / "workers-disabled", scheduler)),
                         "target_binding_path_symlink")
        (self.state / "workers-disabled").unlink()
        worker = self.marker("workers-disabled", mode=0o662)
        self.assertEqual(self.refusal(BE._bound_markers, self.profile, self.policy(worker, scheduler)),
                         "target_binding_path_permission_mismatch")

    def test_fence_verification_is_gated_by_the_marker_binding(self):
        calls = []
        with mock.patch.object(BE, "verify_background_fences", side_effect=lambda *a, **k: calls.append(1) or "ok"):
            bad = self.policy(Path("/tmp/workers-disabled"), self.marker("scheduler-paused"))
            self.assertEqual(self.refusal(BE._verify_phase_b_fences, self.profile, bad),
                             "target_binding_path_outside_target")
            self.assertEqual(calls, [])
            good = self.policy(self.marker("workers-disabled"), self.state / "scheduler-paused")
            self.assertEqual(BE._verify_phase_b_fences(self.profile, good), "ok")
            self.assertEqual(calls, [1])


class BackupTests(Fixture):
    def backup(self, name=BACKUP_NAME, mode=0o600):
        path = self.backups / name
        path.write_bytes(b"-- dump")
        path.chmod(mode)
        return path

    def test_own_backup_passes(self):
        BE._bound_backup(self.profile, self.backup())

    def test_other_targets_backup_and_misnamed_backups_are_refused(self):
        legacy = Path(LEGACY.backup_root) / "wpcaregrid-pre-deploy-20261104T133855Z.sql.gz"
        self.assertEqual(self.refusal(BE._bound_backup, self.profile, legacy),
                         "target_binding_backup_belongs_to_other_target")
        for name in ("wpcaregrid-pre-deploy-20261104T133855Z.sql.gz", BACKUP_NAME + ".evil", "x.sql.gz"):
            self.assertEqual(self.refusal(BE._bound_backup, self.profile, self.backup(name)),
                             "target_binding_backup_identity_invalid", name)

    def test_symlink_hardlink_directory_and_loose_permissions_are_refused(self):
        real = self.backup()
        link = self.backups / f"staging-next-{'b' * 40}-1-abcdef01.sql.gz"
        link.symlink_to(real)
        self.assertEqual(self.refusal(BE._bound_backup, self.profile, link), "target_binding_path_symlink")
        hard = self.backups / f"staging-next-{'c' * 40}-1-abcdef01.sql.gz"
        os.link(real, hard)
        self.assertEqual(self.refusal(BE._bound_backup, self.profile, hard), "target_binding_backup_not_regular_file")
        directory = self.backups / f"staging-next-{'d' * 40}-1-abcdef01.sql.gz"
        directory.mkdir()
        self.assertEqual(self.refusal(BE._bound_backup, self.profile, directory),
                         "target_binding_backup_not_regular_file")
        loose = self.backup(f"staging-next-{'e' * 40}-1-abcdef01.sql.gz", mode=0o666)
        self.assertEqual(self.refusal(BE._bound_backup, self.profile, loose),
                         "target_binding_path_permission_mismatch")

    def test_backup_outside_the_target_backup_root_is_refused(self):
        outside = self.root / BACKUP_NAME
        outside.write_bytes(b"x")
        outside.chmod(0o600)
        self.assertEqual(self.refusal(BE._bound_backup, self.profile, outside), "target_binding_path_outside_target")


class JournalAndRecoveryTests(Fixture):
    def journal(self, state="MIGRATING", profile="staging-next", database="wpcg_staging_next"):
        return {"profile": profile, "database": database, "state": state}

    def test_journal_of_another_target_or_database_is_refused(self):
        for journal in (self.journal(database=LEGACY.database), self.journal(profile="legacy-staging"),
                        self.journal(profile="production", database="wpcg_prod_main")):
            self.assertEqual(self.refusal(BE._bound_journal_identity, journal),
                             "target_binding_journal_belongs_to_other_target")
        for journal in ({}, {"profile": "staging-next"}, None):
            self.assertEqual(self.refusal(BE._bound_journal_identity, journal),
                             "target_binding_journal_identity_missing")

    def test_every_interrupted_state_resumes_deterministically_and_recovery_required_stops(self):
        resumable = [s for s in BIND.TRANSACTION_STATES if s not in {"COMPLETE", "RECOVERY_REQUIRED"}]
        for state in resumable:
            for _ in range(3):
                BE._bound_resume(self.journal(state))
        self.assertEqual(self.refusal(BE._bound_resume, self.journal("RECOVERY_REQUIRED")),
                         "target_binding_manual_recovery_required")
        self.assertEqual(self.refusal(BE._bound_resume, self.journal("HALF_DONE")),
                         "target_binding_recovery_phase_unknown")
        self.assertEqual(self.refusal(BE._bound_resume, self.journal(database="wpcaregrid")),
                         "target_binding_journal_belongs_to_other_target")

    def test_binding_failures_never_leak_non_deploy_refusals(self):
        with mock.patch.object(BIND, "check_journal_identity", side_effect=RuntimeError("boom")):
            self.assertEqual(self.refusal(BE._bound_journal_identity, self.journal()),
                             "target_binding_check_failed")


class WiringTests(unittest.TestCase):
    """Guard against the bindings being removed from the real operation paths."""

    SOURCE = (ROOT / "scripts" / "staging_next_deploy_backend_1_4_9.py").read_text(encoding="utf-8")

    def count(self, name):
        return len(re.findall(rf"^\s+{name}\(", self.SOURCE, re.M))

    def test_each_binding_call_is_present_at_its_operation_sites(self):
        self.assertEqual(self.count("_bound_identity"), 1)
        self.assertEqual(self.count("_bound_lock"), 2)
        self.assertEqual(self.count("_bound_backup"), 3)
        self.assertEqual(self.count("_bound_markers"), 1)
        self.assertEqual(self.count("_bound_resume"), 1)
        self.assertEqual(self.count("_bound_journal_identity"), 2)  # receipt path + _bound_resume

    def test_production_and_arbitrary_targets_cannot_be_selected(self):
        self.assertEqual(len(re.findall(r"select_target\(", self.SOURCE)), 1)
        self.assertIn("select_target(PROFILE)", self.SOURCE)
        with self.assertRaises(BIND.TargetRefused):
            BIND.select_target("production")
        self.assertEqual(REAL.name, "staging-next")
        self.assertNotIn("legacy-staging", self.SOURCE)

    def test_helper_family_pins_the_new_modules_and_keeps_old_versions_unchanged(self):
        entry = (ROOT / "scripts" / "wpcaregrid-staging-next-deploy-priv-1_4_9.py").read_text(encoding="utf-8")
        for needle in ("target_binding_sha256", "environment_contract_sha256", "_PRELOADED_CONTRACT_SOURCE"):
            self.assertIn(needle, entry)
        for old in ("staging_next_deploy_backend_1_4_8.py", "staging_next_db_transaction_1_4_8.py"):
            self.assertNotIn("_bound_", (ROOT / "scripts" / old).read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
