#!/usr/bin/env python3
"""Synthetic isolation tests for immutable deployment environment contracts."""

from __future__ import annotations

import importlib.util
import sys
import unittest
from dataclasses import FrozenInstanceError
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location(
    "deployment_environment_contract", ROOT / "scripts" / "deployment_environment_contract.py")
CONTRACT = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = CONTRACT
SPEC.loader.exec_module(CONTRACT)


def production_config(**changes):
    value = {
        "schema": 1,
        "profile": "production",
        "enabled": True,
        "app_root": "/var/www/wpcaregrid",
        "release_root": "/var/www/wpcaregrid/releases",
        "state_root": "/var/lib/wpcaregrid-deploy/production/state",
        "backup_root": "/var/lib/wpcaregrid-deploy/production/database-backups",
        "database": "wpcaregrid_prod",
        "database_host": "127.0.0.1",
        "database_port": 3306,
        "database_server_uuid_sha256": "a" * 64,
        "isolated_database": "wpcaregrid_restore_test",
        "isolated_database_host": "127.0.0.1",
        "isolated_database_port": 3307,
        "isolated_database_uuid_sha256": "b" * 64,
        "host": "www.example.com",
        "deploy_uid": 997,
        "php_version": "8.4",
        "php_fpm_socket": "/run/php/php8.4-fpm.sock",
        "php_fpm_uid": 33,
        "php_fpm_gid": 33,
        "worker_programs": ["wpcaregrid-production-default", "wpcaregrid-production-backups"],
        "scheduler_unit": "wpcaregrid-production-scheduler.timer",
        "maintenance_marker": "/var/lib/wpcaregrid-deploy/production/state/maintenance",
        "worker_fence_marker": "/var/lib/wpcaregrid-deploy/production/state/workers-disabled",
        "scheduler_fence_marker": "/var/lib/wpcaregrid-deploy/production/state/scheduler-paused",
    }
    value.update(changes)
    return value


class DeploymentEnvironmentContractTests(unittest.TestCase):
    def reject(self, code, **changes):
        with self.assertRaises(CONTRACT.EnvironmentRefused) as caught:
            CONTRACT.resolve_environment("production", production_config=production_config(**changes))
        self.assertEqual(str(caught.exception), code)

    def test_staging_next_identity_is_compiled_and_immutable(self):
        profile = CONTRACT.resolve_environment("staging-next")
        self.assertIs(profile, CONTRACT.STAGING_NEXT)
        self.assertEqual(profile.database, "wpcg_staging_next")
        self.assertEqual(profile.app_root, "/var/www/wpcaregrid-staging-new")
        with self.assertRaises(FrozenInstanceError):
            profile.database = "wpcaregrid_prod"

    def test_staging_identity_cannot_be_overridden(self):
        with self.assertRaises(CONTRACT.EnvironmentRefused) as caught:
            CONTRACT.resolve_environment("staging-next", production_config=production_config())
        self.assertEqual(str(caught.exception), "environment_override_rejected")

    def test_production_is_disabled_until_complete_configuration_exists(self):
        self.assertFalse(CONTRACT.PRODUCTION_TEMPLATE.enabled)
        with self.assertRaises(CONTRACT.EnvironmentRefused) as caught:
            CONTRACT.resolve_environment("production")
        self.assertEqual(str(caught.exception), "production_profile_configuration_missing")

    def test_complete_production_profile_isolated_from_staging(self):
        profile = CONTRACT.resolve_environment("production", production_config=production_config())
        self.assertTrue(profile.enabled)
        self.assertEqual(profile.database, "wpcaregrid_prod")
        self.assertEqual(profile.host, "www.example.com")
        self.assertNotEqual(profile.deploy_uid, CONTRACT.STAGING_NEXT.deploy_uid)
        self.assertFalse(profile.state_root.startswith("/var/lib/wpcaregrid-deploy/staging-next/"))
        self.assertFalse(profile.backup_root.startswith("/var/lib/wpcaregrid-deploy/staging-next/"))

    def test_missing_or_extra_fields_are_rejected(self):
        missing = production_config()
        missing.pop("database_server_uuid_sha256")
        with self.assertRaises(CONTRACT.EnvironmentRefused) as caught:
            CONTRACT.resolve_environment("production", production_config=missing)
        self.assertEqual(str(caught.exception), "production_profile_schema_invalid")
        self.reject("production_profile_schema_invalid", extra_field="not-allowlisted")

    def test_staging_database_and_staging_host_are_rejected(self):
        self.reject("production_database_identity_invalid", database="wpcg_staging_next")
        self.reject("production_host_identity_invalid", host="staging-next.wpcaregrid.com")

    def test_database_and_restore_instance_must_be_distinct(self):
        self.reject("production_database_identity_invalid", isolated_database="wpcaregrid_prod")
        self.reject("production_restore_instance_not_isolated", isolated_database_uuid_sha256="a" * 64)
        self.reject("production_restore_endpoint_not_isolated", isolated_database_port=3306)

    def test_production_filesystem_roots_cannot_overlap_staging(self):
        self.reject("state_root_outside_profile_root", state_root="/var/lib/wpcaregrid-deploy/staging-next/state")
        self.reject("backup_root_outside_profile_root", backup_root="/tmp/wpcg-backups")
        self.reject("app_root_outside_profile_root", app_root="/var/www/wpcaregrid-staging-new")
        self.reject("release_root_outside_profile_root", release_root="/var/www/wpcaregrid-evil/releases")
        self.reject("state_root_invalid", state_root="/var/lib/wpcaregrid-deploy/production/../staging-next")

    def test_php_and_unix_identities_are_validated(self):
        self.reject("production_php_version_invalid", php_version="8.3")
        self.reject("php_fpm_socket_outside_profile_root", php_fpm_socket="/run/wpcaregrid.sock")
        self.reject("production_unix_identity_not_isolated", deploy_uid=996)
        self.reject("production_unix_identity_not_isolated", deploy_uid=33)
        self.reject("php_fpm_uid_invalid", php_fpm_uid=True)

    def test_workers_and_scheduler_are_production_scoped(self):
        self.reject("production_worker_scope_invalid", worker_programs=[
            "wpcaregrid-staging-next-default", "wpcaregrid-production-backups"])
        self.reject("production_worker_scope_invalid", worker_programs=[{}, "wpcaregrid-production-backups"])
        self.reject("production_scheduler_scope_invalid", scheduler_unit="wpcaregrid-staging-next-scheduler.timer")

    def test_unknown_environment_is_rejected(self):
        with self.assertRaises(CONTRACT.EnvironmentRefused) as caught:
            CONTRACT.resolve_environment("staging")
        self.assertEqual(str(caught.exception), "environment_unknown")


if __name__ == "__main__":
    unittest.main(verbosity=2)
