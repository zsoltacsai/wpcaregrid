#!/usr/bin/env python3
"""Offline integrity tests for the pinned 1.0.18 post runner bundle."""
from __future__ import annotations
import hashlib
import importlib.util
import json
import sys
import unittest
import os
import tempfile
from types import SimpleNamespace
from pathlib import Path
from unittest.mock import patch

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
PKG = ROOT / 'deploy/php-fpm-confinement/1.0.18'
BUNDLE = ROOT / 'deploy/php-fpm-confinement/orchestrators/1.0.18-post-io-v5'
spec = importlib.util.spec_from_file_location('gate2_1017_runner', BUNDLE / 'acceptance-post.py')
runner = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = runner
spec.loader.exec_module(runner)

class Gate218RunnerIntegrityTests(unittest.TestCase):
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
        self.assertEqual(json.loads((PKG / 'binding.json').read_text())['package_version'], '1.0.18')

    def test_pin_record_matches_runner_and_package(self):
        record = json.loads((BUNDLE / 'PINNED_INPUTS.json').read_text())
        self.assertEqual(record['acceptance_package_version'], '1.0.18')
        self.assertEqual(record['acceptance_package_tree'], runner.EXPECTED['package_tree'])
        self.assertEqual(record['package_sha256sums'], runner.EXPECTED['sums'])
        self.assertEqual(record['binding_json'], runner.EXPECTED['binding'])
        for key, rel in {'matrix.json':'matrix', 'collect-peers.py':'collector', 'derive-probe-profile.py':'derive',
                         'probe.py':'probe', 'fd-sender.py':'sender', 'sibling-listener.py':'listener',
                         'verify-acceptance.py':'verifier', 'fixtures.py':'fixtures'}.items():
            self.assertEqual(record['tools'][key], runner.EXPECTED[rel])
        self.assertIn('/usr/local/libexec/wpcaregrid-php-fpm-harness/1.0.18/fd-sender.py',
                      (BUNDLE / 'acceptance-post.py').read_text())

    def test_runner_schema_and_readonly_manager_gate_are_present(self):
        bridge = (BUNDLE / 'one_shot_bridge.py').read_text()
        source = (BUNDLE / 'acceptance-post.py').read_text()
        self.assertIn('One-shot Gate 2 1.0.18 post runner', bridge)
        self.assertIn('system_manager_is_unconfined()', source)
        self.assertIn("'acceptance-post'", source)
        self.assertIn("acceptance-1.0.16-post-20261127T001334Z-795c9f", source)
        self.assertIn("acceptance-1.0.18-post-", source)

    def test_unproven_results_continue_but_do_not_enable_cleanup(self):
        source = (BUNDLE / 'acceptance-post.py').read_text()
        self.assertIn("if item['status']=='UNPROVEN':", source)
        self.assertIn("continue", source[source.index("if item['status']=='UNPROVEN':"):source.index("if item['status']!='PASS':")])
        self.assertIn("def cleanup_is_eligible(results, failed):", source)
        self.assertIn("all(item['status']=='PASS' for item in results)", source)
        self.assertIn("failed='owner-ack-required'", source)

    def test_runtime_apparmor_sources_must_match_the_pinned_package(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            package = root / 'package'
            live = root / 'etc-apparmor'
            package.mkdir(); live.mkdir()
            installed = {
                '/etc/apparmor.d/abstractions/wpcaregrid-php-fpm-common': 'apparmor/abstractions/wpcaregrid-php-fpm-common',
                '/etc/apparmor.d/wpcaregrid-nginx': 'apparmor/wpcaregrid-nginx',
                '/etc/apparmor.d/wpcaregrid-php-fpm-production': 'apparmor/wpcaregrid-php-fpm-production',
                '/etc/apparmor.d/wpcaregrid-php-cli-production-build': 'apparmor/wpcaregrid-php-cli-production-build',
                '/etc/apparmor.d/wpcaregrid-php-cli-production-deploy': 'apparmor/wpcaregrid-php-cli-production-deploy',
            }
            import json
            (package / 'binding.json').write_text(json.dumps({'installed_files': installed}))
            for target, source in installed.items():
                package_file = package / source
                package_file.parent.mkdir(parents=True, exist_ok=True)
                package_file.write_bytes(b'profile fixture\n')
                live_file = live / Path(target).relative_to('/etc/apparmor.d')
                live_file.parent.mkdir(parents=True, exist_ok=True)
                live_file.write_bytes(b'profile fixture\n')
                package_file.chmod(0o644); live_file.chmod(0o644)

            real_lstat = os.lstat
            def root_owned_lstat(path):
                info = real_lstat(path)
                return SimpleNamespace(st_mode=info.st_mode, st_uid=0, st_gid=0, st_nlink=info.st_nlink)

            with patch.object(runner, 'PKG', package), patch.object(runner, 'APPARMOR_DIR', live), \
                 patch.object(runner.os, 'lstat', side_effect=root_owned_lstat):
                self.assertTrue(runner.installed_apparmor_sources_match())
                (live / 'wpcaregrid-nginx').write_bytes(b'drifted profile\n')
                self.assertFalse(runner.installed_apparmor_sources_match())

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
