#!/usr/bin/env python3
"""Cross-component production profile isolation tests (synthetic only)."""

from __future__ import annotations

import importlib.util
import sys
import unittest
from pathlib import Path
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
from scripts import deployment_environment_contract as CONTRACT
from scripts import deployment_backend_v2 as BACKEND

SPEC = importlib.util.spec_from_file_location(
    "production_helper_test", ROOT / "scripts" / "wpcaregrid-deploy-production-priv.py")
HELPER = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = HELPER
SPEC.loader.exec_module(HELPER)


def production_config(**changes):
    value = {
        "schema": 1, "profile": "production", "enabled": True,
        "app_root": "/var/www/wpcaregrid-production",
        "application_link": "/var/www/wpcaregrid-production/current",
        "release_root": "/var/www/wpcaregrid-production/releases",
        "incoming_root": "/var/lib/wpcaregrid-deploy/production/incoming",
        "inbox_root": "/var/lib/wpcaregrid-deploy/production/inbox",
        "state_root": "/var/lib/wpcaregrid-deploy/production/state",
        "backup_root": "/var/lib/wpcaregrid-deploy/production/database-backups",
        "shared_env_file": "/var/lib/wpcaregrid-deploy/production/shared/.env",
        "shared_storage_root": "/var/lib/wpcaregrid-deploy/production/shared/storage",
        "shared_vendor_root": "/var/lib/wpcaregrid-deploy/production/shared/vendor",
        "database": "wpcg_prod_main", "database_host": "127.0.0.1", "database_port": 3306,
        "database_server_uuid_sha256": "a" * 64,
        "isolated_database": "wpcg_prod_restore_test", "isolated_database_host": "127.0.0.1",
        "isolated_database_port": 3307, "isolated_database_uuid_sha256": "b" * 64,
        "host": "wpcaregrid.com", "mysql_version": "8.4", "deploy_user": "deploy-production",
        "deploy_uid": 997, "php_version": "8.4",
        "php_fpm_socket": "/run/php/wpcaregrid-production-php8.4-fpm.sock",
        "php_fpm_unit": "wpcaregrid-production-php8.4-fpm.service",
        "php_fpm_uid": 998, "php_fpm_gid": 998,
        "worker_programs": ["wpcaregrid-production-default", "wpcaregrid-production-backups"],
        "scheduler_unit": "wpcaregrid-production-scheduler.timer",
        "maintenance_marker": "/var/lib/wpcaregrid-deploy/production/state/maintenance",
        "worker_fence_marker": "/var/lib/wpcaregrid-deploy/production/state/workers-disabled",
        "scheduler_fence_marker": "/var/lib/wpcaregrid-deploy/production/state/scheduler-paused",
    }
    value.update(changes)
    return value


class ProductionProfileIsolationTests(unittest.TestCase):
    def test_profile_is_disabled_without_complete_root_owned_configuration(self):
        self.assertFalse(CONTRACT.PRODUCTION_TEMPLATE.enabled)
        with self.assertRaises(CONTRACT.EnvironmentRefused):
            CONTRACT.resolve_environment("production")

    def test_complete_synthetic_profile_constructs_separate_backend_and_transaction_targets(self):
        profile = CONTRACT.resolve_environment("production", production_config=production_config())
        deployment = BACKEND.profile_for_environment(profile, helper_artifact_sha256="c" * 64,
                                                     phase_b_policy_sha256="d" * 64)
        BACKEND._safe_profile(deployment)
        self.assertEqual(profile.database, "wpcg_prod_main")
        self.assertEqual(BACKEND._TRANSACTION_MODULE.DATABASE, "wpcg_prod_main")
        self.assertEqual(deployment.name, "production")
        self.assertIsNone(deployment.basic_auth_file)
        self.assertEqual(BACKEND.PHP_FPM_UNIT, "wpcaregrid-production-php8.4-fpm.service")
        self.assertTrue(all(url.startswith("https://wpcaregrid.com/")
                            for url, _status in deployment.acceptance_urls))
        self.assertFalse(any("staging-next" in str(value) for value in (
            profile.application_link, profile.release_root, profile.state_root, profile.backup_root,
            BACKEND._TRANSACTION_MODULE.DATABASE,
            BACKEND._TRANSACTION_MODULE.PHASE_B_POLICY_PATH,
        )))

    def test_production_marketing_runtime_identity_is_exact_and_fail_closed(self):
        profile = CONTRACT.resolve_environment("production", production_config=production_config())
        deployment = BACKEND.profile_for_environment(profile, helper_artifact_sha256="c" * 64,
                                                     phase_b_policy_sha256="d" * 64)
        facts = {
            "application_environment": "production", "deployment_environment": "production",
            "application_url": "https://wpcaregrid.com", "debug": False,
            "marketing_profile": "marketing_only", "marketing_enforcement": "enforced",
            "staging_admin_qa_enabled": False, "database": "wpcg_prod_main",
            "version": "8.4.13", "uuid_sha256": "a" * 64,
        }
        BACKEND._verify_marketing_environment(deployment, facts)
        for key, value in (("application_environment", "staging"),
                           ("deployment_environment", "staging_next"),
                           ("application_url", "https://staging-next.wpcaregrid.com"),
                           ("debug", True), ("marketing_profile", "invitation_beta"),
                           ("marketing_enforcement", "testing"),
                           ("staging_admin_qa_enabled", True)):
            invalid = dict(facts, **{key: value})
            with self.subTest(key=key), self.assertRaisesRegex(
                    BACKEND.DeployRefused, "production_marketing_profile_invalid"):
                BACKEND._verify_marketing_environment(deployment, invalid)

    def test_production_rejects_staging_targets_before_binding(self):
        for overrides in (
            {"database": "wpcg_staging_next"},
            {"isolated_database": "wpcg_staging_next_restore"},
            {"state_root": "/var/lib/wpcaregrid-deploy/staging-next/state"},
            {"host": "staging-next.wpcaregrid.com"},
            {"deploy_user": "deploy-staging-next"},
        ):
            with self.subTest(overrides=overrides), self.assertRaises(CONTRACT.EnvironmentRefused):
                CONTRACT.resolve_environment("production", production_config=production_config(**overrides))

    def test_helper_extracts_contract_fields_from_exact_versioned_policy_schema(self):
        policy = production_config(entrypoint_sha256="a" * 64, backend_sha256="b" * 64,
                                  transaction_module_sha256="c" * 64,
                                  environment_contract_sha256="d" * 64,
                                  helper_artifact_sha256="e" * 64,
                                  phase_b_policy_sha256="f" * 64,
                                  operations=HELPER.OPERATIONS)
        fields = CONTRACT.production_required_fields()
        config = HELPER._environment_config(policy, fields)
        self.assertEqual(set(config), set(fields))
        self.assertNotIn("helper_artifact_sha256", config)
        with self.assertRaises(HELPER.Refused):
            HELPER._environment_config({"profile": "production"}, fields)

    def test_helper_operation_parser_rejects_environment_and_path_overrides(self):
        app_sha, artifact_sha = "a" * 40, "b" * 64
        self.assertEqual(HELPER._parse_operation(["preflight"]), ("preflight", None, None))
        self.assertEqual(HELPER._parse_operation(["authorize", app_sha]), ("authorize", app_sha, None))
        self.assertEqual(HELPER._parse_operation(["stage", app_sha, artifact_sha]),
                         ("stage", app_sha, artifact_sha))
        self.assertEqual(HELPER._parse_operation(["deploy", app_sha, artifact_sha]),
                         ("deploy", app_sha, artifact_sha))
        for args in (
            ["deploy", app_sha, artifact_sha, "--profile=staging-next"],
            ["deploy", app_sha, artifact_sha, "--database=wpcg_staging_next"],
            ["stage", app_sha, artifact_sha, "--path=/var/www/wpcaregrid-staging-new"],
            ["migrate", app_sha],
        ):
            with self.subTest(args=args), self.assertRaisesRegex(HELPER.Refused, "operation_not_allowed"):
                HELPER._parse_operation(args)

    def test_production_public_marketing_acceptance_allows_no_basic_auth_probe(self):
        profile = BACKEND.profile_for_environment(
            CONTRACT.resolve_environment("production", production_config=production_config()),
            helper_artifact_sha256="c" * 64, phase_b_policy_sha256="d" * 64)

        class Response:
            def __init__(self, status):
                self.status = status

            def __enter__(self):
                return self

            def __exit__(self, *_args):
                return False

            @staticmethod
            def read(_maximum):
                return b"synthetic"

        class Client:
            @staticmethod
            def urlopen(request, timeout):
                self.assertEqual(timeout, 8.0)
                return Response(200 if urlsplit(request.full_url).path in {"/up", "/hu", "/en"} else 404)

        passed, active, unauthenticated = BACKEND._bounded_acceptance(profile, opener=Client())
        self.assertTrue(passed)
        self.assertEqual(active, (200, 200, 200, *(404 for _ in range(10))))
        self.assertEqual(unauthenticated, ())

    def test_production_nginx_route_requires_its_own_vhost_and_fpm_socket(self):
        config = b"""# configuration file /etc/nginx/sites-enabled/wpcg-production:
server {
    listen 443 ssl;
    server_name wpcaregrid.com;
    location ~ \\.php$ {
        fastcgi_pass unix:/run/php/wpcaregrid-production-php8.4-fpm.sock;
    }
}
# configuration file /etc/nginx/sites-enabled/other:
server { listen 443 ssl; server_name other.example.com; }
"""
        BACKEND._verify_environment_nginx_route(
            config, hostname="wpcaregrid.com",
            socket_path="/run/php/wpcaregrid-production-php8.4-fpm.sock")
        with self.assertRaisesRegex(BACKEND.DeployRefused, "nginx_fpm_route_mismatch"):
            BACKEND._verify_environment_nginx_route(
                config, hostname="wpcaregrid.com", socket_path="/run/php/php8.5-fpm.sock")
        duplicate = config + b"\n# configuration file /etc/nginx/sites-enabled/duplicate:\n" \
            + config.split(b"# configuration file /etc/nginx/sites-enabled/other:")[0].split(
                b"# configuration file /etc/nginx/sites-enabled/wpcg-production:", 1)[1]
        with self.assertRaisesRegex(BACKEND.DeployRefused, "nginx_https_vhost_ambiguous"):
            BACKEND._verify_environment_nginx_route(
                duplicate, hostname="wpcaregrid.com",
                socket_path="/run/php/wpcaregrid-production-php8.4-fpm.sock")


if __name__ == "__main__":
    unittest.main(verbosity=2)
