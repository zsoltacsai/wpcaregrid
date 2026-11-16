#!/usr/bin/env python3
"""Production helper 2.1.3 (GO-13 Workstream A): synthetic tests only.

Nothing here touches a real host, sudoers, a service, a database or the
network. Host interfaces are replaced by temporary trees and fake runners.
These tests prove source behaviour; they do not prove that a kernel enforces an
AppArmor profile (that is the separate GO-13 live acceptance gate).
"""

from __future__ import annotations

import hashlib
import importlib.util
import json
import os
import py_compile
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import types
import unittest
from dataclasses import replace
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from scripts import release_review_policy_v3 as POLICY  # noqa: E402,F401  (registers nothing)


def _load(name: str, relative: str):
    spec = importlib.util.spec_from_file_location(name, ROOT / relative)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


BACKEND = _load("deployment_backend_v2_1_3_under_test", "scripts/deployment_backend_v2_1_3.py")
CONTRACT = BACKEND._ENVIRONMENT_MODULE
ENTRY = _load("production_helper_2_1_3_entry_under_test", "scripts/wpcaregrid-deploy-production-priv-2_1_3.py")
CONTROLLER = _load("production_controller_2_1_3_under_test", "scripts/production_deploy_controller_2_1_3.py")
INSTALLER = _load("production_installer_2_1_3_under_test", "scripts/install_production_helper_2_1_3.py")
MANIFEST_PATH = ROOT / "deploy/release-manifests/production-deployment-helper-2.1.3.json"
RUNBOOK = ROOT / "docs/PRODUCTION_HELPER_INSTALL_2_1_3.md"

# Protected base fa13359d8277cd3448bc43c471d3ec53e31cdc34. Helper 2.1.2 and the
# shared pinned modules must stay byte-identical.
FROZEN = {
    "scripts/wpcaregrid-deploy-production-priv-2_1_2.py":
        "7d3a7732663f6a0045469dba8524ad7cae8e8e97e5da25b1e1df776c9053542d",
    "scripts/deployment_backend_v2_1_2.py":
        "6c282e65d63ad05c3cfa65eaf586f26120adceeb7f5969ef4f0688192342a116",
    "scripts/production_deploy_controller_2_1_2.py":
        "14d61463898d98b34e6be1b95b0b515e83e011f6b213d2e74e1d5dd8de15ff0b",
    "scripts/install_production_helper_2_1_2.py":
        "a773442e0404a5569ac597193d6a8233a66f0191f0c932a847cd3c2360e854a3",
    "deploy/sudoers/wpcaregrid-production-v2.1.2":
        "c65d382c4f6e86bb591e5b55c6080fa54ffffdbb3cde3b464e8e71aea035dd62",
    "deploy/release-manifests/production-deployment-helper-2.1.2.json":
        "aed57af1e0f06a6c3fae3bff478955f05728418c6643e0111e1494e6494c608b",
    "docs/PRODUCTION_HELPER_INSTALL_2_1_2.md":
        "20b97cf5859d75cd730f268e8384e3ccfdc65f92a490d88bd63137696b867d83",
    "scripts/deployment_transaction_v2.py":
        "ba4c9fd3ea821cb8a1aa5581a74da8c1477b5e30484fc2e09546a26fe60f2d00",
    "scripts/deployment_environment_contract.py":
        "f22bc2ca1d570d932413f91e7c9dbddf7e2e613d56540418e5ca47dafe72caa1",
    "scripts/release_review_policy_v3.py":
        "e50785233d46d7d03e3f734a15fc64764ab85196482789db0c0157cb4307c9db",
    # The staging helper that deploy's existing NOPASSWD grant points at.
    "scripts/wpcaregrid-deploy-priv.py":
        "c61cebec06152fdbf54599fa4296c68d5d5db7c2220dacce38c7104da7b6aba2",
}
SOURCES = {
    "entrypoint_sha256": "scripts/wpcaregrid-deploy-production-priv-2_1_3.py",
    "backend_sha256": "scripts/deployment_backend_v2_1_3.py",
    "transaction_module_sha256": "scripts/deployment_transaction_v2.py",
    "environment_contract_sha256": "scripts/deployment_environment_contract_2_1_3.py",
    "release_review_policy_sha256": "scripts/release_review_policy_v3.py",
}
TRANSFER_UID = 1001
STAGING_RULE = "(root) NOPASSWD: /usr/local/sbin/wpcaregrid-deploy-priv *"


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def file_sha(relative: str) -> str:
    return sha256((ROOT / relative).read_bytes())


def synthetic_pin(file_hashes=None):
    hashes = file_hashes or {path: f"{index + 1:064x}" for index, path in enumerate(CONTRACT.CONFINEMENT_FILES)}
    return CONTRACT.ConfinementPin("1.0.0", "a" * 64, hashes)


PIN = synthetic_pin()


def confinement_policy(pin=PIN, crontab=None):
    return {
        "schema": 2, "package_version": pin.package_version, "www_data_crontab_sha256": crontab,
        "package_sha256sums_sha256": pin.sha256sums_sha256, "files": dict(pin.file_sha256),
        "services": {role: {"unit": b.unit, "apparmor_profile": b.apparmor_profile,
                            "profile_path": b.profile_path, "attachment_path": b.attachment_path}
                     for role, b in CONTRACT.CONFINEMENT_SERVICES.items()},
    }


def production_config(pin=PIN, **changes):
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
        "database_server_uuid_sha256": "c" * 64, "isolated_database": "wpcg_prod_restore_test",
        "isolated_database_host": "127.0.0.1", "isolated_database_port": 3307,
        "isolated_database_uuid_sha256": "d" * 64, "host": "www.wpcaregrid.com", "mysql_version": "8.4",
        "deploy_user": "deploy", "deploy_uid": TRANSFER_UID, "php_version": "8.4",
        "php_fpm_socket": "/run/php/wpcaregrid-production-php8.4-fpm.sock",
        "php_fpm_unit": "wpcaregrid-production-php8.4-fpm.service",
        "php_fpm_uid": 33, "php_fpm_gid": 33,
        "worker_programs": ["wpcaregrid-production-default", "wpcaregrid-production-backups"],
        "scheduler_unit": "wpcaregrid-production-scheduler.timer",
        "maintenance_marker": "/var/lib/wpcaregrid-deploy/production/state/maintenance",
        "worker_fence_marker": "/var/lib/wpcaregrid-deploy/production/state/workers-disabled",
        "scheduler_fence_marker": "/var/lib/wpcaregrid-deploy/production/state/scheduler-paused",
        "privileged_invocation": "root-console-direct",
        "php_fpm_confinement": confinement_policy(pin),
    }
    value.update(changes)
    return value


FPM_EXE = {"production": "/usr/sbin/php-fpm8.4", "staging-next": "/usr/sbin/php-fpm8.5",
           "staging-legacy": "/usr/sbin/php-fpm8.4", "nginx": "/usr/sbin/nginx"}
CRONTAB_PIN = "c" * 64


def peer(pid, *, unit, label, exe, comm="php-fpm", uid=33, gid=33, groups=(), fds=("/dev/null",)):
    return {"pid": pid, "starttime": 100 + pid, "uids": [uid] * 4, "gids": [gid] * 4, "groups": list(groups),
            "ppid": 1, "state": "S",
            "comm": comm, "exe": exe, "argv0": exe, "cmdline_sha256": "e" * 64,
            "cgroup": f"/system.slice/{unit}" if unit else "/user.slice/session-1.scope", "unit": unit,
            "label": label, "fds": list(fds)}


def observation(pin=PIN):
    files = {path: {"sha256": digest, "uid": 0, "gid": 0, "mode": 0o644, "type": "regular", "nlink": 1,
                    "parents_trusted": True} for path, digest in pin.file_sha256.items()}
    services, processes = {}, []
    for offset, role in enumerate(CONTRACT.REQUIRED_ROLES):
        b = CONTRACT.CONFINEMENT_SERVICES[role]
        master = 1000 + offset * 100
        label = f"{b.apparmor_profile} (enforce)"
        active = role != "jobs-scheduler"  # the oneshot scheduler is normally idle
        services[role] = {
            "unit": b.unit, "load_state": "loaded", "active_state": "active" if active else "inactive",
            "main_pid": master if active else 0, "main_label": label if active else "",
            "apparmor_profile_property": b.apparmor_profile, "loaded_profile_mode": "enforce"}
        exe = FPM_EXE.get(role, "/usr/bin/php8.5")
        if active:
            processes += [peer(master + 1, unit=b.unit, label=label, exe=exe),
                          peer(master + 2, unit=b.unit, label=label, exe=exe)]
    return {"schema": 3, "apparmor_enabled": True, "files": files, "services": services,
            "www_data_cron": {"crontab_sha256": None, "cron_d_entries": 0, "wrapper": None,
                              "profile_mode": "enforce"},
            "same_uid_peers": {"complete": True, "incomplete_reasons": [], "processes": processes}}


def with_peer(data, item):
    data["same_uid_peers"]["processes"].append(item)
    return data


def pinned_cron(data):
    data["www_data_cron"] = {"crontab_sha256": CRONTAB_PIN, "cron_d_entries": 0, "profile_mode": "enforce",
                             "wrapper": {"uid": 0, "gid": 0, "mode": 0o755, "type": "regular", "nlink": 1}}
    return data


def resolved(pin=PIN, **changes):
    with patch.object(CONTRACT, "CONFINEMENT_PIN", pin):
        return CONTRACT.resolve_environment("production", production_config=production_config(pin, **changes))


# ==========================================================================
# Unchanged legacy bytes, no production sudoers, manifest/pin consistency
# ==========================================================================

class FrozenAndManifestTests(unittest.TestCase):
    def test_helper_2_1_2_and_shared_modules_are_byte_identical(self):
        for relative, expected in FROZEN.items():
            with self.subTest(path=relative):
                self.assertEqual(file_sha(relative), expected)

    def test_no_production_sudoers_template_exists_for_2_1_3(self):
        self.assertFalse((ROOT / "deploy/sudoers/wpcaregrid-production-v2.1.3").exists())
        self.assertEqual(sorted(p.name for p in (ROOT / "deploy/sudoers").iterdir()), [
            "wpcaregrid-production-v2.0.0", "wpcaregrid-production-v2.1.0",
            "wpcaregrid-production-v2.1.1", "wpcaregrid-production-v2.1.2"])
        manifest = json.loads(MANIFEST_PATH.read_text())
        self.assertIsNone(manifest["production_sudoers"])
        self.assertNotIn("sudoers_template", manifest)
        for relative in ("scripts/install_production_helper_2_1_3.py",
                         "scripts/production_deploy_controller_2_1_3.py",
                         "scripts/wpcaregrid-deploy-production-priv-2_1_3.py"):
            text = (ROOT / relative).read_text()
            self.assertNotIn('"/usr/bin/sudo", "-n", str(HELPER)', text)
            self.assertNotIn("NOPASSWD: NOSETENV", text)
            self.assertNotIn("/etc/sudoers.d/wpcaregrid-production-v2.1.3", text)

    def test_manifest_matches_exact_bytes_and_every_pin(self):
        manifest = json.loads(MANIFEST_PATH.read_text())
        hashes = {key: file_sha(path) for key, path in SOURCES.items()}
        path_keys = {"entrypoint_sha256": "entrypoint", "backend_sha256": "backend",
                     "transaction_module_sha256": "transaction_module",
                     "environment_contract_sha256": "environment_contract",
                     "release_review_policy_sha256": "release_review_policy_module"}
        for key, path in SOURCES.items():
            self.assertEqual(manifest[key], hashes[key], key)
            self.assertEqual(manifest[path_keys[key]], path, key)
        digest = ENTRY.artifact_digest(hashes)
        self.assertEqual(manifest["helper_artifact_sha256"], digest)
        self.assertEqual(manifest["controller_sha256"], file_sha("scripts/production_deploy_controller_2_1_3.py"))
        self.assertEqual(manifest["root_installer_sha256"], file_sha("scripts/install_production_helper_2_1_3.py"))
        self.assertEqual(CONTROLLER.HELPER_ARTIFACT_SHA256, digest)
        self.assertEqual((INSTALLER.ENTRY_SHA256, INSTALLER.BACKEND_SHA256, INSTALLER.TRANSACTION_SHA256,
                          INSTALLER.ENVIRONMENT_SHA256, INSTALLER.REVIEW_POLICY_SHA256),
                         (hashes["entrypoint_sha256"], hashes["backend_sha256"],
                          hashes["transaction_module_sha256"], hashes["environment_contract_sha256"],
                          hashes["release_review_policy_sha256"]))
        self.assertEqual(INSTALLER.HELPER_ARTIFACT_SHA256, digest)
        self.assertEqual(INSTALLER.CONTROLLER_SHA256, manifest["controller_sha256"])
        self.assertEqual(manifest["privileged_invocation"], "root-console-direct")
        self.assertEqual(manifest["transfer_identity"], "deploy")
        self.assertEqual(manifest["php_fpm_confinement_pin"], "UNSET_PENDING_WORKSTREAM_B_APPROVAL")
        runbook = RUNBOOK.read_text()
        for value in (*hashes.values(), digest, manifest["controller_sha256"], manifest["root_installer_sha256"]):
            self.assertIn(f"`{value}`", runbook)

    def test_versions_and_contract_names_agree(self):
        self.assertEqual((ENTRY.VERSION, BACKEND.HELPER_VERSION, CONTROLLER.HELPER_VERSION, INSTALLER.VERSION,
                          CONTRACT.CONTRACT_VERSION), ("2.1.3",) * 5)
        self.assertEqual({ENTRY.CONTRACT, BACKEND.HELPER_CONTRACT, CONTROLLER.HELPER_CONTRACT, INSTALLER.CONTRACT},
                         {"wpcaregrid-deployment-profile-v2.2"})
        self.assertEqual(ENTRY.ENTRYPOINT, INSTALLER.PRODUCTION.entrypoint)
        self.assertEqual(ENTRY.POLICY, INSTALLER.PRODUCTION.policy)
        self.assertEqual(CONTROLLER.HELPER, INSTALLER.PRODUCTION.entrypoint)
        self.assertEqual(set(INSTALLER.POLICY_KEYS), set(CONTRACT.production_required_fields()) | {
            "entrypoint_sha256", "backend_sha256", "transaction_module_sha256", "environment_contract_sha256",
            "release_review_policy_sha256", "helper_artifact_sha256", "phase_b_policy_sha256", "operations"})
        self.assertEqual(INSTALLER.ENVIRONMENT_FIELDS, CONTRACT.environment_fields())

    def test_entrypoint_and_all_new_modules_compile(self):
        # The entrypoint path is kept out of the CI workflow text (CI policy scan).
        with tempfile.TemporaryDirectory() as tmp:
            for relative in (*SOURCES.values(), "scripts/production_deploy_controller_2_1_3.py",
                             "scripts/install_production_helper_2_1_3.py"):
                py_compile.compile(str(ROOT / relative), cfile=str(Path(tmp) / "x.pyc"), doraise=True)

    def test_v3_admission_and_exact_primary_gates_are_unchanged_from_2_1_2(self):
        old = (ROOT / "scripts/deployment_backend_v2_1_2.py").read_text()
        new = (ROOT / "scripts/deployment_backend_v2_1_3.py").read_text()
        def block(text, name):
            start = text.index(f"def {name}(")
            return text[start:text.index("\ndef ", start + 1)]
        for name in ("_verify_primary_ci_and_reviews", "_github_primary_tree", "_verify_agent_release_reviews_v3",
                     "_verify_review_report_artifacts", "_authorized_artifact_corrections", "_github_json",
                     "_github_release_review_ledger", "_verify_artifact_matches_primary", "authorize_release",
                     "_write_receipt", "_successful_receipt"):
            with self.subTest(name=name):
                self.assertEqual(block(old, name), block(new, name))
        self.assertIn('"primary_sha_is_not_latest"', block(new, "_github_primary_tree"))
        self.assertEqual(new.count("_verify_primary_ci_and_reviews("), 3)


# ==========================================================================
# Environment contract 2.1.3
# ==========================================================================

class ContractTests(unittest.TestCase):
    def test_unset_pin_refuses_resolution_and_observation(self):
        self.assertIsNone(CONTRACT.CONFINEMENT_PIN)
        with self.assertRaisesRegex(CONTRACT.EnvironmentRefused, "^confinement_package_not_approved$"):
            CONTRACT.resolve_environment("production", production_config=production_config())
        env = resolved()
        with self.assertRaisesRegex(CONTRACT.EnvironmentRefused, "^confinement_package_not_approved$"):
            CONTRACT.verify_confinement_observation(env, observation())

    def test_malformed_pin_refuses(self):
        bad = CONTRACT.ConfinementPin("1.0.0", "a" * 64, {CONTRACT.CONFINEMENT_FILES[0]: "b" * 64})
        with patch.object(CONTRACT, "CONFINEMENT_PIN", bad), \
                self.assertRaisesRegex(CONTRACT.EnvironmentRefused, "confinement_package_pin_invalid"):
            CONTRACT.resolve_environment("production", production_config=production_config())

    def test_exact_binding_admits_shared_www_data_and_deploy_transfer_identity(self):
        env = resolved()
        self.assertEqual((env.php_fpm_uid, env.php_fpm_gid), (33, 33))
        self.assertEqual((env.deploy_user, env.deploy_uid), ("deploy", TRANSFER_UID))
        self.assertEqual(env.privileged_invocation, "root-console-direct")
        self.assertEqual(env.php_fpm_confinement.roles, ("production", "staging-next", "staging-legacy", "nginx",
                                                         "jobs-supervisor", "jobs-scheduler"))
        self.assertIsNone(env.php_fpm_confinement.www_data_crontab_sha256)

    def test_policy_refusals(self):
        policy = confinement_policy()
        cases = {
            "production_confinement_missing": {k: v for k, v in production_config().items()
                                               if k != "php_fpm_confinement"},
            "production_profile_schema_invalid": dict(production_config(), schema=1),
            "production_privileged_invocation_invalid": dict(production_config(), privileged_invocation="sudo"),
            "production_deploy_identity_invalid": dict(production_config(), deploy_user="deploy-production"),
            "production_php_fpm_identity_must_be_shared_www_data": dict(production_config(), php_fpm_uid=990),
            "production_unix_identity_not_isolated": dict(production_config(), deploy_uid=996),
            "production_confinement_package_mismatch": dict(production_config(), php_fpm_confinement=dict(
                policy, package_sha256sums_sha256="f" * 64)),
            "production_confinement_hash_mismatch": dict(production_config(), php_fpm_confinement=dict(
                policy, files={**policy["files"], CONTRACT.CONFINEMENT_FILES[0]: "e" * 64})),
            "production_confinement_file_set_invalid": dict(production_config(), php_fpm_confinement=dict(
                policy, files={k: v for k, v in list(policy["files"].items())[1:]})),
            "production_confinement_service_binding_invalid": dict(production_config(), php_fpm_confinement=dict(
                policy, services={**policy["services"], "staging-legacy": dict(
                    policy["services"]["staging-legacy"], apparmor_profile="unconfined")})),
            "production_confinement_schema_invalid": dict(production_config(), php_fpm_confinement=dict(
                policy, schema=1)),
            "production_php_version_invalid": dict(production_config(), php_version="8.5"),
        }
        with patch.object(CONTRACT, "CONFINEMENT_PIN", PIN):
            for code, config in cases.items():
                with self.subTest(code=code), self.assertRaisesRegex(CONTRACT.EnvironmentRefused, f"^{code}$"):
                    CONTRACT.resolve_environment("production", production_config=config)
            with self.assertRaisesRegex(CONTRACT.EnvironmentRefused, "environment_unknown"):
                CONTRACT.resolve_environment("staging-next")

    def test_wrong_confinement_identity_cannot_admit_www_data(self):
        # The shared identity is checked only after the binding passes.
        with patch.object(CONTRACT, "CONFINEMENT_PIN", PIN):
            config = production_config(php_fpm_confinement=dict(confinement_policy(), package_version="9.9.9"))
            with self.assertRaisesRegex(CONTRACT.EnvironmentRefused, "production_confinement_package_mismatch"):
                CONTRACT.resolve_environment("production", production_config=config)

    def _refusal(self, mutate, env=None):
        data = observation()
        mutate(data)
        with patch.object(CONTRACT, "CONFINEMENT_PIN", PIN):
            with self.assertRaises(CONTRACT.EnvironmentRefused) as caught:
                CONTRACT.verify_confinement_observation(env or resolved(), data)
        return str(caught.exception)

    def _passes(self, data, env=None):
        with patch.object(CONTRACT, "CONFINEMENT_PIN", PIN):
            CONTRACT.verify_confinement_observation(env or resolved(), data)

    def test_exact_observation_passes(self):
        self._passes(observation())

    def test_missing_mismatched_and_unconfined_observations_refuse(self):
        first = CONTRACT.CONFINEMENT_FILES[0]

        def svc(role, **values):
            return lambda d: d["services"][role].update(values)

        def first_peer(**values):
            return lambda d: d["same_uid_peers"]["processes"][0].update(values)

        cases = {
            "confinement_observation_invalid": lambda d: d.update(schema=2),
            "confinement_apparmor_disabled": lambda d: d.update(apparmor_enabled=False),
            "confinement_file_missing": lambda d: d["files"].pop(first),
            "confinement_observation_extra_file": lambda d: d["files"].update(
                {"/etc/apparmor.d/extra": dict(d["files"][first])}),
            "confinement_file_hash_mismatch": lambda d: d["files"][first].update(sha256="0" * 64),
            "confinement_file_not_root_owned": lambda d: d["files"][first].update(uid=TRANSFER_UID),
            "confinement_file_mode_invalid": lambda d: d["files"][first].update(mode=0o664),
            "confinement_file_not_regular": lambda d: d["files"][first].update(type="other"),
            "confinement_file_parent_untrusted": lambda d: d["files"][first].update(parents_trusted=False),
            "confinement_service_unobserved": lambda d: d["services"].pop("nginx"),
            "confinement_observation_extra_service": lambda d: d["services"].update(
                other=dict(d["services"]["production"])),
            "confinement_service_unit_mismatch": svc("staging-next", unit="php8.4-fpm.service"),
            "confinement_service_not_active": svc("staging-legacy", active_state="inactive", main_label=""),
            "confinement_service_unattached": svc("jobs-scheduler", apparmor_profile_property=""),
            "confinement_service_attachment_fail_open": svc(
                "nginx", apparmor_profile_property="-wpcaregrid-nginx"),
            "confinement_service_profile_mismatch": svc(
                "staging-legacy", apparmor_profile_property="wpcaregrid-php-fpm-staging-next"),
            "confinement_profile_not_enforced": svc("jobs-supervisor", loaded_profile_mode="complain"),
            "confinement_master_not_confined": svc("production", main_label="unconfined"),
            "confinement_peer_inventory_incomplete": lambda d: d["same_uid_peers"].update(
                complete=False, incomplete_reasons=["peer_unreadable"]),
            "confinement_worker_labels_missing": lambda d: d["same_uid_peers"].update(processes=[
                p for p in d["same_uid_peers"]["processes"] if p["unit"] != "nginx.service"]),
            "confinement_same_uid_peer_unconfined": first_peer(label="unconfined"),
            "confinement_process_not_confined": first_peer(label="wpcaregrid-php-fpm-production (complain)"),
            "confinement_peer_executable_unexpected": first_peer(exe="/usr/bin/python3.12"),
            "confinement_peer_cgroup_unexpected": first_peer(cgroup="/system.slice/other.service"),
            "confinement_peer_foreign_descriptor": first_peer(
                fds=["/var/lib/wpcaregrid-deploy/staging-next/shared/.env"]),
            "confinement_peer_label_outside_unit": first_peer(label="wpcaregrid-nginx (enforce)"),
        }
        for code, mutate in cases.items():
            with self.subTest(code=code):
                self.assertEqual(self._refusal(mutate), code)
        self.assertEqual(self._refusal(lambda d: d["same_uid_peers"]["processes"][1].update(
            pid=d["same_uid_peers"]["processes"][0]["pid"])), "confinement_observation_invalid")
        self.assertEqual(self._refusal(lambda d: with_peer(d, peer(9, unit="", label="unconfined", exe="/x",
                                                                    uid=1000, gid=1000))),
                         "confinement_observation_invalid")  # non-www-data entries are not peers

    def test_reproduced_uid33_peers_refuse_independent_of_command_name(self):
        unconfined = {
            "nginx": peer(5001, unit="nginx.service", label="unconfined", exe="/usr/sbin/nginx", comm="nginx"),
            "php-cli": peer(5002, unit="", label="unconfined", exe="/usr/bin/php8.4", comm="php8.4"),
            "queue": peer(5003, unit="supervisor.service", label="unconfined", exe="/usr/bin/php8.5",
                          comm="php8.5"),
            "scheduler": peer(5004, unit="wpcaregrid-staging-next-scheduler.service", label="unconfined",
                              exe="/usr/bin/php8.5", comm="php8.5"),
            "shell": peer(5005, unit="", label="unconfined", exe="/usr/bin/dash", comm="sh"),
            "renamed-fpm": peer(5006, unit="php8.4-fpm.service", label="unconfined",
                                exe="/usr/sbin/php-fpm8.4", comm="kworker/0:1"),
            "gid-only": peer(5007, unit="", label="unconfined", exe="/usr/bin/cat", comm="cat", uid=1000),
            "supplementary-group": peer(5008, unit="", label="unconfined", exe="/usr/bin/bash", comm="bash",
                                        uid=1001, gid=1001, groups=(1001, 33)),
        }
        for name, item in unconfined.items():
            with self.subTest(name=name):
                self.assertEqual(self._refusal(lambda d, item=item: with_peer(d, item)),
                                 "confinement_same_uid_peer_unconfined")

    def test_unknown_service_and_mislabelled_peers_refuse(self):
        cases = (
            (peer(6001, unit="wpcaregrid-unknown.service", label="custom (enforce)", exe="/usr/bin/php8.4"),
             "confinement_same_uid_peer_unknown"),
            (peer(6002, unit="wpcaregrid-unknown.service", label="wpcaregrid-php-fpm-production (enforce)",
                  exe="/usr/sbin/php-fpm8.4"), "confinement_peer_label_outside_unit"),
            (peer(6003, unit="", label="wpcaregrid-php-cli-staging (enforce)", exe="/usr/bin/php8.5"),
             "confinement_peer_label_outside_unit"),
            (peer(6004, unit="nginx.service", label="wpcaregrid-nginx (enforce)", exe="/usr/bin/dash"),
             "confinement_peer_executable_unexpected"),
            (peer(6005, unit="php8.5-fpm.service", label="wpcaregrid-php-fpm-staging-next (enforce)",
                  exe="/usr/sbin/php-fpm8.5", fds=["/var/lib/wpcaregrid-deploy/production/shared/.env"]),
             "confinement_peer_foreign_descriptor"),
            (peer(6006, unit="supervisor.service", label="wpcaregrid-php-cli-staging (enforce)",
                  exe="/usr/bin/php8.5", fds=["/var/lib/wpcaregrid-deploy/production/shared/.env (deleted)"]),
             "confinement_peer_foreign_descriptor"),
            (peer(6007, unit="supervisor.service", label="wpcaregrid-php-cli-staging (kill)", exe="/usr/bin/php8.5"),
             "confinement_process_not_confined"),
        )
        for item, code in cases:
            with self.subTest(code=code, pid=item["pid"]):
                self.assertEqual(self._refusal(lambda d, item=item: with_peer(d, item)), code)

    def test_exact_peer_classes_pass(self):
        data = observation()
        with_peer(data, peer(7001, unit="supervisor.service", label="wpcaregrid-php-cli-staging (enforce)",
                             exe="/usr/bin/php8.4", comm="php"))
        with_peer(data, peer(7002, unit="wpcaregrid-staging-next-scheduler.service",
                             label="wpcaregrid-php-cli-staging (enforce)", exe="/usr/bin/php8.5"))
        with_peer(data, peer(7003, unit="php8.5-fpm.service", label="wpcaregrid-php-fpm-staging-next (enforce)",
                             exe="/usr/lib/php/php-fpm-socket-helper"))
        self._passes(data)

    def test_cron_shim_and_attached_wrapper_only_with_exact_pin(self):
        shim = peer(8001, unit="cron.service", label="unconfined", exe="/usr/sbin/cron", comm="CRON")
        sh = peer(8002, unit="cron.service", label="unconfined", exe="/usr/bin/dash", comm="sh")
        wrapper = peer(8003, unit="cron.service", label="wpcaregrid-php-cli-staging (enforce)",
                       exe="/usr/bin/php8.4", comm="php8.4")
        pinned_env = resolved(php_fpm_confinement=confinement_policy(crontab=CRONTAB_PIN))
        data = pinned_cron(observation())
        for item in (shim, sh, wrapper):
            with_peer(data, dict(item))
        self._passes(data, pinned_env)
        # Without the policy pin, or with an unpinned crontab, every cron class blocks.
        for item in (shim, sh, wrapper):
            with self.subTest(pid=item["pid"]):
                self.assertEqual(self._refusal(lambda d, item=item: with_peer(d, dict(item))),
                                 "confinement_cron_unpinned")
        self.assertEqual(self._refusal(lambda d: pinned_cron(d)), "confinement_cron_unpinned")
        renamed = peer(8004, unit="cron.service", label="unconfined", exe="/usr/bin/php8.4", comm="sh")
        self.assertEqual(self._refusal(lambda d: with_peer(pinned_cron(d), renamed), pinned_env),
                         "confinement_same_uid_peer_unconfined")
        for change, code in (({"cron_d_entries": 1}, "confinement_cron_unpinned"),
                             ({"profile_mode": "complain"}, "confinement_cron_wrapper_unconfined"),
                             ({"wrapper": None}, "confinement_cron_wrapper_unsafe"),
                             ({"crontab_sha256": "d" * 64}, "confinement_cron_unpinned")):
            with self.subTest(code=code):
                self.assertEqual(self._refusal(lambda d, change=change: pinned_cron(d)["www_data_cron"].update(
                    change), pinned_env), code)

    def test_unbound_contract_refuses_observation(self):
        env = replace(resolved(), php_fpm_confinement=None)
        with patch.object(CONTRACT, "CONFINEMENT_PIN", PIN), \
                self.assertRaisesRegex(CONTRACT.EnvironmentRefused, "confinement_not_bound"):
            CONTRACT.verify_confinement_observation(env, observation())

    def test_contract_is_pure_policy(self):
        source = (ROOT / "scripts/deployment_environment_contract_2_1_3.py").read_text()
        for forbidden in ("import os", "import subprocess", "import socket", " open(", "os.read"):
            self.assertNotIn(forbidden, source)


# ==========================================================================
# Backend: root-console caller, binding, confinement gate
# ==========================================================================

def production_profile():
    env = resolved()
    with patch.object(CONTRACT, "CONFINEMENT_PIN", PIN):
        return BACKEND.profile_for_environment(env, helper_artifact_sha256="a" * 64,
                                               phase_b_policy_sha256="b" * 64)


class BackendProfileTests(unittest.TestCase):
    def test_profile_caller_is_root_console_and_transfer_is_deploy(self):
        profile = production_profile()
        self.assertEqual(profile.allowed_uid, 0)
        self.assertEqual(profile.transfer_uid, TRANSFER_UID)
        self.assertEqual(profile.expected_owner_uid, 0)

    def test_bind_refuses_non_production_and_legacy_identities(self):
        env = resolved()
        for change, code in ((dict(name="staging-next"), "environment_contract_invalid"),
                             (dict(deploy_user="deploy-production"), "environment_deploy_identity_invalid"),
                             (dict(privileged_invocation="sudo"), "environment_privileged_invocation_invalid"),
                             (dict(php_fpm_confinement=None), "environment_confinement_unbound")):
            with self.subTest(code=code), self.assertRaisesRegex(BACKEND.DeployRefused, code):
                BACKEND.bind_environment_contract(replace(env, **change))

    def test_non_root_callers_are_refused_by_every_operation(self):
        profile = production_profile()
        for uid in (TRANSFER_UID, 33, 1000):
            with self.subTest(uid=uid):
                self.assertEqual(BACKEND.stage_artifact(profile, invoking_uid=uid, application_sha="a" * 40,
                                                        artifact_sha256="b" * 64).reason, "caller_identity_invalid")
                self.assertEqual(BACKEND.deploy_release(profile, invoking_uid=uid, application_sha="a" * 40,
                                                        artifact_sha256="b" * 64).reason, "caller_identity_invalid")
                self.assertEqual(BACKEND.authorize_release(profile, invoking_uid=uid,
                                                           application_sha="a" * 40)["reason"],
                                 "caller_identity_invalid")
                with self.assertRaisesRegex(BACKEND.DeployRefused, "caller_identity_invalid"):
                    BACKEND.preflight(profile, invoking_uid=uid)

    def test_preflight_gate_refuses_bad_observation_before_any_host_state(self):
        profile = production_profile()
        bad = with_peer(observation(), peer(4242, unit="", label="unconfined", exe="/usr/bin/dash", comm="sh"))
        with patch.object(CONTRACT, "CONFINEMENT_PIN", PIN), \
                patch.object(BACKEND, "_collect_php_fpm_confinement_observation", return_value=bad), \
                patch.object(BACKEND, "_verify_directory_chain") as chain, \
                self.assertRaisesRegex(BACKEND.DeployRefused, "^confinement_same_uid_peer_unconfined$"):
            BACKEND.preflight(profile, invoking_uid=0)
        chain.assert_not_called()

    def test_preflight_gate_refuses_while_pin_unset(self):
        profile = production_profile()
        with patch.object(BACKEND, "_verify_directory_chain") as chain, \
                self.assertRaisesRegex(BACKEND.DeployRefused, "^confinement_package_not_approved$"):
            BACKEND.preflight(profile, invoking_uid=0)
        chain.assert_not_called()

    def test_preflight_gate_passes_exact_observation_then_reaches_host_checks(self):
        profile = production_profile()
        with patch.object(CONTRACT, "CONFINEMENT_PIN", PIN), \
                patch.object(BACKEND, "_collect_php_fpm_confinement_observation", return_value=observation()), \
                patch.object(BACKEND, "_verify_directory_chain",
                             side_effect=BACKEND.DeployRefused("directory_unavailable")):
            with self.assertRaisesRegex(BACKEND.DeployRefused, "^directory_unavailable$"):
                BACKEND.preflight(profile, invoking_uid=0)

    def test_stage_and_deploy_run_the_gate(self):
        profile = production_profile()
        bad = with_peer(observation(), peer(5000, unit="nginx.service", label="unconfined",
                                            exe="/usr/sbin/nginx", comm="nginx"))
        with patch.object(CONTRACT, "CONFINEMENT_PIN", PIN), \
                patch.object(BACKEND, "_collect_php_fpm_confinement_observation", return_value=bad), \
                patch.object(BACKEND, "_verify_directory_chain"), \
                patch.object(BACKEND, "_lstat", return_value=SimpleNamespace(
                    st_mode=stat.S_IFDIR | 0o700, st_uid=TRANSFER_UID)):
            self.assertEqual(BACKEND.stage_artifact(profile, invoking_uid=0, application_sha="a" * 40,
                                                    artifact_sha256="b" * 64).reason,
                             "confinement_same_uid_peer_unconfined")
            self.assertEqual(BACKEND.deploy_release(profile, invoking_uid=0, application_sha="a" * 40,
                                                    artifact_sha256="b" * 64).reason,
                             "confinement_same_uid_peer_unconfined")

    def test_application_php_runs_as_www_data_under_the_production_profile(self):
        profile = production_profile()
        calls = []
        with patch.object(BACKEND.os, "geteuid", return_value=0), \
                patch.object(BACKEND.pwd, "getpwuid", return_value=SimpleNamespace(pw_name="www-data")), \
                patch.object(BACKEND.grp, "getgrgid", return_value=SimpleNamespace(gr_name="www-data")), \
                patch.object(BACKEND, "_run_fixed", side_effect=lambda argv, **kw: calls.append(argv) or b"{}"):
            BACKEND._run_as_php_fpm(profile, ["/usr/bin/php8.4", "-v"], cwd=Path("/"), timeout=5)
        self.assertEqual(calls[0], ["/usr/sbin/runuser", "--user", "www-data", "--group", "www-data", "--",
                                    "/usr/bin/aa-exec", "-p", "wpcaregrid-php-fpm-production", "--",
                                    "/usr/bin/php8.4", "-v"])


# ==========================================================================
# Backend: untrusted transfer input (no-follow, race, copy into root inbox)
# ==========================================================================

class TransferInputTests(unittest.TestCase):
    APP = "a" * 40

    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp())
        self.incoming = self.tmp / "data" / "incoming"
        self.inbox = self.tmp / "data" / "inbox"
        self.incoming.mkdir(parents=True, mode=0o700)
        self.incoming.chmod(0o700)
        self.inbox.mkdir(mode=0o755)
        self.inbox.chmod(0o755)
        self.payload = b"release archive bytes\n" * 100
        self.source = self.incoming / f"{self.APP}.tar"
        self.write(self.source, self.payload)
        uid = os.getuid()
        self.profile = SimpleNamespace(
            incoming_dir=self.incoming, inbox_dir=self.inbox, transfer_uid=uid, expected_owner_uid=uid)
        self.chain = patch.object(BACKEND, "_verify_directory_chain")
        self.chain.start()

    def tearDown(self):
        self.chain.stop()
        shutil.rmtree(self.tmp)

    @staticmethod
    def write(path, raw, mode=0o600):
        path.write_bytes(raw)
        path.chmod(mode)

    def copy(self, expected=None):
        destination = self.inbox / f"{self.APP}.tar"
        BACKEND._copy_transfer_input(self.profile, f"{self.APP}.tar", destination,
                                     expected_sha256=expected or sha256(self.payload), application_sha=self.APP)
        return destination

    def refused(self, code, expected=None):
        with self.assertRaisesRegex(BACKEND.DeployRefused, f"^{code}$"):
            self.copy(expected)
        self.assertEqual(os.listdir(self.inbox), [], "no inbox residue after refusal")

    def test_exact_bytes_are_copied_into_a_private_inbox_file(self):
        destination = self.copy()
        info = os.lstat(destination)
        self.assertEqual(destination.read_bytes(), self.payload)
        self.assertEqual((stat.S_IMODE(info.st_mode), info.st_nlink), (0o600, 1))
        self.assertNotEqual(info.st_ino, os.lstat(self.source).st_ino)
        self.assertEqual(os.listdir(self.inbox), [destination.name])

    def test_symlink_source_is_never_followed(self):
        target = self.tmp / "elsewhere"
        self.write(target, self.payload)
        self.source.unlink()
        self.source.symlink_to(target)
        self.refused("transfer_input_not_regular")

    def test_fifo_source_is_refused_without_blocking(self):
        self.source.unlink()
        os.mkfifo(self.source, 0o600)
        self.refused("transfer_input_not_regular")

    def test_mode_owner_link_count_size_and_hash_are_enforced(self):
        self.source.chmod(0o644)
        self.refused("transfer_input_mode_invalid")
        self.source.chmod(0o600)
        os.link(self.source, self.tmp / "second-link")
        self.refused("transfer_input_link_count_invalid")
        (self.tmp / "second-link").unlink()
        self.profile.transfer_uid = os.getuid() + 1
        with self.assertRaisesRegex(BACKEND.DeployRefused, "incoming_directory_metadata_invalid"):
            self.copy()
        self.profile.transfer_uid = os.getuid()
        self.refused("artifact_hash_mismatch", expected="0" * 64)
        self.write(self.source, b"")
        self.refused("transfer_input_size_invalid")

    def test_missing_and_badly_named_inputs_are_refused(self):
        self.source.unlink()
        self.refused("transfer_input_missing")
        with self.assertRaisesRegex(BACKEND.DeployRefused, "transfer_input_name_invalid"):
            BACKEND._copy_transfer_input(self.profile, "../x.tar", self.inbox / "x",
                                         expected_sha256="0" * 64, application_sha=self.APP)

    def test_incoming_directory_symlink_or_swap_is_refused(self):
        real = self.tmp / "data" / "real-incoming"
        self.incoming.rename(real)
        self.incoming.symlink_to(real)
        with self.assertRaisesRegex(BACKEND.DeployRefused, "incoming_directory_metadata_invalid"):
            self.copy()
        self.incoming.unlink()
        real.rename(self.incoming)
        original_open = os.open

        def swapping_open(path, flags, *args, **kwargs):
            if Path(str(path)) == self.incoming and kwargs.get("dir_fd") is None:
                self.incoming.rename(self.tmp / "data" / "moved")
                (self.tmp / "data" / "incoming").mkdir(mode=0o700)
            return original_open(path, flags, *args, **kwargs)

        with patch.object(BACKEND.os, "open", side_effect=swapping_open):
            with self.assertRaisesRegex(BACKEND.DeployRefused, "incoming_directory_changed_during_open"):
                self.copy()

    def test_file_swapped_between_lstat_and_open_is_refused(self):
        original_open = os.open

        def swapping_open(path, flags, *args, **kwargs):
            if path == f"{self.APP}.tar" and kwargs.get("dir_fd") is not None:
                os.unlink(path, dir_fd=kwargs["dir_fd"])
                fd = original_open(path, os.O_WRONLY | os.O_CREAT, 0o600, dir_fd=kwargs["dir_fd"])
                os.write(fd, self.payload)
                os.close(fd)
            return original_open(path, flags, *args, **kwargs)

        with patch.object(BACKEND.os, "open", side_effect=swapping_open):
            self.refused("transfer_input_changed_during_open")

    def test_symlink_swapped_in_after_lstat_is_not_followed(self):
        target = self.tmp / "secret"
        self.write(target, self.payload)
        original_open = os.open

        def swapping_open(path, flags, *args, **kwargs):
            if path == f"{self.APP}.tar" and kwargs.get("dir_fd") is not None:
                os.unlink(path, dir_fd=kwargs["dir_fd"])
                os.symlink(target, path, dir_fd=kwargs["dir_fd"])
            return original_open(path, flags, *args, **kwargs)

        with patch.object(BACKEND.os, "open", side_effect=swapping_open):
            self.refused("transfer_input_changed_during_open")

    def test_bytes_rewritten_during_read_are_refused(self):
        original_read = os.read
        state = {"done": False}

        def mutating_read(fd, size):
            chunk = original_read(fd, size)
            if not state["done"] and chunk:
                state["done"] = True
                with open(self.source, "r+b") as stream:
                    stream.write(b"X")
                os.utime(self.source, ns=(1, 1))
            return chunk

        with patch.object(BACKEND.os, "read", side_effect=mutating_read):
            self.refused("transfer_input_changed_during_read")

    def test_existing_inbox_file_is_never_overwritten(self):
        destination = self.inbox / f"{self.APP}.tar"
        self.write(destination, b"other", mode=0o600)
        with self.assertRaisesRegex(BACKEND.DeployRefused, "staged_artifact_already_exists"):
            self.copy()
        self.assertEqual(destination.read_bytes(), b"other")


class ConfinementCollectorTests(unittest.TestCase):
    """Root collector over a fake /proc, cgroup, cron and AppArmor tree."""

    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp())
        self.files_root = self.tmp / "files"
        hashes = {}
        for path in CONTRACT.CONFINEMENT_FILES:
            target = self.files_root / path.lstrip("/")
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(f"artifact {path}\n".encode())
            target.chmod(0o644)
            hashes[path] = sha256(target.read_bytes())
        self.pin = synthetic_pin(hashes)
        self.proc = self.tmp / "proc"
        self.proc.mkdir()
        self.units = {}
        for offset, role in enumerate(CONTRACT.REQUIRED_ROLES):
            binding = CONTRACT.CONFINEMENT_SERVICES[role]
            label = f"{binding.apparmor_profile} (enforce)"
            active = role != "jobs-scheduler"
            master = 2000 + offset * 10
            self.units[binding.unit] = {"Id": binding.unit, "LoadState": "loaded",
                                        "ActiveState": "active" if active else "inactive",
                                        "MainPID": str(master if active else 0),
                                        "AppArmorProfile": binding.apparmor_profile}
            if not active:
                continue
            exe = FPM_EXE.get(role, "/usr/bin/php8.5")
            self.process(master, uid=0, unit=binding.unit, label=label, exe=exe, comm="master")
            for worker in (master + 1, master + 2):
                self.process(worker, unit=binding.unit, label=label, exe=exe, ppid=master)
        self.process(1, uid=0, unit="", label="unconfined", exe="/usr/lib/systemd/systemd", comm="systemd")
        self.profiles = self.tmp / "profiles"
        self.profiles.write_text("".join(f"{b.apparmor_profile} (enforce)\n"
                                         for b in CONTRACT.CONFINEMENT_SERVICES.values()) + "other (complain)\n")
        self.enabled = self.tmp / "enabled"
        self.enabled.write_text("Y\n")
        (self.tmp / "cron.d").mkdir()
        (self.tmp / "cron.d" / "php").write_text("09,39 * * * * root [ -x /usr/lib/php/sessionclean ]\n")
        (self.tmp / "crontab").write_text("SHELL=/bin/sh\n17 * * * * root cd / && run-parts --report /etc/cron.hourly\n")

    def tearDown(self):
        shutil.rmtree(self.tmp, ignore_errors=True)

    def process(self, pid, *, unit, label, exe, uid=33, gid=None, ppid=1, comm="php-fpm8.4", state="S",
                starttime=None, fds=("/dev/null",), groups=()):
        gid = (33 if uid == 33 else uid) if gid is None else gid
        base = self.proc / str(pid)
        if base.exists():
            shutil.rmtree(base)
        (base / "attr" / "apparmor").mkdir(parents=True)
        (base / "fd").mkdir()
        (base / "attr" / "apparmor" / "current").write_text(label + "\n")
        (base / "status").write_text(f"Name:\t{comm}\nState:\t{state}\nPPid:\t{ppid}\n"
                                     f"Uid:\t{uid}\t{uid}\t{uid}\t{uid}\nGid:\t{gid}\t{gid}\t{gid}\t{gid}\n"
                                     f"Groups:\t{' '.join(str(g) for g in groups)}\n")
        start = 1000 + pid if starttime is None else starttime
        fields = [state, str(ppid)] + ["0"] * 17 + [str(start)] + ["0"] * 10
        (base / "stat").write_text(f"{pid} ({comm}) " + " ".join(fields) + "\n")
        (base / "comm").write_text(comm + "\n")
        (base / "cmdline").write_bytes(exe.encode() + b"\x00--secret-free\x00")
        (base / "cgroup").write_text(f"0::/system.slice/{unit}\n" if unit else "0::/user.slice/session-4.scope\n")
        os.symlink(exe, base / "exe")
        for index, target in enumerate(fds):
            os.symlink(target, base / "fd" / str(index))
        return base

    def collect(self):
        real_observe = BACKEND._observe_confinement_file

        def run_fixed(argv, **_kwargs):
            return "".join(f"{k}={v}\n" for k, v in self.units[argv[-1]].items()).encode()

        with patch.object(CONTRACT, "CONFINEMENT_PIN", self.pin), \
                patch.object(BACKEND, "CONFINEMENT_PROC_ROOT", self.proc), \
                patch.object(BACKEND, "CONFINEMENT_APPARMOR_PROFILES", self.profiles), \
                patch.object(BACKEND, "CONFINEMENT_APPARMOR_ENABLED", self.enabled), \
                patch.object(BACKEND, "CONFINEMENT_CRONTAB", self.tmp / "www-data-crontab"), \
                patch.object(BACKEND, "CONFINEMENT_SYSTEM_CRONTAB", self.tmp / "crontab"), \
                patch.object(BACKEND, "CONFINEMENT_CRON_D", self.tmp / "cron.d"), \
                patch.object(BACKEND, "CONFINEMENT_CRON_WRAPPER", self.tmp / "wrapper"), \
                patch.object(BACKEND, "_run_fixed", side_effect=run_fixed), \
                patch.object(BACKEND, "_observe_confinement_file",
                             side_effect=lambda p: real_observe(self.files_root / str(p).lstrip("/"))):
            return BACKEND._collect_php_fpm_confinement_observation(None)

    def verify(self, data, crontab=None):
        # Synthetic files are owned by the test user under /tmp; model root ownership.
        for record in data["files"].values():
            record.update(uid=0, gid=0, parents_trusted=True)
        env = resolved(self.pin, php_fpm_confinement=confinement_policy(self.pin, crontab))
        with patch.object(CONTRACT, "CONFINEMENT_PIN", self.pin):
            CONTRACT.verify_confinement_observation(env, data)

    def refusal(self, data=None, crontab=None):
        with self.assertRaises(CONTRACT.EnvironmentRefused) as caught:
            self.verify(data if data is not None else self.collect(), crontab)
        return str(caught.exception)

    def test_collected_evidence_matches_the_binding(self):
        data = self.collect()
        self.assertEqual(data["schema"], 3)
        self.assertEqual(set(data["files"]), set(CONTRACT.CONFINEMENT_FILES))
        for path, record in data["files"].items():
            self.assertEqual((record["type"], record["mode"], record["nlink"], record["sha256"]),
                             ("regular", 0o644, 1, self.pin.file_sha256[path]))
        peers = data["same_uid_peers"]
        self.assertTrue(peers["complete"])
        # Root masters and root/system processes are not www-data peers; every www-data worker is.
        self.assertEqual(len(peers["processes"]), 2 * 5)
        first = peers["processes"][0]
        self.assertEqual((first["uids"], first["gids"], first["unit"], first["exe"], first["fds"]),
                         ([33] * 4, [33] * 4, first["cgroup"].rsplit("/", 1)[1], first["exe"], ["/dev/null"]))
        self.assertEqual(data["services"]["production"]["main_label"], "wpcaregrid-php-fpm-production (enforce)")
        self.assertEqual(data["www_data_cron"], {"crontab_sha256": None, "cron_d_entries": 0, "wrapper": None,
                                                 "profile_mode": "enforce"})
        self.verify(data)

    def test_reproduced_unconfined_uid33_peers_are_inventoried_and_refused(self):
        cases = {
            "nginx": dict(unit="nginx.service", exe="/usr/sbin/nginx", comm="nginx"),
            "php-cli": dict(unit="", exe="/usr/bin/php8.4", comm="php8.4"),
            "queue": dict(unit="supervisor.service", exe="/usr/bin/php8.5", comm="php8.5"),
            "sh": dict(unit="", exe="/usr/bin/dash", comm="sh"),
            "renamed-fpm": dict(unit="php8.4-fpm.service", exe="/usr/sbin/php-fpm8.4", comm="systemd-journal"),
            "gid-33-only": dict(unit="", exe="/usr/bin/cat", comm="cat", uid=1000, gid=33),
            "deploy-in-www-data-group": dict(unit="", exe="/usr/bin/bash", comm="bash", uid=1001, gid=1001,
                                             groups=(1001, 33)),
        }
        for name, spec in cases.items():
            with self.subTest(name=name):
                self.process(9000, label="unconfined", **spec)
                data = self.collect()
                self.assertIn(9000, [p["pid"] for p in data["same_uid_peers"]["processes"]])
                self.assertEqual(self.refusal(data), "confinement_same_uid_peer_unconfined")
                shutil.rmtree(self.proc / "9000")

    def test_unknown_service_with_its_own_profile_is_refused(self):
        self.process(9100, unit="wpcaregrid-unknown.service", label="custom (enforce)", exe="/usr/bin/php8.4")
        self.assertEqual(self.refusal(), "confinement_same_uid_peer_unknown")

    def test_unreadable_live_pid_holds(self):
        base = self.process(9200, unit="", label="unconfined", exe="/usr/bin/dash", comm="sh")
        real = BACKEND._read_proc_bytes

        def denied(path, **kwargs):
            if Path(path) == base / "status":
                raise PermissionError(13, "denied")
            return real(path, **kwargs)

        with patch.object(BACKEND, "_read_proc_bytes", side_effect=denied):
            data = self.collect()
        self.assertEqual((data["same_uid_peers"]["complete"], data["same_uid_peers"]["incomplete_reasons"]),
                         (False, ["peer_unreadable"]))
        self.assertEqual(self.refusal(data), "confinement_peer_inventory_incomplete")

    def test_unparsable_live_pid_holds(self):
        base = self.process(9250, unit="", label="unconfined", exe="/usr/bin/dash", comm="sh")
        (base / "status").write_text("Name:\tsh\nUid:\tgarbage\n")
        data = self.collect()
        self.assertEqual(data["same_uid_peers"]["incomplete_reasons"], ["peer_status_unparsable"])
        self.assertEqual(self.refusal(data), "confinement_peer_inventory_incomplete")

    def test_missing_proc_entry_on_a_live_pid_holds_not_skips(self):
        base = self.process(9300, unit="", label="unconfined", exe="/usr/bin/dash", comm="sh")
        (base / "exe").unlink()  # exe unreadable while /proc/<pid> still exists
        data = self.collect()
        self.assertEqual(data["same_uid_peers"]["incomplete_reasons"], ["peer_unreadable"])

    def test_pid_that_really_exited_mid_read_is_skipped(self):
        base = self.process(9400, unit="", label="unconfined", exe="/usr/bin/dash", comm="sh")
        real = BACKEND._read_proc_bytes

        def vanish(path, **kwargs):
            if Path(path) == base / "comm":
                shutil.rmtree(base)
                raise FileNotFoundError(2, "gone")
            return real(path, **kwargs)

        with patch.object(BACKEND, "_read_proc_bytes", side_effect=vanish):
            data = self.collect()
        self.assertTrue(data["same_uid_peers"]["complete"])
        self.assertNotIn(9400, [p["pid"] for p in data["same_uid_peers"]["processes"]])
        self.verify(data)

    def test_pid_reused_during_read_holds(self):
        base = self.process(9500, unit="", label="unconfined", exe="/usr/bin/dash", comm="sh")
        real = BACKEND._read_proc_bytes
        state = {"done": False}

        def reuse(path, **kwargs):
            if Path(path) == base / "cgroup" and not state["done"]:
                state["done"] = True
                self.process(9500, unit="php8.5-fpm.service", label="wpcaregrid-php-fpm-staging-next (enforce)",
                             exe="/usr/sbin/php-fpm8.5", starttime=999999)
            return real(path, **kwargs)

        with patch.object(BACKEND, "_read_proc_bytes", side_effect=reuse):
            data = self.collect()
        self.assertEqual(data["same_uid_peers"]["incomplete_reasons"], ["peer_pid_reused"])
        self.assertEqual(self.refusal(data), "confinement_peer_inventory_incomplete")

    def test_pid_reused_after_a_read_error_holds(self):
        base = self.process(9550, unit="", label="unconfined", exe="/usr/bin/dash", comm="sh")
        real = BACKEND._read_proc_bytes

        def fail_then_reuse(path, **kwargs):
            if Path(path) == base / "comm":
                self.process(9550, unit="", label="unconfined", exe="/usr/bin/dash", starttime=4242)
                raise FileNotFoundError(2, "gone")
            return real(path, **kwargs)

        with patch.object(BACKEND, "_read_proc_bytes", side_effect=fail_then_reuse):
            data = self.collect()
        self.assertEqual(data["same_uid_peers"]["incomplete_reasons"], ["peer_pid_reused"])

    def test_pids_appearing_during_the_scan_are_inventoried(self):
        real = BACKEND._inventory_pid
        state = {"spawned": False}

        def spawn(pid):
            if not state["spawned"]:
                state["spawned"] = True
                self.process(9600, unit="", label="unconfined", exe="/usr/bin/dash", comm="sh")
            return real(pid)

        with patch.object(BACKEND, "_inventory_pid", side_effect=spawn):
            data = self.collect()
        self.assertIn(9600, [p["pid"] for p in data["same_uid_peers"]["processes"]])
        self.assertEqual(self.refusal(data), "confinement_same_uid_peer_unconfined")

    def test_endless_pid_churn_holds(self):
        real = BACKEND._inventory_pid
        counter = {"next": 20000}

        def churn(pid):
            counter["next"] += 1
            self.process(counter["next"], uid=0, unit="", label="unconfined", exe="/usr/bin/true", comm="true")
            return real(pid)

        with patch.object(BACKEND, "_inventory_pid", side_effect=churn):
            data = self.collect()
        self.assertIn("peer_set_unstable", data["same_uid_peers"]["incomplete_reasons"])

    def test_zombie_is_not_a_peer_but_a_live_non_www_data_unreadable_pid_holds(self):
        base = self.process(9700, unit="", label="unconfined", exe="/usr/bin/dash", comm="sh", state="Z")
        (base / "exe").unlink()
        self.assertTrue(self.collect()["same_uid_peers"]["complete"])
        other = self.process(9701, uid=1000, gid=1000, unit="", label="unconfined", exe="/usr/bin/vim")
        (other / "status").chmod(0)
        if os.geteuid() != 0:
            self.assertEqual(self.collect()["same_uid_peers"]["incomplete_reasons"], ["peer_unreadable"])

    def test_foreign_descriptor_and_mislabelled_unit_are_refused(self):
        self.process(9800, unit="php8.5-fpm.service", label="wpcaregrid-php-fpm-staging-next (enforce)",
                     exe="/usr/sbin/php-fpm8.5", fds=("/var/lib/wpcaregrid-deploy/production/shared/.env",))
        self.assertEqual(self.refusal(), "confinement_peer_foreign_descriptor")
        self.process(9800, unit="nginx.service", label="wpcaregrid-php-fpm-production (enforce)",
                     exe="/usr/sbin/php-fpm8.4")
        self.assertEqual(self.refusal(), "confinement_peer_label_outside_unit")

    def test_master_label_fail_open_attachment_and_missing_files(self):
        (self.proc / "2000" / "attr" / "apparmor" / "current").write_text("unconfined\n")
        self.assertEqual(self.refusal(), "confinement_master_not_confined")
        (self.proc / "2000" / "attr" / "apparmor" / "current").write_text("wpcaregrid-php-fpm-production (enforce)\n")
        self.units["php8.4-fpm.service"]["AppArmorProfile"] = "-wpcaregrid-php-fpm-staging-legacy"
        self.assertEqual(self.refusal(), "confinement_service_attachment_fail_open")
        self.units["php8.4-fpm.service"]["AppArmorProfile"] = "wpcaregrid-php-fpm-staging-legacy"
        (self.files_root / CONTRACT.CONFINEMENT_FILES[2].lstrip("/")).unlink()
        self.assertEqual(self.refusal(), "confinement_file_missing")

    def test_cron_state_collection_and_pin(self):
        (self.tmp / "www-data-crontab").write_bytes(b"* * * * * /usr/local/bin/wpcaregrid-scheduler\n")
        wrapper = self.tmp / "wrapper"
        wrapper.write_text("#!/bin/sh\n")
        wrapper.chmod(0o755)
        self.process(9900, unit="cron.service", label="unconfined", exe="/usr/sbin/cron", comm="CRON")
        data = self.collect()
        cron = data["www_data_cron"]
        pin = sha256(b"* * * * * /usr/local/bin/wpcaregrid-scheduler\n")
        self.assertEqual((cron["crontab_sha256"], cron["cron_d_entries"], cron["wrapper"]["type"]),
                         (pin, 0, "regular"))
        cron["wrapper"].update(uid=0, gid=0)
        self.verify(data, crontab=pin)
        self.assertEqual(self.refusal(json.loads(json.dumps(data))), "confinement_cron_unpinned")
        (self.tmp / "cron.d" / "evil").write_text("* * * * * www-data /tmp/x\n@reboot 33 /tmp/y\nbroken line\n")
        self.assertEqual(self.collect()["www_data_cron"]["cron_d_entries"], 3)


# ==========================================================================
# Entrypoint: root console only, exact pins, refuse while pin unset
# ==========================================================================

class EntrypointTests(unittest.TestCase):
    def test_root_console_requirement(self):
        ok = dict(uid=0, euid=0, gid=0, egid=0, isolated=1)
        ENTRY._require_root_console({"PATH": "/usr/bin"}, **ok)
        for env, ids, code in (
                ({"SUDO_UID": "1001"}, ok, "delegated_invocation_refused"),
                ({"SUDO_USER": "deploy"}, ok, "delegated_invocation_refused"),
                ({"PKEXEC_UID": "1001"}, ok, "delegated_invocation_refused"),
                ({}, dict(ok, uid=TRANSFER_UID), "root_console_required"),
                ({}, dict(ok, euid=TRANSFER_UID), "root_console_required"),
                ({}, dict(ok, gid=TRANSFER_UID), "root_console_required"),
                ({}, dict(ok, isolated=0), "isolated_interpreter_required")):
            with self.subTest(code=code, env=env), self.assertRaisesRegex(ENTRY.Refused, f"^{code}$"):
                ENTRY._require_root_console(env, **ids)

    def test_sudo_invocation_by_deploy_never_reaches_profile_loading(self):
        stderr = []
        with patch.dict(os.environ, {"SUDO_UID": str(TRANSFER_UID), "SUDO_USER": "deploy"}, clear=False), \
                patch.object(ENTRY, "_load_profile") as load, \
                patch.object(ENTRY.sys.stderr, "write", side_effect=stderr.append):
            self.assertEqual(ENTRY.main(["preflight"]), 3)
            load.assert_not_called()
        self.assertEqual(stderr, ["DEPLOYMENT=HOLD;CODE=delegated_invocation_refused\n"])

    def test_operation_parsing_and_stale_shapes(self):
        self.assertEqual(ENTRY._parse_operation(["authorize", "a" * 40]), ("authorize", "a" * 40, None))
        for argv in (["authorize", "A" * 40], ["stage", "a" * 40], ["deploy", "a" * 39, "b" * 64],
                     ["preflight", "x"], ["production"], []):
            with self.subTest(argv=argv), self.assertRaisesRegex(ENTRY.Refused, "operation_not_allowed"):
                ENTRY._parse_operation(argv)

    def _fixture(self, environment_raw=None):
        files = {
            ENTRY.ENTRYPOINT: (ROOT / SOURCES["entrypoint_sha256"]).read_bytes(),
            ENTRY.BACKEND: (ROOT / SOURCES["backend_sha256"]).read_bytes(),
            ENTRY.TRANSACTION: (ROOT / SOURCES["transaction_module_sha256"]).read_bytes(),
            ENTRY.ENVIRONMENT_CONTRACT: environment_raw or (ROOT / SOURCES["environment_contract_sha256"]).read_bytes(),
            ENTRY.REVIEW_POLICY: (ROOT / SOURCES["release_review_policy_sha256"]).read_bytes(),
        }
        hashes = {
            "entrypoint_sha256": sha256(files[ENTRY.ENTRYPOINT]), "backend_sha256": sha256(files[ENTRY.BACKEND]),
            "transaction_module_sha256": sha256(files[ENTRY.TRANSACTION]),
            "environment_contract_sha256": sha256(files[ENTRY.ENVIRONMENT_CONTRACT]),
            "release_review_policy_sha256": sha256(files[ENTRY.REVIEW_POLICY]),
        }
        policy = dict(production_config(), **hashes, helper_artifact_sha256=ENTRY.artifact_digest(hashes),
                      phase_b_policy_sha256="e" * 64, operations=list(ENTRY.OPERATIONS))

        def run(policy_value, argv=("preflight",)):
            served = dict(files)
            served[ENTRY.POLICY] = json.dumps(policy_value).encode()
            saved = {name: sys.modules.get(name) for name in (
                ENTRY.ENVIRONMENT_MODULE_NAME, "_wpcg_release_review_policy_v3",
                "_wpcg_deployment_transaction_v2", "_wpcg_deployment_backend_v2_1_3")}
            try:
                with patch.object(ENTRY, "_read_trusted", side_effect=lambda path, **kw: served[Path(path)]), \
                        patch.object(ENTRY, "_trusted_parent"), \
                        patch.object(ENTRY.pwd, "getpwuid", return_value=SimpleNamespace(pw_name="deploy")), \
                        patch.object(ENTRY.sys, "argv", [str(ENTRY.ENTRYPOINT), *argv]):
                    return ENTRY._load_profile(list(argv))
            finally:
                for name, module in saved.items():
                    if module is None:
                        sys.modules.pop(name, None)
                    else:
                        sys.modules[name] = module
        return policy, run

    def test_enabled_policy_with_every_pin_correct_still_refuses_while_confinement_pin_unset(self):
        policy, run = self._fixture()
        with self.assertRaisesRegex(ENTRY.Refused, "^confinement_package_not_approved$"):
            run(policy)

    def test_artifact_identity_digest_schema_and_transfer_identity_refusals(self):
        policy, run = self._fixture()
        cases = (
            (dict(policy, backend_sha256="0" * 64), "production_artifact_identity_mismatch"),
            (dict(policy, helper_artifact_sha256="0" * 64), "production_helper_manifest_mismatch"),
            (dict(policy, schema=1), "production_profile_disabled_or_invalid"),
            (dict(policy, enabled=False), "production_profile_disabled_or_invalid"),
            ({k: v for k, v in policy.items() if k != "privileged_invocation"}, "production_policy_schema_invalid"),
            (dict(policy, deploy_user="deploy-production"), "production_transfer_identity_invalid"),
            (dict(policy, deploy_uid=0), "production_transfer_identity_invalid"),
            (dict(policy, phase_b_policy_sha256=None), "production_phase_b_policy_unpinned"),
        )
        for value, code in cases:
            with self.subTest(code=code), self.assertRaisesRegex(ENTRY.Refused, f"^{code}$"):
                run(value)

    def test_approved_pin_loads_root_console_profile_bound_to_verified_modules(self):
        raw = (ROOT / SOURCES["environment_contract_sha256"]).read_bytes()
        old = b"CONFINEMENT_PIN: ConfinementPin | None = None\n"
        self.assertEqual(raw.count(old), 1)
        pin_source = ("CONFINEMENT_PIN: ConfinementPin | None = ConfinementPin(\"1.0.0\", \"" + "a" * 64
                      + "\", MappingProxyType(" + repr(dict(PIN.file_sha256)) + "))\n").encode()
        policy, run = self._fixture(raw.replace(old, pin_source))
        backend, profile, operation, app_sha, artifact_sha = run(policy, ("stage", "a" * 40, "b" * 64))
        self.assertEqual((operation, app_sha, artifact_sha), ("stage", "a" * 40, "b" * 64))
        self.assertEqual((profile.allowed_uid, profile.transfer_uid, profile.php_fpm_uid), (0, TRANSFER_UID, 33))
        self.assertEqual(profile.helper_artifact_sha256, policy["helper_artifact_sha256"])
        self.assertIsNotNone(backend.BOUND_ENVIRONMENT.php_fpm_confinement)

    def test_backend_bound_to_another_environment_module_is_refused(self):
        raw = (ROOT / SOURCES["environment_contract_sha256"]).read_bytes()
        saved = {name: sys.modules.get(name) for name in (
            ENTRY.ENVIRONMENT_MODULE_NAME, "_wpcg_release_review_policy_v3", "_wpcg_deployment_transaction_v2",
            "_wpcg_deployment_backend_v2_1_3")}
        try:
            environment = ENTRY._load_environment_module(raw)
            ENTRY._load_transaction((ROOT / SOURCES["transaction_module_sha256"]).read_bytes())
            review = ENTRY._load_review_policy((ROOT / SOURCES["release_review_policy_sha256"]).read_bytes())
            backend_raw = (ROOT / SOURCES["backend_sha256"]).read_bytes()
            self.assertIs(ENTRY._load_backend(backend_raw, review, environment)._ENVIRONMENT_MODULE, environment)
            other = types.ModuleType("other")
            with self.assertRaisesRegex(ENTRY.Refused, "environment_contract_binding_invalid"):
                ENTRY._load_backend(backend_raw, review, other)
            with self.assertRaisesRegex(ENTRY.Refused, "environment_contract_invalid"):
                ENTRY._load_environment_module(raw.replace(b'CONTRACT_VERSION = "2.1.3"', b'CONTRACT_VERSION = "2.1.2"'))
        finally:
            for name, module in saved.items():
                if module is None:
                    sys.modules.pop(name, None)
                else:
                    sys.modules[name] = module


# ==========================================================================
# Controller: deploy packages, root console releases, never sudo
# ==========================================================================

class FakeHelper:
    def __init__(self, responses):
        self.responses = responses
        self.calls = []

    def __call__(self, argv, **kwargs):
        self.calls.append((list(argv), kwargs))
        operation = argv[1]
        value = self.responses[operation]
        if isinstance(value, tuple):
            code, stderr = value
            return subprocess.CompletedProcess(argv, code, b"", stderr)
        payload = dict(value, helper_version="2.1.3", helper_contract="wpcaregrid-deployment-profile-v2.2",
                       helper_artifact_sha256=CONTROLLER.HELPER_ARTIFACT_SHA256)
        return subprocess.CompletedProcess(argv, 0 if payload["result"] in {"PASS", "NO_OP"} else 3,
                                           json.dumps(payload).encode(), b"")


class ControllerTests(unittest.TestCase):
    APP, BASE, ART = "a" * 40, "c" * 40, "b" * 64

    def run_release(self, responses, *, root=True, environ=None):
        fake = FakeHelper(responses)
        flags = SimpleNamespace(flags=SimpleNamespace(isolated=1), argv=sys.argv)
        with patch.object(CONTROLLER.os, "getuid", return_value=0 if root else TRANSFER_UID), \
                patch.object(CONTROLLER.os, "geteuid", return_value=0 if root else TRANSFER_UID), \
                patch.dict(CONTROLLER.os.environ, environ or {}, clear=True), \
                patch.object(CONTROLLER, "sys", flags), \
                patch.object(CONTROLLER, "_verify_helper_file"), \
                patch.object(CONTROLLER.subprocess, "run", side_effect=fake):
            result = CONTROLLER.release(self.APP, self.ART)
        return result, fake

    def passing(self):
        return {"preflight": {"result": "PASS", "application_sha": self.BASE},
                "authorize": {"result": "PASS", "application_sha": self.APP},
                "stage": {"result": "PASS", "application_sha": self.APP},
                "deploy": {"result": "PASS", "application_sha": self.APP}}

    def test_root_console_release_invokes_the_helper_directly_without_sudo(self):
        result, fake = self.run_release(self.passing())
        self.assertEqual(result["result"], "PASS")
        helper = str(CONTROLLER.HELPER)
        self.assertEqual([argv for argv, _ in fake.calls], [
            [helper, "preflight"], [helper, "authorize", self.APP],
            [helper, "stage", self.APP, self.ART], [helper, "deploy", self.APP, self.ART]])
        for argv, kwargs in fake.calls:
            self.assertEqual(kwargs["env"], {})
            self.assertNotIn("sudo", " ".join(argv))

    def test_stale_or_wrong_sha_and_v3_refusals_hold_before_stage(self):
        for reason in ("primary_sha_is_not_latest", "merged_pr_evidence_missing_or_ambiguous",
                       "review_record_missing", "review_artifact_missing", "ci_evidence_failed"):
            responses = self.passing()
            responses["authorize"] = {"result": "HOLD", "reason": reason, "application_sha": self.APP}
            with self.subTest(reason=reason):
                result, fake = self.run_release(responses)
                self.assertEqual((result["result"], result["reason"], result["helper_reason"]),
                                 ("HOLD", "release_not_authorized", reason))
                self.assertEqual([argv[1] for argv, _ in fake.calls], ["preflight", "authorize"])

    def test_confinement_hold_at_preflight_stops_everything(self):
        responses = self.passing()
        responses["preflight"] = (3, b"DEPLOYMENT=HOLD;CODE=confinement_package_not_approved\n")
        result, fake = self.run_release(responses)
        self.assertEqual((result["result"], result["helper_reason"]), ("HOLD", "confinement_package_not_approved"))
        self.assertEqual(len(fake.calls), 1)

    def test_artifact_receipt_failure_stops_before_deploy(self):
        responses = self.passing()
        responses["stage"] = {"result": "HOLD", "reason": "artifact_hash_mismatch"}
        result, fake = self.run_release(responses)
        self.assertEqual(result["reason"], "artifact_hash_mismatch")
        self.assertNotIn("deploy", [argv[1] for argv, _ in fake.calls])

    def test_non_root_or_delegated_release_is_refused(self):
        with self.assertRaises(CONTROLLER.ControllerHold) as caught:
            self.run_release(self.passing(), root=False)
        self.assertEqual(caught.exception.code, "root_console_required")
        with self.assertRaises(CONTROLLER.ControllerHold) as caught:
            self.run_release(self.passing(), environ={"SUDO_UID": str(TRANSFER_UID)})
        self.assertEqual(caught.exception.code, "delegated_invocation_refused")

    def test_helper_response_bound_to_another_artifact_is_refused(self):
        responses = self.passing()
        fake = FakeHelper(responses)

        def wrong(argv, **kwargs):
            result = fake(argv, **kwargs)
            payload = json.loads(result.stdout)
            payload["helper_artifact_sha256"] = "0" * 64
            return subprocess.CompletedProcess(argv, 0, json.dumps(payload).encode(), b"")

        with patch.object(CONTROLLER.subprocess, "run", side_effect=wrong), \
                patch.object(CONTROLLER, "_verify_helper_file"), \
                self.assertRaises(CONTROLLER.ControllerHold) as caught:
            CONTROLLER._helper("preflight")
        self.assertEqual(caught.exception.code, "helper_contract_mismatch")

    def test_packager_is_only_the_deploy_transfer_identity(self):
        with patch.object(CONTROLLER.os, "geteuid", return_value=0), patch.object(CONTROLLER.os, "getuid",
                                                                                 return_value=0):
            with self.assertRaises(CONTROLLER.ControllerHold) as caught:
                CONTROLLER.package(self.APP, self.BASE)
            self.assertEqual(caught.exception.code, "unprivileged_controller_required")
        with patch.object(CONTROLLER.os, "geteuid", return_value=4242), \
                patch.object(CONTROLLER.os, "getuid", return_value=4242), \
                patch.object(CONTROLLER.pwd, "getpwnam", return_value=SimpleNamespace(pw_uid=TRANSFER_UID)):
            with self.assertRaises(CONTROLLER.ControllerHold) as caught:
                CONTROLLER.package(self.APP, self.BASE)
            self.assertEqual(caught.exception.code, "controller_identity_invalid")

    def test_root_release_path_never_runs_git_npm_shell_or_deploy_owned_files(self):
        source = (ROOT / "scripts/production_deploy_controller_2_1_3.py").read_text()
        def block(name):
            start = source.index(f"def {name}(")
            return source[start:source.index("\ndef ", start + 1)]
        for name in ("release", "root_preflight", "_helper", "_helper_process", "_require_root_console"):
            text = block(name)
            for forbidden in ("_git(", "_run(", "npm", "/usr/bin/sudo", "SOURCE_CHECKOUT", "INCOMING_DIRECTORY",
                              "shell=True", "_package_release"):
                self.assertNotIn(forbidden, text, f"{name}: {forbidden}")
        self.assertNotIn("/usr/bin/sudo", source)
        self.assertIn('raise ControllerHold("frontend_build_requires_unprivileged_identity")', source)

    def test_cli_shapes(self):
        out = []
        with patch("builtins.print", side_effect=out.append):
            self.assertEqual(CONTROLLER.main(["sync", self.APP]), 3)
            self.assertEqual(CONTROLLER.main(["release", self.APP]), 3)
        self.assertTrue(all('"operation_not_allowed"' in line for line in out))


# ==========================================================================
# Installer: transfer, no production sudo, unchanged staging grant,
# atomic no-overwrite publication, partial install and rollback
# ==========================================================================

def fake_runner(*, rules=(STAGING_RULE,), negative_rc=1, on_call=None):
    def run(argv, **kwargs):
        if on_call is not None:
            on_call(argv)
        if argv == ["/usr/sbin/visudo", "-c"]:
            return subprocess.CompletedProcess(argv, 0, b"parsed OK\n", b"")
        if argv[:5] == ["/usr/bin/sudo", "-n", "-l", "-U", "deploy"]:
            body = "User deploy may run the following commands on host:\n" + "".join(
                f"    {rule}\n" for rule in rules)
            return subprocess.CompletedProcess(argv, 0, body.encode(), b"")
        if argv[0] == "/usr/sbin/runuser":
            return subprocess.CompletedProcess(argv, negative_rc, b"", b"denied\n")
        raise AssertionError(f"unexpected command {argv}")
    return run


class InstallerTests(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp())
        self.tmp.chmod(0o755)
        t = self.tmp
        for directory in ("usr-libexec-wpcg", "etc-wpcg/profiles", "etc-wpcg/credentials", "var-lib-wpcg",
                          "sudoers.d"):
            (t / directory).mkdir(parents=True, exist_ok=True)
        for directory in t.rglob("*"):
            directory.chmod(0o755)
        (t / "sudoers").write_text("root ALL=(ALL:ALL) ALL\n@includedir /etc/sudoers.d\n")
        self.staging_rule = t / "sudoers.d" / "wpcaregrid-deploy"
        self.staging_rule.write_text("deploy ALL=(root) NOPASSWD: /usr/local/sbin/wpcaregrid-deploy-priv *\n")
        self.staging_rule.chmod(0o440)
        self.source = t / "home-deploy" / ".private" / "wpcg-production-helper-2.1.3"
        self.source.mkdir(parents=True)
        self.source.chmod(0o700)
        for name in INSTALLER.SOURCE_HASHES:
            target = self.source / name
            target.write_bytes((ROOT / "scripts" / name).read_bytes())
            target.chmod(0o600)
        uid, gid = os.getuid(), os.getgid()
        self.layout = replace(
            INSTALLER.PRODUCTION, root_uid=uid, root_gid=gid, source_dir=self.source,
            inbox_parent=t / "var-lib-wpcg" / "production-helper-inbox",
            helper_parent=t / "usr-libexec-wpcg" / "production",
            helper_dir=t / "usr-libexec-wpcg" / "production" / "2.1.3",
            policy=t / "etc-wpcg" / "profiles" / "production-v2.1.3.json",
            sudoers_main=t / "sudoers", sudoers_dir=t / "sudoers.d",
            required_dirs=(t / "usr-libexec-wpcg", t / "etc-wpcg", t / "etc-wpcg" / "profiles", t / "var-lib-wpcg"),
            legacy_paths=(t / "usr-libexec-wpcg" / "production" / "2.1.2",
                          t / "etc-wpcg" / "profiles" / "production-v2.1.2.json",
                          t / "sudoers.d" / "wpcaregrid-production-v2.1.2"),
            phase_b_policy=t / "etc-wpcg" / "profiles" / "production-phase-b-2.0.0.json",
            database_credentials=t / "etc-wpcg" / "credentials" / "production-database-2.0.0.json",
            extra={"test_root": t, "transfer_uid": uid})
        self.sudoers_before = self.sudoers_bytes()

    def tearDown(self):
        shutil.rmtree(self.tmp)

    def sudoers_bytes(self):
        return {p.name: p.read_bytes() for p in [self.tmp / "sudoers", *sorted((self.tmp / "sudoers.d").iterdir())]}

    def install(self, **runner):
        return INSTALLER.install(self.layout, runner=fake_runner(**runner))

    def refused(self, code, **runner):
        with self.assertRaises(INSTALLER.InstallError) as caught:
            self.install(**runner)
        self.assertRegex(str(caught.exception), f"^{code}")
        return str(caught.exception)

    def assert_clean(self):
        self.assertFalse(self.layout.helper_parent.exists())
        self.assertFalse(self.layout.inbox_parent.exists())
        self.assertFalse(self.layout.policy.exists())
        self.assertEqual(self.sudoers_bytes(), self.sudoers_before)

    def test_fresh_install_publishes_exact_files_disabled_policy_and_no_sudoers(self):
        receipt = self.install()
        self.assertEqual(receipt["result"], "PASS")
        self.assertEqual(receipt["production_sudoers"], "ABSENT")
        self.assertEqual(receipt["deploy_sudo_rules_unchanged"], [STAGING_RULE])
        for name, path, mode in self.layout.targets():
            info = os.lstat(path)
            self.assertEqual((stat.S_IMODE(info.st_mode), info.st_nlink), (mode, 1), name)
            if name != "policy":
                self.assertEqual(sha256(path.read_bytes()), INSTALLER.SOURCE_HASHES[name])
        policy = json.loads(self.layout.policy.read_text())
        self.assertEqual((policy["schema"], policy["enabled"], policy["privileged_invocation"],
                          policy["php_fpm_confinement"], policy["deploy_user"]),
                         (2, False, "root-console-direct", None, None))
        self.assertEqual(set(policy), set(INSTALLER.POLICY_KEYS))
        self.assertFalse(self.layout.inbox.exists(), "private inbox copies are removed after publication")
        self.assertEqual(self.sudoers_bytes(), self.sudoers_before)
        self.assertEqual(sorted(p.name for p in (self.tmp / "sudoers.d").iterdir()), ["wpcaregrid-deploy"])

    def test_complete_install_is_read_only_no_op(self):
        self.install()
        before = {p: p.read_bytes() for p in self.layout.helper_dir.iterdir()}
        receipt = self.install()
        self.assertEqual(receipt["result"], "NO_OP")
        self.assertEqual({p: p.read_bytes() for p in self.layout.helper_dir.iterdir()}, before)

    def test_partial_install_is_refused_and_never_repaired(self):
        self.install()
        (self.layout.helper_dir / "deployment_backend_v2_1_3.py").unlink()
        self.refused("partial_installation_detected")
        self.assertFalse((self.layout.helper_dir / "deployment_backend_v2_1_3.py").exists())

    def test_leftover_private_inbox_is_a_partial_install(self):
        self.layout.inbox_parent.mkdir(mode=0o700)
        self.layout.inbox.mkdir(mode=0o700)
        self.refused("partial_installation_detected")

    def test_existing_target_is_never_overwritten(self):
        self.layout.helper_parent.mkdir(mode=0o755)
        self.layout.helper_dir.mkdir(mode=0o755)
        foreign = self.layout.helper_dir / "deployment_backend_v2_1_3.py"
        foreign.write_bytes(b"foreign")
        self.refused("partial_installation_detected")
        self.assertEqual(foreign.read_bytes(), b"foreign")

    def test_failure_during_publication_rolls_back_every_created_inode(self):
        real = INSTALLER._publish

        def failing(path, raw, mode, layout, created):
            if path == layout.policy:
                raise INSTALLER.InstallError("atomic_publish_failed")
            return real(path, raw, mode, layout, created)

        with patch.object(INSTALLER, "_publish", side_effect=failing):
            message = self.refused("atomic_publish_failed")
        self.assertTrue(message.endswith(";ROLLBACK=PASS"))
        self.assert_clean()

    def test_interrupt_rolls_back(self):
        with patch.object(INSTALLER, "_readback", side_effect=KeyboardInterrupt):
            self.assertTrue(self.refused("interrupted").endswith(";ROLLBACK=PASS"))
        self.assert_clean()

    def test_staging_grant_is_preserved_and_any_change_during_install_fails(self):
        def mutate(argv):
            if argv[0] == "/usr/sbin/runuser":
                self.staging_rule.chmod(0o640)
                self.staging_rule.write_text("deploy ALL=(root) NOPASSWD: ALL\n")
        with self.assertRaises(INSTALLER.InstallError) as caught:
            INSTALLER.install(self.layout, runner=fake_runner(on_call=mutate))
        self.assertIn("ROLLBACK=PASS", str(caught.exception))
        self.assertFalse(self.layout.helper_parent.exists())

    def test_sudoers_byte_change_between_snapshots_is_refused(self):
        calls = {"count": 0}
        real = INSTALLER._sudoers_snapshot

        def snapshot(layout):
            calls["count"] += 1
            value = real(layout)
            if calls["count"] == 2:
                value[str(layout.sudoers_main)] = "0" * 64
            return value

        with patch.object(INSTALLER, "_sudoers_snapshot", side_effect=snapshot):
            self.assertIn("ROLLBACK=PASS", self.refused("sudoers_changed_during_install"))
        self.assert_clean()

    def test_deploy_rules_changing_during_install_are_refused(self):
        state = {"calls": 0}
        real = INSTALLER._deploy_sudo_rules

        def rules(runner):
            state["calls"] += 1
            value = real(runner)
            return value if state["calls"] == 1 else ()
        with patch.object(INSTALLER, "_deploy_sudo_rules", side_effect=rules):
            self.refused("deploy_sudo_rules_changed_during_install")
        self.assert_clean()

    def test_deploy_is_not_required_to_have_no_sudo_but_broader_rules_conflict(self):
        self.assertEqual(self.install(rules=(STAGING_RULE,))["result"], "PASS")
        shutil.rmtree(self.layout.helper_parent)
        self.layout.policy.unlink()
        for rules in (("(ALL) NOPASSWD: ALL",), (STAGING_RULE, "(root) NOPASSWD: /bin/sh"),
                      (f"(root) NOPASSWD: {INSTALLER.PRODUCTION.entrypoint} preflight",),
                      ("(root) NOPASSWD: /usr/local/sbin/wpcaregrid-deploy-priv ALL",)):
            with self.subTest(rules=rules):
                self.refused("deploy_sudo_rule_outside_staging_helper", rules=rules)
        self.assert_clean()

    def test_production_sudoers_or_legacy_helpers_conflict(self):
        rule = self.tmp / "sudoers.d" / "wpcaregrid-production-v2.1.3"
        rule.write_text("deploy ALL=(root) NOPASSWD: /usr/local/libexec/wpcaregrid-deploy/production/2.1.3/x\n")
        self.refused("production_sudoers_rule_present")
        rule.unlink()
        legacy = self.tmp / "sudoers.d" / "wpcaregrid-production-v2.1.2"
        legacy.write_text("# legacy\n")
        self.refused("legacy_production_helper_present")
        legacy.unlink()
        self.layout.phase_b_policy.write_text("{}")
        self.refused("phase_b_policy_must_remain_unconfigured")
        self.layout.phase_b_policy.unlink()
        self.assert_clean()

    def test_negative_authorization_success_fails_installation(self):
        self.assertIn("ROLLBACK=PASS", self.refused("negative_authorization_test_failed", negative_rc=0))
        self.assert_clean()

    def test_untrusted_transfer_sources(self):
        entry = self.source / INSTALLER.ENTRY_SOURCE
        original = entry.read_bytes()
        entry.unlink()
        entry.symlink_to(ROOT / "scripts" / INSTALLER.ENTRY_SOURCE)
        self.refused("source_file_metadata_invalid")
        entry.unlink()
        entry.write_bytes(original + b"# tampered\n")
        entry.chmod(0o600)
        self.refused("source_hash_mismatch")
        entry.write_bytes(original)
        os.link(entry, self.tmp / "extra-link")
        self.refused("source_file_metadata_invalid")
        (self.tmp / "extra-link").unlink()
        (self.source / "extra.py").write_bytes(b"")
        self.refused("source_directory_contents_invalid")
        (self.source / "extra.py").unlink()
        self.source.chmod(0o755)
        self.refused("source_directory_metadata_invalid")
        self.source.chmod(0o700)
        entry.chmod(0o644)
        self.refused("source_file_metadata_invalid")
        entry.chmod(0o600)
        self.assert_clean()

    def test_transfer_source_swapped_or_rewritten_during_read_is_refused(self):
        original_open = os.open
        target = self.tmp / "other"
        target.write_bytes(b"other")

        def swap(path, flags, *args, **kwargs):
            if path == INSTALLER.BACKEND_SOURCE and kwargs.get("dir_fd") is not None:
                os.unlink(path, dir_fd=kwargs["dir_fd"])
                os.symlink(target, path, dir_fd=kwargs["dir_fd"])
            return original_open(path, flags, *args, **kwargs)

        with patch.object(INSTALLER.os, "open", side_effect=swap):
            self.refused("source_changed_during_open")
        self.assert_clean()

    def test_source_directory_renamed_after_open_cannot_redirect_reads(self):
        real = INSTALLER._open_source_directory

        def open_then_swap(layout, uid):
            fd = real(layout, uid)
            layout.source_dir.rename(layout.source_dir.with_name("moved"))
            decoy = layout.source_dir
            decoy.mkdir(mode=0o700)
            for name in INSTALLER.SOURCE_HASHES:
                (decoy / name).write_bytes(b"decoy")
                (decoy / name).chmod(0o600)
            return fd

        with patch.object(INSTALLER, "_open_source_directory", side_effect=open_then_swap):
            self.assertEqual(self.install()["result"], "PASS")
        for name, path, _mode in self.layout.targets():
            if name != "policy":
                self.assertEqual(sha256(path.read_bytes()), INSTALLER.SOURCE_HASHES[name])

    def test_root_console_and_trusted_installer_location(self):
        INSTALLER._require_root_console({}, uid=0, euid=0)
        for env, uid, euid, code in (({"SUDO_UID": "1001"}, 0, 0, "delegated_invocation_refused"),
                                     ({}, TRANSFER_UID, 0, "root_required"), ({}, 0, TRANSFER_UID, "root_required")):
            with self.subTest(code=code), self.assertRaisesRegex(INSTALLER.InstallError, code):
                INSTALLER._require_root_console(env, uid=uid, euid=euid)
        installer_copy = self.tmp / "var-lib-wpcg" / "installer.py"
        installer_copy.write_bytes(b"")
        INSTALLER._require_trusted_self(replace(self.layout, installer_path=installer_copy))
        installer_copy.chmod(0o666)
        with self.assertRaisesRegex(INSTALLER.InstallError, "installer_location_untrusted"):
            INSTALLER._require_trusted_self(replace(self.layout, installer_path=installer_copy))
        with self.assertRaisesRegex(INSTALLER.InstallError, "installer_location_untrusted"):
            INSTALLER._require_trusted_self(replace(INSTALLER.PRODUCTION, installer_path=self.source / "x"))

    def test_main_refuses_arguments_and_sudo(self):
        out = []
        with patch("builtins.print", side_effect=out.append):
            self.assertEqual(INSTALLER.main(["--force"]), 3)
            with patch.dict(os.environ, {"SUDO_UID": "1001"}):
                self.assertEqual(INSTALLER.main([]), 3)
        self.assertEqual(out, ["INSTALL=HOLD;CODE=arguments_not_allowed",
                               "INSTALL=HOLD;CODE=delegated_invocation_refused"])

    def test_installer_never_writes_sudoers_or_creates_identities(self):
        source = (ROOT / "scripts/install_production_helper_2_1_3.py").read_text()
        for forbidden in ("useradd", "groupadd", "usermod", "visudo\", \"-cf", "chpasswd", "adduser"):
            self.assertNotIn(forbidden, source)
        self.assertNotRegex(source, r"_publish\([^)]*sudoers")


if __name__ == "__main__":
    unittest.main(verbosity=1)
