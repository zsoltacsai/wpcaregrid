import importlib.util
import io
import json
import os
import pathlib
import stat
import sys
import tempfile
import types
import unittest
from types import SimpleNamespace
from unittest.mock import patch

sys.dont_write_bytecode = True
ROOT = pathlib.Path(__file__).resolve().parents[2]
ARTIFACT = ROOT / 'deploy/php-fpm-confinement/orchestrators/1.0.16-post-io-v2'
SCRIPT = ARTIFACT / 'acceptance-post.py'
spec = importlib.util.spec_from_file_location('acceptance_post_local_test', SCRIPT)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
sys.path.insert(0, str(ARTIFACT))
from one_shot_bridge import REPORT_PREFIX, _parse_report

class FakeVerifier:
    reject = False
    calls = []
    class Verdict:
        def __init__(self): self.failures = []
    @staticmethod
    def check_inventory(verdict, matrix, inv, phase, required_roles, probe_phase, matrix_hash, boot):
        FakeVerifier.calls.append((phase, required_roles, probe_phase, matrix_hash, boot))
        if FakeVerifier.reject: verdict.failures.append('rejected')
    @staticmethod
    def identity(inv): return ('fake-signature',)

class AcceptanceExceptionMetadataTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.evidence = pathlib.Path(self.tmp.name)
        self.matrix_path = self.evidence / 'matrix.json'
        self.matrix_path.write_text('{}')
        self.matrix = {
            'role_units': {'nginx':'nginx.service','production':'fpm.service',
                           'production-build-cli':'build.service','production-deploy-cli':'deploy.service'},
            'role_required': {'nginx': True, 'production': True},
            'phases': {'post': {'excluded_roles': []}},
            'profiles': {
                'profile': {'profile_role':'production','peer_roles':['nginx']},
                'wpcaregrid-nginx': {'profile_role':'nginx','peer_roles':['production']},
                'wpcaregrid-php-fpm-production': {'profile_role':'production','peer_roles':['nginx']},
                'wpcaregrid-php-cli-production-build': {'profile_role':'production-build-cli','peer_roles':['production','nginx']},
                'wpcaregrid-php-cli-production-deploy': {'profile_role':'production-deploy-cli','peer_roles':['production','nginx']},
            },
        }
        self.inventory = {'schema': 2, 'complete': True, 'scans': 2,
                          'matrix_sha256': 'a' * 64, 'boot_id': 'abc',
                          'processes': []}
        mod.CURRENT_PHASE = 'test'
        mod.ACTIVE_EVIDENCE = ''
        FakeVerifier.reject = False
        FakeVerifier.calls = []
        self.old_run, self.old_read_json, self.old_sha = mod.run, mod.read_json, mod.sha
        self.addCleanup(self.restore)

    def restore(self):
        mod.run, mod.read_json, mod.sha = self.old_run, self.old_read_json, self.old_sha

    def mock_capture(self, rc=0, parsed=None, parse_exc=None):
        def fake_run(argv, out=None, err=None, **kwargs):
            pathlib.Path(out).write_text('{}')
            pathlib.Path(err).write_bytes(b'')
            return types.SimpleNamespace(returncode=rc)
        mod.run = fake_run
        mod.read_json = lambda path: (_ for _ in ()).throw(parse_exc) if parse_exc else parsed
        mod.sha = lambda path: 'a' * 64

    def capture(self, label='nginx.inventory_before'):
        return mod.capture_inventory(pathlib.Path(self.tmp.name), self.matrix_path,
                                     FakeVerifier, self.matrix, 'profile', 'abc', self.evidence, label)

    def test_parse_error_metadata_is_sanitized(self):
        self.mock_capture(parse_exc=json.JSONDecodeError('SECRET-MESSAGE', 'SECRET-DOC', 1))
        with self.assertRaises(json.JSONDecodeError) as caught: self.capture()
        fields = mod.safe_exception_fields(caught.exception, caught.exception.__traceback__)
        self.assertEqual(fields['exception_class'], 'JSONDecodeError')
        self.assertEqual(fields['phase'], 'nginx.inventory_before.validate')
        self.assertNotIn('SECRET', json.dumps(fields))

    def test_missing_process_key_is_sanitized(self):
        mod.set_phase('nginx.inventory_before.pick-peers')
        with self.assertRaises(KeyError) as caught:
            mod.pick_peers({}, {'role_units': {'production': 'php.service'}},
                           {'peer_roles': ['production']})
        fields = mod.safe_exception_fields(caught.exception, caught.exception.__traceback__)
        self.assertEqual(fields['exception_class'], 'KeyError')
        self.assertNotIn('processes', json.dumps(fields))

    def test_incomplete_inventory_holds_at_verifier_check(self):
        inv = dict(self.inventory, complete=False)
        self.mock_capture(parsed=inv)
        with self.assertRaises(mod.Hold) as caught: self.capture()
        self.assertEqual(caught.exception.code, 'inventory-check')
        self.assertEqual(mod.CURRENT_PHASE, 'nginx.inventory_before.verifier-check')

    def test_collector_nonzero_holds_at_validate(self):
        self.mock_capture(rc=7, parsed=self.inventory)
        with self.assertRaises(mod.Hold) as caught: self.capture()
        self.assertEqual(caught.exception.code, 'collector')
        self.assertEqual(mod.CURRENT_PHASE, 'nginx.inventory_before.validate')

    def test_verifier_rejection_holds_at_verifier_check(self):
        FakeVerifier.reject = True
        self.mock_capture(parsed=self.inventory)
        with self.assertRaises(mod.Hold) as caught: self.capture()
        self.assertEqual(caught.exception.code, 'inventory-check')
        self.assertEqual(mod.CURRENT_PHASE, 'nginx.inventory_before.verifier-check')

    def test_inventory_check_call_matches_pinned_verifier_argument_order(self):
        self.mock_capture(parsed=self.inventory)
        mod.CURRENT_PHASE = 'nginx.inventory_before.capture'
        result = mod.capture_inventory(pathlib.Path(self.tmp.name), self.matrix_path,
                                       FakeVerifier, self.matrix, 'wpcaregrid-php-fpm-production',
                                       'abc', self.evidence, 'nginx-inventory-before')
        self.assertEqual(result[1], ('fake-signature',))
        self.assertEqual(len(FakeVerifier.calls), 1)
        # Pinned check_inventory(v, matrix, inv, phase, required_roles, probe_phase, matrix_hash, boot)
        self.assertEqual(FakeVerifier.calls[0], (
            'nginx-inventory-before', ['production','nginx'], 'post', 'a'*64, 'abc'))

    def test_required_roles_match_pinned_acceptance_verifier_for_all_profiles(self):
        expected = {
            'wpcaregrid-nginx': ['nginx','production'],
            'wpcaregrid-php-fpm-production': ['production','nginx'],
            'wpcaregrid-php-cli-production-build': ['production-build-cli','production','nginx'],
            'wpcaregrid-php-cli-production-deploy': ['production-deploy-cli','production','nginx'],
        }
        for profile, roles in expected.items():
            self.assertEqual(mod.required_inventory_roles(self.matrix, profile, 'post'), roles)

    def test_sibling_gone_requires_inactive_or_unloaded_unit_and_absent_socket(self):
        old_command_ok, old_lexists = mod.command_ok, mod.os.path.lexists
        self.addCleanup(setattr, mod, 'command_ok', old_command_ok)
        self.addCleanup(setattr, mod.os.path, 'lexists', old_lexists)
        socket_present = {'value': False}
        mod.os.path.lexists = lambda path: socket_present['value']
        mod.command_ok = lambda argv, **kwargs: 3
        self.assertTrue(mod.sibling_gone())
        mod.command_ok = lambda argv, **kwargs: 0
        self.assertFalse(mod.sibling_gone())
        mod.command_ok = lambda argv, **kwargs: 1
        self.assertFalse(mod.sibling_gone())
        mod.command_ok = lambda argv, **kwargs: 4
        socket_present['value'] = True
        self.assertFalse(mod.sibling_gone())

    def test_metadata_writer_is_exclusive_0600_and_contains_no_exception_text(self):
        real_lstat = mod.os.lstat
        def fake_lstat(path):
            if pathlib.Path(path) == self.evidence:
                return types.SimpleNamespace(st_mode=stat.S_IFDIR | 0o700, st_uid=0, st_gid=0)
            return real_lstat(path)
        mod.os.lstat = fake_lstat
        self.addCleanup(setattr, mod.os, 'lstat', real_lstat)
        mod.ACTIVE_EVIDENCE = str(self.evidence)
        fields = mod.safe_exception_fields(RuntimeError('SECRET-ARGS'), None)
        self.assertTrue(mod.write_exception_metadata(fields))
        files = list(self.evidence.glob('exception-meta-*.json'))
        self.assertEqual(len(files), 1)
        self.assertEqual(stat.S_IMODE(files[0].stat().st_mode), 0o600)
        text = files[0].read_text()
        self.assertNotIn('SECRET', text)
        self.assertEqual(set(json.loads(text)), {'phase', 'exception_class', 'errno', 'thrower', 'thrower_truncated'})

    def test_metadata_writer_refuses_existing_name(self):
        real_lstat = mod.os.lstat
        def fake_lstat(path):
            if pathlib.Path(path) == self.evidence:
                return types.SimpleNamespace(st_mode=stat.S_IFDIR | 0o700, st_uid=0, st_gid=0)
            return real_lstat(path)
        mod.os.lstat = fake_lstat
        self.addCleanup(setattr, mod.os, 'lstat', real_lstat)
        mod.ACTIVE_EVIDENCE = str(self.evidence)
        old_token = mod.secrets.token_hex
        mod.secrets.token_hex = lambda n: 'fixedtoken'
        self.addCleanup(setattr, mod.secrets, 'token_hex', old_token)
        collision = self.evidence / 'exception-meta-fixedtoken.json'
        collision.write_text('preserve')
        self.assertFalse(mod.write_exception_metadata({'phase':'test'}))
        self.assertEqual(collision.read_text(), 'preserve')

    def test_thrower_basename_allowlist_and_truncated_traceback(self):
        try:
            exec("raise RuntimeError('SECRET')", {'__name__':'<unsafe-name>'})
        except RuntimeError as exc:
            fields = mod.safe_exception_fields(exc, exc.__traceback__)
        self.assertEqual(fields['thrower']['basename'], 'unknown')
        self.assertFalse(fields['thrower_truncated'])

        def descend(n):
            if n:
                return descend(n - 1)
            raise RuntimeError('SECRET-DEEP')
        try:
            descend(70)
        except RuntimeError as exc:
            deep = mod.safe_exception_fields(exc, exc.__traceback__)
        self.assertIsNone(deep['thrower'])
        self.assertTrue(deep['thrower_truncated'])
        self.assertNotIn('SECRET', json.dumps(deep))

    def test_top_level_exception_report_round_trips_actual_one_shot_parser(self):
        captured_fields = {}
        original_write = mod.write_exception_metadata
        original_gate_state = mod.GATE_STATE.copy()
        mod.GATE_STATE.update({'package_pin_ok':True,'fixture_pre_match':True,'host_preflight_ok':True})
        self.addCleanup(mod.GATE_STATE.update, original_gate_state)
        def record_metadata(fields):
            captured_fields.update(fields)
            return True
        mod.write_exception_metadata = record_metadata
        self.addCleanup(setattr, mod, 'write_exception_metadata', original_write)
        mod.set_phase('nginx.inventory_before.pick-peers')
        mod.ACTIVE_EVIDENCE='/root/fake-evidence'
        output = io.StringIO()
        def synthetic_profile_start():
            raise RuntimeError('SECRET-EXCEPTION-TEXT')
        rc = mod.run_and_emit(synthetic_profile_start, output)
        payload = _parse_report(output.getvalue().encode('ascii'))
        self.assertEqual(rc, 2)
        self.assertIsNotNone(payload)
        self.assertEqual(payload['failure_code'], 'unhandled')
        self.assertEqual(payload['rc'], 2)
        self.assertFalse(payload['overall_pass'])
        self.assertTrue(payload['exception_metadata_written'])
        self.assertEqual(payload['exception_phase'], 'nginx.inventory_before.pick-peers')
        self.assertEqual(payload['exception_class'], 'RuntimeError')
        self.assertTrue(payload['package_pin_ok'])
        self.assertTrue(payload['fixture_pre_match'])
        self.assertTrue(payload['host_preflight_ok'])
        self.assertIn('basename', payload['exception_thrower'])
        self.assertEqual(captured_fields['exception_class'], 'RuntimeError')
        self.assertNotIn('SECRET', output.getvalue())
        self.assertNotIn('SECRET', json.dumps(payload))

    def test_pre_evidence_hold_has_valid_report_without_invented_path(self):
        mod.ACTIVE_EVIDENCE=''
        output=io.StringIO()
        def host_guard(): raise mod.Hold('host-identity')
        rc=mod.run_and_emit(host_guard,output)
        payload=_parse_report(output.getvalue().encode('ascii'))
        self.assertEqual(rc,1)
        self.assertIsNotNone(payload)
        self.assertEqual(payload['evidence_path'],'')
        self.assertEqual(payload['failure_code'],'host-identity')
        self.assertFalse(payload['overall_pass'])

    def test_metadata_write_failure_stays_unhandled_hold_after_parser(self):
        original_write = mod.write_exception_metadata
        mod.write_exception_metadata = lambda fields: False
        self.addCleanup(setattr, mod, 'write_exception_metadata', original_write)
        mod.set_phase('nginx.inventory_before.pick-peers')
        mod.ACTIVE_EVIDENCE='/root/fake-evidence'
        output = io.StringIO()
        def synthetic_failure():
            raise OSError(13, 'SECRET-OS-MESSAGE')
        rc = mod.run_and_emit(synthetic_failure, output)
        payload = _parse_report(output.getvalue().encode('ascii'))
        self.assertEqual(rc, 2)
        self.assertIsNotNone(payload)
        self.assertEqual(payload['failure_code'], 'unhandled')
        self.assertEqual(payload['rc'], 2)
        self.assertFalse(payload['overall_pass'])
        self.assertFalse(payload['exception_metadata_written'])
        self.assertEqual(payload['exception_class'], 'PermissionError')
        self.assertEqual(payload['exception_errno'], 13)
        self.assertNotIn('SECRET', output.getvalue())

    def test_route_attestation_requires_root_only_exact_closed_contract(self):
        envelope=pathlib.Path(self.tmp.name)/'envelope'; envelope.mkdir()
        attestation=envelope/'route-closure.json'
        payload={'schema':1,'host':'stg1.wpcaregrid.com','provider':'cloudflare',
                 'route_closed':True,'apex_dns_only':True,'www_dns_only':True,'no_aaaa':True,
                 'no_load_balancer':True,'no_tunnel':True,'origin_explicit_404':True,
                 'origin_app_upstreams':0,'provider_export_sha256':'a'*64,
                 'origin_config_sha256':'b'*64}
        attestation.write_text(json.dumps(payload))
        with patch.object(mod,'ENVELOPE',envelope):
            actual_lstat=os.lstat
            def fake_lstat(path):
                if pathlib.Path(path)==attestation:
                    return SimpleNamespace(st_mode=stat.S_IFREG|0o600,st_uid=0,st_gid=0,
                                           st_nlink=1,st_size=attestation.stat().st_size)
                if pathlib.Path(path)==envelope:
                    return SimpleNamespace(st_mode=stat.S_IFDIR|0o700,st_uid=0,st_gid=0)
                return actual_lstat(path)
            with patch.object(mod.os,'lstat',side_effect=fake_lstat):
                result=mod.validate_route_closure_evidence(attestation)
            self.assertTrue(result['attested'])
            self.assertEqual(result['origin_app_upstreams'],0)
            payload['route_closed']=False
            attestation.write_text(json.dumps(payload))
            with patch.object(mod.os,'lstat',side_effect=fake_lstat):
                with self.assertRaises(mod.Hold): mod.validate_route_closure_evidence(attestation)

if __name__ == '__main__': unittest.main(verbosity=2)
