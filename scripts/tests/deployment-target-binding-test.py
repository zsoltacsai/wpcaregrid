#!/usr/bin/env python3
"""Synthetic tests for the closed staging deployment target binding.

No test touches a real host: filesystem state is a fake ``lstat`` over an
in-memory table, plus one real temporary-directory symlink case.
"""

from __future__ import annotations

import importlib.util
import os
import stat
import sys
import tempfile
import unittest
from pathlib import Path, PurePosixPath

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("deployment_target_binding", ROOT / "scripts" / "deployment_target_binding.py")
B = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = B
SPEC.loader.exec_module(B)

LEGACY = B.select_target("legacy-staging")
NEXT = B.select_target("staging-next")
UID = 1001
SHA = "a" * 40


class FakeFs:
    """Maps every ancestor of the given regular files/dirs to a stat result."""

    def __init__(self, files=(), symlinks=(), uid=UID, mode=0o600):
        self.entries = {}
        for item in files:
            for parent in PurePosixPath(item).parents:
                self.entries.setdefault(str(parent), (stat.S_IFDIR | 0o755, uid))
            self.entries[item] = (stat.S_IFREG | mode, uid)
        for item in symlinks:
            self.entries[item] = (stat.S_IFLNK | 0o777, uid)

    def lstat(self, path):
        if path not in self.entries:
            raise FileNotFoundError(path)
        mode, uid = self.entries[path]
        return os.stat_result((mode, 0, 0, 1, uid, 0, 0, 0, 0, 0))


def legacy_backup(name="wpcaregrid-pre-deploy-20261104T133855Z.sql.gz"):
    return f"{LEGACY.backup_root}/{name}"


def next_backup(sha=SHA, name=None):
    return f"{NEXT.backup_root}/{name or f'staging-next-{sha}-1762600000000000000-abcdef01.sql.gz'}"


class TargetSelectionTests(unittest.TestCase):
    def test_new_staging_target_is_accepted(self):
        target = B.select_target("staging-next")
        self.assertEqual(target.app_dir, "/var/www/wpcaregrid-staging-new")
        self.assertEqual(target.database, "wpcg_staging_next")
        B.validate_pair(target, "/var/www/wpcaregrid-staging-new", "wpcg_staging_next")

    def test_legacy_target_keeps_its_exact_legacy_values(self):
        target = B.select_target("legacy-staging")
        self.assertEqual((target.app_dir, target.database, target.state_root),
                         ("/var/www/wpcaregrid", "wpcaregrid", "/var/lib/wpcaregrid"))
        self.assertEqual(target.maintenance_marker, "/var/lib/wpcaregrid/deploy-maintenance")
        self.assertEqual(target.worker_fence_marker, "/var/lib/wpcaregrid/workers-disabled")
        self.assertEqual(target.scheduler_fence_marker, "/var/lib/wpcaregrid/scheduler-paused")
        self.assertEqual(target.lock_file, "/home/deploy/private-backups/.deploy.lock")
        B.validate_pair(target, "/var/www/wpcaregrid", "wpcaregrid")

    def test_legacy_values_match_the_legacy_tools_source(self):
        recover = (ROOT / "scripts" / "recover-staging-deployment.py").read_text(encoding="utf-8")
        deploy = (ROOT / "scripts" / "deploy-staging.sh").read_text(encoding="utf-8")
        self.assertIn(f"APP = Path('{LEGACY.app_dir}')", recover)
        self.assertIn(f"GATE = Path('{LEGACY.maintenance_marker}')", recover)
        self.assertIn(f"WORKER_MARKER = Path('{LEGACY.worker_fence_marker}')", recover)
        self.assertIn(f"SCHEDULER_MARKER = Path('{LEGACY.scheduler_fence_marker}')", recover)
        self.assertIn(f"DEPLOY_LOCK = Path('{LEGACY.lock_file}')", recover)
        self.assertIn(f"readonly APP_DIR='{LEGACY.app_dir}'", deploy)
        self.assertIn(f"readonly BACKUP_DIR='{LEGACY.backup_root}'", deploy)

    def test_staging_next_is_derived_from_the_compiled_environment_contract(self):
        contract = B._load_contract().STAGING_NEXT
        self.assertEqual(NEXT.app_dir, contract.app_root)
        self.assertEqual(NEXT.database, contract.database)
        self.assertEqual(NEXT.marker_paths(), (contract.maintenance_marker, contract.worker_fence_marker,
                                               contract.scheduler_fence_marker))

    def test_unknown_missing_and_non_string_targets_fail_closed(self):
        for value in ("staging", "Staging-Next", "staging-next ", "../staging-next", "", None, 7, ["staging-next"]):
            with self.assertRaises(B.TargetRefused, msg=repr(value)):
                B.select_target(value)

    def test_production_can_never_be_selected_here(self):
        for value in ("production", "production-v2", "production-next"):
            with self.assertRaises(B.TargetRefused) as ctx:
                B.select_target(value)
            self.assertEqual(str(ctx.exception), "production_target_not_selectable")
        self.assertNotIn("production", B.TARGETS)

    def test_registry_is_immutable_and_targets_are_disjoint(self):
        with self.assertRaises(TypeError):
            B.TARGETS["extra"] = LEGACY
        with self.assertRaises(Exception):
            LEGACY.database = "other"
        for own, other in ((LEGACY, NEXT), (NEXT, LEGACY)):
            for left in own.owned_roots():
                for right in other.owned_roots():
                    a, b = PurePosixPath(left), PurePosixPath(right)
                    self.assertFalse(a == b or a in b.parents or b in a.parents, (left, right))
        self.assertNotEqual(LEGACY.database, NEXT.database)
        self.assertFalse(set(LEGACY.marker_paths()) & set(NEXT.marker_paths()))
        self.assertNotEqual(LEGACY.lock_file, NEXT.lock_file)


class PairTests(unittest.TestCase):
    def test_wrong_database_for_target_is_rejected(self):
        for target, app, database, reason in (
            (NEXT, NEXT.app_dir, LEGACY.database, "target_pair_cross_environment"),
            (LEGACY, LEGACY.app_dir, NEXT.database, "target_pair_cross_environment"),
            (NEXT, LEGACY.app_dir, NEXT.database, "target_pair_cross_environment"),
            (NEXT, NEXT.app_dir, "wpcg_prod_main", "target_pair_mismatch"),
            (NEXT, "/var/www/other", NEXT.database, "target_pair_mismatch"),
            (LEGACY, LEGACY.app_dir, "wpcaregrid_copy", "target_pair_mismatch"),
        ):
            with self.assertRaises(B.TargetRefused, msg=(app, database)) as ctx:
                B.validate_pair(target, app, database)
            self.assertEqual(str(ctx.exception), reason)

    def test_non_string_pair_values_are_rejected(self):
        with self.assertRaises(B.TargetRefused):
            B.validate_pair(NEXT, None, NEXT.database)
        with self.assertRaises(B.TargetRefused):
            B.validate_pair(NEXT, NEXT.app_dir, ["wpcg_staging_next"])


class PathTests(unittest.TestCase):
    def test_arbitrary_absolute_path_is_rejected(self):
        for value in ("/etc/passwd", "/tmp/evil", "/var/lib", "/var/www", "/var/www/wpcaregrid-staging-new-evil",
                      "/var/lib/wpcaregrid-evil/workers-disabled"):
            with self.assertRaises(B.TargetRefused, msg=value):
                B.check_path(NEXT, value, "app", must_exist=False)
            with self.assertRaises(B.TargetRefused, msg=value):
                B.check_path(LEGACY, value, "marker", must_exist=False)

    def test_traversal_relative_and_unnormalised_paths_are_rejected(self):
        for value in (f"{NEXT.app_dir}/../wpcaregrid", f"{NEXT.app_dir}/./x", f"{NEXT.app_dir}//x",
                      f"{NEXT.app_dir}/", "relative/path", "", f"{NEXT.app_dir}\x00/x", None, 5):
            with self.assertRaises(B.TargetRefused, msg=repr(value)) as ctx:
                B.check_path(NEXT, value, "app", must_exist=False)
            self.assertEqual(str(ctx.exception), "path_invalid")

    def test_other_target_marker_is_rejected(self):
        with self.assertRaises(B.TargetRefused) as ctx:
            B.check_path(NEXT, LEGACY.worker_fence_marker, "marker", must_exist=False)
        self.assertEqual(str(ctx.exception), "path_belongs_to_other_target")
        with self.assertRaises(B.TargetRefused) as ctx:
            B.check_path(LEGACY, NEXT.scheduler_fence_marker, "marker", must_exist=False)
        self.assertEqual(str(ctx.exception), "path_belongs_to_other_target")
        with self.assertRaises(B.TargetRefused):
            B.check_path(NEXT, LEGACY.lock_file, "lock", must_exist=False)

    def test_own_markers_and_lock_are_accepted(self):
        for target in (LEGACY, NEXT):
            for marker in target.marker_paths():
                self.assertEqual(B.check_path(target, marker, "marker", must_exist=False), marker)
            self.assertEqual(B.check_path(target, target.lock_file, "lock", must_exist=False), target.lock_file)

    def test_marker_kind_does_not_widen_to_the_whole_state_root(self):
        with self.assertRaises(B.TargetRefused):
            B.check_path(NEXT, f"{NEXT.state_root}/state/other-file", "marker", must_exist=False)

    def test_symlink_component_is_rejected(self):
        marker = NEXT.worker_fence_marker
        fs = FakeFs(files=[marker], symlinks=[str(PurePosixPath(marker).parent)])
        with self.assertRaises(B.TargetRefused) as ctx:
            B.check_path(NEXT, marker, "marker", lstat_fn=fs.lstat)
        self.assertEqual(str(ctx.exception), "path_symlink")
        fs = FakeFs(symlinks=[NEXT.app_dir])
        fs.entries["/var"] = (stat.S_IFDIR | 0o755, 0)
        fs.entries["/var/www"] = (stat.S_IFDIR | 0o755, 0)
        with self.assertRaises(B.TargetRefused) as ctx:
            B.check_path(NEXT, NEXT.app_dir, "app", lstat_fn=fs.lstat)
        self.assertEqual(str(ctx.exception), "path_symlink")

    def test_real_symlink_on_disk_is_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            real = Path(tmp, "real")
            real.mkdir()
            link = Path(tmp, "link")
            try:
                link.symlink_to(real, target_is_directory=True)
            except (OSError, NotImplementedError):
                self.skipTest("symlinks unavailable on this host")
            target = B.DeploymentTarget(
                name="fixture", app_dir=str(PurePosixPath(link.as_posix())), database="x", state_root="/x",
                backup_root="/b", backup_name_pattern=".*", lock_file="/x/l", maintenance_marker="/x/m",
                worker_fence_marker="/x/w", scheduler_fence_marker="/x/s")
            if not PurePosixPath(link.as_posix()).is_absolute():
                self.skipTest("fixture path is not POSIX-absolute on this host")
            with self.assertRaises(B.TargetRefused) as ctx:
                B.check_path(target, target.app_dir, "app")
            self.assertEqual(str(ctx.exception), "path_symlink")

    def test_permission_mismatch_is_rejected(self):
        marker = NEXT.worker_fence_marker
        wrong_owner = FakeFs(files=[marker], uid=0)
        with self.assertRaises(B.TargetRefused) as ctx:
            B.check_path(NEXT, marker, "marker", lstat_fn=wrong_owner.lstat, expected_uid=UID)
        self.assertEqual(str(ctx.exception), "path_permission_mismatch")
        writable = FakeFs(files=[marker], mode=0o666)
        with self.assertRaises(B.TargetRefused) as ctx:
            B.check_path(NEXT, marker, "marker", lstat_fn=writable.lstat, expected_uid=UID)
        self.assertEqual(str(ctx.exception), "path_permission_mismatch")
        good = FakeFs(files=[marker])
        self.assertEqual(B.check_path(NEXT, marker, "marker", lstat_fn=good.lstat, expected_uid=UID), marker)

    def test_missing_path_and_unreadable_path_are_stops(self):
        with self.assertRaises(B.TargetRefused) as ctx:
            B.check_path(NEXT, NEXT.worker_fence_marker, "marker", lstat_fn=FakeFs().lstat)
        self.assertEqual(str(ctx.exception), "path_missing")

        def boom(_):
            raise PermissionError

        with self.assertRaises(B.TargetRefused) as ctx:
            B.check_path(NEXT, NEXT.worker_fence_marker, "marker", lstat_fn=boom)
        self.assertEqual(str(ctx.exception), "path_unavailable")

    def test_unknown_kind_is_rejected(self):
        with self.assertRaises(B.TargetRefused):
            B.check_path(NEXT, NEXT.app_dir, "anything", must_exist=False)


class BackupTests(unittest.TestCase):
    def test_each_target_accepts_only_its_own_backup(self):
        for target, path in ((NEXT, next_backup()), (LEGACY, legacy_backup()),
                             (LEGACY, legacy_backup(f"wpcaregrid-pre-recovery-{SHA}-0123456789abcdef.sql.gz"))):
            fs = FakeFs(files=[path])
            self.assertEqual(B.check_backup(target, path, lstat_fn=fs.lstat, expected_uid=UID), path)

    def test_backup_of_another_target_is_rejected(self):
        for target, path in ((NEXT, legacy_backup()), (LEGACY, next_backup())):
            with self.assertRaises(B.TargetRefused) as ctx:
                B.check_backup(target, path, lstat_fn=FakeFs(files=[path]).lstat)
            self.assertEqual(str(ctx.exception), "backup_belongs_to_other_target")

    def test_misnamed_or_misplaced_backup_is_rejected(self):
        for target, path in (
            (NEXT, next_backup(name="wpcaregrid-pre-deploy-20261104T133855Z.sql.gz")),
            (NEXT, next_backup(name="../x.sql.gz")),
            (NEXT, f"{NEXT.backup_root}/sub/{next_backup().rsplit('/', 1)[1]}"),
            (LEGACY, legacy_backup("anything.sql.gz")),
            (NEXT, "/tmp/staging-next-" + SHA + "-1-abcdef01.sql.gz"),
        ):
            with self.assertRaises(B.TargetRefused, msg=path):
                B.check_backup(target, path, lstat_fn=FakeFs(files=[path]).lstat)

    def test_symlinked_backup_is_rejected(self):
        path = next_backup()
        fs = FakeFs(symlinks=[path])
        fs.entries[str(PurePosixPath(path).parent)] = (stat.S_IFDIR | 0o755, UID)
        for parent in PurePosixPath(path).parent.parents:
            fs.entries[str(parent)] = (stat.S_IFDIR | 0o755, UID)
        with self.assertRaises(B.TargetRefused) as ctx:
            B.check_backup(NEXT, path, lstat_fn=fs.lstat)
        self.assertEqual(str(ctx.exception), "path_symlink")


class MarkerAndFenceTests(unittest.TestCase):
    def test_marker_document_must_name_this_target(self):
        B.check_marker_document(NEXT, {"target": "staging-next"})
        for doc in ({"target": "legacy-staging"}, {"target": "production"}, {"target": None}):
            with self.assertRaises(B.TargetRefused) as ctx:
                B.check_marker_document(NEXT, doc)
            self.assertEqual(str(ctx.exception), "marker_belongs_to_other_target")
        for doc in ({}, None, "staging-next", []):
            with self.assertRaises(B.TargetRefused) as ctx:
                B.check_marker_document(NEXT, doc)
            self.assertEqual(str(ctx.exception), "marker_target_missing")

    def test_fences_are_per_target(self):
        B.check_fences(NEXT, lambda path: path in NEXT.marker_paths())
        # The other target's active fences never satisfy this target.
        with self.assertRaises(B.TargetRefused) as ctx:
            B.check_fences(NEXT, lambda path: path in LEGACY.marker_paths())
        self.assertEqual(str(ctx.exception), "worker_fence_inactive")
        with self.assertRaises(B.TargetRefused) as ctx:
            B.check_fences(NEXT, lambda path: path == NEXT.worker_fence_marker)
        self.assertEqual(str(ctx.exception), "scheduler_fence_inactive")
        with self.assertRaises(B.TargetRefused):
            B.check_fences(LEGACY, lambda path: path in NEXT.marker_paths())


class RecoveryTests(unittest.TestCase):
    def decide(self, target=NEXT, journal="staging-next", phase="migrating", m=True, w=True, s=True):
        return B.recovery_decision(target, journal, phase, maintenance_active=m, workers_fenced=w,
                                   scheduler_fenced=s)

    def test_interrupted_deploy_with_gate_and_fences_is_recoverable(self):
        for phase in ("gate_closed", "backup_verified", "released", "migrating", "migrated", "verifying", "failed"):
            self.assertEqual(self.decide(phase=phase), "recover")
            self.assertEqual(self.decide(LEGACY, "legacy-staging", phase), "recover")

    def test_partial_deploy_without_full_gate_is_stop(self):
        for flags in ((False, True, True), (True, False, True), (True, True, False), (False, False, False)):
            with self.assertRaises(B.TargetRefused) as ctx:
                self.decide(m=flags[0], w=flags[1], s=flags[2])
            self.assertEqual(str(ctx.exception), "partial_deploy_gate_missing")

    def test_journal_of_another_target_is_stop(self):
        for journal in ("legacy-staging", "production", None):
            with self.assertRaises(B.TargetRefused) as ctx:
                self.decide(journal=journal)
            self.assertEqual(str(ctx.exception), "journal_belongs_to_other_target")

    def test_settled_target_needs_no_recovery_and_must_be_open(self):
        for phase in ("idle", "completed", "rolled_back"):
            self.assertEqual(self.decide(phase=phase, m=False, w=False, s=False), "none")
            with self.assertRaises(B.TargetRefused):
                self.decide(phase=phase, m=True, w=False, s=False)
            with self.assertRaises(B.TargetRefused):
                self.decide(phase=phase, m=False, w=True, s=False)

    def test_unknown_phase_is_stop(self):
        for phase in ("", "MIGRATING", None, 3, "restoring"):
            with self.assertRaises(B.TargetRefused):
                self.decide(phase=phase)

    def test_recovery_is_deterministic(self):
        results = {self.decide(phase="migrating") for _ in range(50)}
        self.assertEqual(results, {"recover"})


if __name__ == "__main__":
    unittest.main()
