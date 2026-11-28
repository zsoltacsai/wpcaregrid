#!/usr/bin/env python3
"""Bind the separate post runner to exact 1.0.16 source inputs."""
from __future__ import annotations

import hashlib
import importlib.util
import json
import pathlib
import stat
import subprocess
import sys
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
ARTIFACT = ROOT / 'deploy/php-fpm-confinement/orchestrators/1.0.16-post-io-v2'
PACKAGE = ROOT / 'deploy/php-fpm-confinement/1.0.16'
EXPECTED = {
    'package_tree': '442fa9ab4e82652a70f997238ad5d98e11ade5b9',
    'package_sha256sums': '5a141648ee771e6ceaa4ff296720857bb52ecd9f1c995d6e58b7e6016a50e622',
    'binding_json': '41d10ad9d8d0c3d03e852bed449b3783aef387a5e55be1e9b9077cd37632633d',
    'tools': {
        'matrix.json': '3ea81dd061e77b27f89b410103b98ba36c5c8b6ab2ed881d4907d4af7bcfcb5a',
        'collect-peers.py': 'ff70498c54223c2d533c352cd422835bcc9c1354a29c5e96269010c61e4fbba7',
        'derive-probe-profile.py': '22503809027074cce467ab8651531b1d9b5d75732af165be7f8cafcc83202a8a',
        'probe.py': '5cde081b25341f804f828f18615bc868b30bba1c70454dc1cbf6bc09ac9ff0af',
        'fd-sender.py': '76940755668615cc7e2fbdcd236b04019f084d8ea7ac3599158dffcb7b9a85ac',
        'sibling-listener.py': '88046b95ef7eeaae3984c77d17a1abf5be969654556e32a8ad21fe8b0de95ea0',
        'verify-acceptance.py': 'b821e435ab42ea9f06c2fbe57b0496f1e16e47bc625c3d3f6d51c448586f8c24',
        'fixtures.py': 'e5cc5ee6dc8c60549bb4b8acd63ab6a22487e3edf288b67d1e3bc640191e8ebd',
    },
}
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('post_orchestrator_tree_test', ARTIFACT / 'acceptance-post.py')
orchestrator = importlib.util.module_from_spec(spec)
spec.loader.exec_module(orchestrator)


def digest(path: pathlib.Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


class OrchestratorIntegrityTests(unittest.TestCase):
    def test_package_tree_and_all_source_pins_are_exact(self):
        tree = subprocess.run(
            ['git', '-C', str(ROOT), 'rev-parse', 'HEAD:deploy/php-fpm-confinement/1.0.16'],
            check=True, capture_output=True, text=True,
        ).stdout.strip()
        self.assertEqual(tree, EXPECTED['package_tree'])
        self.assertEqual(orchestrator.git_tree_oid(PACKAGE), EXPECTED['package_tree'])
        pins = json.loads((ARTIFACT / 'PINNED_INPUTS.json').read_text())
        self.assertEqual(pins, {
            'schema': 1,
            'artifact_kind': 'commit-pinned-acceptance-orchestrator-io-v2',
            'acceptance_package_version': '1.0.16',
            'probe_output_model': 'systemd-append-files-v1',
            'minimum_systemd_major': 249,
            'acceptance_package_tree': EXPECTED['package_tree'],
            'package_sha256sums': EXPECTED['package_sha256sums'],
            'binding_json': EXPECTED['binding_json'],
            'tools': EXPECTED['tools'],
        })
        self.assertEqual(digest(PACKAGE / 'SHA256SUMS'), EXPECTED['package_sha256sums'])
        self.assertEqual(digest(PACKAGE / 'binding.json'), EXPECTED['binding_json'])
        for name, expected in EXPECTED['tools'].items():
            self.assertEqual(digest(PACKAGE / 'acceptance' / name), expected, name)

    def test_bundle_manifest_has_exact_files_modes_and_hashes(self):
        rows = (ARTIFACT / 'SHA256SUMS').read_text().splitlines()
        manifest = {}
        for row in rows:
            digest_value, name = row.split('  ', 1)
            self.assertNotIn(name, manifest)
            self.assertNotIn('/', name)
            manifest[name] = digest_value
        expected = {'acceptance-post.py', 'one_shot_bridge.py', 'pty_io.py',
                    'PINNED_INPUTS.json', 'README.md'}
        self.assertEqual(set(manifest), expected)
        rel_artifact = ARTIFACT.relative_to(ROOT).as_posix()
        artifact_tree = subprocess.run(
            ['git', '-C', str(ROOT), 'rev-parse', f'HEAD:{rel_artifact}'],
            check=True, capture_output=True, text=True,
        ).stdout.strip()
        # This is an immutable historical v2 bundle. The current runbook pins
        # the v3 successor, so its active-runner pins are checked by the v3
        # integrity test rather than compared to this retired bundle.
        self.assertEqual(artifact_tree, 'c10e1112a463add9012381c54ead3ff291d6b931')
        self.assertEqual({p.name for p in ARTIFACT.iterdir()} - {'SHA256SUMS'}, expected)
        for name in sorted(expected):
            path = ARTIFACT / name
            self.assertEqual(digest(path), manifest[name], name)
            want_mode = 0o755 if name in {'acceptance-post.py', 'one_shot_bridge.py'} else 0o644
            info = path.lstat()
            self.assertTrue(stat.S_ISREG(info.st_mode), name)
            self.assertEqual(info.st_mode & 0o777, want_mode, name)
            self.assertEqual(info.st_nlink, 1, name)
        manifest_info=(ARTIFACT / 'SHA256SUMS').lstat()
        self.assertTrue(stat.S_ISREG(manifest_info.st_mode))
        self.assertEqual(manifest_info.st_mode & 0o777, 0o644)
        self.assertEqual(manifest_info.st_nlink, 1)


if __name__ == '__main__':
    unittest.main(verbosity=2)
