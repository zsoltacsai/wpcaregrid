#!/usr/bin/env python3
"""Supervisor state parsing and contract must fail closed for helper 2.1.10."""
import importlib.util
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace

ROOT = Path(__file__).resolve().parents[1]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


CONTRACT = load("wpcg_contract_217_supervisor", ROOT / "deployment_environment_contract_2_1_10.py")


class SupervisorFailClosed(unittest.TestCase):
    def setUp(self):
        self.contract = SimpleNamespace(
            scheduler_unit="wpcaregrid-production-scheduler.timer",
            worker_programs=("wpcaregrid-production-default", "wpcaregrid-production-queue"),
            worker_fence_marker="/var/lib/wpcaregrid-deploy/production/state/workers-disabled",
            scheduler_fence_marker="/var/lib/wpcaregrid-deploy/production/state/scheduler-paused",
        )
        self.units = {u: {"load_state": "masked", "active_state": "inactive", "unit_file_state": "masked"}
                      for u in CONTRACT.production_job_fence_scope(self.contract)["units"]}
        self.markers = {k: {"type": "regular", "uid": 0, "gid": 0, "mode": 0o600, "nlink": 1,
                            "sha256": __import__("hashlib").sha256(CONTRACT.fence_marker_bytes(k)).hexdigest()}
                         for k in CONTRACT.production_job_fence_scope(self.contract)["markers"]}

    def fence(self, supervisor_state, *, runtime=False, programs=None):
        return {"units": self.units,
                "supervisor": {"unit_state": supervisor_state, "config_complete": True,
                               "runtime_available": runtime, "programs": programs or []},
                "markers": self.markers}

    def verify(self, state, **kwargs):
        CONTRACT._verify_production_jobs_fenced(self.contract, self.fence(state, **kwargs), {})

    def test_only_exact_absence_or_active_runtime_can_pass(self):
        absent = {"load_state": "not-found", "active_state": "inactive", "unit_file_state": "not-found"}
        CONTRACT._verify_production_jobs_fenced(self.contract, self.fence(absent), {})
        active = {"load_state": "loaded", "active_state": "active", "unit_file_state": "enabled"}
        stopped = [{"name": "wpcaregrid-production-default", "state": "STOPPED", "autostart": False}]
        CONTRACT._verify_production_jobs_fenced(self.contract, self.fence(active, runtime=True, programs=stopped), {})

    def test_transitional_failed_inactive_and_unknown_states_are_refused(self):
        states = (
            {"load_state": "loaded", "active_state": "activating", "unit_file_state": "enabled"},
            {"load_state": "loaded", "active_state": "deactivating", "unit_file_state": "enabled"},
            {"load_state": "loaded", "active_state": "failed", "unit_file_state": "enabled"},
            {"load_state": "loaded", "active_state": "inactive", "unit_file_state": "enabled"},
            {"load_state": "unknown", "active_state": "unknown", "unit_file_state": "unknown"},
            {"load_state": "not-found", "active_state": "inactive", "unit_file_state": "not-found"},
        )
        for state in states:
            with self.subTest(state=state), self.assertRaises(CONTRACT.EnvironmentRefused):
                self.verify(state, runtime=False, programs=[{"name": "wpcaregrid-production-default",
                    "state": "SUPERVISOR_INACTIVE", "autostart": False}] if state["load_state"] == "not-found" else [])

    def test_active_supervisor_requires_successful_runtime_and_exact_stopped_programs(self):
        active = {"load_state": "loaded", "active_state": "active", "unit_file_state": "enabled"}
        cases = ((False, []), (True, [{"name": "wpcaregrid-production-default", "state": "RUNNING", "autostart": False}]),
                 (True, [{"name": "wpcaregrid-production-default", "state": "STOPPED", "autostart": True}]),
                 (True, [{"name": "wpcaregrid-production-default", "state": "UNKNOWN", "autostart": False}]))
        for runtime, programs in cases:
            with self.subTest(runtime=runtime, programs=programs), self.assertRaises(CONTRACT.EnvironmentRefused):
                self.verify(active, runtime=runtime, programs=programs)

    def test_php85_sentinel_service_member_is_refused(self):
        import hashlib
        process = {
            "pid": 4321, "starttime": 10, "uids": [33, 33, 33, 33],
            "gids": [33, 33, 33, 33], "groups": [33], "ppid": 1,
            "state": "S", "comm": "php-fpm", "exe": "/usr/sbin/php-fpm8.5",
            "argv0": "/usr/sbin/php-fpm8.5",
            "cmdline_sha256": hashlib.sha256(b"php-fpm8.5").hexdigest(),
            "cgroup": "/system.slice/php8.5-fpm.service", "unit": "php8.5-fpm.service",
            "label": "unconfined", "fds": [],
        }
        empty_peers = {"complete": True, "incomplete_reasons": [], "processes": []}
        members = {"complete": True, "incomplete_reasons": [], "processes": [process]}
        with self.assertRaisesRegex(CONTRACT.EnvironmentRefused, "confinement_peer_cgroup_unexpected"):
            CONTRACT._verify_service_members(members, {}, empty_peers)


if __name__ == "__main__":
    unittest.main(verbosity=2)
