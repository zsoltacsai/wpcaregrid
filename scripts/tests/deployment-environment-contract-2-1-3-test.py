#!/usr/bin/env python3
"""Source-policy tests for the 2.1.3 environment contract successor (GO-13 confinement binding).

These tests prove only that the *source policy* binds production/staging FPM
confinement to exact versioned artifact hashes and expected service attachment,
and refuses mismatches. They do NOT prove that any kernel enforces an AppArmor
profile; that is the separate staged-cutover and live-acceptance host gate in
docs/PHP_FPM_CONFINEMENT_GO13.md. Nothing here reads a host or loads a profile.
"""

from __future__ import annotations

import ast
import copy
import hashlib
import importlib.util
import json
import sys
import unittest
from pathlib import Path

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]


def load(name: str, relative: str):
    spec = importlib.util.spec_from_file_location(name, ROOT / relative)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


CONTRACT = load("deployment_environment_contract_2_1_3", "scripts/deployment_environment_contract_2_1_3.py")
OLD = load("deployment_environment_contract_legacy_pin", "scripts/deployment_environment_contract.py")
PACKAGE = ROOT / "deploy" / "php-fpm-confinement" / "1.0.1"
INSTALLED_TO_ARTIFACT = {
    "/etc/apparmor.d/wpcaregrid-php-fpm-production": "apparmor/wpcaregrid-php-fpm-production",
    "/etc/apparmor.d/wpcaregrid-php-fpm-staging-next": "apparmor/wpcaregrid-php-fpm-staging-next",
    "/etc/apparmor.d/wpcaregrid-php-fpm-staging-legacy": "apparmor/wpcaregrid-php-fpm-staging-legacy",
    "/etc/apparmor.d/abstractions/wpcaregrid-php-fpm-common": "apparmor/abstractions/wpcaregrid-php-fpm-common",
    "/etc/systemd/system/wpcaregrid-production-php8.4-fpm.service": "systemd/wpcaregrid-production-php8.4-fpm.service",
    "/etc/systemd/system/php8.5-fpm.service.d/90-wpcaregrid-confinement.conf":
        "systemd/php8.5-fpm.service.d/90-wpcaregrid-confinement.conf",
    "/etc/systemd/system/php8.4-fpm.service.d/90-wpcaregrid-confinement.conf":
        "systemd/php8.4-fpm.service.d/90-wpcaregrid-confinement.conf",
    "/etc/apparmor.d/wpcaregrid-nginx": "apparmor/wpcaregrid-nginx",
    "/etc/apparmor.d/wpcaregrid-php-cli-staging": "apparmor/wpcaregrid-php-cli-staging",
    "/etc/systemd/system/nginx.service.d/90-wpcaregrid-confinement.conf":
        "systemd/nginx.service.d/90-wpcaregrid-confinement.conf",
    "/etc/systemd/system/supervisor.service.d/90-wpcaregrid-confinement.conf":
        "systemd/supervisor.service.d/90-wpcaregrid-confinement.conf",
    "/etc/systemd/system/wpcaregrid-staging-next-scheduler.service.d/90-wpcaregrid-confinement.conf":
        "systemd/wpcaregrid-staging-next-scheduler.service.d/90-wpcaregrid-confinement.conf",
    "/etc/php/8.4/fpm-wpcaregrid-production/php-fpm.conf": "php-fpm/production/php-fpm.conf",
    "/etc/php/8.4/fpm-wpcaregrid-production/pool.d/wpcaregrid-production.conf":
        "php-fpm/production/pool.d/wpcaregrid-production.conf",
}


CRON_PIN = "e" * 64


def confinement_policy():
    return {
        "schema": 1,
        "package_version": "1.0.1",
        "package_sha256sums_sha256": CONTRACT.CONFINEMENT_SUMS_SHA256,
        "www_data_crontab_sha256": CRON_PIN,
        "files": dict(CONTRACT.CONFINEMENT_FILE_SHA256),
        "services": {
            role: {"unit": b.unit, "apparmor_profile": b.apparmor_profile, "profile_path": b.profile_path,
                   "attachment_path": b.attachment_path}
            for role, b in CONTRACT.CONFINEMENT_SERVICES.items()
        },
    }


def production_config(**changes):
    value = {
        "schema": 2, "profile": "production", "enabled": True,
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
        "database_server_uuid_sha256": "a" * 64, "isolated_database": "wpcg_prod_restore_test",
        "isolated_database_host": "127.0.0.1", "isolated_database_port": 3307,
        "isolated_database_uuid_sha256": "b" * 64, "host": "www.wpcaregrid.com", "mysql_version": "8.4",
        "deploy_user": "deploy-production", "deploy_uid": 997, "php_version": "8.4",
        "php_fpm_socket": "/run/php/wpcaregrid-production-php8.4-fpm.sock",
        "php_fpm_unit": "wpcaregrid-production-php8.4-fpm.service",
        "php_fpm_uid": 33, "php_fpm_gid": 33,
        "worker_programs": ["wpcaregrid-production-default", "wpcaregrid-production-backups"],
        "scheduler_unit": "wpcaregrid-production-scheduler.timer",
        "maintenance_marker": "/var/lib/wpcaregrid-deploy/production/state/maintenance",
        "worker_fence_marker": "/var/lib/wpcaregrid-deploy/production/state/workers-disabled",
        "scheduler_fence_marker": "/var/lib/wpcaregrid-deploy/production/state/scheduler-paused",
        "php_fpm_confinement": confinement_policy(),
    }
    value.update(changes)
    return value


def observation(roles=CONTRACT.ALL_ROLES, cron=None, **changes):
    files = {path: {"sha256": digest, "uid": 0, "gid": 0, "mode": 0o644, "type": "regular", "nlink": 1}
             for path, digest in CONTRACT.CONFINEMENT_FILE_SHA256.items()}
    services = {}
    for role in roles:
        b = CONTRACT.CONFINEMENT_SERVICES[role]
        services[role] = {"unit": b.unit, "active": True, "apparmor_profile_property": b.apparmor_profile,
                          "loaded_profile_mode": "enforce",
                          "process_labels": [f"{b.apparmor_profile} (enforce)"] * 3}
    peers = [{"pid": 1000 + i, "comm": "php-fpm", "unit": CONTRACT.CONFINEMENT_SERVICES[role].unit,
              "label": f"{CONTRACT.CONFINEMENT_SERVICES[role].apparmor_profile} (enforce)"}
             for i, role in enumerate(roles)]
    value = {"schema": 1, "files": files, "services": services,
             "same_uid_peers": {"complete": True, "processes": peers},
             "fenced": {"units": {"wpcaregrid-production-scheduler.timer": {"load_state": "masked", "active_state": "inactive"},
                                  "wpcaregrid-production-scheduler.service": {"load_state": "masked", "active_state": "inactive"}},
                        "supervisor_programs": [],
                        "markers": {"worker_fence_marker": True, "scheduler_fence_marker": True}},
             "www_data_cron": cron or {"crontab_sha256": None, "cron_d_entries": 0, "wrapper": None, "profile_mode": "enforce"}}
    value.update(changes)
    return value


def production():
    return CONTRACT.resolve_environment("production", production_config=production_config())


class SourcePolicy(unittest.TestCase):
    def reject(self, code, **changes):
        with self.assertRaises(CONTRACT.EnvironmentRefused) as caught:
            CONTRACT.resolve_environment("production", production_config=production_config(**changes))
        self.assertEqual(str(caught.exception), code)

    def reject_confinement(self, code, mutate):
        policy = copy.deepcopy(confinement_policy())
        mutate(policy)
        self.reject(code, php_fpm_confinement=policy)

    # --- same UID with both profiles and the policy hash passes source policy ---
    def test_same_www_data_uid_with_bound_confinement_passes_source_policy(self):
        contract = production()
        self.assertEqual((contract.php_fpm_uid, contract.php_fpm_gid), (33, 33))
        self.assertEqual((contract.php_fpm_uid, contract.php_fpm_gid),
                         (CONTRACT.STAGING_NEXT.php_fpm_uid, CONTRACT.STAGING_NEXT.php_fpm_gid))
        self.assertEqual(contract.php_fpm_confinement.roles, CONTRACT.ALL_ROLES)
        self.assertEqual(contract.php_fpm_confinement.www_data_crontab_sha256, CRON_PIN)
        self.assertEqual(contract.php_fpm_confinement.package_sha256sums_sha256, CONTRACT.CONFINEMENT_SUMS_SHA256)
        self.assertEqual(CONTRACT.CONFINEMENT_SERVICES["production"].unit, contract.php_fpm_unit)

    def test_no_new_fpm_identity_is_accepted(self):
        self.reject("production_php_fpm_identity_must_be_shared_www_data", php_fpm_uid=998, php_fpm_gid=998)
        self.reject("production_php_fpm_identity_must_be_shared_www_data", php_fpm_gid=998)
        self.reject("production_php_fpm_identity_must_be_shared_www_data", php_fpm_uid=998)

    def test_deploy_identity_still_cannot_equal_fpm_or_staging(self):
        self.reject("production_unix_identity_not_isolated", deploy_uid=33)
        self.reject("production_unix_identity_not_isolated", deploy_uid=CONTRACT.STAGING_NEXT.deploy_uid)

    def test_schema_1_and_php_85_are_not_accepted(self):
        self.reject("production_profile_schema_invalid", schema=1)
        self.reject("production_profile_schema_invalid", schema=True)
        self.reject("production_php_version_invalid", php_version="8.5",
                    php_fpm_socket="/run/php/wpcaregrid-production-php8.5-fpm.sock",
                    php_fpm_unit="wpcaregrid-production-php8.5-fpm.service")

    # --- missing / wrong profile hash refuses ---
    def test_missing_confinement_binding_refuses(self):
        config = production_config()
        del config["php_fpm_confinement"]
        with self.assertRaises(CONTRACT.EnvironmentRefused) as caught:
            CONTRACT.resolve_environment("production", production_config=config)
        self.assertEqual(str(caught.exception), "production_confinement_missing")
        self.reject("production_confinement_schema_invalid", php_fpm_confinement=None)
        self.reject("production_confinement_schema_invalid", php_fpm_confinement={})
        self.reject("production_confinement_schema_invalid", php_fpm_confinement=[])

    def test_wrong_or_missing_file_hash_refuses_for_every_bound_file(self):
        for path in CONTRACT.CONFINEMENT_FILE_SHA256:
            with self.subTest(path=path):
                self.reject_confinement("production_confinement_hash_mismatch",
                                        lambda p, path=path: p["files"].__setitem__(path, "0" * 64))
                self.reject_confinement("production_confinement_file_set_invalid",
                                        lambda p, path=path: p["files"].pop(path))
                self.reject_confinement("production_confinement_file_set_invalid",
                                        lambda p, path=path: p["files"].__setitem__(path, "not-a-hash"))
        self.reject_confinement("production_confinement_file_set_invalid",
                                lambda p: p["files"].__setitem__("/etc/apparmor.d/extra", "a" * 64))

    def test_package_and_service_binding_mismatches_refuse(self):
        self.reject_confinement("production_confinement_package_mismatch",
                                lambda p: p.__setitem__("package_version", "1.0.0"))
        self.reject_confinement("production_confinement_package_mismatch",
                                lambda p: p.__setitem__("package_sha256sums_sha256", "c" * 64))
        for role in CONTRACT.CONFINEMENT_SERVICES:
            self.reject_confinement("production_confinement_service_binding_invalid",
                                    lambda p, role=role: p["services"].pop(role))
            for key in ("unit", "apparmor_profile", "profile_path", "attachment_path"):
                self.reject_confinement("production_confinement_service_binding_invalid",
                                        lambda p, role=role, key=key: p["services"][role].__setitem__(key, "x"))
        self.reject_confinement("production_confinement_schema_invalid", lambda p: p.__setitem__("extra", 1))
        self.reject_confinement("production_confinement_schema_invalid", lambda p: p.__setitem__("schema", 2))

    def test_compiled_hashes_equal_the_committed_artifacts(self):
        self.assertEqual(set(INSTALLED_TO_ARTIFACT), set(CONTRACT.CONFINEMENT_FILE_SHA256))
        for installed, artifact in INSTALLED_TO_ARTIFACT.items():
            self.assertEqual(hashlib.sha256((PACKAGE / artifact).read_bytes()).hexdigest(),
                             CONTRACT.CONFINEMENT_FILE_SHA256[installed], artifact)
        self.assertEqual(hashlib.sha256((PACKAGE / "SHA256SUMS").read_bytes()).hexdigest(),
                         CONTRACT.CONFINEMENT_SUMS_SHA256)
        self.assertEqual(CONTRACT.CONFINEMENT_VERSION, PACKAGE.name)
        for binding in CONTRACT.CONFINEMENT_SERVICES.values():
            self.assertIn(binding.profile_path, binding.files)
            self.assertIn(binding.attachment_path, binding.files)
            self.assertTrue(set(binding.files) <= set(CONTRACT.CONFINEMENT_FILE_SHA256))
            self.assertIn(f"AppArmorProfile={binding.apparmor_profile}",
                          (PACKAGE / INSTALLED_TO_ARTIFACT[binding.attachment_path]).read_text())

    # --- unmanaged staging cannot be treated as isolated ---
    def test_staging_next_contract_requires_confinement_roles(self):
        staging = CONTRACT.resolve_environment("staging-next")
        self.assertEqual(staging.php_fpm_confinement.roles, CONTRACT.STAGING_ROLES)
        CONTRACT.verify_confinement_observation(staging, observation(CONTRACT.STAGING_ROLES))

    def test_unmanaged_staging_service_is_refused_for_staging_and_production(self):
        for contract in (CONTRACT.resolve_environment("staging-next"), production()):
            for role in ("staging-next", "staging-legacy"):
                with self.subTest(contract=contract.name, role=role):
                    for unmanaged in ("", "unconfined"):
                        data = observation()
                        data["services"][role]["apparmor_profile_property"] = unmanaged
                        self.refuse(contract, data, "confinement_service_unattached" if unmanaged == ""
                                    else "confinement_service_profile_mismatch")
                    data = observation()
                    del data["services"][role]
                    self.refuse(contract, data, "confinement_service_unobserved")

    def test_profile_files_present_but_service_not_attached_is_not_isolated(self):
        # Files on disk with correct hashes, never attached by a drop-in: still refused.
        data = observation()
        data["services"]["staging-next"]["apparmor_profile_property"] = ""
        data["services"]["staging-next"]["process_labels"] = ["unconfined"]
        self.refuse(CONTRACT.resolve_environment("staging-next"), data, "confinement_service_unattached")

    def test_attached_but_running_unconfined_or_complain_is_refused(self):
        contract = production()
        for label in ("unconfined", "wpcaregrid-php-fpm-staging-next (complain)", "other (enforce)", ""):
            data = observation()
            data["services"]["staging-next"]["process_labels"] = [
                "wpcaregrid-php-fpm-staging-next (enforce)", label]
            self.refuse(contract, data, "confinement_process_not_confined")
        data = observation()
        data["services"]["staging-legacy"]["process_labels"] = []
        self.refuse(contract, data, "confinement_process_labels_missing")
        data = observation()
        data["services"]["production"]["loaded_profile_mode"] = "complain"
        self.refuse(contract, data, "confinement_profile_not_enforced")
        data["services"]["production"]["loaded_profile_mode"] = "unloaded"
        self.refuse(contract, data, "confinement_profile_not_enforced")

    def refuse(self, contract, data, code):
        with self.assertRaises(CONTRACT.EnvironmentRefused) as caught:
            CONTRACT.verify_confinement_observation(contract, data)
        self.assertEqual(str(caught.exception), code)

    def test_complete_observation_passes_and_inactive_service_needs_no_labels(self):
        CONTRACT.verify_confinement_observation(production(), observation())
        data = observation()
        data["services"]["production"].update(active=False, process_labels=[])
        CONTRACT.verify_confinement_observation(production(), data)
        data["services"]["production"]["process_labels"] = ["x (enforce)"]
        self.refuse(production(), data, "confinement_observation_invalid")

    def test_wrong_missing_or_unsafe_installed_files_refuse(self):
        contract = production()
        for path in CONTRACT.CONFINEMENT_FILE_SHA256:
            with self.subTest(path=path):
                data = observation()
                del data["files"][path]
                self.refuse(contract, data, "confinement_file_missing")
                data = observation()
                data["files"][path]["sha256"] = "d" * 64
                self.refuse(contract, data, "confinement_file_hash_mismatch")
                data = observation()
                data["files"][path]["uid"] = 33
                self.refuse(contract, data, "confinement_file_not_root_owned")
                data = observation()
                data["files"][path]["gid"] = 33
                self.refuse(contract, data, "confinement_file_not_root_owned")
                data = observation()
                data["files"][path]["mode"] = 0o664
                self.refuse(contract, data, "confinement_file_mode_invalid")
                data = observation()
                data["files"][path]["nlink"] = 2
                self.refuse(contract, data, "confinement_file_mode_invalid")
                data = observation()
                data["files"][path]["type"] = "symlink"
                self.refuse(contract, data, "confinement_file_not_regular")

    def test_malformed_observation_and_unbound_contract_fail_closed(self):
        contract = production()
        for bad in (None, [], {}, {"schema": 2, "files": {}, "services": {}, "same_uid_peers": {}},
                    {**observation(), "extra": 1}, {"schema": 1, "files": [], "services": {}}):
            self.refuse(contract, bad, "confinement_observation_invalid")
        data = observation()
        data["services"]["production"]["unit"] = "php8.4-fpm.service"
        self.refuse(contract, data, "confinement_service_unit_mismatch")
        data = observation()
        data["services"]["production"]["active"] = "yes"
        self.refuse(contract, data, "confinement_observation_invalid")
        data = observation()
        data["files"][next(iter(data["files"]))]["uid"] = True
        self.refuse(contract, data, "confinement_observation_invalid")
        self.refuse(CONTRACT.PRODUCTION_TEMPLATE, observation(), "confinement_not_bound")

    def test_staging_contract_does_not_require_production_attachment(self):
        data = observation(CONTRACT.STAGING_ROLES)
        CONTRACT.verify_confinement_observation(CONTRACT.resolve_environment("staging-next"), data)
        self.refuse(production(), data, "confinement_service_unobserved")

    # --- every uid-33 process maps to exactly one permitted class; unknown blocks ---
    def peer(self, comm, label, unit, pid=5000):
        return {"pid": pid, "comm": comm, "label": label, "unit": unit}

    def test_nginx_and_job_peers_confined_to_their_profiles_are_permitted(self):
        data = observation()
        data["same_uid_peers"]["processes"] += [
            self.peer("nginx", "wpcaregrid-nginx (enforce)", "nginx.service", 5001),
            self.peer("php8.5", "wpcaregrid-php-cli-staging (enforce)", "supervisor.service", 5002),
            self.peer("php8.5", "wpcaregrid-php-cli-staging (enforce)", "wpcaregrid-staging-next-scheduler.service", 5003),
            self.peer("php8.4", "wpcaregrid-php-cli-staging (enforce)", "cron.service", 5004)]
        for contract in (production(), CONTRACT.resolve_environment("staging-next")):
            CONTRACT.verify_confinement_observation(contract, data)

    def test_unconfined_nginx_queue_worker_scheduler_and_unknown_processes_block(self):
        for contract in (production(), CONTRACT.resolve_environment("staging-next")):
            for comm, unit in (("nginx", "nginx.service"), ("php", "supervisor.service"),
                               ("php", "wpcaregrid-staging-next-scheduler.service"), ("python3", "supervisor.service"),
                               ("bash", "user@33.service"), ("php", "wpcaregrid-production-queue.service"),
                               ("sleep", "")):
                data = observation()
                data["same_uid_peers"]["processes"].append(self.peer(comm, "unconfined", unit))
                self.refuse(contract, data, "confinement_same_uid_peer_unconfined")
        for label in ("wpcaregrid-nginx (complain)", "wpcaregrid-php-cli-staging (complain)", "nginx (enforce)", ""):
            data = observation()
            data["same_uid_peers"]["processes"].append(self.peer("nginx", label, "nginx.service"))
            self.refuse(production(), data, "confinement_same_uid_peer_unconfined")

    def test_a_profile_label_outside_its_own_unit_blocks(self):
        for label, unit in (("wpcaregrid-nginx (enforce)", "supervisor.service"),
                            ("wpcaregrid-php-cli-staging (enforce)", "nginx.service"),
                            ("wpcaregrid-php-fpm-production (enforce)", "nginx.service"),
                            ("wpcaregrid-php-cli-staging (enforce)", "php8.5-fpm.service")):
            data = observation()
            data["same_uid_peers"]["processes"].append(self.peer("php", label, unit))
            self.refuse(production(), data, "confinement_peer_label_outside_unit")

    def test_unattached_nginx_and_job_services_are_not_isolated(self):
        for role in ("nginx", "jobs-supervisor", "jobs-scheduler"):
            for contract in (production(), CONTRACT.resolve_environment("staging-next")):
                data = observation()
                data["services"][role]["apparmor_profile_property"] = ""
                self.refuse(contract, data, "confinement_service_unattached")
                data = observation()
                data["services"][role]["apparmor_profile_property"] = "wpcaregrid-php-fpm-production"
                self.refuse(contract, data, "confinement_service_profile_mismatch")
                data = observation()
                del data["services"][role]
                self.refuse(contract, data, "confinement_service_unobserved")
                data = observation()
                data["services"][role]["loaded_profile_mode"] = "complain"
                self.refuse(contract, data, "confinement_profile_not_enforced")

    def test_cron_shim_is_permitted_only_with_the_exact_pinned_crontab_and_attached_wrapper(self):
        wrapper = {"uid": 0, "gid": 0, "mode": 0o755, "type": "regular", "nlink": 1}
        cron = {"crontab_sha256": CRON_PIN, "cron_d_entries": 0, "wrapper": wrapper, "profile_mode": "enforce"}
        data = observation(cron=cron)
        data["same_uid_peers"]["processes"].append(self.peer("CRON", "unconfined", "cron.service"))
        data["same_uid_peers"]["processes"].append(self.peer("sh", "unconfined", "cron.service", 5001))
        CONTRACT.verify_confinement_observation(production(), data)
        # shim present but no crontab observed/pinned
        bare = observation()
        bare["same_uid_peers"]["processes"].append(self.peer("CRON", "unconfined", "cron.service"))
        self.refuse(production(), bare, "confinement_cron_unpinned")
        # wrong pin, cron.d, or staging compiled contract (no pin) with any crontab
        for bad in (dict(cron, crontab_sha256="f" * 64), dict(cron, cron_d_entries=1)):
            self.refuse(production(), observation(cron=bad), "confinement_cron_unpinned")
        self.refuse(CONTRACT.resolve_environment("staging-next"), observation(CONTRACT.STAGING_ROLES, cron=cron),
                    "confinement_cron_unpinned")
        # wrapper must be root-owned, not writable by others, single link, regular; profile enforce
        for broken in (dict(wrapper, uid=33), dict(wrapper, gid=33), dict(wrapper, mode=0o775), dict(wrapper, mode=0o757),
                       dict(wrapper, nlink=2), dict(wrapper, type="symlink"), None):
            self.refuse(production(), observation(cron=dict(cron, wrapper=broken)), "confinement_cron_wrapper_unsafe")
        self.refuse(production(), observation(cron=dict(cron, profile_mode="complain")), "confinement_cron_wrapper_unconfined")
        self.refuse(production(), observation(cron=dict(cron, profile_mode="unloaded")), "confinement_cron_wrapper_unconfined")
        # an unrelated unconfined process under cron is not the shim
        other = observation(cron=cron)
        other["same_uid_peers"]["processes"].append(self.peer("php", "unconfined", "cron.service"))
        self.refuse(production(), other, "confinement_same_uid_peer_unconfined")
        shim_elsewhere = observation(cron=cron)
        shim_elsewhere["same_uid_peers"]["processes"].append(self.peer("sh", "unconfined", "nginx.service"))
        self.refuse(production(), shim_elsewhere, "confinement_same_uid_peer_unconfined")

    def test_policy_cron_pin_must_be_null_or_a_hash(self):
        self.reject_confinement("production_confinement_cron_pin_invalid", lambda p: p.__setitem__("www_data_crontab_sha256", "x"))
        self.reject_confinement("production_confinement_schema_invalid", lambda p: p.pop("www_data_crontab_sha256"))
        self.assertIsNone(CONTRACT.resolve_environment(
            "production", production_config=production_config(
                php_fpm_confinement={**confinement_policy(), "www_data_crontab_sha256": None})).php_fpm_confinement.www_data_crontab_sha256)

    # --- production marketing needs no jobs: queue workers and scheduler are fenced, fail-closed ---
    def fenced_refuse(self, mutate):
        data = observation()
        mutate(data["fenced"])
        self.refuse(production(), data, "confinement_production_jobs_not_fenced")

    def test_production_jobs_must_be_masked_inactive_and_marker_fenced(self):
        timer, twin = "wpcaregrid-production-scheduler.timer", "wpcaregrid-production-scheduler.service"
        for unit in (timer, twin):
            for state in ({"load_state": "loaded", "active_state": "inactive"}, {"load_state": "masked", "active_state": "active"},
                          {"load_state": "masked", "active_state": "activating"}, {"load_state": "not-found", "active_state": "inactive"}):
                self.fenced_refuse(lambda f, unit=unit, state=state: f["units"].__setitem__(unit, state))
            self.fenced_refuse(lambda f, unit=unit: f["units"].pop(unit))
        for marker in ("worker_fence_marker", "scheduler_fence_marker"):
            self.fenced_refuse(lambda f, marker=marker: f["markers"].__setitem__(marker, False))

    def test_production_supervisor_programs_must_be_absent_or_stopped_and_not_autostarting(self):
        ok = observation()
        ok["fenced"]["supervisor_programs"] = [
            {"name": "wpcaregrid-production-default", "state": "STOPPED", "autostart": False},
            {"name": "wpcaregrid-staging-next-default", "state": "RUNNING", "autostart": True}]  # staging jobs are not production's
        CONTRACT.verify_confinement_observation(production(), ok)
        for state, autostart in (("RUNNING", False), ("STARTING", False), ("BACKOFF", False), ("FATAL", False),
                                 ("EXITED", False), ("STOPPED", True)):
            self.fenced_refuse(lambda f, s=state, a=autostart: f["supervisor_programs"].append(
                {"name": "wpcaregrid-production-backups", "state": s, "autostart": a}))
        # any wpcaregrid-production-* program counts, even if it is not one of the two declared workers
        self.fenced_refuse(lambda f: f["supervisor_programs"].append(
            {"name": "wpcaregrid-production-extra", "state": "RUNNING", "autostart": False}))

    def test_fenced_observation_shape_is_strict_and_staging_does_not_fence_its_own_jobs(self):
        for bad in (None, {}, {"units": {}, "supervisor_programs": [], "markers": {}},
                    {"units": {}, "supervisor_programs": "x", "markers": {}}):
            data = observation(fenced=bad)
            self.refuse(production(), data, "confinement_observation_invalid" if not isinstance(bad, dict) or "markers" not in bad
                        or bad.get("supervisor_programs") == "x" else "confinement_production_jobs_not_fenced")
        data = observation()
        data["fenced"]["supervisor_programs"] = [{"name": "wpcaregrid-production-default", "state": "STOPPED"}]
        self.refuse(production(), data, "confinement_observation_invalid")
        data = observation(CONTRACT.STAGING_ROLES)
        data["fenced"]["supervisor_programs"] = [{"name": "wpcaregrid-staging-next-default", "state": "RUNNING", "autostart": True}]
        CONTRACT.verify_confinement_observation(CONTRACT.resolve_environment("staging-next"), data)
        data = observation(CONTRACT.STAGING_ROLES)
        data["fenced"] = {}
        self.refuse(CONTRACT.resolve_environment("staging-next"), data, "confinement_observation_invalid")

    def test_peer_class_table_matches_the_acceptance_matrix(self):
        matrix = json.loads((PACKAGE / "acceptance/matrix.json").read_text())
        self.assertEqual({r: CONTRACT.CONFINEMENT_SERVICES[r].unit for r in CONTRACT.CONFINEMENT_SERVICES},
                         {r: matrix["role_units"][r] for r in CONTRACT.CONFINEMENT_SERVICES})
        self.assertEqual({r: CONTRACT.CONFINEMENT_SERVICES[r].apparmor_profile for r in CONTRACT.CONFINEMENT_SERVICES},
                         {r: matrix["role_profiles"][r] for r in CONTRACT.CONFINEMENT_SERVICES})
        self.assertEqual((matrix["role_units"]["jobs-cron"], matrix["role_profiles"]["jobs-cron"]),
                         (CONTRACT.CRON_UNIT, CONTRACT.JOBS_PROFILE))
        (shim,) = matrix["permitted_unconfined_classes"]
        self.assertEqual((shim["unit"], set(shim["comm"])), (CONTRACT.CRON_UNIT, set(CONTRACT.CRON_SHIM_COMM)))
        self.assertEqual({r for r, v in matrix["role_required"].items() if v},
                         {"production", "staging-next", "staging-legacy", "nginx"})

    # --- GO-13 invariant: every same-UID peer is enforce-attached, inventory complete ---
    def test_unconfined_same_uid_peer_is_refused_even_if_all_services_are_attached(self):
        for contract in (CONTRACT.resolve_environment("staging-next"), production()):
            for comm in ("php-fpm8.5", "nginx", "php", "supervisord"):
                data = observation()
                data["same_uid_peers"]["processes"].append(
                    {"pid": 4242, "comm": comm, "label": "unconfined", "unit": "supervisor.service"})
                self.refuse(contract, data, "confinement_same_uid_peer_unconfined")
        data = observation()
        data["same_uid_peers"]["processes"].append(
            {"pid": 4242, "comm": "php-fpm8.5", "label": "wpcaregrid-php-fpm-staging-next (complain)",
             "unit": "php8.5-fpm.service"})
        self.refuse(production(), data, "confinement_same_uid_peer_unconfined")

    def test_accepted_unconfined_set_is_empty_by_default(self):
        self.assertEqual(CONTRACT.ACCEPTED_UNCONFINED_SAME_UID_COMM, frozenset())

    def test_incomplete_or_inconsistent_peer_inventory_is_refused(self):
        data = observation()
        data["same_uid_peers"]["complete"] = False
        self.refuse(production(), data, "confinement_peer_inventory_incomplete")
        data = observation()
        data["same_uid_peers"]["complete"] = 1
        self.refuse(production(), data, "confinement_peer_inventory_incomplete")
        data = observation()
        data["same_uid_peers"]["processes"] = [p for p in data["same_uid_peers"]["processes"]
                                               if "legacy" not in p["label"]]
        self.refuse(production(), data, "confinement_peer_inventory_incomplete")
        data = observation()
        data["same_uid_peers"]["processes"][0]["unit"] = "php8.4-fpm.service"
        self.refuse(production(), data, "confinement_peer_label_outside_unit")
        data = observation()
        del data["same_uid_peers"]
        self.refuse(production(), data, "confinement_observation_invalid")
        data = observation()
        data["same_uid_peers"]["extra"] = 1
        self.refuse(production(), data, "confinement_observation_invalid")
        data = observation()
        data["same_uid_peers"]["processes"][0]["pid"] = "1"
        self.refuse(production(), data, "confinement_observation_invalid")

    def test_inactive_service_needs_no_peer_process(self):
        data = observation()
        data["services"]["production"].update(active=False, process_labels=[])
        data["same_uid_peers"]["processes"] = [p for p in data["same_uid_peers"]["processes"]
                                               if p["unit"] != "wpcaregrid-production-php8.4-fpm.service"]
        CONTRACT.verify_confinement_observation(production(), data)

    def test_contract_check_alone_is_documented_as_insufficient(self):
        doc = ast.get_docstring(ast.parse((ROOT / "scripts/deployment_environment_contract_2_1_3.py").read_text()))
        self.assertIn("separate staged-cutover and live-acceptance host gates", doc)
        runbook = (ROOT / "docs/PHP_FPM_CONFINEMENT_GO13.md").read_text()
        self.assertIn("is not sufficient on its own", runbook)

    def test_acceptance_verifier_and_contract_share_the_same_empty_accepted_set(self):
        verifier = load("wpcg_verify_acceptance_for_contract", "deploy/php-fpm-confinement/1.0.1/acceptance/verify-acceptance.py")
        self.assertEqual(verifier.ACCEPTED_UNCONFINED_SAME_UID_COMM, CONTRACT.ACCEPTED_UNCONFINED_SAME_UID_COMM)

    # --- supersession and honesty ---
    def test_predecessor_is_unchanged_and_still_refuses_shared_identity(self):
        digest = hashlib.sha256((ROOT / "scripts/deployment_environment_contract.py").read_bytes()).hexdigest()
        self.assertEqual(digest, "f22bc2ca1d570d932413f91e7c9dbddf7e2e613d56540418e5ca47dafe72caa1")
        manifest = (ROOT / "deploy/release-manifests/production-deployment-helper-2.1.2.json").read_text()
        self.assertIn(digest, manifest)
        old_config = production_config(schema=1, php_fpm_uid=33, php_fpm_gid=33)
        del old_config["php_fpm_confinement"]
        with self.assertRaises(OLD.EnvironmentRefused) as caught:
            OLD.resolve_environment("production", production_config=old_config)
        self.assertEqual(str(caught.exception), "production_unix_identity_not_isolated")

    def test_module_is_pure_policy_with_no_host_access_and_no_enforcement_claim(self):
        tree = ast.parse((ROOT / "scripts/deployment_environment_contract_2_1_3.py").read_text())
        imported = set()
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                imported.update(alias.name.split(".")[0] for alias in node.names)
            elif isinstance(node, ast.ImportFrom):
                imported.add((node.module or "").split(".")[0])
        self.assertEqual(imported, {"__future__", "re", "dataclasses", "pathlib", "types", "typing"})
        names = {n.id for n in ast.walk(tree) if isinstance(n, ast.Name)}
        self.assertFalse(names & {"open", "exec", "eval", "compile", "__import__"})
        doc = ast.get_docstring(tree)
        self.assertIn("cannot show that a kernel enforces", doc)
        self.assertIn("live-acceptance", doc)
        json.dumps(confinement_policy())  # policy field names are plain JSON data


if __name__ == "__main__":
    unittest.main(verbosity=2)
