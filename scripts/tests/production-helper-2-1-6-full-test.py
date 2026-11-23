#!/usr/bin/env python3
"""Production helper 2.1.6 (GO-13 Workstream A): synthetic tests only.

Nothing here touches a real host, sudoers, a service, a database or the
network. Host interfaces are replaced by temporary trees and fake runners.
These tests prove source behaviour; they do not prove that a kernel enforces an
AppArmor profile (that is the separate GO-13 live acceptance gate).
"""

from __future__ import annotations

import hashlib
import importlib.util
import io
import json
import os
import py_compile
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import tarfile
import time
import types
import unittest
from dataclasses import replace
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from scripts import release_review_policy_v3_1 as POLICY  # noqa: E402,F401  (registers nothing)


def _load(name: str, relative: str):
    spec = importlib.util.spec_from_file_location(name, ROOT / relative)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


BACKEND = _load("deployment_backend_v2_1_6_under_test", "scripts/deployment_backend_v2_1_6.py")
CONTRACT = BACKEND._ENVIRONMENT_MODULE
ENTRY = _load("production_helper_2_1_6_entry_under_test", "scripts/wpcaregrid-deploy-production-priv-2_1_6.py")
CONTROLLER = _load("production_controller_2_1_6_under_test", "scripts/production_deploy_controller_2_1_6.py")
INSTALLER = _load("production_installer_2_1_6_under_test", "scripts/install_production_helper_2_1_6.py")
VITE_VERIFY = _load("verify_committed_vite_build_under_test", "scripts/tests/verify-committed-vite-build.py")
MANIFEST_PATH = ROOT / "deploy/release-manifests/production-deployment-helper-2.1.6.json"
RUNBOOK = ROOT / "docs/PRODUCTION_HELPER_INSTALL_2_1_6.md"

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
    "entrypoint_sha256": "scripts/wpcaregrid-deploy-production-priv-2_1_6.py",
    "backend_sha256": "scripts/deployment_backend_v2_1_6.py",
    "transaction_module_sha256": "scripts/deployment_transaction_v2.py",
    "environment_contract_sha256": "scripts/deployment_environment_contract_2_1_6.py",
    "release_review_policy_sha256": "scripts/release_review_policy_v3_1.py",
}
TRANSFER_UID = 1001
SOCKET = "/run/mysqld/wpcaregrid-production.sock"
STAGING_RULE = "(root) NOPASSWD: /usr/local/sbin/wpcaregrid-deploy-priv *"


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def file_sha(relative: str) -> str:
    return sha256((ROOT / relative).read_bytes())


def synthetic_pin(file_hashes=None):
    hashes = file_hashes or {path: f"{index + 1:064x}" for index, path in enumerate(CONTRACT.CONFINEMENT_FILES)}
    return CONTRACT.ConfinementPin("1.0.0", "a" * 64, "b" * 40, hashes)


PIN = synthetic_pin()


def confinement_policy(pin=PIN):
    return {
        "schema": CONTRACT.CONFINEMENT_SCHEMA, "package_version": pin.package_version,
        "package_sha256sums_sha256": pin.sha256sums_sha256, "package_tree": pin.package_tree,
        "files": dict(pin.file_sha256),
        "services": {role: {"unit": b.unit, "apparmor_profile": b.apparmor_profile,
                            "profile_path": b.profile_path, "attachment_path": b.attachment_path}
                     for role, b in CONTRACT.CONFINEMENT_SERVICES.items()},
        "transients": {role: {"apparmor_profile": t.apparmor_profile, "profile_path": t.profile_path,
                              "executable": t.executable}
                       for role, t in CONTRACT.TRANSIENT_CLASSES.items()},
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
        "database_socket": SOCKET,
        "database_server_uuid_sha256": "c" * 64, "isolated_database": "wpcg_prod_restore_test",
        "isolated_database_host": "127.0.0.1", "isolated_database_port": 3307,
        "isolated_database_uuid_sha256": "d" * 64, "host": "www.wpcaregrid.com", "mysql_version": "8.4",
        "deploy_user": "deploy", "deploy_uid": TRANSFER_UID, "php_version": "8.5",
        "php_fpm_socket": "/run/php/wpcaregrid-production-php8.5-fpm.sock",
        "php_fpm_unit": "wpcaregrid-production-php8.5-fpm.service",
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


FPM_EXE = {"production": "/usr/sbin/php-fpm8.5", "staging-next": "/usr/sbin/php-fpm8.5",
           "staging-legacy": "/usr/sbin/php-fpm8.4", "nginx": "/usr/sbin/nginx"}
HELPER_CGROUP = "/user.slice/user-0.slice/session-7.scope"
DEPLOY_CLI_LABEL = "wpcaregrid-php-cli-production-deploy (enforce)"
BUILD_CLI_LABEL = "wpcaregrid-php-cli-production-build (enforce)"


def peer(pid, *, unit, label, exe, comm="php-fpm", uid=33, gid=33, groups=(), fds=("/dev/null",)):
    return {"pid": pid, "starttime": 100 + pid, "uids": [uid] * 4, "gids": [gid] * 4, "groups": list(groups),
            "ppid": 1, "state": "S",
            "comm": comm, "exe": exe, "argv0": exe, "cmdline_sha256": "e" * 64,
            "cgroup": f"/system.slice/{unit}" if unit else "/user.slice/session-1.scope", "unit": unit,
            "label": label, "fds": list(fds)}


def observation(pin=PIN):
    files = {path: {"sha256": digest, "uid": 0, "gid": 0, "mode": 0o644, "type": "regular", "nlink": 1,
                    "parents_trusted": True} for path, digest in pin.file_sha256.items()}
    services, processes, service_members = {}, [], []
    for offset, role in enumerate(CONTRACT.REQUIRED_ROLES):
        b = CONTRACT.CONFINEMENT_SERVICES[role]
        master = 1000 + offset * 100
        label = f"{b.apparmor_profile} (enforce)"
        active = role not in {"jobs-scheduler", "jobs-legacy-scheduler"}  # oneshot schedulers are normally idle
        services[role] = {
            "unit": b.unit, "load_state": "loaded", "active_state": "active" if active else "inactive",
            "main_pid": master if active else 0, "main_label": label if active else "",
            "apparmor_profile_property": b.apparmor_profile, "loaded_profile_mode": "enforce"}
        exe = FPM_EXE.get(role, "/usr/bin/php8.5")
        if active:
            if role == "production":
                # The pinned production FPM unit runs its master as www-data.
                item = peer(master, unit=b.unit, label=label, exe=exe, comm="master")
                processes.append(item)
            else:
                item = peer(master, unit=b.unit, label=label, exe=exe, comm="master", uid=0, gid=0)
            service_members.append(item)
            for pid in (master + 1, master + 2):
                item = peer(pid, unit=b.unit, label=label, exe=exe)
                processes.append(item)
                service_members.append(item)
    return {"schema": 3, "apparmor_enabled": True, "production_jobs_fence": fence(),
            "files": files, "services": services,
            "www_data_scheduled_jobs": {"complete": True, "crontab_present": False, "system_cron_entries": 0,
                                        "at_jobs": 0},
            "helper": {"pid": 4000, "starttime": 50, "cgroup": HELPER_CGROUP, "deploy_cli_profile_mode": "enforce",
                       "deploy_cli_cmdline_sha256": BACKEND.deploy_cli_cmdline_sha256(SOCKET),
                       "build_cli_profile_mode": "enforce",
                       "build_cli_cmdline_sha256": BACKEND.build_cli_cmdline_sha256()},
            "same_uid_peers": {"complete": True, "incomplete_reasons": [], "processes": processes},
            "service_members": {"complete": True, "incomplete_reasons": [], "processes": service_members}}


FENCE_UNITS = ("wpcaregrid-production-scheduler.timer", "wpcaregrid-production-scheduler.service")
MARKER_KINDS = ("worker_fence_marker", "scheduler_fence_marker")


def fence():
    return {
        "units": {unit: {"load_state": "masked", "active_state": "inactive", "unit_file_state": "masked"}
                  for unit in FENCE_UNITS},
        "supervisor": {"config_complete": True, "runtime_available": True, "programs": []},
        "markers": {kind: {"type": "regular", "uid": 0, "gid": 0, "mode": 0o600, "nlink": 1,
                           "sha256": sha256(CONTRACT.fence_marker_bytes(kind))} for kind in MARKER_KINDS},
    }


def with_peer(data, item):
    data["same_uid_peers"]["processes"].append(item)
    if item.get("unit") in {binding.unit for binding in CONTRACT.CONFINEMENT_SERVICES.values()}:
        data["service_members"]["processes"].append(item)
    return data


def cmdline_sha(argv):
    return sha256(b"".join(arg.encode() + b"\0" for arg in argv))


def deploy_cli_peer(pid, argv=None, **changes):
    argv = argv or BACKEND._deploy_cli_commands(SOCKET)[0]
    item = peer(pid, unit="", label=DEPLOY_CLI_LABEL, exe="/usr/bin/php8.5", comm="php8.5")
    item.update(cgroup=HELPER_CGROUP, cmdline_sha256=cmdline_sha(argv), argv0=argv[0])
    item.update(changes)
    return item


def build_cli_peer(pid, argv=None, **changes):
    argv = argv or BACKEND._build_cli_commands()[0]
    item = peer(pid, unit="", label=BUILD_CLI_LABEL, exe="/usr/bin/php8.5", comm="php8.5")
    item.update(cgroup=HELPER_CGROUP, cmdline_sha256=cmdline_sha(argv), argv0=argv[0])
    item.update(changes)
    return item


def resolved(pin=PIN, **changes):
    with patch.object(CONTRACT, "CONFINEMENT_PIN", pin):
        return CONTRACT.resolve_environment("production", production_config=production_config(pin, **changes))


# ==========================================================================
# Unchanged legacy bytes, no production sudoers, manifest/pin consistency
# ==========================================================================

class FrozenAndManifestTests(unittest.TestCase):
    def test_vite_build_verifier_uses_head_not_index(self):
        with tempfile.TemporaryDirectory() as temp:
            repo = Path(temp)
            subprocess.run(["git", "init", "--quiet", str(repo)], check=True)
            subprocess.run(["git", "-C", str(repo), "config", "user.name", "test"], check=True)
            subprocess.run(["git", "-C", str(repo), "config", "user.email", "test@example.invalid"], check=True)
            build = repo / "public/build"
            build.mkdir(parents=True)
            (build / "manifest.json").write_text("{}\n")
            (build / "assets.js").write_text("trusted\n")
            subprocess.run(["git", "-C", str(repo), "add", "public/build"], check=True)
            subprocess.run(["git", "-C", str(repo), "commit", "--quiet", "-m", "trusted"], check=True)
            self.assertIsNone(VITE_VERIFY.verify(repo))
            (build / "assets.js").write_text("staged attacker bytes\n")
            subprocess.run(["git", "-C", str(repo), "add", "public/build/assets.js"], check=True)
            (build / "assets.js").write_text("trusted\n")
            self.assertIsNone(VITE_VERIFY.verify(repo), "staged index bytes must not replace reviewed HEAD")
            (build / "assets.js").write_text("working tree attacker bytes\n")
            self.assertEqual(VITE_VERIFY.verify(repo), "content_mismatch")
            (build / "assets.js").write_text("trusted\n")
            (build / "extra.js").write_text("uncommitted\n")
            self.assertEqual(VITE_VERIFY.verify(repo), "file_set_mismatch")

    def test_helper_2_1_2_and_shared_modules_are_byte_identical(self):
        for relative, expected in FROZEN.items():
            with self.subTest(path=relative):
                self.assertEqual(file_sha(relative), expected)

    def test_no_production_sudoers_template_exists_for_2_1_6(self):
        self.assertFalse((ROOT / "deploy/sudoers/wpcaregrid-production-v2.1.6").exists())
        self.assertEqual(sorted(p.name for p in (ROOT / "deploy/sudoers").iterdir()), [
            "wpcaregrid-production-v2.0.0", "wpcaregrid-production-v2.1.0",
            "wpcaregrid-production-v2.1.1", "wpcaregrid-production-v2.1.2"])
        manifest = json.loads(MANIFEST_PATH.read_text())
        self.assertIsNone(manifest["production_sudoers"])
        self.assertNotIn("sudoers_template", manifest)
        for relative in ("scripts/install_production_helper_2_1_6.py",
                         "scripts/production_deploy_controller_2_1_6.py",
                         "scripts/wpcaregrid-deploy-production-priv-2_1_6.py"):
            text = (ROOT / relative).read_text()
            self.assertNotIn('"/usr/bin/sudo", "-n", str(HELPER)', text)
            self.assertNotIn("NOPASSWD: NOSETENV", text)
            self.assertNotIn("/etc/sudoers.d/wpcaregrid-production-v2.1.6", text)

    def test_vite_build_is_tracked_and_matches_git_blob_bytes(self):
        result = subprocess.run([sys.executable, str(ROOT / "scripts/tests/verify-committed-vite-build.py")],
                                cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False)
        self.assertEqual(result.returncode, 0, result.stdout.decode("utf-8", "replace"))
        self.assertRegex(result.stdout.decode("ascii"), r"VITE_BUILD=PASS;FILES=[1-9][0-9]*\n")

    def test_packager_requires_built_assets_to_match_exact_git_source_tree(self):
        committed = {"public/build/manifest.json": b'{"resources/js/app.js":{}}',
                     "public/build/assets/app.js": b"trusted-build"}
        CONTROLLER._verify_built_assets_match_source(dict(committed), dict(committed))
        cases = (
            (dict(committed, **{"public/build/assets/app.js": b"attacker-js"}),
             "frontend_build_not_bound_to_source_tree"),
            ({"public/build/manifest.json": committed["public/build/manifest.json"]},
             "frontend_build_not_bound_to_source_tree"),
            ({"public/build/assets/app.js": committed["public/build/assets/app.js"]},
             "frontend_build_not_bound_to_source_tree"),
        )
        for generated, code in cases:
            with self.subTest(code=code, generated=sorted(generated)):
                with self.assertRaisesRegex(CONTROLLER.ControllerHold, code):
                    CONTROLLER._verify_built_assets_match_source(generated, committed)

    def test_primary_comparison_includes_generated_build_assets(self):
        raw = b"trusted source asset"
        blob = hashlib.sha1(b"blob " + str(len(raw)).encode("ascii") + b"\0" + raw).hexdigest()
        temp = Path(tempfile.mkdtemp())
        try:
            asset = temp / "public/build/assets/app.js"
            asset.parent.mkdir(parents=True)
            asset.write_bytes(raw)
            asset.chmod(0o644)
            candidate = {"public/build/assets/app.js": hashlib.sha256(raw).hexdigest()}
            with patch.object(BACKEND, "_local_git_tree", return_value={"public/build/assets/app.js": blob}), \
                    patch.object(BACKEND, "_verify_frontend_build"), \
                    patch.object(BACKEND, "_verify_primary_ci_and_reviews"):
                profile = SimpleNamespace(test_only=True, expected_owner_uid=os.getuid())
                BACKEND._verify_artifact_matches_primary(profile, "a" * 40, temp, candidate, {})
                malicious = b"attacker javascript"
                asset.write_bytes(malicious)
                bad_candidate = {"public/build/assets/app.js": hashlib.sha256(malicious).hexdigest()}
                with self.assertRaisesRegex(BACKEND.DeployRefused, "artifact_source_content_mismatch"):
                    BACKEND._verify_artifact_matches_primary(profile, "a" * 40, temp, bad_candidate, {})
        finally:
            shutil.rmtree(temp, ignore_errors=True)

    def test_packaged_archive_has_one_committed_build_and_no_private_paths(self):
        temp = Path(tempfile.mkdtemp())
        try:
            repo, incoming = temp / "repo", temp / "incoming"
            repo.mkdir()
            incoming.mkdir(mode=0o700)
            def git(*args):
                return subprocess.run(["git", "-C", str(repo), *args], check=True,
                                      stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10).stdout
            git("init", "--quiet", "-b", "main")
            git("config", "user.name", "WPCG test")
            git("config", "user.email", "wpcg-test@example.invalid")
            entries = {
                "artisan": b"<?php // test\n", "composer.json": b'{"require":{"php":"^8.4"}}\n',
                "composer.lock": b'{"packages":[]}\n', "package.json": b'{"private":true}\n',
                "package-lock.json": b'{"lockfileVersion":3,"packages":{}}\n',
                "public/build/assets/app.js": b"trusted generated bytes",
                "public/build/manifest.json":
                    b'{"resources/js/app.js":{"file":"assets/app.js","isEntry":true}}',
                "storage/logs/.gitignore": b"*\n!.gitignore\n",
            }
            for name, raw in entries.items():
                path = repo / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(raw)
            git("add", "-A")
            git("commit", "--quiet", "-m", "base")
            base_sha = git("rev-parse", "HEAD").decode().strip()
            git("commit", "--quiet", "--allow-empty", "-m", "release")
            app_sha = git("rev-parse", "HEAD").decode().strip()
            real_run = subprocess.run

            def fake_run(argv, *args, **kwargs):
                if argv and str(argv[0]).endswith("/npm"):
                    return subprocess.CompletedProcess(argv, 0, b"", b"")
                return real_run(argv, *args, **kwargs)

            def fake_version(argv, **_kwargs):
                return b"v22.21.1" if str(argv[0]).endswith("/node") else b"10.9.4"

            with patch.object(CONTROLLER, "SOURCE_CHECKOUT", repo), \
                    patch.object(CONTROLLER, "INCOMING_DIRECTORY", incoming), \
                    patch.object(CONTROLLER.shutil, "which", side_effect=lambda name, **_kwargs:
                                "/usr/bin/node" if name == "node" else "/usr/bin/npm"), \
                    patch.object(CONTROLLER, "_run", side_effect=fake_version), \
                    patch.object(CONTROLLER, "_database_migrations_between", return_value=[]), \
                    patch.object(CONTROLLER.subprocess, "run", side_effect=fake_run):
                archive_path, artifact_sha = CONTROLLER._package_release(app_sha, base_sha, test_only=True)
            with tarfile.open(archive_path, "r:") as archive:
                names = [member.name for member in archive.getmembers()]
                self.assertEqual(names.count("public/build/manifest.json"), 1)
                self.assertEqual(names.count("public/build/assets/app.js"), 1)
                self.assertNotIn("storage/logs/.gitignore", names)
            destination = temp / "extracted"
            destination.mkdir()
            old_profile, old_php = BACKEND.PROFILE, BACKEND.PHP_VERSION
            BACKEND.PROFILE, BACKEND.PHP_VERSION = "production", "8.5"
            try:
                files, _manifest = BACKEND._extract_archive(
                    archive_path.read_bytes(), destination, app_sha=app_sha, base_sha=base_sha,
                    artifact_sha=artifact_sha, helper_artifact_sha256=CONTROLLER.HELPER_ARTIFACT_SHA256)
                self.assertIn("public/build/assets/app.js", files)
                self.assertNotIn("storage/logs/.gitignore", files)

                # The production-bound extractor must refuse an otherwise
                # self-consistent archive that declares the staging profile.
                altered = io.BytesIO()
                with tarfile.open(archive_path, "r:") as source, tarfile.open(fileobj=altered, mode="w:") as target:
                    for member in source.getmembers():
                        raw = source.extractfile(member).read()
                        if member.name == "release-manifest.json":
                            document = json.loads(raw)
                            document["profile"] = "staging-next"
                            raw = (json.dumps(document, sort_keys=True, separators=(",", ":")) + "\n").encode()
                            member.size = len(raw)
                        target.addfile(member, io.BytesIO(raw))
                altered_bytes = altered.getvalue()
                rejected_destination = temp / "rejected-extracted"
                rejected_destination.mkdir()
                with self.assertRaisesRegex(BACKEND.DeployRefused, "^release_manifest_identity_mismatch$"):
                    BACKEND._extract_archive(
                        altered_bytes, rejected_destination, app_sha=app_sha, base_sha=base_sha,
                        artifact_sha=hashlib.sha256(altered_bytes).hexdigest(),
                        helper_artifact_sha256=CONTROLLER.HELPER_ARTIFACT_SHA256)
            finally:
                BACKEND.PROFILE, BACKEND.PHP_VERSION = old_profile, old_php
        finally:
            shutil.rmtree(temp, ignore_errors=True)

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
        self.assertEqual(manifest["controller_sha256"], file_sha("scripts/production_deploy_controller_2_1_6.py"))
        self.assertEqual(manifest["root_installer_sha256"], file_sha("scripts/install_production_helper_2_1_6.py"))
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
        pin = CONTRACT.confinement_pin()
        self.assertEqual(manifest["php_fpm_confinement_pin"], {
            "schema": CONTRACT.CONFINEMENT_SCHEMA, "package_version": pin.package_version,
            "sha256sums_sha256": pin.sha256sums_sha256, "package_tree": pin.package_tree,
            "files": len(CONTRACT.CONFINEMENT_FILES),
            "services": list(CONTRACT.REQUIRED_ROLES),
            "transients": list(CONTRACT.TRANSIENT_ROLES),
            "binding_sha256": CONTRACT.confinement_binding_sha256()})
        expected_binding = CONTRACT.confinement_binding_sha256()
        for module in (CONTROLLER, INSTALLER):
            self.assertEqual(module.CONFINEMENT_SCHEMA, CONTRACT.CONFINEMENT_SCHEMA)
            self.assertEqual(module.CONFINEMENT_PACKAGE_VERSION, pin.package_version)
            self.assertEqual(module.CONFINEMENT_SHA256SUMS_SHA256, pin.sha256sums_sha256)
            self.assertEqual(module.CONFINEMENT_PACKAGE_TREE, pin.package_tree)
            self.assertEqual(module.CONFINEMENT_FILE_COUNT, len(CONTRACT.CONFINEMENT_FILES))
            self.assertEqual(tuple(module.CONFINEMENT_SERVICE_ROLES), CONTRACT.REQUIRED_ROLES)
            self.assertEqual(tuple(module.CONFINEMENT_TRANSIENT_ROLES), CONTRACT.TRANSIENT_ROLES)
            self.assertEqual(module.CONFINEMENT_BINDING_SHA256, expected_binding)
        runbook = RUNBOOK.read_text()
        for value in (*hashes.values(), digest, manifest["controller_sha256"], manifest["root_installer_sha256"]):
            self.assertIn(f"`{value}`", runbook)

    def test_versions_and_contract_names_agree(self):
        self.assertEqual((ENTRY.VERSION, BACKEND.HELPER_VERSION, CONTROLLER.HELPER_VERSION, INSTALLER.VERSION,
                          CONTRACT.CONTRACT_VERSION), ("2.1.6",) * 5)
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
            for relative in (*SOURCES.values(), "scripts/production_deploy_controller_2_1_6.py",
                             "scripts/install_production_helper_2_1_6.py"):
                py_compile.compile(str(ROOT / relative), cfile=str(Path(tmp) / "x.pyc"), doraise=True)

    def test_v3_admission_and_exact_primary_gates_are_unchanged_from_2_1_2(self):
        old = (ROOT / "scripts/deployment_backend_v2_1_2.py").read_text()
        new = (ROOT / "scripts/deployment_backend_v2_1_6.py").read_text()
        def block(text, name):
            start = text.index(f"def {name}(")
            return text[start:text.index("\ndef ", start + 1)]
        for name in ("_verify_primary_ci_and_reviews", "_github_primary_tree", "_verify_agent_release_reviews_v3",
                     "_verify_review_report_artifacts", "_github_json",
                     "_github_release_review_ledger", "authorize_release",
                     "_write_receipt", "_successful_receipt"):
            with self.subTest(name=name):
                self.assertEqual(block(old, name), block(new, name))
        self.assertIn('"primary_sha_is_not_latest"', block(new, "_github_primary_tree"))
        self.assertEqual(new.count("_verify_primary_ci_and_reviews("), 3)


# ==========================================================================
# Environment contract 2.1.6
# ==========================================================================

class ContractTests(unittest.TestCase):
    def test_cli_transient_is_allowed_during_command_but_refused_after_it(self):
        data = with_peer(observation(), deploy_cli_peer(4100))
        self._passes(data)
        with patch.object(CONTRACT, "CONFINEMENT_PIN", PIN), \
                self.assertRaisesRegex(CONTRACT.EnvironmentRefused,
                                       "^confinement_cli_process_survived$"):
            CONTRACT.verify_confinement_observation(resolved(), data, allow_transients=False)

    def test_approved_pin_binds_package_1_0_7_exactly(self):
        pin = CONTRACT.confinement_pin()
        self.assertEqual(pin.package_version, "1.0.7")
        self.assertEqual(pin.sha256sums_sha256,
                         "3e839c69a597ad4884c663b76fa6a2447d4b030cbbf5a1e174843ccb83db0947")
        self.assertEqual(pin.package_tree, "1d0685561b33e26ff33394911c6d6f9892721ea9")
        self.assertEqual(len(CONTRACT.CONFINEMENT_FILES), 14)
        self.assertEqual(set(pin.file_sha256), set(CONTRACT.CONFINEMENT_FILES))
        self.assertEqual(CONTRACT.TRANSIENT_ROLES, ("production-deploy-cli", "production-build-cli"))

    def test_unset_pin_refuses_resolution_and_observation(self):
        env = resolved()
        with patch.object(CONTRACT, "CONFINEMENT_PIN", None):
            with self.assertRaisesRegex(CONTRACT.EnvironmentRefused, "^confinement_package_not_approved$"):
                CONTRACT.resolve_environment("production", production_config=production_config())
            with self.assertRaisesRegex(CONTRACT.EnvironmentRefused, "^confinement_package_not_approved$"):
                CONTRACT.verify_confinement_observation(env, observation())

    def test_malformed_pin_refuses(self):
        bad = CONTRACT.ConfinementPin("1.0.0", "a" * 64, "b" * 40,
                                      {CONTRACT.CONFINEMENT_FILES[0]: "b" * 64})
        with patch.object(CONTRACT, "CONFINEMENT_PIN", bad), \
                self.assertRaisesRegex(CONTRACT.EnvironmentRefused, "confinement_package_pin_invalid"):
            CONTRACT.resolve_environment("production", production_config=production_config())
        bad_tree = CONTRACT.ConfinementPin("1.0.0", "a" * 64, "not-a-tree",
                                           synthetic_pin().file_sha256)
        with patch.object(CONTRACT, "CONFINEMENT_PIN", bad_tree), \
                self.assertRaisesRegex(CONTRACT.EnvironmentRefused, "confinement_package_pin_invalid"):
            CONTRACT.resolve_environment("production", production_config=production_config())

    def test_exact_binding_admits_shared_www_data_and_deploy_transfer_identity(self):
        env = resolved()
        self.assertEqual((env.php_fpm_uid, env.php_fpm_gid), (33, 33))
        self.assertEqual((env.deploy_user, env.deploy_uid), ("deploy", TRANSFER_UID))
        self.assertEqual(env.privileged_invocation, "root-console-direct")
        self.assertEqual(env.php_fpm_confinement.roles, ("production", "staging-next", "nginx",
                                                         "jobs-supervisor", "jobs-scheduler"))

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
                policy, services={**policy["services"], "staging-next": dict(
                    policy["services"]["staging-next"], apparmor_profile="unconfined")})),
            "production_confinement_transient_binding_invalid": dict(
                production_config(), php_fpm_confinement=dict(
                    policy, transients={**policy["transients"], "production-build-cli": dict(
                        policy["transients"]["production-build-cli"], executable="/usr/bin/php8.4")})),
            "production_confinement_schema_invalid": dict(production_config(), php_fpm_confinement=dict(
                policy, schema=1)),
            "production_php_version_invalid": dict(production_config(), php_version="8.4"),
        }
        with patch.object(CONTRACT, "CONFINEMENT_PIN", PIN):
            for code, config in cases.items():
                with self.subTest(code=code), self.assertRaisesRegex(CONTRACT.EnvironmentRefused, f"^{code}$"):
                    CONTRACT.resolve_environment("production", production_config=config)
            with self.assertRaisesRegex(CONTRACT.EnvironmentRefused, "production_confinement_package_mismatch"):
                CONTRACT.resolve_environment("production", production_config=production_config(
                    php_fpm_confinement=dict(policy, package_tree="c" * 40)))
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

    def test_production_master_is_in_the_www_data_peer_inventory(self):
        data = observation()
        master = data["services"]["production"]["main_pid"]
        process = next(item for item in data["same_uid_peers"]["processes"] if item["pid"] == master)
        self.assertEqual(process["uids"], [33] * 4)
        self._passes(data)
        self.assertEqual(self._refusal(lambda d: d["same_uid_peers"]["processes"].__setitem__(
            slice(None), [item for item in d["same_uid_peers"]["processes"] if item["pid"] != master])),
                         "confinement_worker_labels_missing")

    def test_production_release_secrets_and_logs_are_forbidden_descriptors(self):
        paths = (
            "/var/www/wpcaregrid-production/releases/" + "a" * 40 + "/bootstrap/cache/config.php",
            "/var/www/wpcaregrid-production/releases/" + "a" * 40 + "/bootstrap/cache/config.php (deleted)",
            "/var/log/wpcaregrid-production/php-fpm.log",
            "/var/log/wpcaregrid-production/php-fpm.log (deleted)",
            "/var/www/wpcaregrid-production/shared/.env",
        )
        for path in paths:
            with self.subTest(path=path):
                def set_staging_fd(data, value=path):
                    peer_item = next(item for item in data["same_uid_peers"]["processes"]
                                     if item["unit"] == "php8.5-fpm.service")
                    peer_item["fds"] = [value]
                self.assertEqual(self._refusal(set_staging_fd),
                                 "confinement_peer_foreign_descriptor")
        data = observation()
        nginx = next(item for item in data["same_uid_peers"]["processes"] if item["unit"] == "nginx.service")
        nginx["fds"] = ["/var/www/wpcaregrid-production/releases/" + "a" * 40 + "/public/build/app.js"]
        self._passes(data)
        for path in ("public/storage", "public/storage/uploads/avatar.jpg",
                     "bootstrap/cache/config.php"):
            with self.subTest(nginx_path=path):
                full_path = "/var/www/wpcaregrid-production/releases/" + "a" * 40 + "/" + path
                self.assertEqual(self._refusal(lambda d, value=full_path: next(
                    item for item in d["same_uid_peers"]["processes"] if item["unit"] == "nginx.service").update(
                        fds=[value])), "confinement_peer_foreign_descriptor")

    def test_every_service_cgroup_member_including_root_masters_is_verified(self):
        data = observation()
        members = data["service_members"]["processes"]
        self.assertEqual(len(members), 12)
        self.assertTrue(any(item["unit"] == "php8.5-fpm.service" and item["uids"] == [0] * 4
                            for item in members))
        self._passes(data)
        for mutate, code in (
            (lambda d: next(item for item in d["service_members"]["processes"]
                            if item["unit"] == "php8.5-fpm.service").update(label="unconfined"),
             "confinement_master_not_confined"),
            (lambda d: next(item for item in d["service_members"]["processes"]
                            if item["unit"] == "php8.5-fpm.service").update(
                                fds=["/var/www/wpcaregrid-production/shared/.env"]),
             "confinement_peer_foreign_descriptor"),
            (lambda d: d["service_members"].update(complete=False, incomplete_reasons=["peer_set_unstable"]),
             "confinement_peer_inventory_incomplete"),
        ):
            with self.subTest(code=code):
                self.assertEqual(self._refusal(mutate), code)
        def remove_stage_main(data):
            pid = data["services"]["staging-next"]["main_pid"]
            data["service_members"]["processes"][:] = [item for item in data["service_members"]["processes"]
                                                        if item["pid"] != pid]
        self.assertEqual(self._refusal(remove_stage_main), "confinement_service_member_missing")

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
            "confinement_service_not_active": svc("staging-next", active_state="inactive", main_label=""),
            "confinement_service_unattached": svc("jobs-scheduler", apparmor_profile_property=""),
            "confinement_service_attachment_fail_open": svc(
                "nginx", apparmor_profile_property="-wpcaregrid-nginx"),
            "confinement_service_profile_mismatch": svc(
                "staging-next", apparmor_profile_property="wpcaregrid-php-fpm-production"),
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
            "php-cli": peer(5002, unit="", label="unconfined", exe="/usr/bin/php8.5", comm="php8.5"),
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
            (peer(6001, unit="wpcaregrid-unknown.service", label="custom (enforce)", exe="/usr/bin/php8.5"),
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
                             exe="/usr/bin/php8.5", comm="php"))
        scheduler = data["services"]["jobs-scheduler"]
        scheduler.update(active_state="active", main_pid=7004, main_label="wpcaregrid-php-cli-staging (enforce)")
        data["service_members"]["processes"].append(peer(
            7004, unit="wpcaregrid-staging-next-scheduler.service",
            label="wpcaregrid-php-cli-staging (enforce)", exe="/usr/bin/php8.5", uid=0, gid=0))
        with_peer(data, peer(7002, unit="wpcaregrid-staging-next-scheduler.service",
                             label="wpcaregrid-php-cli-staging (enforce)", exe="/usr/bin/php8.5"))
        with_peer(data, peer(7003, unit="php8.5-fpm.service", label="wpcaregrid-php-fpm-staging-next (enforce)",
                             exe="/usr/lib/php/php-fpm-socket-helper"))
        self._passes(data)

    def test_shim_free_model_refuses_every_cron_or_at_class(self):
        # Former cron-shim and cron-attached job peers are no longer classes.
        for item, code in (
                (peer(8001, unit="cron.service", label="unconfined", exe="/usr/sbin/cron", comm="CRON"),
                 "confinement_same_uid_peer_unconfined"),
                (peer(8002, unit="cron.service", label="unconfined", exe="/usr/bin/dash", comm="sh"),
                 "confinement_same_uid_peer_unconfined"),
                (peer(8003, unit="cron.service", label="wpcaregrid-php-cli-staging (enforce)", exe="/usr/bin/php8.4"),
                 "confinement_peer_label_outside_unit"),
                (peer(8004, unit="atd.service", label="unconfined", exe="/usr/bin/dash", comm="sh"),
                 "confinement_same_uid_peer_unconfined")):
            with self.subTest(pid=item["pid"]):
                self.assertEqual(self._refusal(lambda d, item=item: with_peer(d, item)), code)
        jobs = lambda d: d["www_data_scheduled_jobs"]  # noqa: E731
        for change, code in (({"crontab_present": True}, "confinement_www_data_scheduled_job_present"),
                             ({"system_cron_entries": 1}, "confinement_www_data_scheduled_job_present"),
                             ({"at_jobs": 1}, "confinement_www_data_scheduled_job_present"),
                             ({"complete": False}, "confinement_www_data_jobs_unverifiable"),
                             ({"at_jobs": -1}, "confinement_observation_invalid")):
            with self.subTest(change=change):
                self.assertEqual(self._refusal(lambda d, c=change: jobs(d).update(c)), code)
        self.assertEqual(self._refusal(lambda d: d.pop("www_data_scheduled_jobs")), "confinement_observation_invalid")
        policy = confinement_policy()
        policy["www_data_crontab_sha256"] = "c" * 64
        with patch.object(CONTRACT, "CONFINEMENT_PIN", PIN), \
                self.assertRaisesRegex(CONTRACT.EnvironmentRefused, "production_confinement_schema_invalid"):
            CONTRACT.resolve_environment("production", production_config=production_config(
                php_fpm_confinement=policy))

    def test_acceptance_probe_profiles_are_never_a_class(self):
        for item in (peer(8301, unit="run-u42.service", label="wpcaregrid-php-fpm-production-probe (enforce)",
                          exe="/usr/bin/python3.12"),
                     peer(8302, unit="php8.5-fpm.service", label="wpcaregrid-php-fpm-staging-next-probe (enforce)",
                          exe="/usr/sbin/php-fpm8.5"),
                     peer(8303, unit="", label="wpcaregrid-php-cli-production-deploy-probe (enforce)",
                          exe="/usr/bin/php8.5")):
            with self.subTest(label=item["label"]):
                self.assertIn(self._refusal(lambda d, item=item: with_peer(d, item)),
                              {"confinement_same_uid_peer_unknown", "confinement_peer_label_outside_unit"})

    def test_legacy_scheduler_and_legacy_fpm_are_not_admitted_roles(self):
        self.assertNotIn("jobs-legacy-scheduler", CONTRACT.CONFINEMENT_SERVICES)
        self.assertNotIn("staging-legacy", CONTRACT.CONFINEMENT_SERVICES)
        self.assertEqual(len(CONTRACT.CONFINEMENT_FILES), 14)
        self.assertIn("/etc/apparmor.d/wpcaregrid-php-cli-production-deploy", CONTRACT.CONFINEMENT_FILES)
        self.assertIn("/etc/apparmor.d/wpcaregrid-php-cli-production-build", CONTRACT.CONFINEMENT_FILES)
        self.assertNotIn("/etc/systemd/system/wpcaregrid-staging-legacy-scheduler.service.d/90-wpcaregrid-confinement.conf", CONTRACT.CONFINEMENT_FILES)
        self.assertNotIn("/etc/apparmor.d/wpcaregrid-php-fpm-staging-legacy", CONTRACT.CONFINEMENT_FILES)
        self.assertNotIn("/etc/systemd/system/php8.4-fpm.service.d/90-wpcaregrid-confinement.conf", CONTRACT.CONFINEMENT_FILES)
        self.assertEqual(self._refusal(lambda d: d["services"].update(
            {"jobs-legacy-scheduler": {"unit": "wpcaregrid-staging-legacy-scheduler.service",
                                       "load_state": "loaded", "active_state": "active",
                                       "main_pid": 8100, "main_label": "wpcaregrid-php-cli-staging (enforce)",
                                       "apparmor_profile_property": "wpcaregrid-php-cli-staging",
                                       "loaded_profile_mode": "enforce"}})),
            "confinement_observation_extra_service")

    def test_production_deploy_cli_class_is_exact(self):
        for argv in BACKEND._deploy_cli_commands(SOCKET):
            with self.subTest(argv=argv[:3]):
                self._passes(with_peer(observation(), deploy_cli_peer(8200, argv)))
        cases = (
            (deploy_cli_peer(8201, unit="wpcaregrid-production-php8.5-fpm.service",
                             cgroup="/system.slice/wpcaregrid-production-php8.5-fpm.service"),
             "confinement_deploy_cli_outside_helper_session"),
            (deploy_cli_peer(8202, cgroup="/user.slice/user-0.slice/session-8.scope"),
             "confinement_deploy_cli_outside_helper_session"),
            (deploy_cli_peer(8203, exe="/usr/bin/php8.4"), "confinement_peer_executable_unexpected"),
            (deploy_cli_peer(8204, argv=("/usr/bin/php8.5", "artisan", "tinker")),
             "confinement_deploy_cli_command_unexpected"),
            (deploy_cli_peer(8205, uids=[33, 33, 0, 33]), "confinement_deploy_cli_identity_invalid"),
            (deploy_cli_peer(8208, groups=[33, 1001]), "confinement_deploy_cli_identity_invalid"),
            (deploy_cli_peer(8209, argv0="php"), "confinement_peer_executable_unexpected"),
            (deploy_cli_peer(8210, starttime=10), "confinement_deploy_cli_stale"),
            (deploy_cli_peer(8206, fds=["/var/lib/wpcaregrid-deploy/staging-next/shared/.env"]),
             "confinement_peer_foreign_descriptor"),
            (deploy_cli_peer(8207, label="wpcaregrid-php-cli-production-deploy (complain)"),
             "confinement_process_not_confined"),
        )
        for item, code in cases:
            with self.subTest(code=code, pid=item["pid"]):
                self.assertEqual(self._refusal(lambda d, item=item: with_peer(d, item)), code)
        helper = lambda d: d["helper"]  # noqa: E731
        for change, code in (({"cgroup": "/system.slice/wpcaregrid-root.service"},
                              "confinement_helper_not_root_console_session"),
                             ({"cgroup": "/user.slice/user-1001.slice/session-3.scope"},
                              "confinement_helper_not_root_console_session"),
                             ({"deploy_cli_profile_mode": "unloaded"}, "confinement_deploy_cli_profile_not_enforced"),
                             ({"deploy_cli_cmdline_sha256": []}, "confinement_observation_invalid"),
                             ({"build_cli_profile_mode": "unloaded"}, "confinement_build_cli_profile_not_enforced"),
                             ({"build_cli_cmdline_sha256": []}, "confinement_observation_invalid")):
            with self.subTest(change=change):
                self.assertEqual(self._refusal(lambda d, c=change: helper(d).update(c)), code)

    def test_production_build_cli_class_is_exact(self):
        for argv in BACKEND._build_cli_commands():
            with self.subTest(argv=argv[:3]):
                self._passes(with_peer(observation(), build_cli_peer(8250, argv)))
        cases = (
            (build_cli_peer(8251, unit="wpcaregrid-production-php8.5-fpm.service",
                            cgroup="/system.slice/wpcaregrid-production-php8.5-fpm.service"),
             "confinement_build_cli_outside_helper_session"),
            (build_cli_peer(8252, cgroup="/user.slice/user-0.slice/session-8.scope"),
             "confinement_build_cli_outside_helper_session"),
            (build_cli_peer(8253, exe="/usr/bin/php8.4"), "confinement_peer_executable_unexpected"),
            (build_cli_peer(8254, argv=("/usr/bin/php8.5", "artisan", "tinker")),
             "confinement_build_cli_command_unexpected"),
            (build_cli_peer(8255, argv=BACKEND._deploy_cli_commands(SOCKET)[0]),
             "confinement_build_cli_command_unexpected"),
            (build_cli_peer(8256, uids=[33, 33, 0, 33]), "confinement_build_cli_identity_invalid"),
            (build_cli_peer(8257, groups=[33, 1001]), "confinement_build_cli_identity_invalid"),
            (build_cli_peer(8258, argv0="php"), "confinement_peer_executable_unexpected"),
            (build_cli_peer(8259, starttime=10), "confinement_build_cli_stale"),
            (build_cli_peer(8260, fds=["/var/lib/wpcaregrid-deploy/staging-next/shared/.env"]),
             "confinement_peer_foreign_descriptor"),
            (build_cli_peer(8261, label="wpcaregrid-php-cli-production-build (complain)"),
             "confinement_process_not_confined"),
            (deploy_cli_peer(8262, argv=BACKEND._build_cli_commands()[0]),
             "confinement_deploy_cli_command_unexpected"),
        )
        for item, code in cases:
            with self.subTest(code=code, pid=item["pid"]):
                self.assertEqual(self._refusal(lambda d, item=item: with_peer(d, item)), code)

    def test_production_jobs_fence_is_required_and_checked_first(self):
        fence_of = lambda d: d["production_jobs_fence"]  # noqa: E731
        stopped = {"name": "wpcaregrid-production-default", "state": "STOPPED", "autostart": False}
        cases = {
            "confinement_observation_invalid": lambda d: d.pop("production_jobs_fence"),
            "confinement_production_jobs_unverifiable": lambda d: fence_of(d)["units"].pop(FENCE_UNITS[1]),
            "confinement_production_jobs_not_fenced": lambda d: fence_of(d)["units"][FENCE_UNITS[0]].update(
                load_state="loaded", unit_file_state="enabled"),
        }
        for code, mutate in cases.items():
            with self.subTest(code=code):
                self.assertEqual(self._refusal(mutate), code)
        unit_cases = ({"active_state": "active"}, {"unit_file_state": "disabled"}, {"load_state": "not-found"},
                      {"active_state": "activating"})
        for unit in FENCE_UNITS:
            for change in unit_cases:
                with self.subTest(unit=unit, change=change):
                    self.assertEqual(self._refusal(lambda d, u=unit, c=change: fence_of(d)["units"][u].update(c)),
                                     "confinement_production_jobs_not_fenced")
        program_cases = (
            (dict(stopped, state="RUNNING"), "confinement_production_jobs_not_fenced"),
            (dict(stopped, state="BACKOFF"), "confinement_production_jobs_not_fenced"),
            (dict(stopped, state="NOT_LOADED"), "confinement_production_jobs_not_fenced"),
            (dict(stopped, autostart=True), "confinement_production_jobs_not_fenced"),
            (dict(stopped, state="SUPERVISOR_INACTIVE"), "confinement_production_jobs_not_fenced"),
            (dict(stopped, state="weird"), "confinement_observation_invalid"),
        )
        for program, code in program_cases:
            with self.subTest(program=program):
                self.assertEqual(self._refusal(lambda d, p=program: fence_of(d)["supervisor"]["programs"].append(p)),
                                 code)
        self.assertEqual(self._refusal(lambda d: fence_of(d)["supervisor"].update(config_complete=False)),
                         "confinement_production_jobs_unverifiable")
        self.assertEqual(self._refusal(lambda d: fence_of(d)["supervisor"].update(runtime_available=False)),
                         "confinement_production_jobs_unverifiable")
        for kind in MARKER_KINDS:
            for change, code in ((None, "confinement_production_jobs_not_fenced"),
                                 ({"uid": TRANSFER_UID}, "confinement_production_fence_marker_invalid"),
                                 ({"gid": 33}, "confinement_production_fence_marker_invalid"),
                                 ({"mode": 0o644}, "confinement_production_fence_marker_invalid"),
                                 ({"type": "other"}, "confinement_production_fence_marker_invalid"),
                                 ({"nlink": 2}, "confinement_production_fence_marker_invalid"),
                                 ({"sha256": sha256(b"schema=1;profile=staging-next;workers=disabled\n")},
                                  "confinement_production_fence_marker_invalid")):
                with self.subTest(kind=kind, change=change):
                    if change is None:
                        mutate = lambda d, k=kind: fence_of(d)["markers"].update({k: None})  # noqa: E731
                    else:
                        mutate = lambda d, k=kind, c=change: fence_of(d)["markers"][k].update(c)  # noqa: E731
                    self.assertEqual(self._refusal(mutate), code)
        # The fence is evaluated before any confinement evidence.
        self.assertEqual(self._refusal(lambda d: (d.update(apparmor_enabled=False),
                                                  fence_of(d)["markers"].update(worker_fence_marker=None))),
                         "confinement_production_jobs_not_fenced")

    def test_exactly_stopped_non_autostarting_programs_and_inactive_supervisor_pass(self):
        data = observation()
        data["production_jobs_fence"]["supervisor"]["programs"] = [
            {"name": "wpcaregrid-production-default", "state": "STOPPED", "autostart": False},
            {"name": "workers:wpcaregrid-production-backups_00", "state": "STOPPED", "autostart": False}]
        self._passes(data)
        data = observation()
        data["services"]["jobs-supervisor"].update(active_state="inactive", main_pid=0, main_label="")
        data["same_uid_peers"]["processes"] = [p for p in data["same_uid_peers"]["processes"]
                                               if p["unit"] != "supervisor.service"]
        data["service_members"]["processes"] = [p for p in data["service_members"]["processes"]
                                                if p["unit"] != "supervisor.service"]
        data["production_jobs_fence"]["supervisor"].update(runtime_available=False, programs=[
            {"name": "wpcaregrid-production-default", "state": "SUPERVISOR_INACTIVE", "autostart": False}])
        self._passes(data)

    def test_unbound_contract_refuses_observation(self):
        env = replace(resolved(), php_fpm_confinement=None)
        with patch.object(CONTRACT, "CONFINEMENT_PIN", PIN), \
                self.assertRaisesRegex(CONTRACT.EnvironmentRefused, "confinement_not_bound"):
            CONTRACT.verify_confinement_observation(env, observation())

    def test_contract_is_pure_policy(self):
        source = (ROOT / "scripts/deployment_environment_contract_2_1_6.py").read_text()
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
    def test_timed_out_fixed_command_kills_its_process_group(self):
        if sys.platform != "linux":
            self.skipTest("process group timeout behavior is verified on Linux CI")
        with tempfile.TemporaryDirectory() as tmp:
            pid_file = Path(tmp) / "child.pid"
            child = "import time; time.sleep(30)"
            parent = ("import subprocess,sys,time; "
                      f"p=subprocess.Popen([sys.executable,'-c',{child!r}]); "
                      f"open({str(pid_file)!r},'w').write(str(p.pid)); time.sleep(30)")
            with self.assertRaisesRegex(BACKEND.DeployRefused, "^runtime_probe_timeout$"):
                # Leave room for a loaded CI runner to start both Python
                # interpreters before the timeout path is exercised.
                BACKEND._run_fixed([sys.executable, "-c", parent], timeout=3.0, maximum=4096)
            self.assertTrue(pid_file.exists(), "grandchild should have started before timeout")
            child_pid = int(pid_file.read_text())
            deadline = time.monotonic() + 2
            while time.monotonic() < deadline:
                try:
                    raw = Path(f"/proc/{child_pid}/stat").read_text()
                except FileNotFoundError:
                    break
                state = raw.rsplit(") ", 1)[1].split()[0]
                if state in {"Z", "X"}:
                    break
                time.sleep(0.02)
            else:
                self.fail("timed out command left a running grandchild")

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
        with patch.object(CONTRACT, "CONFINEMENT_PIN", None), \
                patch.object(BACKEND, "_verify_directory_chain") as chain, \
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

    def test_application_php_runs_only_as_exact_commands_under_the_matching_cli_profile(self):
        profile = production_profile()
        calls, gates = [], []
        probe = list(BACKEND._deploy_cli_commands(SOCKET)[0])
        with patch.object(BACKEND.os, "geteuid", return_value=0), \
                patch.object(BACKEND.pwd, "getpwuid", return_value=SimpleNamespace(pw_name="www-data")), \
                patch.object(BACKEND.grp, "getgrgid", return_value=SimpleNamespace(gr_name="www-data")), \
                patch.object(BACKEND, "_verify_php_fpm_confinement",
                             side_effect=lambda p, **_kw: gates.append(p)), \
                patch.object(BACKEND, "_run_fixed", side_effect=lambda argv, **kw: calls.append(argv) or b"{}"):
            BACKEND._run_as_php_fpm(profile, probe, cwd=Path("/"), timeout=5)
            self.assertEqual(calls[0], ["/usr/sbin/runuser", "--user", "www-data", "--group", "www-data", "--",
                                        "/usr/bin/aa-exec", "-p", "wpcaregrid-php-cli-production-deploy", "--",
                                        *probe])
            self.assertEqual(len(gates), 1, "inventory re-checked after the PHP operation")
            for index, argv in enumerate(BACKEND._build_cli_commands(), start=1):
                BACKEND._run_as_php_fpm(profile, list(argv), cwd=Path("/"), timeout=5)
                self.assertEqual(calls[index], ["/usr/sbin/runuser", "--user", "www-data", "--group", "www-data",
                                                "--", "/usr/bin/aa-exec", "-p",
                                                "wpcaregrid-php-cli-production-build", "--", *argv])
            self.assertEqual(len(gates), 3, "inventory re-checked after every PHP operation")
            for argv in (["/usr/bin/php8.5", "-v"], ["/usr/local/bin/php", "-r", probe[2]],
                         ["/usr/bin/php8.5", "artisan", "migrate", "--force"],
                         ["/usr/bin/php8.5", "-r", BACKEND._MIGRATION_RUNNER_SCRIPT]):
                with self.subTest(argv=argv[:3]), \
                        self.assertRaisesRegex(BACKEND.DeployRefused, "deploy_cli_command_not_allowed"):
                    BACKEND._run_as_php_fpm(profile, argv, cwd=Path("/"), timeout=5)
            self.assertEqual(len(calls), 3)
        # A failed PHP command still re-checks, and a confinement refusal takes precedence.
        with patch.object(BACKEND.os, "geteuid", return_value=0), \
                patch.object(BACKEND.pwd, "getpwuid", return_value=SimpleNamespace(pw_name="www-data")), \
                patch.object(BACKEND.grp, "getgrgid", return_value=SimpleNamespace(gr_name="www-data")), \
                patch.object(BACKEND, "_run_fixed", side_effect=BACKEND.DeployRefused("runtime_probe_failed_1")), \
                patch.object(BACKEND, "_verify_php_fpm_confinement",
                             side_effect=BACKEND.DeployRefused("confinement_same_uid_peer_unconfined")):
            with self.assertRaisesRegex(BACKEND.DeployRefused, "confinement_same_uid_peer_unconfined"):
                BACKEND._run_as_php_fpm(profile, probe, cwd=Path("/"), timeout=5)

    def test_deploy_and_build_cli_commands_are_the_helpers_actual_php_calls(self):
        deploy = BACKEND._deploy_cli_commands(SOCKET)
        build = BACKEND._build_cli_commands()
        self.assertEqual(len(deploy), 1)
        self.assertEqual(len(build), 2)
        self.assertTrue(all(argv[0] == "/usr/bin/php8.5" for argv in (*deploy, *build)))
        self.assertEqual([argv[1:3] for argv in build],
                         [("artisan", "package:discover"), ("artisan", "config:cache")])
        source = (ROOT / "scripts/deployment_backend_v2_1_6.py").read_text()
        self.assertEqual(source.count("_run_as_php_fpm(profile,"), 5)
        self.assertIn('runner = "/usr/local/bin/php" if profile.test_only else PHP_BINARY', source)
        self.assertNotIn('_run_as_php_fpm(profile, ["/usr/local/bin/php"', source)
        old = (ROOT / "scripts/deployment_backend_v2_1_2.py").read_text()
        self.assertIn('output = _run_as_php_fpm(profile, ["/usr/local/bin/php", "-r", script]', old)
        self.assertEqual(len(set(BACKEND.deploy_cli_cmdline_sha256(SOCKET))), 1)
        self.assertEqual(len(set(BACKEND.build_cli_cmdline_sha256())), 2)
        self.assertFalse(set(BACKEND.deploy_cli_cmdline_sha256(SOCKET))
                         & set(BACKEND.build_cli_cmdline_sha256()))

    def test_release_activation_is_followed_by_the_peer_gate_before_any_receipt(self):
        source = (ROOT / "scripts/deployment_backend_v2_1_6.py").read_text()
        body = source[source.index("def deploy_release("):]
        activation = body.index("_atomic_symlink(final, profile.application_link")
        acceptance = body.index('raise DeployRefused("post_deploy_acceptance_failed")', activation)
        gate = body.index("_verify_php_fpm_confinement(profile)", acceptance)
        self.assertLess(gate, body.index("_write_receipt(profile", acceptance))
        self.assertLess(gate, body.index("except DeployRefused as error:", acceptance))

# ==========================================================================
# Backend: untrusted transfer input (no-follow, race, copy into root inbox)
# ==========================================================================

class DatabaseSocketPolicyTests(unittest.TestCase):
    """Runtime identity probe: exact policy Unix socket only, no TCP, no other instance."""

    def test_policy_requires_the_exact_production_instance_socket(self):
        self.assertEqual(resolved().database_socket, SOCKET)
        self.assertEqual(CONTRACT.PRODUCTION_DATABASE_SOCKET, SOCKET)
        g1 = _load("g1_contract_for_socket", "scripts/production_g1_mysql_contract.py")
        self.assertEqual(g1.INSTANCES["wpcaregrid-production"].socket, SOCKET)  # repository source default
        self.assertEqual(g1.INSTANCES["wpcaregrid-production"].unit, CONTRACT.PRODUCTION_DATABASE_UNIT)
        with patch.object(CONTRACT, "CONFINEMENT_PIN", PIN):
            for value, code in (("/run/mysqld/mysqld.sock", "production_database_socket_mismatch"),
                                ("/run/mysqld/wpcaregrid-production-restore.sock",
                                 "production_database_socket_mismatch"),
                                ("/run/mysqld/other.sock", "production_database_socket_mismatch"),
                                ("/var/run/mysqld/wpcaregrid-production.sock", "production_database_socket_invalid"),
                                ("/run/mysqld/../mysqld/wpcaregrid-production.sock",
                                 "production_database_socket_invalid"),
                                ("127.0.0.1:3306", "production_database_socket_invalid"),
                                (None, "production_database_socket_invalid")):
                with self.subTest(value=value), self.assertRaisesRegex(CONTRACT.EnvironmentRefused, f"^{code}$"):
                    CONTRACT.resolve_environment("production", production_config=production_config(
                        database_socket=value))
            config = production_config()
            del config["database_socket"]
            with self.assertRaisesRegex(CONTRACT.EnvironmentRefused, "production_profile_schema_invalid"):
                CONTRACT.resolve_environment("production", production_config=config)

    def test_probe_embeds_only_the_policy_socket_and_never_reads_the_environment(self):
        script = BACKEND._runtime_probe_script(SOCKET)
        self.assertIn(f"$wpcgSocket='{SOCKET}';", script)
        self.assertIn('unix_socket"=>$wpcgSocket', script)
        self.assertIn("@@socket AS server_socket", script)
        self.assertIn("PDO::ATTR_CONNECTION_STATUS", script)
        for forbidden in ("getenv", "$_ENV", "$_SERVER", "DB_SOCKET", "env(", "127.0.0.1", "3306", 'port"=>3'):
            self.assertNotIn(forbidden, script)
        for value in ("/run/mysqld/mysqld.sock", "/run/mysqld/x';system('id');'.sock", None):
            with self.subTest(value=value), patch.object(BACKEND, "BOUND_ENVIRONMENT", None), \
                    self.assertRaisesRegex(BACKEND.DeployRefused, "database_socket_policy_invalid"):
                BACKEND._runtime_probe_script(value)  # None: no bound root policy, nothing to fall back to
        # The socket is bound into the deploy-CLI command-line allowlist.
        with patch.object(CONTRACT, "PRODUCTION_DATABASE_SOCKET", "/run/mysqld/other.sock"):
            other = BACKEND.deploy_cli_cmdline_sha256("/run/mysqld/other.sock")
        self.assertNotEqual(other[0], BACKEND.deploy_cli_cmdline_sha256(SOCKET)[0])

    def test_rendered_probe_is_valid_php(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("php CLI not installed here; CI runs this check")
        with tempfile.TemporaryDirectory() as tmp:
            target = Path(tmp) / "probe.php"
            target.write_text("<?php " + BACKEND._runtime_probe_script(SOCKET) + "\n")
            result = subprocess.run([php, "-l", str(target)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def _runtime(self, **probe_changes):
        profile = production_profile()
        result = {"database": "wpcg_prod_main", "version": "8.4.13", "uuid_sha256": "c" * 64,
                  "server_socket": SOCKET, "connection_status": "Localhost via UNIX socket",
                  "application_environment": "production", "deployment_environment": "production",
                  "application_url": "https://www.wpcaregrid.com", "debug": False,
                  "marketing_profile": "marketing_only", "marketing_enforcement": "enforced",
                  "staging_admin_qa_enabled": False}
        result.update(probe_changes)
        calls = []

        def run_fixed(argv, **kwargs):
            return b"8.5.1" if argv[-1] == "echo PHP_VERSION;" else b""

        def run_php(profile_arg, argv, **kwargs):
            calls.append(argv)
            return json.dumps(result).encode()

        with patch.object(BACKEND, "_run_fixed", side_effect=run_fixed), \
                patch.object(BACKEND, "_verify_environment_nginx_route"), \
                patch.object(BACKEND, "_verify_database_socket") as socket_check, \
                patch.object(BACKEND, "_run_as_php_fpm", side_effect=run_php):
            outcome = BACKEND._verify_runtime(profile, Path("/var/www/wpcaregrid-production/releases/x"))
        socket_check.assert_called_once()
        self.assertEqual(calls[0], ["/usr/bin/php8.5", "-r", BACKEND._runtime_probe_script(SOCKET)])
        return outcome

    def test_runtime_accepts_only_the_exact_unix_socket(self):
        self.assertEqual(self._runtime()["database"], "wpcg_prod_main")
        for changes, code in (
                ({"connection_status": "127.0.0.1 via TCP/IP"}, "database_connection_not_unix_socket"),
                ({"connection_status": "localhost via TCP/IP"}, "database_connection_not_unix_socket"),
                ({"server_socket": "/run/mysqld/mysqld.sock"}, "database_socket_mismatch"),
                ({"server_socket": "/run/mysqld/wpcaregrid-production-restore.sock"}, "database_socket_mismatch"),
                ({"uuid_sha256": "d" * 64}, "database_identity_mismatch"),
                ({"connection_status": None}, "database_connection_not_unix_socket")):
            with self.subTest(changes=changes), self.assertRaisesRegex(BACKEND.DeployRefused, f"^{code}$"):
                self._runtime(**changes)
        with self.assertRaisesRegex(BACKEND.DeployRefused, "production_marketing_environment_unavailable"):
            profile = production_profile()
            BACKEND._verify_marketing_environment(profile, {"database": "x"})


class DatabaseSocketHostCheckTests(unittest.TestCase):
    """Root-side: the policy path must be the single listener of the production mysqld."""

    def setUp(self):
        import socket as socket_module
        self.socket_module = socket_module
        self.tmp = Path(tempfile.mkdtemp(prefix="wpcg", dir="/tmp"))
        self.tmp.chmod(0o755)
        (self.tmp / "run" / "mysqld").mkdir(parents=True)
        for directory in (self.tmp / "run", self.tmp / "run" / "mysqld"):
            directory.chmod(0o755)
        self.path = self.tmp / "run" / "mysqld" / "wpcaregrid-production.sock"
        self.listener = socket_module.socket(socket_module.AF_UNIX, socket_module.SOCK_STREAM)
        self.listener.bind(str(self.path))
        self.listener.listen(1)
        self.proc = self.tmp / "proc"
        (self.proc / "net").mkdir(parents=True)
        self.rows = [(SOCKET, "00010000", "01", 4242)]
        self.mysqld(4100, exe="/usr/sbin/mysqld", inodes=(4242, 77))
        self.unit = {"Id": "mysql@wpcaregrid-production.service", "LoadState": "loaded", "ActiveState": "active",
                     "MainPID": "4100", "AppArmorProfile": "wpcaregrid-mysql-production"}

    def tearDown(self):
        self.listener.close()
        shutil.rmtree(self.tmp)

    def mysqld(self, pid, *, exe, inodes):
        base = self.proc / str(pid)
        shutil.rmtree(base, ignore_errors=True)
        (base / "fd").mkdir(parents=True)
        os.symlink(exe, base / "exe")
        for index, inode in enumerate(inodes):
            os.symlink(f"socket:[{inode}]", base / "fd" / str(index))

    def check(self):
        table = "Num       RefCount Protocol Flags    Type St Inode Path\n" + "".join(
            f"0000000000000000: 00000002 00000000 {flags} 0001 {state} {inode} {path}\n"
            for path, flags, state, inode in self.rows)
        (self.proc / "net" / "unix").write_text(table)
        with patch.object(BACKEND, "BOUND_ENVIRONMENT", resolved()), \
                patch.object(BACKEND, "DATABASE_SOCKET_ROOT", self.tmp), \
                patch.object(BACKEND, "CONFINEMENT_PROC_ROOT", self.proc), \
                patch.object(BACKEND.pwd, "getpwnam", return_value=SimpleNamespace(pw_uid=os.getuid())), \
                patch.object(BACKEND, "_run_fixed", side_effect=lambda argv, **kw: "".join(
                    f"{k}={v}\n" for k, v in self.unit.items()).encode()):
            BACKEND._verify_database_socket(production_profile())

    def refused(self, code):
        with self.assertRaisesRegex(BACKEND.DeployRefused, f"^{code}$"):
            self.check()

    def test_exact_listening_socket_of_the_production_mysqld_passes(self):
        self.check()

    def test_alternate_unix_socket_or_tcp_only_listener_is_refused(self):
        self.rows = [("/run/mysqld/mysqld.sock", "00010000", "01", 4242)]
        self.refused("database_socket_listener_invalid")  # only another instance's socket listens
        self.rows = []
        self.refused("database_socket_listener_invalid")  # TCP-only server: no Unix listener at all
        self.rows = [(SOCKET, "00000000", "03", 4242)]
        self.refused("database_socket_listener_invalid")  # a connected client, not a listener

    def test_second_listener_on_the_same_path_is_refused(self):
        self.rows = [(SOCKET, "00010000", "01", 4242), (SOCKET, "00010000", "01", 5353)]
        self.refused("database_socket_listener_invalid")

    def test_listener_not_owned_by_the_production_mysqld_is_refused(self):
        self.rows = [(SOCKET, "00010000", "01", 5353)]
        self.refused("database_socket_owner_mismatch")
        self.rows = [(SOCKET, "00010000", "01", 4242)]
        self.mysqld(4100, exe="/usr/bin/socat", inodes=(4242,))
        self.refused("database_socket_owner_mismatch")
        self.mysqld(4100, exe="/usr/sbin/mysqld", inodes=(4242,))
        self.unit["ActiveState"] = "inactive"
        self.refused("database_socket_owner_mismatch")

    def test_socket_path_type_owner_and_parents_are_enforced(self):
        self.listener.close()
        self.path.unlink()
        self.refused("database_socket_unavailable")
        self.path.write_text("not a socket")
        self.refused("database_socket_invalid")
        self.path.unlink()
        self.path.symlink_to(self.tmp / "elsewhere.sock")
        self.refused("database_socket_invalid")
        self.path.unlink()
        self.listener = self.socket_module.socket(self.socket_module.AF_UNIX, self.socket_module.SOCK_STREAM)
        self.listener.bind(str(self.path))
        (self.tmp / "run" / "mysqld").chmod(0o777)
        self.refused("database_socket_parent_untrusted")
        (self.tmp / "run" / "mysqld").chmod(0o755)
        with patch.object(BACKEND.pwd, "getpwnam", side_effect=KeyError("mysql")):
            with self.assertRaisesRegex(BACKEND.DeployRefused, "database_socket_owner_unavailable"):
                with patch.object(BACKEND, "BOUND_ENVIRONMENT", resolved()):
                    BACKEND._verify_database_socket(production_profile())


class BootstrapCacheTests(unittest.TestCase):
    """www-data writes the candidate bootstrap/cache; root must never follow what it planted."""

    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp())
        self.release = self.tmp / "releases" / ("a" * 40)
        self.cache = self.release / "bootstrap" / "cache"
        self.cache.mkdir(parents=True)
        (self.cache / ".gitignore").write_text("*\n!.gitignore\n")
        (self.cache / ".gitignore").chmod(0o644)
        self.cache.chmod(0o755)
        self.victim = self.tmp / "victim"
        self.victim.write_text("root-owned target\n")
        self.victim.chmod(0o600)
        uid = os.getuid()
        self.profile = SimpleNamespace(build_application_bootstrap=True, test_only=True, expected_owner_uid=uid,
                                       php_fpm_uid=uid, php_fpm_gid=os.getgid())
        self.owned = []

    def tearDown(self):
        shutil.rmtree(self.tmp)

    def run_bootstrap(self, plant=None):
        def fake_php(profile, argv, **kwargs):
            for name in ("packages.php", "services.php") if "package:discover" in argv else ("config.php",):
                target = self.cache / name
                if not target.exists():
                    target.write_text("<?php return [];\n")
            if plant is not None and "config:cache" in argv:
                plant()
            return b""

        def record(fd, uid, gid):
            self.owned.append((os.readlink(f"/proc/self/fd/{fd}"), uid, gid))

        real_lstat = BACKEND._lstat

        def lstat_model_root_group(path, reason):
            info = real_lstat(path, reason)
            if Path(path).name == ".gitignore":  # non-root test: model the root-group checked-out file
                return SimpleNamespace(st_mode=info.st_mode, st_uid=info.st_uid, st_gid=0, st_nlink=info.st_nlink)
            return info

        with patch.object(BACKEND, "_run_as_php_fpm", side_effect=fake_php), \
                patch.object(BACKEND, "_lstat", side_effect=lstat_model_root_group), \
                patch.object(BACKEND.os, "fchown", side_effect=record), \
                patch.object(BACKEND.os, "chown"), \
                patch.object(BACKEND, "_verify_generated_bootstrap_caches"):
            BACKEND._prepare_application_bootstrap(self.profile, self.release)

    def test_exact_generated_files_are_re_owned_by_descriptor_and_sealed(self):
        self.run_bootstrap()
        names = sorted(Path(path).name for path, _uid, _gid in self.owned)
        self.assertEqual(names, ["cache", "cache", "config.php", "packages.php", "services.php"])
        self.assertEqual(stat.S_IMODE(os.stat(self.cache).st_mode), 0o755)
        self.assertEqual(stat.S_IMODE(os.stat(self.cache / "config.php").st_mode), 0o640)
        self.assertEqual(stat.S_IMODE(os.stat(self.cache / "packages.php").st_mode), 0o644)

    def assert_refused_and_victim_untouched(self, plant, code):
        with self.assertRaisesRegex(BACKEND.DeployRefused, code):
            self.run_bootstrap(plant)
        self.assertEqual(self.victim.read_text(), "root-owned target\n")
        self.assertEqual(stat.S_IMODE(os.stat(self.victim).st_mode), 0o600)
        self.assertNotIn(str(self.victim), [path for path, _uid, _gid in self.owned])
        self.assertEqual(stat.S_IMODE(os.lstat(self.cache).st_mode), 0o700, "left sealed")

    def test_symlink_planted_as_a_generated_file_is_never_followed(self):
        def plant():
            (self.cache / "config.php").unlink()
            (self.cache / "config.php").symlink_to(self.victim)
        self.assert_refused_and_victim_untouched(plant, "bootstrap_cache_unexpected_entry")

    def test_extra_entry_hard_link_or_directory_is_refused(self):
        self.assert_refused_and_victim_untouched(
            lambda: (self.cache / "routes-v7.php").write_text("x"), "bootstrap_cache_unexpected_entry")
        self.setUp()
        self.assert_refused_and_victim_untouched(
            lambda: os.link(self.cache / "services.php", self.tmp / "keep"), "bootstrap_cache_unexpected_entry")
        self.setUp()
        self.assert_refused_and_victim_untouched(
            lambda: (self.cache / "events.php").mkdir(), "bootstrap_cache_unexpected_entry")

    def test_swap_to_symlink_after_entry_check_is_refused_by_o_nofollow(self):
        real = BACKEND._verify_cache_entries

        def swap_after_check(profile, cache, *, required):
            real(profile, cache, required=required)
            (cache / "packages.php").unlink()
            (cache / "packages.php").symlink_to(self.victim)

        with patch.object(BACKEND, "_verify_cache_entries", side_effect=swap_after_check):
            self.assert_refused_and_victim_untouched(None, "bootstrap_package_cache_metadata_invalid")

    def test_activation_re_verifies_the_sealed_cache_first(self):
        source = (ROOT / "scripts/deployment_backend_v2_1_6.py").read_text()
        body = source[source.index("def deploy_release("):]
        self.assertLess(body.index("_verify_generated_bootstrap_caches(profile, final)\n        activated = True"),
                        body.index("_atomic_symlink(final, profile.application_link"))
        for name in ("_prepare_application_bootstrap", "_prepare_package_discovery_cache"):
            block = source[source.index(f"def {name}("):]
            block = block[:block.index("\ndef ", 1)]
            self.assertNotIn("os.chown(generated", block)
            self.assertNotIn("os.chown(config_cache", block)
            self.assertIn("_seal_cache_directory(profile, cache, mode=0o700)", block)


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
                # Create the replacement while the original inode is still
                # allocated, then atomically substitute it.  Unlink+create
                # can immediately reuse the old inode on some filesystems,
                # making the race fixture nondeterministic.
                replacement = f".{self.APP}.replacement"
                fd = original_open(replacement, os.O_WRONLY | os.O_CREAT | os.O_EXCL,
                                   0o600, dir_fd=kwargs["dir_fd"])
                os.write(fd, self.payload)
                os.close(fd)
                os.unlink(path, dir_fd=kwargs["dir_fd"])
                os.rename(replacement, path, src_dir_fd=kwargs["dir_fd"], dst_dir_fd=kwargs["dir_fd"])
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
        self.cgroups = self.tmp / "cgroups"
        self.cgroups.mkdir()
        self.units = {}
        for offset, role in enumerate(CONTRACT.REQUIRED_ROLES):
            binding = CONTRACT.CONFINEMENT_SERVICES[role]
            label = f"{binding.apparmor_profile} (enforce)"
            active = role not in {"jobs-scheduler", "jobs-legacy-scheduler"}
            master = 2000 + offset * 10
            self.units[binding.unit] = {"Id": binding.unit, "LoadState": "loaded",
                                        "ActiveState": "active" if active else "inactive",
                                        "MainPID": str(master if active else 0),
                                        "AppArmorProfile": binding.apparmor_profile}
            if not active:
                continue
            exe = FPM_EXE.get(role, "/usr/bin/php8.5")
            self.process(master, uid=33 if role == "production" else 0, unit=binding.unit,
                         label=label, exe=exe, comm="master")
            for worker in (master + 1, master + 2):
                self.process(worker, unit=binding.unit, label=label, exe=exe, ppid=master)
        self.process(1, uid=0, unit="", label="unconfined", exe="/usr/lib/systemd/systemd", comm="systemd")
        self.helper_pid = os.getpid()
        self.process(self.helper_pid, uid=0, unit="", label="unconfined", exe="/usr/bin/python3.12",
                     comm="wpcaregrid-depl", cgroup=HELPER_CGROUP, starttime=1)
        self.profiles = self.tmp / "profiles"
        self.profiles.write_text("".join(f"{b.apparmor_profile} (enforce)\n"
                                         for b in CONTRACT.CONFINEMENT_SERVICES.values())
                                 + "wpcaregrid-php-cli-production-deploy (enforce)\n"
                                 + "wpcaregrid-php-cli-production-build (enforce)\nother (complain)\n")
        self.enabled = self.tmp / "enabled"
        self.enabled.write_text("Y\n")
        (self.tmp / "cron.d").mkdir()
        (self.tmp / "cron.d" / "php").write_text("09,39 * * * * root [ -x /usr/lib/php/sessionclean ]\n")
        (self.tmp / "crontab").write_text("SHELL=/bin/sh\n17 * * * * root cd / && run-parts --report /etc/cron.hourly\n")
        for unit in FENCE_UNITS:
            self.units[unit] = {"Id": unit, "LoadState": "masked", "ActiveState": "inactive", "UnitFileState": "masked"}
        self.markers = self.tmp / "markers"
        for kind, path in (("worker_fence_marker", "/var/lib/wpcaregrid-deploy/production/state/workers-disabled"),
                           ("scheduler_fence_marker", "/var/lib/wpcaregrid-deploy/production/state/scheduler-paused")):
            target = self.markers / path.lstrip("/")
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(CONTRACT.fence_marker_bytes(kind))
            target.chmod(0o600)
        self.supervisor = self.tmp / "supervisor"
        (self.supervisor / "conf.d").mkdir(parents=True)
        (self.supervisor / "supervisord.conf").write_text(
            "[supervisord]\nlogfile=/var/log/supervisor/supervisord.log\n[include]\nfiles = /etc/supervisor/conf.d/*.conf\n")
        (self.supervisor / "conf.d" / "staging.conf").write_text(
            "[program:wpcaregrid-staging-next-default]\ncommand=/usr/bin/php8.5 /var/www/wpcaregrid-staging-new/artisan"
            " queue:work\nuser=www-data\nautostart=true\n")
        self.supervisorctl = (3, b"wpcaregrid-staging-next-default   RUNNING   pid 2041, uptime 1:00:00\n")

    def tearDown(self):
        shutil.rmtree(self.tmp, ignore_errors=True)

    def process(self, pid, *, unit, label, exe, uid=33, gid=None, ppid=1, comm="php-fpm8.5", state="S",
                starttime=None, fds=("/dev/null",), groups=(), cgroup=None, argv=None):
        gid = (33 if uid == 33 else uid) if gid is None else gid
        base = self.proc / str(pid)
        if base.exists():
            shutil.rmtree(base)
        (base / "attr" / "apparmor").mkdir(parents=True)
        (base / "fd").mkdir()
        (base / "attr" / "apparmor" / "current").write_text(label + "\n")
        (base / "status").write_text(f"Name:\t{comm}\nState:\t{state}\nPPid:\t{ppid}\n"
                                     "Threads:\t1\n"
                                     f"Uid:\t{uid}\t{uid}\t{uid}\t{uid}\nGid:\t{gid}\t{gid}\t{gid}\t{gid}\n"
                                     f"Groups:\t{' '.join(str(g) for g in groups)}\n")
        start = 1000 + pid if starttime is None else starttime
        fields = [state, str(ppid)] + ["0"] * 17 + [str(start)] + ["0"] * 10
        (base / "stat").write_text(f"{pid} ({comm}) " + " ".join(fields) + "\n")
        task = base / "task" / str(pid)
        task.mkdir(parents=True)
        (task / "stat").write_text(f"{pid} ({comm}) " + " ".join(fields) + "\n")
        (base / "comm").write_text(comm + "\n")
        (base / "cmdline").write_bytes(b"".join(a.encode() + b"\x00" for a in argv) if argv
                                       else exe.encode() + b"\x00--secret-free\x00")
        if cgroup is None:
            cgroup = f"/system.slice/{unit}" if unit else "/user.slice/user-1000.slice/session-4.scope"
        (base / "cgroup").write_text(f"0::{cgroup}\n")
        os.symlink(exe, base / "exe")
        for index, target in enumerate(fds):
            os.symlink(target, base / "fd" / str(index))
        self._refresh_cgroups()
        return base

    def collect(self):
        self._refresh_cgroups()
        real_observe = BACKEND._observe_confinement_file

        def run_fixed(argv, **_kwargs):
            return "".join(f"{k}={v}\n" for k, v in self.units[argv[-1]].items()).encode()

        with patch.object(CONTRACT, "CONFINEMENT_PIN", self.pin), \
                patch.object(BACKEND, "CONFINEMENT_PROC_ROOT", self.proc), \
                patch.object(BACKEND, "CONFINEMENT_CGROUP_ROOT", self.cgroups), \
                patch.object(BACKEND, "CONFINEMENT_APPARMOR_PROFILES", self.profiles), \
                patch.object(BACKEND, "CONFINEMENT_APPARMOR_ENABLED", self.enabled), \
                patch.object(BACKEND, "CONFINEMENT_CRONTAB", self.tmp / "www-data-crontab"), \
                patch.object(BACKEND, "CONFINEMENT_SYSTEM_CRONTAB", self.tmp / "crontab"), \
                patch.object(BACKEND, "CONFINEMENT_CRON_D", self.tmp / "cron.d"), \
                patch.object(BACKEND, "CONFINEMENT_AT_SPOOL", self.tmp / "atjobs"), \
                patch.object(BACKEND, "_run_fixed", side_effect=run_fixed), \
                patch.object(BACKEND, "FENCE_MARKER_ROOT", self.markers), \
                patch.object(BACKEND, "SUPERVISOR_CONFIG", self.supervisor / "supervisord.conf"), \
                patch.object(BACKEND, "SUPERVISOR_CONF_D", self.supervisor / "conf.d"), \
                patch.object(BACKEND, "_run_status", side_effect=lambda argv: self.supervisorctl), \
                patch.object(BACKEND, "_observe_confinement_file",
                             side_effect=lambda p: real_observe(self.files_root / str(p).lstrip("/"))):
            env = resolved(self.pin)
            with patch.object(BACKEND, "BOUND_ENVIRONMENT", env):
                return BACKEND._collect_php_fpm_confinement_observation(env)

    def _refresh_cgroups(self):
        service_units = {binding.unit for binding in CONTRACT.CONFINEMENT_SERVICES.values()}
        members = {unit: [] for unit in service_units}
        for entry in self.proc.iterdir():
            if not entry.name.isdigit() or not entry.is_dir():
                continue
            try:
                text = (entry / "cgroup").read_text()
            except OSError:
                continue
            for line in text.splitlines():
                if not line.startswith("0::"):
                    continue
                match = BACKEND._PEER_UNIT_RE.fullmatch(line[3:])
                if match and match.group(1) in members:
                    members[match.group(1)].append(int(entry.name))
        for unit, pids in members.items():
            root = self.cgroups / "system.slice" / unit
            root.mkdir(parents=True, exist_ok=True)
            (root / "cgroup.procs").write_text("".join(f"{pid}\n" for pid in sorted(pids)))

    def verify(self, data):
        # Synthetic files are owned by the test user under /tmp; model root ownership.
        for record in data["files"].values():
            record.update(uid=0, gid=0, parents_trusted=True)
        for record in data["production_jobs_fence"]["markers"].values():
            if record is not None:
                record.update(uid=0, gid=0)
        env = resolved(self.pin, php_fpm_confinement=confinement_policy(self.pin))
        with patch.object(CONTRACT, "CONFINEMENT_PIN", self.pin):
            CONTRACT.verify_confinement_observation(env, data)

    def refusal(self, data=None):
        with self.assertRaises(CONTRACT.EnvironmentRefused) as caught:
            self.verify(data if data is not None else self.collect())
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
        # The production master runs as www-data; other unit masters are root.
        self.assertEqual(len(peers["processes"]), 2 * 4 + 1)
        self.assertEqual(len(data["service_members"]["processes"]), 12)
        first = peers["processes"][0]
        self.assertEqual((first["uids"], first["gids"], first["unit"], first["exe"], first["fds"]),
                         ([33] * 4, [33] * 4, first["cgroup"].rsplit("/", 1)[1], first["exe"], ["/dev/null"]))
        self.assertEqual(data["services"]["production"]["main_label"], "wpcaregrid-php-fpm-production (enforce)")
        self.assertEqual(data["www_data_scheduled_jobs"], {"complete": True, "crontab_present": False,
                                                           "system_cron_entries": 0, "at_jobs": 0})
        self.assertEqual((data["helper"]["pid"], data["helper"]["starttime"], data["helper"]["cgroup"],
                          data["helper"]["deploy_cli_profile_mode"], data["helper"]["build_cli_profile_mode"]),
                         (self.helper_pid, 1, HELPER_CGROUP, "enforce", "enforce"))
        self.assertEqual(data["helper"]["deploy_cli_cmdline_sha256"],
                         BACKEND.deploy_cli_cmdline_sha256(SOCKET))
        self.assertEqual(data["helper"]["build_cli_cmdline_sha256"], BACKEND.build_cli_cmdline_sha256())
        self.verify(data)

    def test_collected_production_jobs_fence(self):
        fence_data = self.collect()["production_jobs_fence"]
        self.assertEqual(set(fence_data["units"]), set(FENCE_UNITS))
        self.assertEqual(fence_data["supervisor"], {"config_complete": True, "runtime_available": True,
                                                    "programs": []})
        self.assertEqual(fence_data["markers"]["worker_fence_marker"]["sha256"],
                         sha256(CONTRACT.fence_marker_bytes("worker_fence_marker")))

    def test_unmasked_or_running_scheduler_is_refused(self):
        self.units[FENCE_UNITS[0]].update(LoadState="loaded", UnitFileState="enabled", ActiveState="active")
        self.assertEqual(self.refusal(), "confinement_production_jobs_not_fenced")
        self.units[FENCE_UNITS[0]].update(LoadState="masked", UnitFileState="masked", ActiveState="inactive")
        self.units[FENCE_UNITS[1]].pop("UnitFileState")
        with self.assertRaisesRegex(BACKEND.DeployRefused, "confinement_production_jobs_unverifiable"):
            self.collect()

    def test_missing_or_tampered_fence_markers_are_refused(self):
        worker = self.markers / "var/lib/wpcaregrid-deploy/production/state/workers-disabled"
        worker.unlink()
        self.assertEqual(self.refusal(), "confinement_production_jobs_not_fenced")
        worker.symlink_to(self.tmp / "enabled")
        self.assertEqual(self.refusal(), "confinement_production_fence_marker_invalid")
        worker.unlink()
        worker.write_bytes(b"schema=1;profile=production;workers=enabled\n")
        worker.chmod(0o600)
        self.assertEqual(self.refusal(), "confinement_production_fence_marker_invalid")
        worker.write_bytes(CONTRACT.fence_marker_bytes("worker_fence_marker"))
        worker.chmod(0o644)
        self.assertEqual(self.refusal(), "confinement_production_fence_marker_invalid")

    def test_production_supervisor_programs_must_be_exactly_stopped_and_not_autostarting(self):
        conf = self.supervisor / "conf.d" / "production.conf"
        conf.write_text("[program:wpcaregrid-production-default]\ncommand=/usr/bin/php8.4 artisan queue:work\n"
                        "autostart=false\nnumprocs=2\nprocess_name=%(program_name)s_%(process_num)02d\n")
        self.supervisorctl = (3, b"wpcaregrid-production-default_00 STOPPED Not started\n"
                                 b"wpcaregrid-production-default_01 STOPPED Not started\n")
        data = self.collect()
        self.assertEqual([p["name"] for p in data["production_jobs_fence"]["supervisor"]["programs"]],
                         ["wpcaregrid-production-default_00", "wpcaregrid-production-default_01"])
        self.verify(data)
        self.supervisorctl = (0, b"wpcaregrid-production-default_00 RUNNING pid 1, uptime 0:01:00\n"
                                 b"wpcaregrid-production-default_01 STOPPED Not started\n")
        self.assertEqual(self.refusal(), "confinement_production_jobs_not_fenced")
        conf.write_text("[program:wpcaregrid-production-default]\ncommand=/usr/bin/php8.4 artisan queue:work\n")
        self.supervisorctl = (3, b"wpcaregrid-production-default STOPPED Not started\n")
        self.assertEqual(self.refusal(), "confinement_production_jobs_not_fenced")  # autostart defaults to true
        conf.write_text("[program:wpcaregrid-production-default]\ncommand=x\nautostart=false\n")
        self.supervisorctl = (3, b"")
        self.assertEqual(self.refusal(), "confinement_production_jobs_not_fenced")  # defined but not loaded

    def test_production_program_is_found_by_command_path_not_only_name(self):
        (self.supervisor / "conf.d" / "laravel.conf").write_text(
            "[program:laravel-worker]\ncommand=/usr/bin/php8.4 /var/www/wpcaregrid-production/current/artisan"
            " queue:work\nautostart=false\n")
        self.supervisorctl = (0, b"laravel-worker RUNNING pid 9, uptime 0:00:10\n")
        self.assertEqual(self.refusal(), "confinement_production_jobs_not_fenced")

    def test_runtime_only_production_program_and_unverifiable_supervisor_are_refused(self):
        self.supervisorctl = (3, b"wpcaregrid-production-backups STOPPED Not started\n")
        self.assertEqual(self.refusal(), "confinement_production_jobs_not_fenced")  # no definition: autostart unknown
        self.supervisorctl = None
        self.assertEqual(self.refusal(), "confinement_production_jobs_unverifiable")
        self.supervisorctl = (2, b"")
        self.assertEqual(self.refusal(), "confinement_production_jobs_unverifiable")
        self.supervisorctl = (3, b"")
        (self.supervisor / "conf.d" / "broken.conf").write_text("[program:x\ncommand=y\n")
        self.assertEqual(self.refusal(), "confinement_production_jobs_unverifiable")

    def test_reproduced_unconfined_uid33_peers_are_inventoried_and_refused(self):
        cases = {
            "nginx": dict(unit="nginx.service", exe="/usr/sbin/nginx", comm="nginx"),
            "php-cli": dict(unit="", exe="/usr/bin/php8.5", comm="php8.5"),
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

    def test_full_rescan_catches_a_process_that_becomes_uid33(self):
        self.process(9050, uid=0, gid=0, unit="", label="unconfined", exe="/usr/bin/python3.12",
                     comm="worker")
        real = BACKEND._inventory_pid
        state = {"changed": False}

        def become_peer(pid, service_units=frozenset()):
            record = real(pid, service_units)
            if pid == 9050 and not state["changed"]:
                state["changed"] = True
                status = self.proc / "9050" / "status"
                raw = status.read_text().replace("Uid:\t0\t0\t0\t0", "Uid:\t33\t33\t33\t33")
                raw = raw.replace("Gid:\t0\t0\t0\t0", "Gid:\t33\t33\t33\t33")
                status.write_text(raw)
            return record

        with patch.object(BACKEND, "_inventory_pid", side_effect=become_peer):
            data = self.collect()
        self.assertIn(9050, [item["pid"] for item in data["same_uid_peers"]["processes"]])
        self.assertEqual(self.refusal(data), "confinement_same_uid_peer_unconfined")

    def test_service_cgroup_includes_root_masters_and_extra_root_processes(self):
        unit = "php8.5-fpm.service"
        master = next(role for role in CONTRACT.REQUIRED_ROLES if CONTRACT.CONFINEMENT_SERVICES[role].unit == unit)
        self.process(9060, uid=0, gid=0, unit=unit, label="unconfined", exe="/bin/sh", comm="sh")
        data = self.collect()
        self.assertIn(9060, [item["pid"] for item in data["service_members"]["processes"]])
        self.assertEqual(self.refusal(data), "confinement_master_not_confined")
        existing_master = next(item for item in data["service_members"]["processes"]
                               if item["unit"] == unit and item["pid"] == data["services"][master]["main_pid"])
        self.assertEqual(existing_master["uids"], [0] * 4)

    def test_root_master_holding_foreign_secret_descriptor_is_refused(self):
        master = self.proc / str(2010)
        (master / "fd" / "77").symlink_to("/var/www/wpcaregrid-production/shared/.env")
        data = self.collect()
        self.assertEqual(self.refusal(data), "confinement_peer_foreign_descriptor")

    def test_unknown_service_with_its_own_profile_is_refused(self):
        self.process(9100, unit="wpcaregrid-unknown.service", label="custom (enforce)", exe="/usr/bin/php8.5")
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
        self.assertIn("peer_pid_reused", data["same_uid_peers"]["incomplete_reasons"])

    def test_pids_appearing_during_the_scan_are_inventoried(self):
        real = BACKEND._inventory_pid
        state = {"spawned": False}

        def spawn(pid, service_units=frozenset()):
            if not state["spawned"]:
                state["spawned"] = True
                self.process(9600, unit="", label="unconfined", exe="/usr/bin/dash", comm="sh")
            return real(pid, service_units)

        with patch.object(BACKEND, "_inventory_pid", side_effect=spawn):
            data = self.collect()
        self.assertIn(9600, [p["pid"] for p in data["same_uid_peers"]["processes"]])
        self.assertEqual(self.refusal(data), "confinement_same_uid_peer_unconfined")

    def test_endless_pid_churn_holds(self):
        real = BACKEND._inventory_pid
        counter = {"next": 20000}

        def churn(pid, service_units=frozenset()):
            counter["next"] += 1
            self.process(counter["next"], uid=0, unit="", label="unconfined", exe="/usr/bin/true", comm="true")
            return real(pid, service_units)

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

    def test_zombie_leader_with_live_www_data_thread_holds_inventory(self):
        base = self.process(9702, unit="", label="unconfined", exe="/usr/bin/php8.5",
                            comm="php", state="Z")
        (base / "status").write_text((base / "status").read_text().replace("Threads:\t1", "Threads:\t2"))
        task = base / "task" / "9703"
        task.mkdir()
        fields = ["S", "9702"] + ["0"] * 17 + ["19703"] + ["0"] * 10
        (task / "stat").write_text("9703 (php-worker) " + " ".join(fields) + "\n")
        data = self.collect()
        self.assertEqual(data["same_uid_peers"]["incomplete_reasons"],
                         ["peer_zombie_leader_has_live_threads"])
        self.assertEqual(self.refusal(data), "confinement_peer_inventory_incomplete")

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
        self.units["php8.5-fpm.service"]["AppArmorProfile"] = "-wpcaregrid-php-fpm-staging-next"
        self.assertEqual(self.refusal(), "confinement_service_attachment_fail_open")
        self.units["php8.5-fpm.service"]["AppArmorProfile"] = "wpcaregrid-php-fpm-staging-next"
        (self.files_root / CONTRACT.CONFINEMENT_FILES[2].lstrip("/")).unlink()
        self.assertEqual(self.refusal(), "confinement_file_missing")

    def test_www_data_crontab_system_cron_and_at_jobs_are_refused(self):
        crontab = self.tmp / "www-data-crontab"
        crontab.write_bytes(b"* * * * * /usr/local/bin/wpcaregrid-scheduler\n")
        self.assertEqual(self.collect()["www_data_scheduled_jobs"]["crontab_present"], True)
        self.assertEqual(self.refusal(), "confinement_www_data_scheduled_job_present")
        crontab.unlink()
        (self.tmp / "cron.d" / "evil").write_text("* * * * * www-data /tmp/x\n@reboot 33 /tmp/y\nbroken line\n")
        self.assertEqual(self.collect()["www_data_scheduled_jobs"]["system_cron_entries"], 3)
        self.assertEqual(self.refusal(), "confinement_www_data_scheduled_job_present")
        (self.tmp / "cron.d" / "evil").unlink()
        (self.tmp / "atjobs").mkdir()
        (self.tmp / "atjobs" / ".SEQ").write_text("1\n")
        job = self.tmp / "atjobs" / "a0000101b2c3d4"
        job.write_text("#!/bin/sh\n")
        real_lstat = os.lstat
        with patch.object(BACKEND.os, "lstat", side_effect=lambda path, *a, **k: SimpleNamespace(
                st_uid=33, st_gid=33, st_mode=0o100700) if Path(path) == job else real_lstat(path, *a, **k)):
            data = self.collect()
        self.assertEqual(data["www_data_scheduled_jobs"]["at_jobs"], 1)
        self.assertEqual(self.refusal(data), "confinement_www_data_scheduled_job_present")
        self.assertEqual(self.collect()["www_data_scheduled_jobs"]["at_jobs"], 0)  # test user's own job

    def test_unreadable_cron_state_is_unverifiable(self):
        (self.tmp / "cron.d" / "locked").write_text("* * * * * root true\n")
        real = BACKEND._read_proc_bytes
        with patch.object(BACKEND, "_read_proc_bytes", side_effect=lambda path, **kw: (_ for _ in ()).throw(
                PermissionError(13, "denied")) if Path(path).name == "locked" else real(path, **kw)):
            data = self.collect()
        self.assertFalse(data["www_data_scheduled_jobs"]["complete"])
        self.assertEqual(self.refusal(data), "confinement_www_data_jobs_unverifiable")

    def test_deploy_cli_peer_in_the_helper_session_passes_and_elsewhere_refuses(self):
        probe = BACKEND._deploy_cli_commands(SOCKET)[0]
        self.process(9950, unit="", label=DEPLOY_CLI_LABEL, exe="/usr/bin/php8.5", comm="php8.5",
                     cgroup=HELPER_CGROUP, argv=probe)
        self.verify(self.collect())
        self.process(9950, unit="", label=DEPLOY_CLI_LABEL, exe="/usr/bin/php8.5", comm="php8.5",
                     cgroup=HELPER_CGROUP, argv=("/usr/bin/php8.5", "artisan", "tinker"))
        self.assertEqual(self.refusal(), "confinement_deploy_cli_command_unexpected")
        self.process(9950, unit="", label=DEPLOY_CLI_LABEL, exe="/usr/bin/php8.5", comm="php8.5", argv=probe)
        self.assertEqual(self.refusal(), "confinement_deploy_cli_outside_helper_session")
        self.process(self.helper_pid, uid=0, unit="", label="unconfined", exe="/usr/bin/python3.12",
                     cgroup="/system.slice/cron.service")
        self.assertEqual(self.refusal(), "confinement_helper_not_root_console_session")

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

    def test_fence_and_confinement_gate_runs_before_every_operation(self):
        for argv in (["preflight"], ["verify-install"], ["authorize", "a" * 40], ["stage", "a" * 40, "b" * 64],
                     ["deploy", "a" * 40, "b" * 64]):
            backend = SimpleNamespace(
                DeployRefused=BACKEND.DeployRefused,
                _verify_php_fpm_confinement=lambda profile: (_ for _ in ()).throw(
                    BACKEND.DeployRefused("confinement_production_jobs_not_fenced")),
                preflight=None, verify_helper_installation=None, authorize_release=None,
                stage_artifact=None, deploy_release=None)
            stderr = []
            with self.subTest(argv=argv), \
                    patch.object(ENTRY, "_require_root_console"), \
                    patch.object(ENTRY, "_load_profile", return_value=(backend, SimpleNamespace(),
                                                                       *ENTRY._parse_operation(argv))), \
                    patch.object(ENTRY.sys.stderr, "write", side_effect=stderr.append):
                self.assertEqual(ENTRY.main(argv), 3)
                self.assertEqual(stderr, ["DEPLOYMENT=HOLD;CODE=confinement_production_jobs_not_fenced\n"])

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
                "_wpcg_deployment_transaction_v2", "_wpcg_deployment_backend_v2_1_6")}
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
        raw = (ROOT / SOURCES["environment_contract_sha256"]).read_bytes()
        unset = re.sub(rb"CONFINEMENT_PIN: ConfinementPin \| None = ConfinementPin\(.*?\n\)\n",
                       b"CONFINEMENT_PIN: ConfinementPin | None = None\n", raw, count=1, flags=re.S)
        self.assertNotEqual(unset, raw)
        policy, run = self._fixture(unset)
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
        pattern = re.compile(rb"CONFINEMENT_PIN: ConfinementPin \| None = ConfinementPin\(.*?\n\)\n", re.S)
        self.assertIsNotNone(pattern.search(raw))
        pin_source = ("CONFINEMENT_PIN: ConfinementPin | None = ConfinementPin(\"1.0.0\", \"" + "a" * 64
                      + "\", \"" + "b" * 40 + "\", MappingProxyType(" + repr(dict(PIN.file_sha256)) + "))\n").encode()
        policy, run = self._fixture(pattern.sub(lambda _match: pin_source, raw, count=1))
        backend, profile, operation, app_sha, artifact_sha = run(policy, ("stage", "a" * 40, "b" * 64))
        self.assertEqual((operation, app_sha, artifact_sha), ("stage", "a" * 40, "b" * 64))
        self.assertEqual((profile.allowed_uid, profile.transfer_uid, profile.php_fpm_uid), (0, TRANSFER_UID, 33))
        self.assertEqual(profile.helper_artifact_sha256, policy["helper_artifact_sha256"])
        self.assertIsNotNone(backend.BOUND_ENVIRONMENT.php_fpm_confinement)

    def test_backend_bound_to_another_environment_module_is_refused(self):
        raw = (ROOT / SOURCES["environment_contract_sha256"]).read_bytes()
        saved = {name: sys.modules.get(name) for name in (
            ENTRY.ENVIRONMENT_MODULE_NAME, "_wpcg_release_review_policy_v3", "_wpcg_deployment_transaction_v2",
            "_wpcg_deployment_backend_v2_1_6")}
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
                ENTRY._load_environment_module(raw.replace(b'CONTRACT_VERSION = "2.1.6"', b'CONTRACT_VERSION = "2.1.2"'))
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
        payload = dict(value, helper_version="2.1.6", helper_contract="wpcaregrid-deployment-profile-v2.2",
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
        source = (ROOT / "scripts/production_deploy_controller_2_1_6.py").read_text()
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
        self.source = t / "home-deploy" / ".private" / "wpcg-production-helper-2.1.6"
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
            helper_dir=t / "usr-libexec-wpcg" / "production" / "2.1.6",
            policy=t / "etc-wpcg" / "profiles" / "production-v2.1.6.json",
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
        self.assertEqual(receipt["php_fpm_confinement"], {
            "schema": 3, "package_version": "1.0.7",
            "sha256sums_sha256": CONTRACT.CONFINEMENT_PIN.sha256sums_sha256,
            "package_tree": CONTRACT.CONFINEMENT_PIN.package_tree, "files": 14,
            "services": list(CONTRACT.REQUIRED_ROLES), "transients": list(CONTRACT.TRANSIENT_ROLES),
            "binding_sha256": CONTRACT.confinement_binding_sha256()})
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
        (self.layout.helper_dir / "deployment_backend_v2_1_6.py").unlink()
        message = self.refused("partial_installation_detected")
        self.assertIn("RECOVERY=REINSTALL_AFTER_MANUAL_CLEANUP", message)
        self.assertFalse((self.layout.helper_dir / "deployment_backend_v2_1_6.py").exists())

    def test_leftover_private_inbox_is_a_partial_install(self):
        self.layout.inbox_parent.mkdir(mode=0o700)
        self.layout.inbox.mkdir(mode=0o700)
        message = self.refused("partial_installation_detected")
        self.assertIn("RECOVERY=REMOVE_STALE_INBOX", message)

    def test_partial_install_recovery_is_advisory_and_never_mutates(self):
        self.install()
        backend = self.layout.helper_dir / "deployment_backend_v2_1_6.py"
        backend.unlink()
        before = {path: (path.exists(), path.read_bytes() if path.is_file() else None)
                  for _name, path, _mode in self.layout.targets()}
        self.assertEqual(INSTALLER._recovery(self.layout), "REINSTALL_AFTER_MANUAL_CLEANUP")
        after = {path: (path.exists(), path.read_bytes() if path.is_file() else None)
                 for _name, path, _mode in self.layout.targets()}
        self.assertEqual(after, before)
        self.assertFalse(backend.exists(), "recovery classification never repairs")

    def test_disabled_policy_is_the_only_installed_policy(self):
        receipt = self.install()
        policy = json.loads(self.layout.policy.read_text())
        self.assertIs(policy["enabled"], False)
        self.assertTrue(all(policy[field] is None for field in CONTRACT.environment_fields()
                            if field not in {"privileged_invocation", "php_fpm_confinement"}))
        self.assertEqual(policy["privileged_invocation"], "root-console-direct")
        self.assertIsNone(policy["php_fpm_confinement"])
        self.assertEqual(receipt["profile_enabled"], False)
        self.assertEqual(receipt["phase_b_policy"], "DISABLED")

    def test_existing_target_is_never_overwritten(self):
        self.layout.helper_parent.mkdir(mode=0o755)
        self.layout.helper_dir.mkdir(mode=0o755)
        foreign = self.layout.helper_dir / "deployment_backend_v2_1_6.py"
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
        rule = self.tmp / "sudoers.d" / "wpcaregrid-production-v2.1.6"
        rule.write_text("deploy ALL=(root) NOPASSWD: /usr/local/libexec/wpcaregrid-deploy/production/2.1.6/x\n")
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
        source = (ROOT / "scripts/install_production_helper_2_1_6.py").read_text()
        for forbidden in ("useradd", "groupadd", "usermod", "visudo\", \"-cf", "chpasswd", "adduser"):
            self.assertNotIn(forbidden, source)
        self.assertNotRegex(source, r"_publish\([^)]*sudoers")


if __name__ == "__main__":
    unittest.main(verbosity=1)
