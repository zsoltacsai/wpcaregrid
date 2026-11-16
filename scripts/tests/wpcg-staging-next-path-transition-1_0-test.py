from __future__ import annotations

import importlib.util
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock


SCRIPT = Path(__file__).resolve().parents[1] / "wpcg-staging-next-path-transition-1_0.py"
SPEC = importlib.util.spec_from_file_location("wpcg_path_transition", SCRIPT)
TRANSITION = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = TRANSITION
SPEC.loader.exec_module(TRANSITION)


class PathTransitionTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="wpcg-path-transition-")
        self.root = Path(self.temp.name)
        self.uid, self.gid = os.getuid(), os.getgid()
        for name, mode in (("etc", 0o755), ("etc/nginx", 0o755),
                           ("etc/nginx/sites-available", 0o755),
                           ("var", 0o755), ("var/www", 0o755),
                           ("var/lib/wpcaregrid-deploy/staging-next/state", 0o700)):
            path = self.root / name
            path.mkdir(parents=True, exist_ok=True)
            path.chmod(mode)
        app = self.root / TRANSITION.APP_ROOT.relative_to("/")
        (app / "public").mkdir(parents=True)
        (app / "keep.me").write_text("preserve")
        self.config_logical = "/etc/nginx/sites-available/staging-next.conf"
        self.config = self.root / Path(self.config_logical).relative_to("/")
        self.original = (
            "server {\n"
            "    listen 443 ssl;\n"
            "    server_name staging-next.wpcaregrid.com;\n"
            "    root /var/www/wpcaregrid-staging-new/public;\n"
            "    location ~ \\.php$ {\n"
            "        fastcgi_pass unix:/run/php/php8.5-fpm.sock;\n"
            "    }\n"
            "}\n"
        ).encode()
        self.config.write_bytes(self.original)
        self.config.chmod(0o644)

    def tearDown(self):
        self.temp.cleanup()

    def run_transition(self, mode="check"):
        return TRANSITION.transition(mode=mode, root=self.root, nginx_file=self.config_logical,
                                     root_uid=self.uid, root_gid=self.gid,
                                     app_uid=self.uid, app_gid=self.gid,
                                     test_mode=True)

    def test_check_is_read_only_and_reports_exact_changes(self):
        before = self.config.read_bytes()
        receipt = self.run_transition("check")
        self.assertEqual(receipt["result"], "PASS")
        self.assertEqual(receipt["reason"], "ready_for_root_transition")
        self.assertEqual(self.config.read_bytes(), before)
        self.assertFalse((self.root / TRANSITION.ACTIVE_LINK.relative_to("/")).exists())
        self.assertEqual((self.root / TRANSITION.APP_ROOT.relative_to("/") / "keep.me").read_text(),
                         "preserve")

    def test_apply_creates_only_sibling_link_and_changes_vhost_root(self):
        receipt = self.run_transition("apply")
        self.assertEqual(receipt["result"], "PASS")
        self.assertEqual(receipt["reason"], "path_transition_complete")
        link = self.root / TRANSITION.ACTIVE_LINK.relative_to("/")
        self.assertTrue(link.is_symlink())
        self.assertEqual(os.readlink(link), str(TRANSITION.APP_ROOT))
        changed = self.config.read_bytes()
        self.assertIn(TRANSITION.NEW_WEB_ROOT.encode(), changed)
        self.assertNotIn(TRANSITION.OLD_WEB_ROOT.encode(), changed)
        backup = self.root / Path(receipt["configuration_backup"]).relative_to("/")
        self.assertTrue(backup.is_file())
        self.assertEqual(backup.read_bytes(), self.original)
        self.assertEqual((self.root / TRANSITION.APP_ROOT.relative_to("/") / "keep.me").read_text(),
                         "preserve")
        self.assertEqual(receipt["app_storage_migration"], "not_performed")

    def test_ambiguous_vhost_and_wrong_fpm_refuse_before_writes(self):
        duplicate = self.original + self.original
        (self.root / "nginx.dump").write_bytes(
            f"# configuration file {self.config_logical}:\n".encode() + duplicate)
        with self.assertRaisesRegex(TRANSITION.TransitionStop, "nginx_target_vhost_ambiguous"):
            TRANSITION._prepare_config((self.root / "nginx.dump").read_bytes(), self.config_logical,
                                       TRANSITION.OLD_WEB_ROOT, TRANSITION.NEW_WEB_ROOT)
        wrong = self.original.replace(b"php8.5-fpm.sock", b"php8.4-fpm.sock")
        with self.assertRaisesRegex(TRANSITION.TransitionStop, "nginx_php_fpm_route_mismatch"):
            TRANSITION._validate_vhost(wrong.decode(), self.config_logical, TRANSITION.OLD_WEB_ROOT)
        self.assertEqual(self.config.read_bytes(), self.original)

    def test_include_inside_target_vhost_fails_closed(self):
        source = self.original.replace(
            b"    root /var/www/wpcaregrid-staging-new/public;",
            b"    root /var/www/wpcaregrid-staging-new/public;\n    include /etc/nginx/snippets/common.conf;")
        with self.assertRaisesRegex(TRANSITION.TransitionStop, "nginx_target_vhost_include_unsupported"):
            TRANSITION._validate_vhost(source.decode(), self.config_logical, TRANSITION.OLD_WEB_ROOT)

    def test_nginx_test_failure_restores_config_and_removes_owned_link(self):
        calls = {"test": 0}
        config_source = self.config_logical

        def dump():
            return (f"# configuration file {config_source}:\n".encode()
                    + self.config.read_bytes().rstrip(b"\n") + b"\n")

        def test():
            calls["test"] += 1
            return calls["test"] != 2

        with mock.patch.object(TRANSITION, "_nginx_ops", return_value=(dump, test, lambda: True)):
            receipt = self.run_transition("apply")
        self.assertEqual(receipt["result"], "STOP_ROLLED_BACK")
        self.assertEqual(self.config.read_bytes(), self.original)
        self.assertFalse((self.root / TRANSITION.ACTIVE_LINK.relative_to("/")).exists())
        self.assertEqual(receipt["rollback"], "PASS")

    def test_empty_systemctl_stdout_is_success_when_exit_status_is_zero(self):
        with mock.patch.object(TRANSITION, "_run", return_value=b"") as run:
            _dump, nginx_test, reload = TRANSITION._nginx_ops(self.root, test_mode=False,
                                                              nginx_file=self.config_logical)
            self.assertTrue(nginx_test())
            self.assertTrue(reload())
        self.assertEqual(run.call_count, 2)

    def test_reload_failure_restores_config_and_removes_owned_link(self):
        reload_results = iter((False, True))
        with mock.patch.object(TRANSITION, "_nginx_ops", return_value=(
                lambda: f"# configuration file {self.config_logical}:\n".encode()
                        + self.config.read_bytes().rstrip(b"\n") + b"\n",
                lambda: True,
                lambda: next(reload_results))):
            receipt = self.run_transition("apply")
        self.assertEqual(receipt["result"], "STOP_ROLLED_BACK")
        self.assertEqual(receipt["reason"], "nginx_reload_failed")
        self.assertEqual(self.config.read_bytes(), self.original)
        self.assertFalse((self.root / TRANSITION.ACTIVE_LINK.relative_to("/")).exists())

    def test_nginx_command_timeout_fails_closed(self):
        with mock.patch.object(TRANSITION.subprocess, "run",
                               side_effect=subprocess.TimeoutExpired("/usr/sbin/nginx", 20)):
            with self.assertRaisesRegex(TRANSITION.TransitionStop, "system_command_failed"):
                TRANSITION._run(["/usr/sbin/nginx", "-t"], timeout=20)

    def test_existing_sibling_link_is_never_replaced(self):
        link = self.root / TRANSITION.ACTIVE_LINK.relative_to("/")
        link.symlink_to("/wrong")
        with self.assertRaisesRegex(TRANSITION.TransitionStop, "active_link_conflict"):
            self.run_transition("apply")
        self.assertEqual(os.readlink(link), "/wrong")
        self.assertEqual(self.config.read_bytes(), self.original)

    def test_receipt_bound_rollback_restores_vhost_and_removes_only_owned_link(self):
        applied = self.run_transition("apply")
        self.assertEqual(applied["result"], "PASS")
        reversed_receipt = TRANSITION.rollback(
            root=self.root, receipt_path=applied["complete_receipt_path"],
            root_uid=self.uid, root_gid=self.gid, test_mode=True)
        self.assertEqual(reversed_receipt["result"], "PASS")
        self.assertEqual(reversed_receipt["reason"], "path_transition_rolled_back")
        self.assertEqual(self.config.read_bytes(), self.original)
        self.assertFalse((self.root / TRANSITION.ACTIVE_LINK.relative_to("/")).exists())
        backup = self.root / Path(applied["configuration_backup"]).relative_to("/")
        self.assertEqual(backup.read_bytes(), self.original)

    def test_receipt_bound_rollback_holds_on_changed_link(self):
        applied = self.run_transition("apply")
        link = self.root / TRANSITION.ACTIVE_LINK.relative_to("/")
        link.unlink()
        link.symlink_to("/changed")
        changed_config = self.config.read_bytes()
        with self.assertRaisesRegex(TRANSITION.TransitionStop, "rollback_link_identity_mismatch"):
            TRANSITION.rollback(root=self.root, receipt_path=applied["complete_receipt_path"],
                                root_uid=self.uid, root_gid=self.gid, test_mode=True)
        self.assertEqual(self.config.read_bytes(), changed_config)
        self.assertEqual(os.readlink(link), "/changed")


if __name__ == "__main__":
    unittest.main()
