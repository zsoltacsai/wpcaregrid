#!/usr/bin/env python3
"""Fake-systemd checks for the side-by-side 1.0.16 post I/O runner."""
import importlib.util
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
ARTIFACT = ROOT / 'deploy/php-fpm-confinement/orchestrators/1.0.16-post-io-v2'
MODULE_PATH = ARTIFACT / 'acceptance-post.py'
spec = importlib.util.spec_from_file_location('acceptance_post_io_v2_under_test', MODULE_PATH)
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)


class ProbeOutputTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.root.chmod(0o700)
        self.package = self.root / 'package'
        (self.package / 'acceptance').mkdir(parents=True)
        (self.package / 'acceptance/probe.py').write_text('pass\n')
        self.old_umask = os.umask(0o077)
        self.evidence_patch = patch.object(module, 'ACTIVE_EVIDENCE', str(self.root))
        self.evidence_patch.start()
        self.addCleanup(self.evidence_patch.stop)
        owner = (os.geteuid(), os.getegid())
        self.owner_patch = patch.object(module, 'PROBE_OUTPUT_OWNER', owner)
        self.owner_patch.start()
        self.addCleanup(self.owner_patch.stop)
        self.commands = []

    def tearDown(self):
        os.umask(self.old_umask)
        self.tmp.cleanup()

    def _fake_success(self, argv, *, stdout, stderr, timeout, check):
        self.commands.append(tuple(argv))
        self.assertIs(stdout, subprocess.DEVNULL)
        self.assertEqual(timeout, 90)
        self.assertFalse(check)
        self.assertNotIn('--pipe', argv)
        properties = [argv[i + 1] for i, value in enumerate(argv[:-1]) if value == '-p']
        out_path = Path(next(x.split('append:', 1)[1] for x in properties if x.startswith('StandardOutput=append:')))
        err_path = Path(next(x.split('append:', 1)[1] for x in properties if x.startswith('StandardError=append:')))
        self.assertNotEqual(out_path, err_path)
        self.assertNotEqual(out_path.stat().st_ino if out_path.exists() else -1,
                            os.fstat(stderr.fileno()).st_ino)
        self.assertNotEqual(err_path.stat().st_ino, os.fstat(stderr.fileno()).st_ino)
        self.assertNotEqual(out_path.stat().st_ino, err_path.stat().st_ino)
        self.assertEqual(properties.count('TimeoutStartSec=10s'), 1)
        self.assertEqual(properties.count('RuntimeMaxSec=75s'), 1)
        self.assertEqual(properties.count('TimeoutStopSec=5s'), 1)
        # Simulate separate systemd-run diagnostics and service stdout/stderr.
        stderr.write(b'launcher-secret\n')
        with out_path.open('ab') as child_stdout:
            child_stdout.write(b'{"probe":"json"}\n')
        with err_path.open('ab') as child_stderr:
            child_stderr.write(b'traceback-secret\n')
        return SimpleNamespace(returncode=0)

    def test_probe_streams_are_separate_from_systemd_run_stderr(self):
        with patch.object(module, 'command_ok', return_value=4), \
             patch.object(module.subprocess, 'run', side_effect=self._fake_success):
            rc = module.launch_probe(self.package, '{}', 'wpcaregrid-nginx',
                                     'confined', {}, True,
                                     self.root / 'nginx-confined.json',
                                     self.root / 'nginx-confined.stderr')
        self.assertEqual(rc, 0)
        self.assertEqual(len(self.commands), 1)
        self.assertEqual((self.root / 'nginx-confined.json').read_bytes(), b'{"probe":"json"}\n')
        self.assertEqual((self.root / 'nginx-confined.stderr').read_bytes(), b'traceback-secret\n')
        self.assertEqual((self.root / 'nginx-confined.stderr.systemd-run').read_bytes(), b'launcher-secret\n')
        self.assertNotIn(b'launcher-secret', (self.root / 'nginx-confined.stderr').read_bytes())
        for path in (self.root / 'nginx-confined.json', self.root / 'nginx-confined.stderr',
                     self.root / 'nginx-confined.stderr.systemd-run'):
            info = path.lstat()
            self.assertEqual(info.st_mode & 0o777, 0o600)
            self.assertEqual(info.st_nlink, 1)

    def test_existing_output_is_never_truncated_or_followed(self):
        out = self.root / 'nginx-baseline.json'
        out.write_bytes(b'preserve-me')
        out.chmod(0o600)
        with patch.object(module, 'command_ok', return_value=4), \
             patch.object(module.subprocess, 'run') as fake_run:
            with self.assertRaises(module.Hold) as raised:
                module.launch_probe(self.package, '{}', 'wpcaregrid-nginx',
                                    'baseline', {}, False, out,
                                    self.root / 'nginx-baseline.stderr')
        self.assertEqual(raised.exception.code, 'probe-output-exists')
        fake_run.assert_not_called()
        self.assertEqual(out.read_bytes(), b'preserve-me')

    def test_symlink_output_is_rejected_without_touching_target(self):
        target = self.root / 'target'
        target.write_bytes(b'preserve-target')
        target.chmod(0o600)
        out = self.root / 'nginx-baseline.json'
        out.symlink_to(target)
        with patch.object(module, 'command_ok', return_value=4), \
             patch.object(module.subprocess, 'run') as fake_run:
            with self.assertRaises(module.Hold) as raised:
                module.launch_probe(self.package, '{}', 'wpcaregrid-nginx',
                                    'baseline', {}, False, out,
                                    self.root / 'nginx-baseline.stderr')
        self.assertEqual(raised.exception.code, 'probe-output-exists')
        fake_run.assert_not_called()
        self.assertEqual(target.read_bytes(), b'preserve-target')

    def test_parent_timeout_fails_closed_and_preserves_targets_without_retry(self):
        timeout = subprocess.TimeoutExpired(['/usr/bin/systemd-run'], 90)
        with patch.object(module, 'command_ok', return_value=4) as unit_check, \
             patch.object(module.subprocess, 'run', side_effect=timeout) as fake_run:
            with self.assertRaises(module.Hold) as raised:
                module.launch_probe(self.package, '{}', 'wpcaregrid-nginx',
                                    'confined', {}, True,
                                    self.root / 'nginx-confined.json',
                                    self.root / 'nginx-confined.stderr')
        self.assertEqual(raised.exception.code, 'probe-timeout')
        fake_run.assert_called_once()
        unit_check.assert_called_once()
        self.assertEqual((self.root / 'nginx-confined.json').read_bytes(), b'')
        self.assertEqual((self.root / 'nginx-confined.stderr').read_bytes(), b'')
        self.assertTrue((self.root / 'nginx-confined.stderr.systemd-run').exists())

    def test_collision_check_failure_does_not_spawn(self):
        with patch.object(module, 'command_ok', return_value=0), \
             patch.object(module.subprocess, 'run') as fake_run:
            with self.assertRaises(module.Hold) as raised:
                module.launch_probe(Path('/pinned/package'), '{}', 'wpcaregrid-nginx',
                                    'baseline', {}, False,
                                    self.root / 'nginx-baseline.json',
                                    self.root / 'nginx-baseline.stderr')
        self.assertEqual(raised.exception.code, 'probe-unit-existing')
        fake_run.assert_not_called()

    def test_systemd_version_gate_fails_closed_and_accepts_documented_versions(self):
        for version, expected in ((248, False), (249, True), (255, True)):
            proc = SimpleNamespace(returncode=0, stdout=f'systemd {version} (test)\n'.encode())
            with patch.object(module.subprocess, 'run', return_value=proc):
                self.assertEqual(module.systemd_append_io_supported(), expected)
        for proc in (SimpleNamespace(returncode=1, stdout=b''),
                     SimpleNamespace(returncode=0, stdout=b'not-systemd\n')):
            with patch.object(module.subprocess, 'run', return_value=proc):
                self.assertFalse(module.systemd_append_io_supported())

    def test_parent_log_open_rejects_replaced_symlink(self):
        path = self.root / 'launcher.stderr'
        identity = module._create_probe_output(path)
        path.unlink()
        target = self.root / 'outside'
        target.write_bytes(b'preserve')
        path.symlink_to(target)
        with self.assertRaises(module.Hold) as raised:
            module._open_probe_output_for_parent(path, identity)
        self.assertEqual(raised.exception.code, 'probe-output-open')
        self.assertEqual(target.read_bytes(), b'preserve')


if __name__ == '__main__':
    unittest.main(verbosity=2)
