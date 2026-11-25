#!/usr/bin/env python3
"""Regression checks for immutable production confinement 1.0.14 probe cleanup."""
import hashlib
import importlib.util
import json
import os
import subprocess
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
PKG = ROOT / "deploy/php-fpm-confinement/1.0.14"
PREVIOUS = ROOT / "deploy/php-fpm-confinement/1.0.13"
RUNTIME_BASE = ROOT / "deploy/php-fpm-confinement/1.0.12"
EXPECTED_SHA256SUMS = "58cb88770b084b927b74274a96eba960d6c61b4e2defc44f3e1ae57923970eaf"
EXPECTED_BINDING = "d5a80a91023c9bee128cf4f092f0ae10def6bf3c791fbd957071e682212e29f9"
EXPECTED_PACKAGE_TREE = "3a80b54343021b45bb0ae3e011ff8ee62ddcca4c"


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


class ProbeEofCleanup(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.probe = load("production_confinement_1014_probe", PKG / "acceptance/probe.py")

    def run_calibration(self, failing_call=None):
        probe = self.probe
        real_fork = os.fork
        real_waitpid = os.waitpid
        children = []
        waited = []
        calls = []

        def tracked_fork():
            child = real_fork()
            if child > 0:
                children.append(child)
            return child

        def tracked_waitpid(pid, options):
            result = real_waitpid(pid, options)
            waited.append(result)
            return result

        def fake_timed(results, ident, kind, target, call, *, pid=None, extra=None):
            calls.append(ident)
            if failing_call == ident:
                raise RuntimeError("simulated probe failure")
            results.append({"id": ident, "kind": kind, "target": target,
                            "pid": pid or os.getpid(), "errno": 0})

        with patch.object(probe, "timed", fake_timed), \
             patch.object(probe.os, "fork", tracked_fork), \
             patch.object(probe.os, "waitpid", tracked_waitpid):
            if failing_call is None:
                results = []
                probe.descendant_calibration(results)
            else:
                results = []
                with self.assertRaisesRegex(RuntimeError, "simulated probe failure"):
                    probe.descendant_calibration(results)

        self.assertEqual(len(children), 1)
        self.assertEqual(len(waited), 1)
        pid, status = waited[0]
        self.assertEqual(pid, children[0])
        self.assertTrue(os.WIFEXITED(status))
        self.assertEqual(os.WEXITSTATUS(status), 0)
        return calls, results, children[0]

    def test_normal_path_runs_every_calibration_and_reaps_eof_child(self):
        calls, results, child = self.run_calibration()
        expected = [
            "control:self-maps",
            "calibration:child-signal",
            "calibration:child-maps",
            "calibration:child-environ",
            "calibration:child-process_vm_readv",
            "calibration:child-ptrace-seize",
        ]
        self.assertEqual(calls, expected)
        self.assertEqual([item["id"] for item in results], expected)
        self.assertTrue(all(item["child"] == child for item in results))

    def test_probe_exception_still_closes_pipe_and_reaps_child(self):
        calls, results, _child = self.run_calibration("calibration:child-maps")
        self.assertEqual(calls, [
            "control:self-maps", "calibration:child-signal", "calibration:child-maps"
        ])
        self.assertEqual(len(results), 2)

    def test_probe_uses_eof_cleanup_without_signalling_the_child(self):
        source = (PKG / "acceptance/probe.py").read_text(encoding="utf-8")
        self.assertIn("os.pipe2(os.O_CLOEXEC)", source)
        self.assertIn("_close_calibration_pipe_and_reap(write_end, child)", source)
        self.assertNotIn("signal.SIGKILL", source)

    def test_package_pins_and_runtime_files_match_immutable_predecessors(self):
        sums = (PKG / "SHA256SUMS").read_bytes()
        binding_bytes = (PKG / "binding.json").read_bytes()
        binding = json.loads(binding_bytes)
        matrix = json.loads((PKG / "acceptance/matrix.json").read_text(encoding="utf-8"))
        self.assertEqual(hashlib.sha256(sums).hexdigest(), EXPECTED_SHA256SUMS)
        self.assertEqual(hashlib.sha256(binding_bytes).hexdigest(), EXPECTED_BINDING)
        root_tree = subprocess.run(["git", "write-tree"], cwd=ROOT, text=True,
                                   capture_output=True, check=True).stdout.strip()
        entry = subprocess.run(["git", "ls-tree", root_tree, "deploy/php-fpm-confinement/1.0.14"],
                               cwd=ROOT, text=True, capture_output=True, check=True).stdout.strip()
        self.assertEqual(entry.split()[2], EXPECTED_PACKAGE_TREE)
        self.assertEqual(binding["package_version"], "1.0.14")
        self.assertEqual(matrix["version"], "1.0.14")
        self.assertEqual(binding["installed_files"], json.loads(
            (RUNTIME_BASE / "binding.json").read_text(encoding="utf-8"))["installed_files"])

        entries = {}
        for line in sums.decode("utf-8").splitlines():
            digest, name = line.split("  ", 1)
            entries[name] = digest
        actual = {p.relative_to(PKG).as_posix() for p in PKG.rglob("*")
                  if p.is_file() and p.name != "SHA256SUMS"}
        self.assertEqual(set(entries), actual)
        for name, digest in entries.items():
            self.assertEqual(hashlib.sha256((PKG / name).read_bytes()).hexdigest(), digest, name)
        for relative in binding["installed_files"].values():
            self.assertEqual((PKG / relative).read_bytes(), (RUNTIME_BASE / relative).read_bytes(), relative)

        previous = {p.relative_to(PREVIOUS).as_posix() for p in PREVIOUS.rglob("*") if p.is_file()}
        current = {p.relative_to(PKG).as_posix() for p in PKG.rglob("*") if p.is_file()}
        self.assertEqual(previous, current)
        for relative in previous - {"SHA256SUMS", "acceptance/probe.py", "acceptance/matrix.json", "binding.json"}:
            self.assertEqual((PKG / relative).read_bytes(), (PREVIOUS / relative).read_bytes(), relative)


if __name__ == "__main__":
    unittest.main(verbosity=2)
