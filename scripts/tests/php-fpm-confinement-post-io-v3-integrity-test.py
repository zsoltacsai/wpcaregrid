#!/usr/bin/env python3
"""Verify the v3 runner is immutable and pinned to the unchanged 1.0.16 package."""
import hashlib
import json
import pathlib
import re
import stat
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
REL = 'deploy/php-fpm-confinement/orchestrators/1.0.16-post-io-v3'
ARTIFACT = ROOT / REL
PACKAGE = ROOT / 'deploy/php-fpm-confinement/1.0.16'
V2 = ROOT / 'deploy/php-fpm-confinement/orchestrators/1.0.16-post-io-v2'


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


class IoV3BundleIntegrity(unittest.TestCase):
    def test_artifact_tree_and_package_inputs_are_exact(self):
        root_tree = subprocess.run(['git', '-C', str(ROOT), 'write-tree'],
                                   capture_output=True, check=True, text=True).stdout.strip()
        tree = subprocess.run(['git', '-C', str(ROOT), 'rev-parse', f'{root_tree}:{REL}'],
                              capture_output=True, check=True, text=True).stdout.strip()
        spec = importlib_load_runner()
        self.assertEqual(spec.git_tree_oid(ARTIFACT), tree)
        pins = json.loads((ARTIFACT / 'PINNED_INPUTS.json').read_text())
        old = json.loads((V2 / 'PINNED_INPUTS.json').read_text())
        self.assertEqual(pins['acceptance_package_version'], '1.0.16')
        for key in ('acceptance_package_tree', 'package_sha256sums', 'binding_json', 'tools',
                    'probe_output_model', 'minimum_systemd_major'):
            self.assertEqual(pins[key], old[key], key)
        self.assertEqual(pins['artifact_kind'], 'commit-pinned-acceptance-orchestrator-io-v3-probe-self-stat')
        self.assertEqual(pins['probe_profile_overlay'], {
            'rule': 'owner @{PROC}/@{pid}/stat r,',
            'scope': 'derived probe profile only',
            'ptrace_policy': 'unchanged; deny ptrace remains',
        })
        for name, expected in pins['tools'].items():
            self.assertEqual(digest(PACKAGE / 'acceptance' / name), expected, name)

    def test_bundle_manifest_modes_and_runbook_pins_match(self):
        manifest = {}
        for row in (ARTIFACT / 'SHA256SUMS').read_text().splitlines():
            value, name = row.split('  ', 1)
            self.assertNotIn(name, manifest)
            self.assertNotIn('/', name)
            manifest[name] = value
        expected = {'acceptance-post.py', 'one_shot_bridge.py', 'pty_io.py', 'PINNED_INPUTS.json', 'README.md'}
        self.assertEqual(set(manifest), expected)
        self.assertEqual({p.name for p in ARTIFACT.iterdir()}, expected | {'SHA256SUMS'})
        for name in expected | {'SHA256SUMS'}:
            path = ARTIFACT / name
            self.assertTrue(stat.S_ISREG(path.lstat().st_mode), name)
            self.assertEqual(path.lstat().st_nlink, 1, name)
            self.assertEqual(digest(path), manifest.get(name, digest(path)), name)
            want = 0o755 if name in {'acceptance-post.py', 'one_shot_bridge.py'} else 0o644
            self.assertEqual(stat.S_IMODE(path.lstat().st_mode), want, name)
        runbook = (ROOT / 'docs/PHP_FPM_CONFINEMENT_GO20_PHP85_1_0_16.md').read_text()
        self.assertIn(f"ORCH_REL='{REL}'", runbook)
        self.assertIn(f"= '{digest(ARTIFACT / 'SHA256SUMS')}'", runbook)
        for name in ('acceptance-post.py', 'one_shot_bridge.py'):
            self.assertRegex(runbook, rf"sha256sum {re.escape(name)}.*{manifest[name]}")
        root_tree = subprocess.run(['git', '-C', str(ROOT), 'write-tree'],
                                   capture_output=True, check=True, text=True).stdout.strip()
        tree = subprocess.run(['git', '-C', str(ROOT), 'rev-parse', f'{root_tree}:{REL}'],
                              capture_output=True, check=True, text=True).stdout.strip()
        self.assertIn(f"ORCH_TREE='{tree}'", runbook)


def importlib_load_runner():
    import importlib.util
    import sys
    spec = importlib.util.spec_from_file_location('acceptance_post_io_v3_integrity', ARTIFACT / 'acceptance-post.py')
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


if __name__ == '__main__':
    unittest.main(verbosity=2)
