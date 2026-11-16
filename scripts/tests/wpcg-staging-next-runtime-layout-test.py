from __future__ import annotations

import importlib.util
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock


SCRIPT = Path(__file__).resolve().parents[1] / "wpcg_staging_next_runtime_layout.py"
SPEC = importlib.util.spec_from_file_location("wpcg_runtime_layout", SCRIPT)
LAYOUT = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = LAYOUT
SPEC.loader.exec_module(LAYOUT)


class RuntimeLayoutTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="wpcg-layout-")
        self.root = Path(self.temp.name)
        self.ids = LAYOUT._test_identities()
        for logical, mode in (("/var", 0o755), ("/var/www", 0o755), ("/var/lib", 0o755),
                              (LAYOUT.APP_ROOT, 0o755)):
            path = LAYOUT._physical(self.root, logical)
            path.mkdir(parents=True, exist_ok=True)
            path.chmod(mode)
        (LAYOUT._physical(self.root, LAYOUT.APP_ROOT) / "keep.me").write_text("preserve")

    def tearDown(self):
        self.temp.cleanup()

    def test_apply_creates_exact_empty_layout_and_repeat_is_idempotent(self):
        result = LAYOUT.provision(self.root, self.ids, apply=True)
        self.assertEqual(result["result"], "PASS")
        self.assertEqual((LAYOUT._physical(self.root, LAYOUT.APP_ROOT) / "keep.me").read_text(), "preserve")
        self.assertFalse(LAYOUT._physical(self.root, LAYOUT.ACTIVE_LINK).exists())
        for spec in LAYOUT._specs(self.ids):
            info = os.lstat(LAYOUT._physical(self.root, spec.path))
            self.assertEqual((info.st_uid, info.st_gid, info.st_mode & 0o7777),
                             (spec.uid, spec.gid, spec.mode))
        again = LAYOUT.provision(self.root, self.ids, apply=True)
        self.assertEqual(again["result"], "PASS")
        self.assertTrue(all(item["state"] == "existing_exact" for item in again["paths"]))
        self.assertIn("does not preserve, migrate", result["scope_note"])

    def test_check_mode_is_read_only_and_reports_hold_for_missing_paths(self):
        before = set(self.root.rglob("*"))
        result = LAYOUT.provision(self.root, self.ids, apply=False)
        self.assertEqual(result["result"], "HOLD")
        self.assertEqual(set(self.root.rglob("*")), before)

    def test_existing_active_link_path_stops_without_modifying_it(self):
        link = LAYOUT._physical(self.root, LAYOUT.ACTIVE_LINK)
        link.symlink_to("/unused")
        with self.assertRaisesRegex(LAYOUT.LayoutError, "active_link_path_already_exists"):
            LAYOUT.provision(self.root, self.ids, apply=True)
        self.assertTrue(link.is_symlink())
        self.assertFalse(LAYOUT._physical(self.root, LAYOUT.DEPLOY_ROOT).exists())

    def test_wrong_parent_mode_stops(self):
        var_www = LAYOUT._physical(self.root, "/var/www")
        var_www.chmod(0o775)
        with self.assertRaisesRegex(LAYOUT.LayoutError, "directory_mode_mismatch"):
            LAYOUT.provision(self.root, self.ids, apply=True)
        self.assertFalse(LAYOUT._physical(self.root, LAYOUT.DEPLOY_ROOT).exists())

    def test_unexpected_existing_runtime_data_stops(self):
        staging = LAYOUT._physical(self.root, LAYOUT.STAGING_ROOT)
        staging.mkdir(parents=True)
        (staging / "operator-data").write_text("leave")
        with self.assertRaisesRegex(LAYOUT.LayoutError, "unexpected_staging_root_content"):
            LAYOUT.provision(self.root, self.ids, apply=True)
        self.assertEqual((staging / "operator-data").read_text(), "leave")

    def test_failure_rolls_back_only_empty_created_directories(self):
        original_mkdir = os.mkdir

        def fail_on_inbox(path, mode=0o777, *, dir_fd=None):
            if str(path).endswith("/staging-next/inbox"):
                raise OSError("synthetic failure")
            return original_mkdir(path, mode, dir_fd=dir_fd)

        with mock.patch.object(LAYOUT.os, "mkdir", side_effect=fail_on_inbox):
            with self.assertRaisesRegex(LAYOUT.LayoutError, "directory_creation_failed"):
                LAYOUT.provision(self.root, self.ids, apply=True)
        self.assertFalse(LAYOUT._physical(self.root, LAYOUT.DEPLOY_ROOT).exists())
        self.assertTrue((LAYOUT._physical(self.root, LAYOUT.APP_ROOT) / "keep.me").exists())


if __name__ == "__main__":
    unittest.main()
