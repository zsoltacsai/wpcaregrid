#!/usr/bin/env python3
"""Verify the transaction journal's per-process environment binding."""

from __future__ import annotations

import importlib.util
import gzip
import tempfile
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
from scripts import deployment_environment_contract as CONTRACT
from scripts import deployment_backend_v2 as BACKEND
TRANSACTION = BACKEND._TRANSACTION_MODULE


def production_config(**changes):
    value = {
        "schema": 1, "profile": "production", "enabled": True,
        "app_root": "/var/www/wpcaregrid-production", "application_link": "/var/www/wpcaregrid-production/current",
        "release_root": "/var/www/wpcaregrid-production/releases",
        "incoming_root": "/var/lib/wpcaregrid-deploy/production/incoming",
        "inbox_root": "/var/lib/wpcaregrid-deploy/production/inbox",
        "state_root": "/var/lib/wpcaregrid-deploy/production/state",
        "backup_root": "/var/lib/wpcaregrid-deploy/production/database-backups",
        "shared_env_file": "/var/lib/wpcaregrid-deploy/production/shared/.env",
        "shared_storage_root": "/var/lib/wpcaregrid-deploy/production/shared/storage",
        "shared_vendor_root": "/var/lib/wpcaregrid-deploy/production/shared/vendor",
        "database": "wpcg_prod_main", "database_host": "127.0.0.1", "database_port": 3306,
        "database_server_uuid_sha256": "a" * 64, "isolated_database": "wpcg_prod_restore_test",
        "isolated_database_host": "127.0.0.1", "isolated_database_port": 3307,
        "isolated_database_uuid_sha256": "b" * 64, "host": "www.wpcaregrid.com",
        "mysql_version": "8.4", "deploy_user": "deploy-production", "deploy_uid": 997, "php_version": "8.4",
        "php_fpm_socket": "/run/php/wpcaregrid-production-php8.4-fpm.sock",
        "php_fpm_unit": "wpcaregrid-production-php8.4-fpm.service", "php_fpm_uid": 998, "php_fpm_gid": 998,
        "worker_programs": ["wpcaregrid-production-default", "wpcaregrid-production-backups"],
        "scheduler_unit": "wpcaregrid-production-scheduler.timer",
        "maintenance_marker": "/var/lib/wpcaregrid-deploy/production/state/maintenance",
        "worker_fence_marker": "/var/lib/wpcaregrid-deploy/production/state/workers-disabled",
        "scheduler_fence_marker": "/var/lib/wpcaregrid-deploy/production/state/scheduler-paused",
    }
    value.update(changes)
    return value


class EnvironmentTransactionBindingTests(unittest.TestCase):
    def setUp(self):
        TRANSACTION.bind_environment_contract(CONTRACT.STAGING_NEXT)

    def test_production_binding_separates_every_database_transaction_target(self):
        profile = CONTRACT.resolve_environment("production", production_config=production_config())
        TRANSACTION.bind_environment_contract(profile)
        self.assertEqual(TRANSACTION.PROFILE, "production")
        self.assertEqual(TRANSACTION.DATABASE, "wpcg_prod_main")
        self.assertEqual(str(TRANSACTION.PHASE_B_BACKUP_PATH),
                         "/var/lib/wpcaregrid-deploy/production/database-backups")
        self.assertEqual(str(TRANSACTION.PHASE_B_POLICY_PATH),
                         "/etc/wpcaregrid-deploy/profiles/production-phase-b-2.0.0.json")
        self.assertEqual(str(TRANSACTION.PHASE_B_WORKER_DISABLED_MARKER),
                         "/var/lib/wpcaregrid-deploy/production/state/workers-disabled")
        self.assertTrue(all("production" in item for item in TRANSACTION.PHASE_B_WORKERS))
        self.assertNotEqual(TRANSACTION.DATABASE, "wpcg_staging_next")

    def test_partial_or_untrusted_contract_cannot_rebind_transaction_module(self):
        before = (TRANSACTION.PROFILE, TRANSACTION.DATABASE, TRANSACTION.PHASE_B_BACKUP_PATH)
        with self.assertRaises(TRANSACTION.TransactionRefused):
            TRANSACTION.bind_environment_contract(type("Untrusted", (), {
                "name": "production", "enabled": True, "database": "wpcg_prod_main",
            })())
        self.assertEqual(before, (TRANSACTION.PROFILE, TRANSACTION.DATABASE,
                                  TRANSACTION.PHASE_B_BACKUP_PATH))

    def test_production_never_binds_staging_database_or_filesystem(self):
        profile = CONTRACT.resolve_environment("production", production_config=production_config())
        TRANSACTION.bind_environment_contract(profile)
        self.assertFalse(str(TRANSACTION.PHASE_B_BACKUP_PATH).startswith(
            "/var/lib/wpcaregrid-deploy/staging-next/"))
        self.assertNotIn("staging-next", str(TRANSACTION.PHASE_B_POLICY_PATH))
        self.assertNotIn("staging-next", str(TRANSACTION.PHASE_B_WORKER_DISABLED_MARKER))

    def test_production_transaction_schema_names_are_dynamic_and_staging_names_rejected(self):
        profile = CONTRACT.resolve_environment("production", production_config=production_config())
        TRANSACTION.bind_environment_contract(profile)
        tx_id = "1" * 32
        restore_db = TRANSACTION._database_name("restore", tx_id)
        rehearsal_db = TRANSACTION._database_name("rehearsal", tx_id)
        self.assertEqual(restore_db, "wpcg_prod_main_restore_" + tx_id)
        self.assertEqual(rehearsal_db, "wpcg_prod_main_rehearsal_" + tx_id)
        for database in ("wpcg_prod_main", restore_db, rehearsal_db):
            TRANSACTION._database_data_fingerprint(None, database=database, isolated=False, table_names=[])
        with self.assertRaisesRegex(TRANSACTION.TransactionRefused, "phase_b_data_fingerprint_input_invalid"):
            TRANSACTION._database_data_fingerprint(None, database="wpcg_staging_next",
                                                  isolated=False, table_names=[])

    def test_profile_backup_preflight_rejects_schema_switch_and_cross_schema_sql(self):
        profile = CONTRACT.resolve_environment("production", production_config=production_config())
        TRANSACTION.bind_environment_contract(profile)
        with tempfile.TemporaryDirectory(prefix="wpcg-prod-backup-synthetic-") as temporary:
            path = Path(temporary) / "synthetic.sql.gz"
            for sql in (
                b"CREATE TABLE `users` (`id` int);\n",
                b"INSERT INTO `wpcg_staging_next`.`users` VALUES (1);\n",
                b"INSERT INTO `wpcg_prod_other`.`users` VALUES (1);\n",
                b"USE `wpcg_prod_main`;\n",
                b"SET GLOBAL log_bin_trust_function_creators=ON;\n",
            ):
                with self.subTest(sql=sql):
                    path.write_bytes(gzip.compress(sql))
                    if b"`users`" in sql and b"." not in sql:
                        self.assertEqual(len(TRANSACTION._verify_backup_sql(path)[0]), 64)
                    else:
                        with self.assertRaises(TRANSACTION.TransactionRefused):
                            TRANSACTION._verify_backup_sql(path)

    def test_backend_profile_is_constructed_only_from_the_bound_contract(self):
        profile = CONTRACT.resolve_environment("production", production_config=production_config())
        deployment = BACKEND.profile_for_environment(profile, helper_artifact_sha256="c" * 64,
                                                     phase_b_policy_sha256="d" * 64)
        BACKEND._safe_profile(deployment)
        self.assertEqual(deployment.name, "production")
        self.assertEqual(str(deployment.application_link), "/var/www/wpcaregrid-production/current")
        self.assertEqual(str(deployment.releases_dir), "/var/www/wpcaregrid-production/releases")
        self.assertEqual(str(deployment.state_dir), "/var/lib/wpcaregrid-deploy/production/state")
        self.assertIsNone(deployment.basic_auth_file)
        self.assertEqual(BACKEND.DATABASE, "wpcg_prod_main")
        reset = CONTRACT.resolve_environment("staging-next")
        BACKEND.bind_environment_contract(reset)
        with self.assertRaises(BACKEND.DeployRefused) as caught:
            BACKEND._safe_profile(deployment)
        self.assertEqual(str(caught.exception), "profile_not_enabled")


if __name__ == "__main__":
    unittest.main(verbosity=2)
