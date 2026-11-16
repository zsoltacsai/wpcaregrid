#!/usr/bin/env python3
"""Focused path/bootstrap/Nginx regressions for staging-next helper 1.4.12."""
from __future__ import annotations

import importlib.util
import os
import stat
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]


def load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


CONTRACT = load("contract_1412_paths", ROOT / "scripts/deployment_environment_contract_1_4_12.py")
BINDING = load("binding_1412_paths", ROOT / "scripts/deployment_target_binding_1_4_12.py")
BACKEND = load("backend_1412_paths", ROOT / "scripts/staging_next_deploy_backend_1_4_12.py")


def fake_stat(mode: int, uid: int = 0, nlink: int = 1):
    return SimpleNamespace(st_mode=mode, st_uid=uid, st_nlink=nlink)


class StagingNextPathContractTests(unittest.TestCase):
    def setUp(self):
        self.target = BINDING.select_target("staging-next")

    def test_app_root_and_link_are_bound_separately_and_do_not_collide(self):
        self.assertEqual(CONTRACT.STAGING_NEXT.app_root, "/var/www/wpcaregrid-staging-new")
        self.assertEqual(CONTRACT.STAGING_NEXT.application_link,
                         "/var/www/wpcaregrid-staging-next-current")
        BINDING.validate_pair(self.target, CONTRACT.STAGING_NEXT.app_root,
                              CONTRACT.STAGING_NEXT.application_link, "wpcg_staging_next")
        for root, link, db in (
            ("/var/www/wpcaregrid-staging-new", "/var/www/wpcaregrid-staging-new", "wpcg_staging_next"),
            ("/var/www/wpcaregrid", "/var/www/wpcaregrid-staging-next-current", "wpcg_staging_next"),
            ("/var/www/wpcaregrid-staging-new", "/tmp/current", "wpcg_staging_next"),
            ("/var/www/wpcaregrid-staging-new", "/var/www/wpcaregrid-staging-next-current", "wpcaregrid"),
        ):
            with self.subTest(root=root, link=link, db=db), self.assertRaises(BINDING.TargetRefused):
                BINDING.validate_pair(self.target, root, link, db)

    def test_application_link_accepts_only_exact_baseline_or_versioned_release(self):
        path = self.target.application_link
        app_root = self.target.app_dir
        release = f"{self.target.state_root}/releases/{'a' * 40}"
        parent_modes = {
            "/": fake_stat(stat.S_IFDIR | 0o755),
            "/var": fake_stat(stat.S_IFDIR | 0o755),
            "/var/www": fake_stat(stat.S_IFDIR | 0o755),
        }

        def check(target_text, *, uid=0, mode=stat.S_IFLNK | 0o777, nlink=1, parent_override=None):
            def lstat(value):
                if value in (parent_override or {}):
                    return (parent_override or {})[value]
                if value == path:
                    return fake_stat(mode, uid, nlink)
                if value in parent_modes:
                    return parent_modes[value]
                raise FileNotFoundError(value)
            return BINDING.check_application_link(
                self.target, path, lstat_fn=lstat, readlink_fn=lambda _value: target_text)

        self.assertEqual(check(app_root), "bootstrap_baseline")
        self.assertEqual(check(release), "release")
        for bad in ("relative/current", "/var/www/wpcaregrid", release + "/../escape",
                    f"{self.target.state_root}/incoming/{'a' * 40}"):
            with self.subTest(target=bad), self.assertRaises(BINDING.TargetRefused):
                check(bad)
        with self.assertRaises(BINDING.TargetRefused):
            check(release, uid=1000)
        with self.assertRaises(BINDING.TargetRefused):
            check(release, nlink=2)
        with self.assertRaises(BINDING.TargetRefused):
            check(release, parent_override={"/var/www": fake_stat(stat.S_IFLNK | 0o777)})

    def test_installer_can_validate_missing_final_link_without_creating_it(self):
        path = self.target.application_link
        parent_modes = {
            "/": fake_stat(stat.S_IFDIR | 0o755),
            "/var": fake_stat(stat.S_IFDIR | 0o755),
            "/var/www": fake_stat(stat.S_IFDIR | 0o755),
        }

        def lstat(value):
            if value in parent_modes:
                return parent_modes[value]
            raise FileNotFoundError(value)

        self.assertIsNone(BINDING.check_application_link(
            self.target, path, lstat_fn=lstat, must_exist=False))

    def test_unreceipted_bootstrap_baseline_returns_actionable_hold(self):
        profile = BACKEND.PRODUCTION_PROFILE
        link_info = fake_stat(stat.S_IFLNK | 0o777, uid=BACKEND.APPLICATION_LINK_UID)
        with patch.object(BACKEND.os, "lstat", return_value=link_info), \
             patch.object(BACKEND.os, "readlink", return_value=str(profile.application_root)), \
             self.assertRaises(BACKEND.DeployRefused) as error:
            BACKEND._current_release(profile)
        self.assertEqual(str(error.exception), "bootstrap_baseline_requires_authenticated_release_receipt")

    def test_symlink_switch_preserves_app_root_and_refuses_directory_at_link(self):
        uid = os.getuid()
        with tempfile.TemporaryDirectory() as temporary:
            parent = Path(temporary)
            app_root = parent / "wpcaregrid-staging-new"
            app_root.mkdir(mode=0o755)
            sentinel = app_root / "preserve-me.txt"
            sentinel.write_text("existing app remains", encoding="utf-8")
            root_inode = app_root.stat().st_ino
            link = parent / "wpcaregrid-staging-next-current"
            first = parent / "releases" / ("a" * 40)
            second = parent / "releases" / ("b" * 40)
            first.parent.mkdir()
            first.mkdir()
            second.mkdir()
            with patch.object(BACKEND, "APPLICATION_LINK_UID", uid):
                BACKEND._atomic_symlink(first, link, expected_owner_uid=uid)
                self.assertTrue(link.is_symlink())
                self.assertEqual(link.resolve(), first)
                self.assertEqual(app_root.stat().st_ino, root_inode)
                self.assertEqual(sentinel.read_text(encoding="utf-8"), "existing app remains")
                BACKEND._atomic_symlink(second, link, expected_owner_uid=uid)
                self.assertEqual(link.resolve(), second)
                self.assertEqual(app_root.stat().st_ino, root_inode)

                link.unlink()
                link.mkdir()
                marker = link / "do-not-delete"
                marker.write_text("preserve", encoding="utf-8")
                with self.assertRaises(BACKEND.DeployRefused) as error:
                    BACKEND._atomic_symlink(first, link, expected_owner_uid=uid)
                self.assertEqual(str(error.exception), "active_release_metadata_invalid")
                self.assertTrue(marker.is_file())
                self.assertEqual(app_root.stat().st_ino, root_inode)

    def test_nginx_requires_the_sibling_current_public_root_and_php_socket(self):
        header = "# configuration file /etc/nginx/nginx.conf:\n"
        root = "events {}\nhttp { include /etc/nginx/conf.d/*.conf; }\n"

        def dump(document_root, socket="/run/php/php8.5-fpm.sock"):
            vhost = (
                "server { listen 443 ssl; server_name staging-next.wpcaregrid.com;\n"
                f"root {document_root};\n"
                "location / { try_files $uri $uri/ /index.php?$query_string; }\n"
                f"location ~ \\.php$ {{ fastcgi_pass unix:{socket}; }}\n"
                "}\n"
            )
            return (header + root + "\n# configuration file /etc/nginx/conf.d/staging-next.conf:\n"
                    + vhost).encode()

        expected = f"{self.target.application_link}/public"
        BACKEND._verify_staging_next_nginx_route(dump(expected))
        with self.assertRaises(BACKEND.DeployRefused) as error:
            BACKEND._verify_staging_next_nginx_route(dump(f"{self.target.app_dir}/public"))
        self.assertEqual(str(error.exception), "nginx_staging_document_root_mismatch")
        with self.assertRaises(BACKEND.DeployRefused) as error:
            BACKEND._verify_staging_next_nginx_route(dump(expected, "/run/php/php8.4-fpm.sock"))
        self.assertEqual(str(error.exception), "nginx_staging_fpm_route_mismatch")


if __name__ == "__main__":
    unittest.main()
