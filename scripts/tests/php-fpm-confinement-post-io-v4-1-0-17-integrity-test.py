#!/usr/bin/env python3
"""Offline integrity tests for the pinned 1.0.17 post runner bundle."""
from __future__ import annotations
import hashlib
import importlib.util
import json
import sys
import unittest
from pathlib import Path

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
PKG = ROOT / 'deploy/php-fpm-confinement/1.0.17'
BUNDLE = ROOT / 'deploy/php-fpm-confinement/orchestrators/1.0.17-post-io-v4'
spec = importlib.util.spec_from_file_location('gate2_1017_runner', BUNDLE / 'acceptance-post.py')
runner = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = runner
spec.loader.exec_module(runner)

class Gate217RunnerIntegrityTests(unittest.TestCase):
    def test_manifest_exact_file_set_modes_and_hashes(self):
        required = {'acceptance-post.py', 'one_shot_bridge.py', 'pty_io.py', 'PINNED_INPUTS.json', 'README.md'}
        rows = (BUNDLE / 'SHA256SUMS').read_text(encoding='ascii').splitlines()
        declared = {}
        for row in rows:
            digest, name = row.split('  ', 1)
            self.assertRegex(digest, r'^[0-9a-f]{64}$')
            self.assertNotIn(name, declared)
            declared[name] = digest
        self.assertEqual(set(declared), required)
        self.assertEqual({p.name for p in BUNDLE.iterdir()} - {'SHA256SUMS'}, required)
        for name, digest in declared.items():
            path = BUNDLE / name
            self.assertTrue(path.is_file())
            self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(), digest, name)
            self.assertEqual(path.stat().st_mode & 0o777, 0o755 if name in {'acceptance-post.py', 'one_shot_bridge.py'} else 0o644)

    def test_package_pins_match_exact_side_by_side_package(self):
        pins = runner.EXPECTED
        self.assertEqual(runner.git_tree_oid(PKG), pins['package_tree'])
        fields = {
            'sums': 'SHA256SUMS', 'binding': 'binding.json',
            'matrix': 'acceptance/matrix.json', 'collector': 'acceptance/collect-peers.py',
            'derive': 'acceptance/derive-probe-profile.py', 'probe': 'acceptance/probe.py',
            'sender': 'acceptance/fd-sender.py', 'listener': 'acceptance/sibling-listener.py',
            'verifier': 'acceptance/verify-acceptance.py', 'fixtures': 'acceptance/fixtures.py',
        }
        for key, rel in fields.items():
            self.assertEqual(hashlib.sha256((PKG / rel).read_bytes()).hexdigest(), pins[key], rel)
        self.assertEqual(json.loads((PKG / 'binding.json').read_text())['package_version'], '1.0.17')

    def test_pin_record_matches_runner_and_package(self):
        record = json.loads((BUNDLE / 'PINNED_INPUTS.json').read_text())
        self.assertEqual(record['acceptance_package_version'], '1.0.17')
        self.assertEqual(record['acceptance_package_tree'], runner.EXPECTED['package_tree'])
        self.assertEqual(record['package_sha256sums'], runner.EXPECTED['sums'])
        self.assertEqual(record['binding_json'], runner.EXPECTED['binding'])
        for key, rel in {'matrix.json':'matrix', 'collect-peers.py':'collector', 'derive-probe-profile.py':'derive',
                         'probe.py':'probe', 'fd-sender.py':'sender', 'sibling-listener.py':'listener',
                         'verify-acceptance.py':'verifier', 'fixtures.py':'fixtures'}.items():
            self.assertEqual(record['tools'][key], runner.EXPECTED[rel])
        self.assertIn('/usr/local/libexec/wpcaregrid-php-fpm-harness/1.0.17/fd-sender.py',
                      (BUNDLE / 'acceptance-post.py').read_text())

    def test_runner_schema_and_readonly_manager_gate_are_present(self):
        bridge = (BUNDLE / 'one_shot_bridge.py').read_text()
        source = (BUNDLE / 'acceptance-post.py').read_text()
        self.assertIn('One-shot Gate 2 1.0.17 post runner', bridge)
        self.assertIn('system_manager_is_unconfined()', source)
        self.assertIn("'acceptance-post'", source)
        self.assertIn("acceptance-1.0.16-post-20261127T001334Z-795c9f", source)
        self.assertIn("acceptance-1.0.17-post-", source)

    def test_probe_io_and_runtime_bounds_remain_separate_and_bounded(self):
        source = (BUNDLE / 'acceptance-post.py').read_text()
        start = source.index('def launch_probe(')
        end = source.index('\ndef start_sibling(', start)
        launch = source[start:end]
        self.assertIn("if not system_manager_is_unconfined()", launch)
        self.assertIn("'StandardOutput=append:'+str(out)", launch)
        self.assertIn("'StandardError=append:'+str(err)", launch)
        self.assertIn("'RuntimeMaxSec=75s'", launch)
        self.assertIn("timeout=90", launch)
        self.assertNotIn("'--pipe'", launch)

if __name__ == '__main__':
    unittest.main(verbosity=2)
