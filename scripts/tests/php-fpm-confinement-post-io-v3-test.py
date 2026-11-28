#!/usr/bin/env python3
"""Focused tests for the v3 probe-only /proc self-stat overlay."""
import importlib.util
import os
import shutil
import sys
import subprocess
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
ARTIFACT = ROOT / 'deploy/php-fpm-confinement/orchestrators/1.0.16-post-io-v3'
spec = importlib.util.spec_from_file_location('acceptance_post_io_v3_test', ARTIFACT / 'acceptance-post.py')
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)


class ProbeSelfStatOverlayTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.root.chmod(0o700)
        self.src = self.root / 'source.profile'
        self.src.write_text('source profile\n')
        self.dest = self.root / 'wpcaregrid-nginx-probe.profile'
        self.stderr = self.root / 'derive.stderr'
        self.evidence_patch = patch.object(module, 'ACTIVE_EVIDENCE', str(self.root))
        self.evidence_patch.start()
        self.addCleanup(self.evidence_patch.stop)
        owner = (os.geteuid(), os.getegid())
        self.owner_patch = patch.object(module, 'PROBE_PROFILE_OWNER', owner)
        self.owner_patch.start()
        self.addCleanup(self.owner_patch.stop)
        self.generated = (
            b'#include <tunables/global>\n'
            b'profile wpcaregrid-nginx-probe flags=(attach_disconnected) {\n'
            b'  audit deny ptrace,\n'
            b'  /proc/[0-9]*/attr/current r,\n'
            b'}\n'
        )

    def tearDown(self):
        self.tmp.cleanup()

    def _run_success(self, argv, *, stdin, stdout, stderr, check):
        self.assertTrue(hasattr(stdin, 'fileno'))
        self.assertIs(stdout, __import__('subprocess').PIPE)
        self.assertTrue(hasattr(stderr, 'fileno'))
        self.assertFalse(check)
        return SimpleNamespace(returncode=0, stdout=self.generated)

    def test_only_derived_probe_gets_owner_self_stat_read_and_deny_ptrace_stays(self):
        with patch.object(module.subprocess, 'run', side_effect=self._run_success):
            self.assertEqual(module.derive_probe_profile(self.src, self.dest, self.stderr, Path('/pinned/derive.py')), 0)
        data = self.dest.read_bytes()
        self.assertTrue(data.endswith(b'  owner @{PROC}/@{pid}/stat r,\n}\n'))
        self.assertEqual(data.count(b'owner @{PROC}/@{pid}/stat r,'), 1)
        self.assertIn(b'  audit deny ptrace,\n', data)
        self.assertEqual(self.dest.stat().st_mode & 0o777, 0o600)
        self.assertEqual(self.dest.stat().st_nlink, 1)
        self.assertEqual(self.stderr.read_bytes(), b'')

    def test_nonzero_deriver_does_not_create_profile(self):
        proc = SimpleNamespace(returncode=2, stdout=self.generated)
        with patch.object(module.subprocess, 'run', return_value=proc):
            with self.assertRaises(module.Hold) as raised:
                module.derive_probe_profile(self.src, self.dest, self.stderr, Path('/pinned/derive.py'))
        self.assertEqual(raised.exception.code, 'derive-profile')
        self.assertFalse(self.dest.exists())

    def test_bad_or_overbound_deriver_output_holds_without_profile(self):
        for data in (b'not a profile', b'x' * 2_000_001):
            with self.subTest(size=len(data)), patch.object(
                module.subprocess, 'run', return_value=SimpleNamespace(returncode=0, stdout=data)
            ):
                with self.assertRaises(module.Hold) as raised:
                    module.derive_probe_profile(self.src, self.dest, self.stderr, Path('/pinned/derive.py'))
                self.assertEqual(raised.exception.code, 'derive-profile-output')
                self.assertFalse(self.dest.exists())
                self.stderr.unlink()

    def test_existing_destination_is_not_replaced(self):
        self.dest.write_bytes(b'preserve')
        with patch.object(module.subprocess, 'run', side_effect=self._run_success):
            with self.assertRaises(module.Hold) as raised:
                module.derive_probe_profile(self.src, self.dest, self.stderr, Path('/pinned/derive.py'))
        self.assertEqual(raised.exception.code, 'derive-artifact-exists')
        self.assertEqual(self.dest.read_bytes(), b'preserve')

    def test_no_ptrace_grant_or_production_profile_edit_is_in_overlay(self):
        source = (ARTIFACT / 'acceptance-post.py').read_text()
        self.assertIn("owner @{PROC}/@{pid}/stat r,", source)
        self.assertNotIn("ptrace read", source)
        package_profile = (ROOT / 'deploy/php-fpm-confinement/1.0.16/apparmor/wpcaregrid-nginx').read_text()
        self.assertNotIn("owner @{PROC}/@{pid}/stat r,", package_profile)

    def test_overlay_rejects_duplicate_or_unclosed_profile(self):
        with self.assertRaises(module.Hold) as raised:
            module.add_probe_self_stat_rule(self.generated[:-2] + b'  owner @{PROC}/@{pid}/stat r,\n}\n')
        self.assertEqual(raised.exception.code, 'derive-profile-output')
        with self.assertRaises(module.Hold):
            module.add_probe_self_stat_rule(b'profile incomplete\n')

    @unittest.skipUnless(shutil.which('apparmor_parser'), 'apparmor_parser is installed in the isolated CI job')
    def test_derived_probe_with_overlay_compiles_without_loading(self):
        package = ROOT / 'deploy/php-fpm-confinement/1.0.16'
        src = package / 'apparmor/wpcaregrid-nginx'
        derive = package / 'acceptance/derive-probe-profile.py'
        proc = subprocess.run(['/usr/bin/python3', '-B', str(derive)], input=src.read_bytes(),
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False)
        self.assertEqual(proc.returncode, 0, proc.stderr.decode('utf-8', 'replace'))
        profile_bytes = module.add_probe_self_stat_rule(proc.stdout)
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / 'wpcaregrid-nginx-probe.profile'
            path.write_bytes(profile_bytes)
            path.chmod(0o600)
            result = subprocess.run(['apparmor_parser', '-Q', '-K', '-I', str(package / 'apparmor'), str(path)],
                                    capture_output=True, text=True, check=False)
            self.assertEqual(result.returncode, 0, result.stderr)


if __name__ == '__main__':
    unittest.main(verbosity=2)
