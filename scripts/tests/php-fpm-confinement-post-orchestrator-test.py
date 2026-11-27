#!/usr/bin/python3
import importlib.util
import os
import stat
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
ARTIFACT = ROOT / 'deploy/php-fpm-confinement/orchestrators/1.0.16-post'
MODULE_PATH = ARTIFACT / 'acceptance-post.py'
spec = importlib.util.spec_from_file_location('acceptance_post_1_0_16_under_test', MODULE_PATH)
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)


class DeriveProfileTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.old_umask = os.umask(0o077)
        self.owner_patch = patch.object(module, 'PROBE_PROFILE_OWNER', (os.geteuid(), os.getegid()))
        self.owner_patch.start()
        self.addCleanup(self.owner_patch.stop)
        self.evidence_patch = patch.object(module, 'ACTIVE_EVIDENCE', str(self.root))
        self.evidence_patch.start()
        self.addCleanup(self.evidence_patch.stop)
        # Patch only the AppArmor profile-list lookup; source profile reads remain real.
        self.original_read_text = Path.read_text
        def profile_status(path, *args, **kwargs):
            if str(path) == '/sys/kernel/security/apparmor/profiles':
                return ''
            return self.original_read_text(path, *args, **kwargs)
        self.profile_read_patch = patch.object(Path, 'read_text', profile_status)
        self.profile_read_patch.start()
        self.addCleanup(self.profile_read_patch.stop)
        self.src = self.root / 'source.profile'
        self.src.write_bytes(b'profile source\n')
        self.profile = self.root / 'derived.profile'
        self.stderr = self.root / 'derive.stderr'
        self.script = self.root / 'derive-probe-profile.py'

    def tearDown(self):
        os.umask(self.old_umask)
        self.tmp.cleanup()

    def test_pass_uses_open_file_objects_and_captures_stderr(self):
        seen = {}

        def fake_run(argv, *, stdin, stdout, stderr, check):
            seen.update({'argv': argv, 'stdin': stdin, 'stdout': stdout,
                         'stderr': stderr, 'check': check})
            self.assertTrue(hasattr(stdin, 'fileno'))
            self.assertTrue(hasattr(stdout, 'fileno'))
            self.assertTrue(hasattr(stderr, 'fileno'))
            self.assertFalse(isinstance(stderr, Path))
            stdout.write(b'derived profile\n')
            stderr.write(b'expected diagnostic\n')
            return SimpleNamespace(returncode=0)

        with patch.object(module.subprocess, 'run', side_effect=fake_run):
            rc = module.derive_probe_profile(self.src, self.profile, self.stderr, self.script)
        self.assertEqual(rc, 0)
        self.assertEqual(self.profile.read_bytes(), b'derived profile\n')
        self.assertEqual(self.stderr.read_bytes(), b'expected diagnostic\n')
        self.assertTrue(seen['check'] is False)

    def test_spawn_exception_removes_only_created_partial_profile(self):
        def fail_spawn(argv, **kwargs):
            self.assertTrue(hasattr(kwargs['stderr'], 'fileno'))
            kwargs['stdout'].write(b'partial')
            kwargs['stderr'].write(b'spawn failed')
            raise OSError(5, 'synthetic spawn failure')

        with patch.object(module.subprocess, 'run', side_effect=fail_spawn):
            with self.assertRaisesRegex(OSError, 'synthetic spawn failure'):
                module.derive_probe_profile(self.src, self.profile, self.stderr, self.script)
        self.assertFalse(os.path.lexists(self.profile))
        self.assertEqual(self.stderr.read_bytes(), b'spawn failed')

    def test_nonzero_derive_removes_partial_profile_and_fails_closed(self):
        def fail_derive(argv, *, stdin, stdout, stderr, check):
            stdout.write(b'partial')
            return SimpleNamespace(returncode=7)

        with patch.object(module.subprocess, 'run', side_effect=fail_derive):
            with self.assertRaises(module.Hold) as raised:
                module.derive_probe_profile(self.src, self.profile, self.stderr, self.script)
        self.assertEqual(raised.exception.code, 'derive-profile')
        self.assertFalse(os.path.lexists(self.profile))

    def test_replaced_profile_is_not_unlinked(self):
        def replace_then_fail(argv, *, stdin, stdout, stderr, check):
            os.unlink(self.profile)
            self.profile.write_bytes(b'not ours')
            raise OSError(5, 'synthetic spawn failure')

        with patch.object(module.subprocess, 'run', side_effect=replace_then_fail):
            with self.assertRaises(module.Hold) as raised:
                module.derive_probe_profile(self.src, self.profile, self.stderr, self.script)
        self.assertEqual(raised.exception.code, 'derive-artifact-cleanup')
        self.assertEqual(self.profile.read_bytes(), b'not ours')

    def test_mode_mismatch_holds_without_unlink(self):
        def change_mode_then_fail(argv, *, stdin, stdout, stderr, check):
            os.chmod(self.profile, 0o640)
            raise OSError(5, 'synthetic spawn failure')

        with patch.object(module.subprocess, 'run', side_effect=change_mode_then_fail):
            with self.assertRaises(module.Hold) as raised:
                module.derive_probe_profile(self.src, self.profile, self.stderr, self.script)
        self.assertEqual(raised.exception.code, 'derive-artifact-cleanup')
        self.assertTrue(self.profile.exists())
        self.assertEqual(self.profile.stat().st_mode & 0o777, 0o640)

    def test_loaded_probe_label_holds_without_unlink(self):
        def loaded_status(path, *args, **kwargs):
            if str(path) == '/sys/kernel/security/apparmor/profiles':
                return 'derived (enforce)\n'
            return self.original_read_text(path, *args, **kwargs)
        with patch.object(Path, 'read_text', loaded_status):
            def spawn_then_fail(argv, **kwargs):
                raise OSError(5, 'synthetic spawn failure')
            with patch.object(module.subprocess, 'run', side_effect=spawn_then_fail):
                with self.assertRaises(module.Hold) as raised:
                    module.derive_probe_profile(self.src, self.profile, self.stderr, self.script)
        self.assertEqual(raised.exception.code, 'derive-artifact-cleanup')
        self.assertTrue(self.profile.exists())

    def test_stale_profile_absent_is_required(self):
        stale=self.root/'old-run'/'wpcaregrid-nginx-probe.profile'
        with patch.object(module,'STALE_PARTIAL_PROFILE',stale):
            self.assertTrue(module.stale_profile_absent())

    def test_reappeared_stale_profile_holds_without_unlink(self):
        run_dir=self.root/'old-run'; run_dir.mkdir()
        stale=run_dir/'wpcaregrid-nginx-probe.profile'; stale.write_bytes(b'preserve')
        with patch.object(module,'STALE_PARTIAL_PROFILE',stale):
            self.assertFalse(module.stale_profile_absent())
        self.assertEqual(stale.read_bytes(),b'preserve')

    def test_partial_load_failure_attempts_unload_and_verifies_absence(self):
        with patch.object(module,'command_ok',side_effect=[1,0]) as command, \
             patch.object(module,'probe_profile_state',side_effect=[True,True,False]):
            with self.assertRaises(module.Hold) as raised:
                module.load_probe_profile(self.profile)
        self.assertEqual(raised.exception.code,'probe-load')
        self.assertEqual(command.call_count,2)

    def test_unload_rc_zero_but_profile_still_loaded_fails(self):
        with patch.object(module,'command_ok',return_value=0), \
             patch.object(module,'probe_profile_state',side_effect=[True,True]):
            self.assertFalse(module.unload_probe(self.profile))


class SiblingOwnershipTests(unittest.TestCase):
    def setUp(self):
        module.SIBLING_UNITS.clear()
        self.tmp = tempfile.TemporaryDirectory()
        self.evidence = Path(self.tmp.name)
        self.pkg = self.evidence / 'package'
        (self.pkg / 'acceptance').mkdir(parents=True)
        self.matrix = self.pkg / 'acceptance/matrix.json'
        self.matrix.write_text('{}')
        self.commands = []
        self.foreign = {'wpcg-sibling-canary.service': 'inactive'}
        self.lexists_patch = patch.object(module.os.path, 'lexists', return_value=False)
        self.lexists_patch.start()
        self.addCleanup(self.lexists_patch.stop)
        self.nonce_patch = patch.object(module.secrets, 'token_hex', return_value='a' * 32)
        self.nonce_patch.start()
        self.addCleanup(self.nonce_patch.stop)

        def command_ok(argv, **kwargs):
            self.commands.append(tuple(argv))
            if argv[1] == 'is-active':
                name = argv[-1]
                return 0 if self.foreign.get(name) == 'active' else 3
            if argv[1] == 'stop':
                self.foreign[argv[-1]] = 'inactive'
                return 0
            return 0
        self.command_patch = patch.object(module, 'command_ok', side_effect=command_ok)
        self.command_patch.start()
        self.addCleanup(self.command_patch.stop)

    def tearDown(self):
        module.SIBLING_UNITS.clear()
        self.tmp.cleanup()

    def _assert_failed_start_does_not_stop_late_foreign_unit(self, outcome):
        nonce_unit='wpcg-g2-sibling-'+('a'*32)+'.service'
        def fake_run(argv, **kwargs):
            self.assertIn('--unit=wpcg-g2-sibling-' + ('a' * 32), argv)
            # Model another actor activating the attempted nonce name after
            # our precheck, while this invocation's systemd-run fails/times out.
            self.foreign[nonce_unit] = 'active'
            self.foreign['wpcg-sibling-canary.service'] = 'active'
            if isinstance(outcome, BaseException):
                raise outcome
            return SimpleNamespace(returncode=outcome)

        matrix={'profiles':{'wpcaregrid-php-cli-production-build':{'peer_roles':['production']}}}
        with patch.object(module, 'PKG', self.pkg), \
             patch.object(module, 'capture_inventory', return_value=({},('stable',),0)), \
             patch.object(module, 'pick_peers', return_value=[]), \
             patch.object(module.os.path, 'lexists', return_value=False), \
             patch.object(module.secrets, 'token_hex', side_effect=['sendernonce','a'*32]), \
             patch.object(module, 'command_ok', side_effect=lambda argv, **kwargs: self._command(argv)), \
             patch.object(module, 'run', side_effect=fake_run), \
             patch.object(module, 'stop_sibling', wraps=module.stop_sibling) as stop:
            result=module.profile_run('wpcaregrid-php-cli-production-build','{}',matrix,object(),
                                      'bootid',self.evidence)
        self.assertEqual(result['status'],'HOLD')
        self.assertEqual(result['failure_code'],'sibling-start')
        # Exercise the actual profile_run finally path: no success callback means
        # no owned-unit token and therefore no stop operation.
        stop.assert_not_called()
        self.assertEqual(self.foreign['wpcg-sibling-canary.service'], 'active')
        self.assertEqual(self.foreign[nonce_unit], 'active')
        self.assertFalse(any(command[1] == 'stop' for command in self.commands))
        self.assertFalse(any('wpcg-sibling-canary.service' in command for command in self.commands))

    def _command(self, argv):
        self.commands.append(tuple(argv))
        if argv[1]=='is-active':
            return 0 if self.foreign.get(argv[-1])=='active' else 3
        if argv[1]=='stop':
            self.foreign[argv[-1]]='inactive'
            return 0
        return 0

    def test_systemd_run_failure_does_not_stop_late_foreign_unit(self):
        self._assert_failed_start_does_not_stop_late_foreign_unit(1)

    def test_systemd_run_timeout_does_not_stop_late_foreign_unit(self):
        timeout = module.subprocess.TimeoutExpired(['systemd-run'], 20)
        self._assert_failed_start_does_not_stop_late_foreign_unit(timeout)

    def test_cleanup_rejects_shared_or_malformed_unit_name(self):
        self.assertFalse(module.stop_sibling('wpcg-sibling-canary.service'))
        self.assertEqual(self.commands, [])

    def test_two_cli_sibling_lifecycles_share_evidence_dir(self):
        socket = {'present': False}
        units = {}
        nonce_values = ['1' * 32, '2' * 32]
        run_calls = []

        def command_ok(argv, **kwargs):
            self.commands.append(tuple(argv))
            if argv[1] == 'is-active':
                name = argv[-1]
                return 0 if units.get(name) == 'active' or self.foreign.get(name) == 'active' else 3
            if argv[1] == 'stop':
                units[argv[-1]] = 'inactive'
                socket['present'] = False
                return 0
            return 0

        def subprocess_run(argv, *, stdout, stderr, **kwargs):
            run_calls.append((tuple(argv), stdout.name, stderr.name))
            unit_arg = next(arg for arg in argv if arg.startswith('--unit='))
            name = unit_arg.split('=', 1)[1] + '.service'
            units[name] = 'active'
            socket['present'] = True
            return SimpleNamespace(returncode=0)

        with patch.object(module.secrets, 'token_hex', side_effect=nonce_values), \
             patch.object(module, 'command_ok', side_effect=command_ok), \
             patch.object(module.subprocess, 'run', side_effect=subprocess_run), \
             patch.object(module.os.path, 'lexists', side_effect=lambda path: socket['present']), \
             patch.object(module.os.path, 'exists', side_effect=lambda path: socket['present']), \
             patch.object(module.os, 'lstat', return_value=SimpleNamespace(st_mode=stat.S_IFSOCK)), \
             patch.object(module.stat, 'S_ISSOCK', return_value=True):
            started = []
            module.start_sibling(self.pkg, self.matrix, self.evidence, started.append)
            self.assertTrue(module.stop_sibling(started[0]))
            module.start_sibling(self.pkg, self.matrix, self.evidence, started.append)

            self.assertEqual(len(run_calls), 2)
            self.assertNotEqual(run_calls[0][1], run_calls[1][1])
            self.assertNotEqual(run_calls[0][2], run_calls[1][2])
            self.assertTrue(all(Path(path).is_file() for call in run_calls for path in call[1:]))
            self.assertEqual(len(started), 2)
            self.assertNotEqual(started[0], started[1])

            self.assertTrue(module.stop_sibling(started[1]))
            self.assertTrue(module.sibling_gone())

        stops = [command[-1] for command in self.commands if len(command) > 1 and command[1] == 'stop']
        self.assertEqual(stops, started)
        self.assertFalse(socket['present'])


if __name__ == '__main__':
    unittest.main(verbosity=2)
