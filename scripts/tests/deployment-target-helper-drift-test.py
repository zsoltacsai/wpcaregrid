#!/usr/bin/env python3
"""Static drift guard between deployment_target_binding and the pinned helpers.

Reads helper sources as text only (never imports or executes them), so it runs
anywhere. It fails if the staging-next helper family and the target binding
disagree about the isolated target, or if any staging-next helper references
the legacy staging application, database, state or backup locations.
"""

from __future__ import annotations

import importlib.util
import re
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("deployment_target_binding", ROOT / "scripts" / "deployment_target_binding.py")
B = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = B
SPEC.loader.exec_module(B)

NEXT = B.select_target("staging-next")
LEGACY = B.select_target("legacy-staging")
HELPER_GLOBS = ("staging_next_deploy_backend*.py", "staging_next_db_transaction*.py",
                "staging_next_deploy_controller.py", "wpcaregrid-staging-next-deploy-priv*.py")
LEGACY_PATH = re.compile(r"/var/www/wpcaregrid(?![-\w])|/var/lib/wpcaregrid(?![-\w])|/home/deploy/private-backups")


def helper_files():
    found = sorted({p for pattern in HELPER_GLOBS for p in (ROOT / "scripts").glob(pattern)})
    assert found, "no staging-next helper sources found"
    return found


class HelperDriftTests(unittest.TestCase):
    def test_helper_family_is_discovered(self):
        names = {p.name for p in helper_files()}
        self.assertIn("staging_next_deploy_backend_1_4_8.py", names)
        self.assertIn("staging_next_db_transaction_1_4_8.py", names)

    def test_staging_next_helpers_never_reference_legacy_locations(self):
        for path in helper_files():
            text = path.read_text(encoding="utf-8")
            self.assertIsNone(LEGACY_PATH.search(text), f"{path.name} references a legacy-staging path")
            self.assertNotRegex(text, r'["\']wpcaregrid["\']', f"{path.name} names the legacy database")

    def test_every_backend_binds_the_isolated_application_and_database(self):
        for path in sorted((ROOT / "scripts").glob("staging_next_deploy_backend*.py")):
            text = path.read_text(encoding="utf-8")
            self.assertIn(f'DATABASE = "{NEXT.database}"', text, path.name)
            self.assertIn(f'application_link=Path("{NEXT.app_dir}")', text, path.name)

    def test_latest_backend_directories_stay_inside_the_binding_state_root(self):
        text = (ROOT / "scripts" / "staging_next_deploy_backend_1_4_8.py").read_text(encoding="utf-8")
        declared = re.findall(r'(?:_dir|_file)=Path\("(/var/lib/wpcaregrid-deploy/[^"]+)"\)', text)
        self.assertGreaterEqual(len(declared), 8)
        for value in declared:
            self.assertEqual(B.check_path(NEXT, value, "state", must_exist=False), value)
            with self.assertRaises(B.TargetRefused):
                B.check_path(LEGACY, value, "state", must_exist=False)

    def test_backend_state_and_lock_match_the_binding(self):
        text = (ROOT / "scripts" / "staging_next_deploy_backend_1_4_8.py").read_text(encoding="utf-8")
        state = re.search(r'state_dir=Path\("([^"]+)"\)', text).group(1)
        self.assertEqual(f"{state}/deploy.lock", NEXT.lock_file)
        self.assertEqual(B.check_path(NEXT, NEXT.lock_file, "lock", must_exist=False), NEXT.lock_file)
        for marker in NEXT.marker_paths():
            self.assertTrue(marker.startswith(state + "/"), marker)

    def test_backend_backup_name_matches_binding_pattern(self):
        text = (ROOT / "scripts" / "staging_next_db_transaction_1_4_8.py").read_text(encoding="utf-8")
        self.assertIn('f"staging-next-{application_sha}-{time.time_ns()}-{secrets.token_hex(4)}.sql.gz"', text)
        sample = f"staging-next-{'b' * 40}-1762600000000000000-0123abcd.sql.gz"
        self.assertRegex(sample, NEXT.backup_name_pattern)
        self.assertNotRegex(f"wpcaregrid-pre-deploy-20261104T133855Z.sql.gz", NEXT.backup_name_pattern)

    def test_backup_directory_is_inside_binding_backup_root(self):
        text = (ROOT / "scripts" / "staging_next_db_transaction_1_4_8.py").read_text(encoding="utf-8")
        self.assertIn("database-backups", text + (ROOT / "scripts" / "staging_next_deploy_backend_1_4_8.py").read_text(encoding="utf-8")
                      + (ROOT / "scripts" / "deployment_environment_contract.py").read_text(encoding="utf-8"))
        self.assertTrue(NEXT.backup_root.endswith("/staging-next/database-backups"))

    def test_legacy_tools_still_name_only_legacy_locations(self):
        for name in ("recover-staging-deployment.py", "deploy-staging.sh", "run-scheduler.sh"):
            text = (ROOT / "scripts" / name).read_text(encoding="utf-8")
            self.assertNotIn("wpcaregrid-staging-new", text, name)
            self.assertNotIn("wpcaregrid-deploy/staging-next", text, name)
            self.assertNotIn("wpcg_staging_next", text, name)


if __name__ == "__main__":
    unittest.main()
