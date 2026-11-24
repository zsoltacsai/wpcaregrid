#!/usr/bin/env python3
"""Synthetic runtime smoke tests for helper 2.1.10 code paths; no host operations."""
import importlib.util
import os
import sys
import tempfile
import unittest
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


BACKEND = load("production_backend_219_runtime", "deployment_backend_v2_1_10.py")
CONTRACT = load("production_contract_219_runtime", "deployment_environment_contract_2_1_10.py")
ENTRY = load("production_entry_219_runtime", "wpcaregrid-deploy-production-priv-2_1_10.py")
CONTROLLER = load("production_controller_219_runtime", "production_deploy_controller_2_1_10.py")
INSTALLER = load("production_installer_219_runtime", "install_production_helper_2_1_10.py")


class HelperRuntimeTests(unittest.TestCase):
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
