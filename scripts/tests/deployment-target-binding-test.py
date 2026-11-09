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
import unittest.mock
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
            with unittest.mock.patch.object(B, "TARGETS", {"fixture": target}):
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


class HardeningTests(unittest.TestCase):
    def test_non_bool_recovery_flags_and_unhashable_phase_are_refused(self):
        for flags in ((1, True, True), (True, "no", True), (True, True, [0]), (None, True, True)):
            with self.assertRaises(B.TargetRefused) as ctx:
                B.recovery_decision(NEXT, "staging-next", "migrating", maintenance_active=flags[0],
                                    workers_fenced=flags[1], scheduler_fenced=flags[2])
            self.assertEqual(str(ctx.exception), "recovery_flags_invalid")
        for phase in (["x"], {"a": 1}, b"migrating"):
            with self.assertRaises(B.TargetRefused):
                B.recovery_decision(NEXT, "staging-next", phase, maintenance_active=True,
                                    workers_fenced=True, scheduler_fenced=True)

    def test_marker_and_lock_paths_are_exact_not_prefixes(self):
        for kind, base in (("marker", LEGACY.maintenance_marker), ("lock", LEGACY.lock_file)):
            for suffix in ("/x", "/x/y"):
                with self.assertRaises(B.TargetRefused, msg=base + suffix):
                    B.check_path(LEGACY, base + suffix, kind, must_exist=False)

    def test_legacy_scheduler_lock_is_modelled(self):
        lock = "/var/lib/wpcaregrid/scheduler.lock"
        self.assertEqual(B.check_path(LEGACY, lock, "lock", must_exist=False), lock)
        with self.assertRaises(B.TargetRefused):
            B.check_path(NEXT, lock, "lock", must_exist=False)

    def test_forged_target_instances_are_refused_everywhere(self):
        forged = B.DeploymentTarget(**{**NEXT.__dict__, "app_dir": LEGACY.app_dir})
        calls = (
            lambda: B.check_path(forged, forged.app_dir, "app", must_exist=False),
            lambda: B.validate_pair(forged, forged.app_dir, forged.database),
            lambda: B.check_fences(forged, lambda _p: True),
            lambda: B.check_marker_document(forged, {"target": "staging-next"}),
            lambda: B.check_backup(forged, next_backup()),
            lambda: B.recovery_decision(forged, "staging-next", "idle", maintenance_active=False,
                                        workers_fenced=False, scheduler_fenced=False),
        )
        for call in calls:
            with self.assertRaises(B.TargetRefused) as ctx:
                call()
            self.assertEqual(str(ctx.exception), "target_not_registered")

    def test_backup_must_be_a_single_link_regular_file(self):
        path = next_backup()
        for mode in (stat.S_IFDIR | 0o755, stat.S_IFIFO | 0o600):
            fs = FakeFs(files=[path])
            fs.entries[path] = (mode, UID)
            with self.assertRaises(B.TargetRefused) as ctx:
                B.check_backup(NEXT, path, lstat_fn=fs.lstat)
            self.assertEqual(str(ctx.exception), "backup_not_regular_file")
        base = FakeFs(files=[path])

        def hardlinked(p):
            r = base.lstat(p)
            return os.stat_result((r.st_mode, 0, 0, 2, r.st_uid, 0, 0, 0, 0, 0)) if p == path else r

        with self.assertRaises(B.TargetRefused):
            B.check_backup(NEXT, path, lstat_fn=hardlinked)


class AppSecRoundTwoTests(unittest.TestCase):
    def test_unhashable_or_non_string_target_name_is_target_refused(self):
        import dataclasses
        for name in ([], {}, 5, None):
            forged = dataclasses.replace(LEGACY, name=name)
            for call in (lambda: B.check_path(forged, forged.app_dir, "app", must_exist=False),
                         lambda: B.validate_pair(forged, forged.app_dir, forged.database)):
                with self.assertRaises(B.TargetRefused) as ctx:
                    call()
                self.assertEqual(str(ctx.exception), "target_not_registered")

    def test_hostile_marker_document_and_fence_probe_are_target_refused(self):
        class Boom(dict):
            def __getitem__(self, key):
                raise KeyError(key)

        import collections.abc
        with self.assertRaises(B.TargetRefused):
            B.check_marker_document(NEXT, Boom({"target": "staging-next"}))

        def explode(_path):
            raise RuntimeError("probe")

        with self.assertRaises(B.TargetRefused) as ctx:
            B.check_fences(NEXT, explode)
        self.assertEqual(str(ctx.exception), "fence_check_unavailable")
        with self.assertRaises(B.TargetRefused):
            B.check_fences(NEXT, lambda _p: "yes")
        with self.assertRaises(B.TargetRefused):
            B.check_fences(NEXT, lambda _p: 1)

    def test_preseeded_sys_modules_cannot_poison_the_contract(self):
        import types
        name = "_wpcg_target_binding_environment_contract"
        fake = types.ModuleType(name)
        fake.STAGING_NEXT = types.SimpleNamespace(app_root=LEGACY.app_dir, database=LEGACY.database)
        sys.modules[name] = fake
        try:
            fresh = B._load_contract()
            self.assertIsNot(fresh, fake)
            self.assertEqual(fresh.STAGING_NEXT.app_root, NEXT.app_dir)
            self.assertIs(sys.modules[name], fake)
        finally:
            sys.modules.pop(name, None)

    def test_contract_load_failures_are_target_refused(self):
        with unittest.mock.patch.object(B.importlib.util, "spec_from_file_location", side_effect=OSError):
            with self.assertRaises(B.TargetRefused) as ctx:
                B._load_contract()
        self.assertEqual(str(ctx.exception), "environment_contract_unavailable")

    def test_registry_validation_rejects_overlap_and_malformed_targets(self):
        import dataclasses
        overlap = dataclasses.replace(NEXT, name="x", database="other",
                                      app_dir=LEGACY.app_dir + "/sub",
                                      maintenance_marker=NEXT.maintenance_marker + "2",
                                      worker_fence_marker=NEXT.worker_fence_marker + "2",
                                      scheduler_fence_marker=NEXT.scheduler_fence_marker + "2")
        with self.assertRaises(B.TargetRefused):
            B._validate_registry((LEGACY, overlap))
        same_db = dataclasses.replace(NEXT, name="y")
        with self.assertRaises(B.TargetRefused):
            B._validate_registry((NEXT, same_db))
        relative = dataclasses.replace(NEXT, lock_file="state/deploy.lock")
        with self.assertRaises(B.TargetRefused):
            B._validate_registry((LEGACY, relative))
        B._validate_registry((LEGACY, NEXT))

    def test_staging_next_state_root_is_derived_from_the_contract(self):
        contract = B._load_contract().STAGING_NEXT
        self.assertEqual(NEXT.state_root, str(PurePosixPath(contract.state_root).parent))
        self.assertEqual(NEXT.lock_file, contract.state_root + "/deploy.lock")

    def test_backup_name_suffix_and_group_writable_mode_mutation_gaps(self):
        junk = next_backup(name=f"staging-next-{SHA}-1-abcdef01.sql.gz.evil")
        with self.assertRaises(B.TargetRefused):
            B.check_backup(NEXT, junk, lstat_fn=FakeFs(files=[junk]).lstat)
        junk2 = next_backup(name=f"staging-next-{SHA}-1-abcdef0123.sql.gz")
        with self.assertRaises(B.TargetRefused):
            B.check_backup(NEXT, junk2, lstat_fn=FakeFs(files=[junk2]).lstat)
        marker = NEXT.worker_fence_marker
        group_writable = FakeFs(files=[marker], mode=0o660)
        with self.assertRaises(B.TargetRefused) as ctx:
            B.check_path(NEXT, marker, "marker", lstat_fn=group_writable.lstat, expected_uid=UID)
        self.assertEqual(str(ctx.exception), "path_permission_mismatch")
        other_writable = FakeFs(files=[marker], mode=0o602)
        with self.assertRaises(B.TargetRefused):
            B.check_path(NEXT, marker, "marker", lstat_fn=other_writable.lstat, expected_uid=UID)

    def test_registry_validation_remaining_branches(self):
        import dataclasses
        other = dict(name="z", database="z_db", app_dir="/var/www/z", state_root="/var/lib/z",
                     backup_root="/srv/z-backups", lock_file="/var/lib/z/l",
                     maintenance_marker="/var/lib/z/m", worker_fence_marker="/var/lib/z/w",
                     scheduler_fence_marker="/var/lib/z/s")
        base = dataclasses.replace(LEGACY, **other)
        B._validate_registry((NEXT, base))
        for change in ({"database": NEXT.database},
                       {"maintenance_marker": "/elsewhere/m"},
                       {"extra_locks": ("relative.lock",)},
                       {"app_dir": "/var/www/z/../y"},
                       {"app_dir": "/var/www/z/"},
                       {"worker_fence_marker": NEXT.worker_fence_marker}):
            with self.assertRaises(B.TargetRefused, msg=change):
                B._validate_registry((NEXT, dataclasses.replace(base, **change)))

    def test_contract_load_leaves_sys_modules_clean_and_checks_origin(self):
        name = "_wpcg_target_binding_environment_contract"
        sys.modules.pop(name, None)
        B._load_contract()
        self.assertNotIn(name, sys.modules)
        fake_file = types_module_with_file("/tmp/elsewhere.py")
        with unittest.mock.patch.object(B.importlib.util, "module_from_spec", return_value=fake_file),                 unittest.mock.patch.object(B.importlib.util, "spec_from_file_location") as spec:
            spec.return_value.loader.exec_module = lambda module: None
            with self.assertRaises(B.TargetRefused):
                B._load_contract()
        self.assertNotIn(name, sys.modules)

    def test_registered_requires_a_deployment_target_instance(self):
        for value in (None, "staging-next", {"name": "staging-next"}, object()):
            with self.assertRaises(B.TargetRefused):
                B._registered(value)


def types_module_with_file(path):
    import types
    module = types.ModuleType("fake")
    module.__file__ = path
    return module


class JournalAndTransactionStateTests(unittest.TestCase):
    def test_journal_identity_must_match_target_profile_and_database(self):
        B.check_journal_identity(NEXT, {"profile": "staging-next", "database": "wpcg_staging_next"})
        for journal in ({"profile": "staging-next", "database": "wpcaregrid"},
                        {"profile": "legacy-staging", "database": "wpcg_staging_next"},
                        {"profile": "production", "database": "wpcg_prod_main"},
                        {"profile": ["staging-next"], "database": "wpcg_staging_next"}):
            with self.assertRaises(B.TargetRefused) as ctx:
                B.check_journal_identity(NEXT, journal)
            self.assertEqual(str(ctx.exception), "journal_belongs_to_other_target")
        for journal in ({}, {"profile": "staging-next"}, None, "x", []):
            with self.assertRaises(B.TargetRefused) as ctx:
                B.check_journal_identity(NEXT, journal)
            self.assertEqual(str(ctx.exception), "journal_identity_missing")

        class Hostile(dict):
            def __getitem__(self, key):
                raise RuntimeError

        with self.assertRaises(B.TargetRefused):
            B.check_journal_identity(NEXT, Hostile(profile="staging-next", database="x"))

    def test_real_states_resume_only_with_verified_fences_when_past_the_fence_step(self):
        for state in B.TRANSACTION_STATES[:4]:
            self.assertEqual(B.transaction_state_decision(NEXT, state, fences_verified=False), "resume")
        for state in B.TRANSACTION_STATES[4:9]:
            self.assertEqual(B.transaction_state_decision(NEXT, state, fences_verified=True), "resume")
            with self.assertRaises(B.TargetRefused) as ctx:
                B.transaction_state_decision(NEXT, state, fences_verified=False)
            self.assertEqual(str(ctx.exception), "partial_deploy_gate_missing")
        self.assertEqual(B.transaction_state_decision(NEXT, "COMPLETE", fences_verified=False), "none")

    def test_recovery_required_unknown_states_and_non_bool_flags_are_stops(self):
        with self.assertRaises(B.TargetRefused) as ctx:
            B.transaction_state_decision(NEXT, "RECOVERY_REQUIRED", fences_verified=True)
        self.assertEqual(str(ctx.exception), "manual_recovery_required")
        for state in ("migrating", "", None, 5, ["COMPLETE"], "COMPLETE "):
            with self.assertRaises(B.TargetRefused, msg=repr(state)):
                B.transaction_state_decision(NEXT, state, fences_verified=True)
        for flag in (1, "yes", None):
            with self.assertRaises(B.TargetRefused):
                B.transaction_state_decision(NEXT, "MIGRATING", fences_verified=flag)

        class SneakyState(str):
            def __eq__(self, other):
                return True

            __hash__ = str.__hash__

        with self.assertRaises(B.TargetRefused):
            B.transaction_state_decision(NEXT, SneakyState("anything"), fences_verified=True)

    def test_state_list_matches_the_real_transaction_module(self):
        text = (ROOT / "scripts" / "staging_next_db_transaction_1_4_8.py").read_text(encoding="utf-8")
        block = text[text.index("STATES = ("):]
        block = block[:block.index(")")]
        import re as _re
        self.assertEqual(tuple(_re.findall(r'"([A-Z_]+)"', block)), B.TRANSACTION_STATES)

    def test_injected_lstat_failures_are_target_refused(self):
        marker = NEXT.worker_fence_marker
        for fn in (lambda p: 1 / 0, lambda p: object(), lambda p: types_namespace()):
            with self.assertRaises(B.TargetRefused) as ctx:
                B.check_path(NEXT, marker, "marker", lstat_fn=fn, expected_uid=0)
            self.assertEqual(str(ctx.exception), "path_unavailable")
        backup = next_backup()
        fs = FakeFs(files=[backup])
        broken = lambda p: object() if p == backup else fs.lstat(p)
        with self.assertRaises(B.TargetRefused):
            B.check_backup(NEXT, backup, lstat_fn=broken)

    def test_marker_document_target_must_be_a_real_string(self):
        class Sneaky(str):
            def __ne__(self, other):
                return False

            def __eq__(self, other):
                return True

            __hash__ = str.__hash__

        with self.assertRaises(B.TargetRefused):
            B.check_marker_document(NEXT, {"target": Sneaky("anything")})


def types_namespace():
    import types
    return types.SimpleNamespace(st_mode=stat.S_IFREG | 0o600)


class PreloadedContractTests(unittest.TestCase):
    def run_binding(self, preloaded):
        import types as _types
        source_path = ROOT / "scripts" / "deployment_target_binding.py"
        module = _types.ModuleType("_binding_under_test")
        module.__file__ = str(source_path)
        module.__dict__["_PRELOADED_CONTRACT_SOURCE"] = preloaded
        sys.modules[module.__name__] = module
        try:
            exec(compile(source_path.read_bytes(), str(source_path), "exec", dont_inherit=True), module.__dict__)
        finally:
            sys.modules.pop(module.__name__, None)
        return module

    def test_authenticated_contract_bytes_are_used_instead_of_the_disk_file(self):
        real = (ROOT / "scripts" / "deployment_environment_contract.py").read_bytes()
        self.assertEqual(self.run_binding(real).STAGING_NEXT.database, NEXT.database)
        altered = real.replace(b'database="wpcg_staging_next"', b'database="wpcg_from_preload"', 1)
        self.assertNotEqual(altered, real)
        self.assertEqual(self.run_binding(altered).STAGING_NEXT.database, "wpcg_from_preload")

    def test_invalid_preloaded_contract_is_target_refused_and_leaves_no_module_behind(self):
        for bad in ("text", 5, b"raise SystemExit(3)", b"syntax error here", b"X = 1"):
            with self.assertRaises(Exception, msg=repr(bad)) as ctx:
                self.run_binding(bad)
            self.assertEqual(type(ctx.exception).__name__, "TargetRefused", repr(bad))
            self.assertEqual(str(ctx.exception), "environment_contract_unavailable")
        self.assertNotIn("_wpcg_target_binding_environment_contract", sys.modules)


if __name__ == "__main__":
    unittest.main()
