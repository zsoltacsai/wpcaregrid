#!/usr/bin/env python3
"""Synthetic runtime smoke tests for helper 2.1.11 code paths; no host operations."""
import importlib.util
import hashlib
import json
import os
import subprocess
import stat
import sys
import tempfile
import unittest
from dataclasses import replace
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


BACKEND = load("production_backend_219_runtime", "deployment_backend_v2_1_11.py")
CONTRACT = load("production_contract_219_runtime", "deployment_environment_contract_2_1_11.py")
ENTRY = load("production_entry_219_runtime", "wpcaregrid-deploy-production-priv-2_1_11.py")
CONTROLLER = load("production_controller_219_runtime", "production_deploy_controller_2_1_11.py")
INSTALLER = load("production_installer_219_runtime", "install_production_helper_2_1_11.py")


class HelperRuntimeTests(unittest.TestCase):
    def test_installer_publishes_exact_files_and_verify_existing_is_noop(self):
        if os.getuid() == 0:
            self.skipTest("synthetic ownership test expects an unprivileged runner")
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            for relative in ("usr-libexec", "etc-wpcg/profiles", "etc-wpcg/credentials",
                             "var-lib-wpcg", "sudoers.d", "home-deploy/.private"):
                (root / relative).mkdir(parents=True, exist_ok=True)
            for directory in root.rglob("*"):
                if directory.is_dir():
                    directory.chmod(0o755)
            (root / "sudoers").write_text("root ALL=(ALL:ALL) ALL\\n@includedir /etc/sudoers.d\\n")
            staging = root / "sudoers.d/wpcaregrid-deploy"
            staging.write_text("deploy ALL=(root) NOPASSWD: /usr/local/sbin/wpcaregrid-deploy-priv *\\n")
            staging.chmod(0o440)
            source = root / "home-deploy/.private/wpcg-production-helper-2.1.11"
            source.mkdir()
            source.chmod(0o700)
            for name in INSTALLER.SOURCE_HASHES:
                target = source / name
                target.write_bytes((ROOT / name).read_bytes())
                target.chmod(0o600)
            uid, gid = os.getuid(), os.getgid()
            layout = replace(
                INSTALLER.PRODUCTION, root_uid=uid, root_gid=gid, source_dir=source,
                inbox_parent=root / "var-lib-wpcg/production-helper-inbox",
                helper_parent=root / "usr-libexec/production",
                helper_dir=root / "usr-libexec/production/2.1.11",
                policy=root / "etc-wpcg/profiles/production-v2.1.11.json",
                sudoers_main=root / "sudoers", sudoers_dir=root / "sudoers.d",
                required_dirs=(root / "usr-libexec", root / "etc-wpcg", root / "etc-wpcg/profiles",
                               root / "var-lib-wpcg"),
                legacy_paths=(), phase_b_policy=root / "etc-wpcg/profiles/phase-b.json",
                database_credentials=root / "etc-wpcg/credentials/database.json",
                extra={"test_root": root, "transfer_uid": uid})

            def runner(argv, **kwargs):
                if argv == ["/usr/sbin/visudo", "-c"]:
                    return subprocess.CompletedProcess(argv, 0, b"parsed OK\\n", b"")
                if argv[:5] == ["/usr/bin/sudo", "-n", "-l", "-U", "deploy"]:
                    output = "User deploy may run commands:\\n    (root) NOPASSWD: /usr/local/sbin/wpcaregrid-deploy-priv *\\n"
                    return subprocess.CompletedProcess(argv, 0, output.encode(), b"")
                if argv[0] == "/usr/sbin/runuser":
                    return subprocess.CompletedProcess(argv, 1, b"", b"denied\\n")
                raise AssertionError(f"unexpected synthetic command: {argv}")

            installed = INSTALLER.install(layout, runner=runner)
            self.assertEqual(installed["result"], "PASS")
            self.assertFalse(layout.inbox_parent.exists())
            self.assertFalse(installed["profile_enabled"])
            for name, path, mode in layout.targets():
                info = os.lstat(path)
                self.assertEqual(stat.S_IMODE(info.st_mode), mode, name)
                if name != "policy":
                    self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(), INSTALLER.SOURCE_HASHES[name])
            policy = json.loads(layout.policy.read_text())
            self.assertFalse(policy["enabled"])
            self.assertEqual(INSTALLER.verify_existing(layout, runner=runner)["result"], "NO_OP")

    def test_active_sentinel_cgroup_is_collected_for_rejection(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            unit = root / "system.slice" / "php8.5-fpm.service"
            unit.mkdir(parents=True)
            (unit / "cgroup.procs").write_text("4321\n")
            services = {role: {"active_state": "inactive"} for role in CONTRACT.REQUIRED_ROLES}
            with patch.object(BACKEND, "CONFINEMENT_CGROUP_ROOT", root), \
                    patch.object(BACKEND, "_systemd_unit_state", return_value={
                        "load_state": "loaded", "active_state": "active", "unit_file_state": "enabled"}):
                members, reasons = BACKEND._service_cgroup_pids(services)
            self.assertEqual(members["php8.5-fpm.service"], {4321})
            self.assertEqual(reasons, [])

    def test_active_sentinel_without_readable_cgroup_makes_inventory_incomplete(self):
        with tempfile.TemporaryDirectory() as temp:
            services = {role: {"active_state": "inactive"} for role in CONTRACT.REQUIRED_ROLES}
            with patch.object(BACKEND, "CONFINEMENT_CGROUP_ROOT", Path(temp)), \
                    patch.object(BACKEND, "_systemd_unit_state", return_value={
                        "load_state": "loaded", "active_state": "active", "unit_file_state": "enabled"}):
                members, reasons = BACKEND._service_cgroup_pids(services)
            self.assertEqual(members["php8.5-fpm.service"], set())
            self.assertIn("service_cgroup_unavailable", reasons)

    def test_full_contract_verifier_rejects_invalid_runtime_observation(self):
        contract = type("ProductionContract", (), {"name": "production", "php_fpm_confinement": CONTRACT.confinement_pin()})()
        with self.assertRaises(CONTRACT.EnvironmentRefused):
            CONTRACT.verify_confinement_observation(contract, {})

    def test_entrypoint_installer_and_controller_execute_closed_argument_paths(self):
        self.assertEqual(INSTALLER.main(["--unexpected"]), 3)
        self.assertEqual(CONTROLLER.main(["unexpected"]), 3)
        # The entrypoint's argument validation is exercised before profile loading.
        with patch.object(ENTRY, "_require_root_console", return_value=None):
            self.assertEqual(ENTRY.main(["unexpected"]), 3)


if __name__ == "__main__":
    unittest.main(verbosity=2)
