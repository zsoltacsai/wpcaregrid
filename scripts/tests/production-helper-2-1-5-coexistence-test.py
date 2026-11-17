#!/usr/bin/env python3
"""Helper 2.1.5 (GO-20 PHP 8.5): coexistence with the immutable 2.1.4, the 1.0.6 pin and PHP 8.5 identities.

Synthetic tests only: temporary trees and fake runners, no host, sudoers, service or
network. They prove source behaviour, not kernel enforcement of AppArmor profiles.
"""

from __future__ import annotations

import hashlib
import importlib.util
import json
import os
import re
import shutil
import subprocess
import sys
import unittest
from dataclasses import replace
from pathlib import Path
from unittest.mock import patch

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))


def _load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


# The inherited synthetic suite owns the fixtures (installer tree, observations, fake runner).
FULL = _load("production_helper_2_1_5_full_for_coexistence", Path(__file__).with_name("production-helper-2-1-5-full-test.py"))
BACKEND, CONTRACT, ENTRY, CONTROLLER, INSTALLER = FULL.BACKEND, FULL.CONTRACT, FULL.ENTRY, FULL.CONTROLLER, FULL.INSTALLER
OLD_CONTRACT = _load("deployment_environment_contract_2_1_4_for_coexistence",
                     ROOT / "scripts/deployment_environment_contract_2_1_4.py")
PACKAGE = ROOT / "deploy/php-fpm-confinement/1.0.6"
SUMS_SHA256 = "bf6e734ef0489c34006862c706a6d0fc59d62b8c18b00bbe04a276512c0a5e18"
TREE_106 = "6d239b64d5637971bf28ec7a2de6d7a5686804df"
TREE_105 = "e8e59b769ed6c6acd85a3bf259364bace5ec374b"

PROD_UNIT = "wpcaregrid-production-php8.5-fpm.service"
PROD_SOCKET = "/run/php/wpcaregrid-production-php8.5-fpm.sock"
STAGING_NEXT_UNIT = "php8.5-fpm.service"


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def git_tree(relative: str):
    try:
        result = subprocess.run(["git", "rev-parse", f"HEAD:{relative}"], cwd=ROOT, capture_output=True, check=False)
    except OSError:
        return None
    return result.stdout.decode().strip() if result.returncode == 0 else None


# ==========================================================================
# The pin binds the real 1.0.6 package bytes
# ==========================================================================

class PinBindsPackage106Tests(unittest.TestCase):
    def test_pin_equals_the_package_bytes(self):
        pin = CONTRACT.confinement_pin()
        self.assertEqual(pin.package_version, "1.0.6")
        self.assertEqual(pin.sha256sums_sha256, SUMS_SHA256)
        self.assertEqual(sha256((PACKAGE / "SHA256SUMS").read_bytes()), SUMS_SHA256)
        self.assertEqual(pin.package_tree, TREE_106)
        binding = json.loads((PACKAGE / "binding.json").read_text())
        self.assertEqual(binding["package_version"], "1.0.6")
        sums = {line.split()[1]: line.split()[0] for line in (PACKAGE / "SHA256SUMS").read_text().splitlines()}
        installed = binding["installed_files"]
        self.assertEqual(len(installed), 17)
        self.assertEqual(set(pin.file_sha256), set(installed))
        self.assertEqual(set(CONTRACT.CONFINEMENT_FILES), set(installed))
        for host_path, relative in installed.items():
            digest = sha256((PACKAGE / relative).read_bytes())
            self.assertEqual(sums[relative], digest, relative)
            self.assertEqual(pin.file_sha256[host_path], digest, host_path)

    def test_package_tree_id_is_the_committed_tree(self):
        tree = git_tree("deploy/php-fpm-confinement/1.0.6")
        if tree is None:
            self.skipTest("git tree object unavailable")
        self.assertEqual(tree, TREE_106)
        old = git_tree("deploy/php-fpm-confinement/1.0.5")
        if old is not None:
            self.assertEqual(old, TREE_105, "package 1.0.5 is frozen")

    def test_services_and_transients_match_binding_json(self):
        binding = json.loads((PACKAGE / "binding.json").read_text())
        self.assertEqual(set(binding["services"]), set(CONTRACT.REQUIRED_ROLES))
        self.assertEqual(len(CONTRACT.REQUIRED_ROLES), 7)
        for role, entry in binding["services"].items():
            service = CONTRACT.CONFINEMENT_SERVICES[role]
            self.assertEqual((service.unit, service.apparmor_profile, service.profile_path, service.attachment_path),
                             (entry["unit"], entry["apparmor_profile"], entry["profile_path"], entry["attachment_path"]), role)
            self.assertEqual(set(service.files), set(entry["files"]), role)
        self.assertEqual(tuple(CONTRACT.TRANSIENT_ROLES), ("production-deploy-cli", "production-build-cli"))
        for role, profile in (("production-deploy-cli", "wpcaregrid-php-cli-production-deploy"),
                              ("production-build-cli", "wpcaregrid-php-cli-production-build")):
            transient = CONTRACT.TRANSIENT_CLASSES[role]
            spec = binding["aa_exec_profiles"][profile]
            self.assertEqual((transient.apparmor_profile, transient.profile_path, transient.executable),
                             (profile, spec["profile_path"], spec["exe"]))
            self.assertEqual(transient.executable, "/usr/bin/php8.5")
            self.assertEqual(set(transient.files), set(spec["files"]))

    def test_argv_under_php85_match_the_profile_commands(self):
        binding = json.loads((PACKAGE / "binding.json").read_text())
        deploy = binding["aa_exec_profiles"]["wpcaregrid-php-cli-production-deploy"]["commands_supported"]
        build = binding["aa_exec_profiles"]["wpcaregrid-php-cli-production-build"]["commands_supported"]
        self.assertEqual([c["argv"][:2] for c in deploy], [["/usr/bin/php8.5", "-r"]])
        self.assertEqual([tuple(c["argv"]) for c in build], [
            ("/usr/bin/php8.5", "artisan", "package:discover", "--ansi", "--no-interaction"),
            ("/usr/bin/php8.5", "artisan", "config:cache", "--no-interaction")])
        self.assertEqual(tuple(BACKEND._build_cli_commands()), tuple(tuple(c["argv"]) for c in build))
        probe = BACKEND._deploy_cli_commands(FULL.SOCKET)
        self.assertEqual(len(probe), 1)
        self.assertEqual(probe[0][:2], ("/usr/bin/php8.5", "-r"))
        self.assertIn(FULL.SOCKET, probe[0][2])
        for argv in (("/usr/bin/php8.4", "artisan", "config:cache", "--no-interaction"),
                     ("/usr/bin/php8.5", "artisan", "migrate", "--force"),
                     ("/usr/bin/php8.5", "-v")):
            self.assertNotIn(argv, (*BACKEND._deploy_cli_commands(FULL.SOCKET), *BACKEND._build_cli_commands()))

    def test_binding_digest_is_recomputable_and_distinct_from_2_1_4(self):
        digest = CONTRACT.confinement_binding_sha256()
        raw = json.dumps(CONTRACT.confinement_binding(), sort_keys=True, separators=(",", ":")).encode()
        self.assertEqual(digest, sha256(raw))
        self.assertEqual(digest, "c349684665ac8a563d9b948dd4a03bc63ecd3e06e396fcd34daf5894ca33df4d")
        self.assertEqual(OLD_CONTRACT.confinement_binding_sha256(),
                         "0c189479fca557e8b47a7bfdeddaf753d96a75c24c3a58321cdf81ac2507cee0")
        self.assertNotEqual(digest, OLD_CONTRACT.confinement_binding_sha256())

    def test_contract_still_resolves_only_with_its_own_pin_on_the_old_binding_never(self):
        # A schema-3 policy echoing package 1.0.5 must not resolve against the 1.0.6 pin.
        with patch.object(CONTRACT, "CONFINEMENT_PIN", FULL.PIN):
            config = FULL.production_config()
        old = FULL.confinement_policy(CONTRACT.confinement_pin())
        policy = dict(old, package_version="1.0.5", package_tree=TREE_105,
                      package_sha256sums_sha256="b1ce309cd58c0b19f25091365e875f01b45853762bad4ca3458726c0662f469c")
        with self.assertRaises(CONTRACT.EnvironmentRefused):
            CONTRACT.resolve_environment("production", production_config=dict(config, php_fpm_confinement=policy))


# ==========================================================================
# PHP 8.5 identities
# ==========================================================================

class Php85IdentityTests(unittest.TestCase):
    def test_production_names_and_executables(self):
        production = CONTRACT.CONFINEMENT_SERVICES["production"]
        self.assertEqual(production.unit, PROD_UNIT)
        self.assertEqual(production.attachment_path, "/etc/systemd/system/" + PROD_UNIT)
        self.assertIn("/etc/php/8.5/fpm-wpcaregrid-production/php-fpm.conf", production.files)
        self.assertIn("/etc/php/8.5/fpm-wpcaregrid-production/pool.d/wpcaregrid-production.conf", production.files)
        self.assertEqual(CONTRACT.CLASS_EXECUTABLES["production"], frozenset({"/usr/sbin/php-fpm8.5", "/usr/bin/kill"}))
        self.assertEqual(CONTRACT.CONFINEMENT_SERVICES["staging-legacy"].unit, "php8.4-fpm.service")
        self.assertEqual(CONTRACT.CLASS_EXECUTABLES["staging-legacy"], frozenset({"/usr/sbin/php-fpm8.4"}))
        self.assertEqual(CONTRACT.DEPLOY_CLI_EXECUTABLE, "/usr/bin/php8.5")
        self.assertEqual(CONTRACT.BUILD_CLI_EXECUTABLE, "/usr/bin/php8.5")
        self.assertEqual(CONTRACT.CLASS_EXECUTABLES["production-deploy-cli"], frozenset({"/usr/bin/php8.5"}))
        self.assertEqual(CONTRACT.CLASS_EXECUTABLES["production-build-cli"], frozenset({"/usr/bin/php8.5"}))
        # No PHP 8.4 production identity remains anywhere in the production role set.
        for path in production.files:
            self.assertNotIn("php8.4", path)
            self.assertNotIn("/8.4/", path)

    def test_policy_requires_php85_unit_and_socket(self):
        resolved = FULL.resolved()
        self.assertEqual((resolved.php_version, resolved.php_fpm_unit, resolved.php_fpm_socket),
                         ("8.5", PROD_UNIT, PROD_SOCKET))
        self.assertEqual(resolved.mysql_version, "8.4")
        self.assertEqual(resolved.database_socket, "/run/mysqld/wpcaregrid-production.sock")
        cases = {
            "production_php_version_invalid": {"php_version": "8.4"},
            "production_php_fpm_socket_invalid": {
                "php_fpm_socket": "/run/php/wpcaregrid-production-php8.4-fpm.sock"},
        }
        for code, change in cases.items():
            with self.subTest(code=code), patch.object(CONTRACT, "CONFINEMENT_PIN", FULL.PIN), \
                    self.assertRaisesRegex(CONTRACT.EnvironmentRefused, f"^{code}$"):
                CONTRACT.resolve_environment("production", production_config=FULL.production_config(**change))
        for unit in ("wpcaregrid-production-php8.4-fpm.service", STAGING_NEXT_UNIT, "php8.4-fpm.service"):
            with self.subTest(unit=unit), patch.object(CONTRACT, "CONFINEMENT_PIN", FULL.PIN), \
                    self.assertRaisesRegex(CONTRACT.EnvironmentRefused, "^production_php_fpm_socket_invalid$"):
                CONTRACT.resolve_environment("production", production_config=FULL.production_config(php_fpm_unit=unit))
        # The unix-only database socket contract is unchanged.
        for socket_path in ("/run/mysqld/mysqld.sock", "/run/mysqld/wpcaregrid-production-restore.sock"):
            with self.subTest(socket=socket_path), patch.object(CONTRACT, "CONFINEMENT_PIN", FULL.PIN), \
                    self.assertRaisesRegex(CONTRACT.EnvironmentRefused, "^production_database_socket_mismatch$"):
                CONTRACT.resolve_environment("production",
                                             production_config=FULL.production_config(database_socket=socket_path))

    def test_old_2_1_4_contract_would_refuse_the_php85_policy(self):
        # Proof that 2.1.4 cannot admit the 1.0.6 layout: it stays bound to PHP 8.4 / package 1.0.5.
        with patch.object(OLD_CONTRACT, "CONFINEMENT_PIN", FULL.PIN):
            config = FULL.production_config()
            with self.assertRaises(OLD_CONTRACT.EnvironmentRefused):
                OLD_CONTRACT.resolve_environment("production", production_config=config)

    def test_shared_executable_never_decides_the_class(self):
        data = FULL.observation()
        with patch.object(CONTRACT, "CONFINEMENT_PIN", FULL.PIN):
            CONTRACT.verify_confinement_observation(FULL.resolved(), data)
        production, staging_next = ([p for p in data["same_uid_peers"]["processes"]
                                     if p["unit"] == unit] for unit in (PROD_UNIT, STAGING_NEXT_UNIT))
        self.assertTrue(production and staging_next)
        self.assertEqual({p["exe"] for p in production} | {p["exe"] for p in staging_next}, {"/usr/sbin/php-fpm8.5"})

    def _refusal(self, mutate):
        data = FULL.observation()
        mutate(data)
        with patch.object(CONTRACT, "CONFINEMENT_PIN", FULL.PIN), \
                self.assertRaises(CONTRACT.EnvironmentRefused) as caught:
            CONTRACT.verify_confinement_observation(FULL.resolved(), data)
        return str(caught.exception)

    def test_label_and_unit_pair_is_the_only_identity(self):
        production_label = "wpcaregrid-php-fpm-production (enforce)"
        staging_label = "wpcaregrid-php-fpm-staging-next (enforce)"
        legacy_label = "wpcaregrid-php-fpm-staging-legacy (enforce)"
        fpm85 = "/usr/sbin/php-fpm8.5"
        cases = (
            ("production label in the staging-next unit", dict(unit=STAGING_NEXT_UNIT, label=production_label),
             "confinement_peer_label_outside_unit"),
            ("staging-next label in the production unit", dict(unit=PROD_UNIT, label=staging_label),
             "confinement_peer_label_outside_unit"),
            ("legacy label in the production unit", dict(unit=PROD_UNIT, label=legacy_label),
             "confinement_peer_label_outside_unit"),
            ("unconfined php-fpm8.5 in the production unit", dict(unit=PROD_UNIT, label="unconfined"),
             "confinement_same_uid_peer_unconfined"),
            ("complain-mode label in the production unit",
             dict(unit=PROD_UNIT, label="wpcaregrid-php-fpm-production (complain)"),
             "confinement_process_not_confined"),
        )
        for name, change, code in cases:
            with self.subTest(name=name):
                def mutate(data, change=change):
                    item = FULL.peer(7001, unit=change["unit"], label=change["label"], exe=fpm85)
                    FULL.with_peer(data, item)
                self.assertEqual(self._refusal(mutate), code)

    def test_php84_fpm_binary_is_refused_in_production_but_not_in_legacy(self):
        def php84_in_production(data):
            FULL.with_peer(data, FULL.peer(7002, unit=PROD_UNIT, label="wpcaregrid-php-fpm-production (enforce)",
                                           exe="/usr/sbin/php-fpm8.4"))
        self.assertEqual(self._refusal(php84_in_production), "confinement_peer_executable_unexpected")

        def php85_in_legacy(data):
            FULL.with_peer(data, FULL.peer(7003, unit="php8.4-fpm.service",
                                           label="wpcaregrid-php-fpm-staging-legacy (enforce)",
                                           exe="/usr/sbin/php-fpm8.5"))
        self.assertEqual(self._refusal(php85_in_legacy), "confinement_peer_executable_unexpected")

    def test_cli_classes_require_php85_in_the_helper_session_only(self):
        with patch.object(CONTRACT, "CONFINEMENT_PIN", FULL.PIN):
            CONTRACT.verify_confinement_observation(FULL.resolved(), FULL.with_peer(FULL.observation(), FULL.deploy_cli_peer(7100)))
            CONTRACT.verify_confinement_observation(FULL.resolved(), FULL.with_peer(FULL.observation(), FULL.build_cli_peer(7101)))
        for name, item, code in (
                ("php8.4 deploy class", FULL.deploy_cli_peer(7102, exe="/usr/bin/php8.4"),
                 "confinement_peer_executable_unexpected"),
                ("php8.4 build class", FULL.build_cli_peer(7103, exe="/usr/bin/php8.4"),
                 "confinement_peer_executable_unexpected"),
                ("php8.5 deploy class in a staging unit",
                 FULL.deploy_cli_peer(7104, unit=STAGING_NEXT_UNIT, cgroup="/system.slice/" + STAGING_NEXT_UNIT),
                 "confinement_deploy_cli_outside_helper_session"),
                ("php8.5 build class outside the helper session",
                 FULL.build_cli_peer(7105, cgroup="/user.slice/user-1000.slice/session-3.scope"),
                 "confinement_build_cli_outside_helper_session")):
            with self.subTest(name=name):
                self.assertEqual(self._refusal(lambda data, item=item: FULL.with_peer(data, item)), code)

    def test_transient_commands_run_under_php85_through_aa_exec(self):
        source = Path(BACKEND.__file__).read_text()
        self.assertIn('AA_EXEC = "/usr/bin/aa-exec"', source)
        self.assertIn('prefix += [AA_EXEC, "-p", cli_profile, "--"]', source)
        for argv in (*BACKEND._deploy_cli_commands(FULL.SOCKET), *BACKEND._build_cli_commands()):
            self.assertEqual(argv[0], "/usr/bin/php8.5")
        self.assertEqual(len(BACKEND._deploy_cli_commands(FULL.SOCKET)) + len(BACKEND._build_cli_commands()), 3)


# ==========================================================================
# Installing alongside the immutable 2.1.4
# ==========================================================================

class AlongsideHelper214Tests(unittest.TestCase):
    """The 2.1.5 installer never touches an installed 2.1.4 and never confuses it with a legacy helper."""

    setUp = FULL.InstallerTests.setUp
    tearDown = FULL.InstallerTests.tearDown
    sudoers_bytes = FULL.InstallerTests.sudoers_bytes
    install = FULL.InstallerTests.install
    refused = FULL.InstallerTests.refused

    def install_214(self):
        """Fabricate an already installed, immutable 2.1.4 beside the 2.1.5 targets."""
        directory = self.layout.helper_parent / "2.1.4"
        self.layout.helper_parent.mkdir(mode=0o755, exist_ok=True)
        directory.mkdir(mode=0o755)
        contents = {}
        for name in ("wpcaregrid-deploy-production-priv", "deployment_backend_v2_1_4.py", "deployment_transaction_v2.py",
                     "deployment_environment_contract_2_1_4.py", "release_review_policy_v3_1.py",
                     "production_deploy_controller.py"):
            path = directory / name
            path.write_bytes(b"frozen 2.1.4 " + name.encode())
            path.chmod(0o755 if name == "wpcaregrid-deploy-production-priv" else 0o644)
            contents[path] = path.read_bytes()
        policy = self.tmp / "etc-wpcg" / "profiles" / "production-v2.1.4.json"
        policy.write_text('{"enabled": false, "frozen": "2.1.4"}\n')
        policy.chmod(0o644)
        contents[policy] = policy.read_bytes()
        return contents

    def snapshot(self, paths):
        out = {}
        for path in paths:
            info = os.lstat(path)
            out[path] = (path.read_bytes(), info.st_ino, info.st_mode, info.st_mtime_ns, info.st_nlink)
        return out

    def test_installs_beside_an_installed_2_1_4_and_leaves_it_untouched(self):
        old = self.install_214()
        before = self.snapshot(old)
        receipt = self.install()
        self.assertEqual(receipt["result"], "PASS")
        self.assertEqual(receipt["helper_version"], "2.1.5")
        self.assertEqual(self.snapshot(old), before, "2.1.4 files, policy, inodes and mtimes are untouched")
        self.assertEqual(sorted(p.name for p in self.layout.helper_parent.iterdir()), ["2.1.4", "2.1.5"])
        self.assertEqual(sorted(p.name for p in (self.layout.helper_parent / "2.1.4").iterdir()),
                         sorted(p.name for p in (self.layout.helper_parent / "2.1.4").iterdir()))
        # 2.1.5 has its own disabled policy; the 2.1.4 policy is a different path with the old content.
        self.assertNotEqual(self.layout.policy.name, "production-v2.1.4.json")
        policy = json.loads(self.layout.policy.read_text())
        self.assertIs(policy["enabled"], False)
        self.assertIsNone(policy["php_fpm_confinement"])
        self.assertEqual(self.sudoers_bytes(), self.sudoers_before)
        self.assertEqual(sorted(p.name for p in (self.tmp / "sudoers.d").iterdir()), ["wpcaregrid-deploy"])
        self.assertFalse(self.layout.inbox.exists())

    def test_2_1_3_and_2_1_4_paths_are_not_legacy_conflicts(self):
        legacy = {str(path) for path in INSTALLER.PRODUCTION.legacy_paths}
        for version in ("2.0.0", "2.1.0", "2.1.1", "2.1.2"):
            self.assertIn(f"/usr/local/libexec/wpcaregrid-deploy/production/{version}", legacy)
            self.assertIn(f"/etc/wpcaregrid-deploy/profiles/production-v{version}.json", legacy)
            self.assertIn(f"/etc/sudoers.d/wpcaregrid-production-v{version}", legacy)
        for version in ("2.1.3", "2.1.4", "2.1.5"):
            self.assertFalse([p for p in legacy if version in p], version)
        self.assertEqual(len(legacy), 12)

    def test_real_layout_uses_only_2_1_5_paths_and_its_own_inbox(self):
        layout = INSTALLER.PRODUCTION
        self.assertEqual(str(layout.helper_dir), "/usr/local/libexec/wpcaregrid-deploy/production/2.1.5")
        self.assertEqual(str(layout.policy), "/etc/wpcaregrid-deploy/profiles/production-v2.1.5.json")
        self.assertEqual(str(layout.inbox), "/var/lib/wpcaregrid-deploy/production-helper-inbox/2.1.5")
        self.assertEqual(str(layout.source_dir), "/home/deploy/.private/wpcg-production-helper-2.1.5")
        self.assertEqual(str(ENTRY.ENTRYPOINT), "/usr/local/libexec/wpcaregrid-deploy/production/2.1.5/wpcaregrid-deploy-production-priv")
        self.assertEqual(str(ENTRY.POLICY), str(layout.policy))
        self.assertEqual(str(CONTROLLER.HELPER), str(ENTRY.ENTRYPOINT))
        for _name, path, _mode in layout.targets():
            self.assertIn("2.1.5", str(path).replace("2_1_5", "2.1.5"))
            self.assertNotIn("2.1.4", str(path))
            self.assertNotIn("2_1_4", str(path))
        for name in ("wpcaregrid-deploy-production-priv-2_1_5.py", "production_deploy_controller_2_1_5.py",
                     "install_production_helper_2_1_5.py", "deployment_backend_v2_1_5.py",
                     "deployment_environment_contract_2_1_5.py"):
            text = (ROOT / "scripts" / name).read_text()
            for forbidden in ("production/2.1.4", "production-v2.1.4", "helper-2.1.4", "inbox/2.1.4",
                              "_v2_1_4", "contract_2_1_4\"", "wpcaregrid-production-php8.4-fpm",
                              "/etc/php/8.4/fpm-wpcaregrid-production"):
                self.assertNotIn(forbidden, text, f"{name}: {forbidden}")

    def test_complete_2_1_5_with_2_1_4_present_is_a_read_only_no_op(self):
        old = self.install_214()
        self.install()
        before_old = self.snapshot(old)
        before_new = {p: p.read_bytes() for p in self.layout.helper_dir.iterdir()}
        receipt = self.install()
        self.assertEqual(receipt["result"], "NO_OP")
        self.assertEqual(INSTALLER.verify_existing(self.layout, runner=FULL.fake_runner())["result"], "NO_OP")
        self.assertEqual(self.snapshot(old), before_old)
        self.assertEqual({p: p.read_bytes() for p in self.layout.helper_dir.iterdir()}, before_new)

    def test_partial_2_1_5_with_2_1_4_present_is_refused_without_touching_either(self):
        old = self.install_214()
        self.install()
        (self.layout.helper_dir / "deployment_backend_v2_1_5.py").unlink()
        before_old = self.snapshot(old)
        message = self.refused("partial_installation_detected")
        self.assertIn("RECOVERY=REINSTALL_AFTER_MANUAL_CLEANUP", message)
        self.assertEqual(self.snapshot(old), before_old)
        self.assertFalse((self.layout.helper_dir / "deployment_backend_v2_1_5.py").exists(), "never repaired")

    def test_any_preexisting_2_1_5_target_refuses_and_is_never_overwritten(self):
        old = self.install_214()
        before_old = self.snapshot(old)
        for target in ("policy", "directory", "inbox"):
            with self.subTest(target=target):
                if target == "policy":
                    self.layout.policy.write_text("foreign policy\n")
                    self.layout.policy.chmod(0o644)
                elif target == "directory":
                    self.layout.helper_dir.mkdir(mode=0o755)
                else:
                    self.layout.inbox_parent.mkdir(mode=0o700)
                    self.layout.inbox.mkdir(mode=0o700)
                self.refused("partial_installation_detected")
                if target == "policy":
                    self.assertEqual(self.layout.policy.read_text(), "foreign policy\n")
                    self.layout.policy.unlink()
                elif target == "directory":
                    self.assertEqual(list(self.layout.helper_dir.iterdir()), [])
                    self.layout.helper_dir.rmdir()
                else:
                    self.layout.inbox.rmdir()
                    self.layout.inbox_parent.rmdir()
                self.assertEqual(self.snapshot(old), before_old)

    def test_legacy_helpers_before_2_1_3_still_refuse_even_with_2_1_4_present(self):
        self.install_214()
        for relative in ("usr-libexec-wpcg/production/2.1.2", "etc-wpcg/profiles/production-v2.1.2.json",
                         "sudoers.d/wpcaregrid-production-v2.1.2"):
            path = self.tmp / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            if relative.endswith("2.1.2"):
                path.mkdir(exist_ok=True) if "libexec" in relative else path.write_text("x\n")
            else:
                path.write_text("x\n")
            self.refused("legacy_production_helper_present")
            if path.is_dir():
                path.rmdir()
            else:
                path.unlink()

    def test_phase_b_policy_and_database_credentials_still_block_installation(self):
        self.install_214()
        for path, code in ((self.layout.phase_b_policy, "phase_b_policy_must_remain_unconfigured"),
                           (self.layout.database_credentials, "database_credentials_must_remain_unconfigured")):
            path.write_text("{}\n")
            self.refused(code)
            path.unlink()

    def test_production_sudoers_and_deploy_grant_checks_are_unchanged(self):
        self.install_214()
        (self.tmp / "sudoers.d" / "wpcaregrid-production-v2.1.5").write_text("deploy ALL=(root) NOPASSWD: ALL\n")
        self.refused("production_sudoers_rule_present")
        (self.tmp / "sudoers.d" / "wpcaregrid-production-v2.1.5").unlink()
        self.refused("deploy_sudo_rule_outside_staging_helper", rules=(FULL.STAGING_RULE,
                     "(root) NOPASSWD: /usr/local/libexec/wpcaregrid-deploy/production/2.1.5/wpcaregrid-deploy-production-priv"))
        self.assertFalse(self.layout.helper_dir.exists())


# ==========================================================================
# Frozen predecessors
# ==========================================================================

class FrozenPredecessorTests(unittest.TestCase):
    FROZEN_214 = {
        "scripts/wpcaregrid-deploy-production-priv-2_1_4.py": "fe25f28a5cdc85eff17961e7893d72c17015ff318c353909fad77f1ddfefe9d0",
        "scripts/deployment_backend_v2_1_4.py": "1aaacf5c194d59d5d623aaffab4b960e386ad5fbcb9ae9ab5cc20d8693fad519",
        "scripts/deployment_environment_contract_2_1_4.py": "65b06392f26fe8fc3104b67c82b2b56d59d3dc3698661e658dec7f96725a46b7",
        "scripts/production_deploy_controller_2_1_4.py": "58070256654714b2f7830890794ff4971e1095ae435804c7fc5f88e5a3dd7c62",
        "scripts/install_production_helper_2_1_4.py": "fa3d9f73b2c86ade9fba0c37191852523e3acec0af72d7da3d187872581c17bf",
        "scripts/provision_wpcaregrid_deploy_parent_dirs_2_1_4.py": "936dd92c6a68a5df84966d43e8d19c925e51252ba2292f1e54fbda0dfb9fabd1",
        "scripts/release_review_policy_v3_1.py": "5559217003066b77fb2533c8a113fa6d19c497924add59e1db3e023641143d6f",
        "scripts/deployment_transaction_v2.py": "ba4c9fd3ea821cb8a1aa5581a74da8c1477b5e30484fc2e09546a26fe60f2d00",
        "deploy/release-manifests/production-deployment-helper-2.1.4.json": "591f1574ba88c95db99599149a85f95028cf99cd533a39144220c60f1d6b5a98",
        "docs/PRODUCTION_HELPER_INSTALL_2_1_4.md": "bf0cdc67c58fc2589c1c4f27737b35905a61ccc5e5bbc5f673cff9cb81571697",
    }

    def test_2_1_4_and_shared_modules_are_byte_identical(self):
        for relative, digest in self.FROZEN_214.items():
            with self.subTest(path=relative):
                self.assertEqual(sha256((ROOT / relative).read_bytes()), digest)

    def test_2_1_4_remains_bound_to_package_105(self):
        pin = OLD_CONTRACT.confinement_pin()
        self.assertEqual((pin.package_version, pin.package_tree), ("1.0.5", TREE_105))
        self.assertEqual(OLD_CONTRACT.CONFINEMENT_SERVICES["production"].unit, "wpcaregrid-production-php8.4-fpm.service")

    def test_shared_modules_are_reused_not_copied(self):
        for relative in ("scripts/deployment_transaction_v2_1_5.py", "scripts/release_review_policy_v3_1_5.py"):
            self.assertFalse((ROOT / relative).exists(), relative)
        self.assertEqual(INSTALLER.TRANSACTION_SOURCE, "deployment_transaction_v2.py")
        self.assertEqual(INSTALLER.REVIEW_POLICY_SOURCE, "release_review_policy_v3_1.py")
        self.assertEqual(INSTALLER.TRANSACTION_SHA256, self.FROZEN_214["scripts/deployment_transaction_v2.py"])
        self.assertEqual(INSTALLER.REVIEW_POLICY_SHA256, self.FROZEN_214["scripts/release_review_policy_v3_1.py"])

    def test_installer_pins_the_doc_pin_table(self):
        doc = (ROOT / "docs/PRODUCTION_HELPER_INSTALL_2_1_5.md").read_text()
        block = doc.split("<!-- PINS:BEGIN -->")[1].split("<!-- PINS:END -->")[0]
        for name, digest in INSTALLER.SOURCE_HASHES.items():
            self.assertIn(f"`{digest}`", block, name)
        self.assertIn(f"`{INSTALLER.HELPER_ARTIFACT_SHA256}`", block)
        self.assertIn(f"`{sha256((ROOT / 'scripts/install_production_helper_2_1_5.py').read_bytes())}`", block)
        self.assertEqual(len(re.findall(r"\| `[0-9a-f]{64}` \|", block)), 8)


if __name__ == "__main__":
    unittest.main(verbosity=1)
